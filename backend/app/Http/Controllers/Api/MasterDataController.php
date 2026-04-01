<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\EntityVersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MasterDataController extends Controller
{
    /**
     * @var array<string, string>
     */
    private array $entityTableMap = [
        'facilities' => 'facilities',
        'departments' => 'departments',
        'providers' => 'providers',
        'services' => 'services',
        'service_pricing' => 'service_pricings',
        'hours' => 'facility_hours',
        'addresses' => 'addresses',
    ];

    public function __construct(
        private readonly EntityVersioningService $versioning,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function index(Request $request, string $entity): JsonResponse
    {
        $table = $this->resolveTable($entity);
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Unknown entity.', ApiResponse::requestId($request)), 404);
        }

        $perPage = min((int) $request->query('per_page', 20), 200);
        $page = max((int) $request->query('page', 1), 1);

        $query = DB::table($table);
        if ($request->filled('facility_id') && $this->hasColumn($table, 'facility_id')) {
            $query->where('facility_id', (int) $request->query('facility_id'));
        }
        if ($request->filled('status') && $this->hasColumn($table, 'status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / max($perPage, 1)),
            ],
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function store(Request $request, string $entity): JsonResponse
    {
        $table = $this->resolveTable($entity);
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Unknown entity.', ApiResponse::requestId($request)), 404);
        }

        $payload = $request->except(['id', 'created_at', 'version']);
        $payload['updated_at'] = now()->utc();
        $payload['created_at'] = now()->utc();

        $row = DB::transaction(function () use ($table, $payload, $entity, $request) {
            $externalKey = (string) ($payload['external_key'] ?? '');
            if ($externalKey !== '' && $this->hasColumn($table, 'external_key')) {
                $existing = DB::table($table)->where('external_key', $externalKey)->first();
                if ($existing !== null) {
                    $nextVersion = ((int) ($existing->version ?? 1)) + 1;
                    DB::table($table)->where('id', $existing->id)->update(array_merge($payload, ['version' => $nextVersion]));
                    $updated = (array) DB::table($table)->where('id', $existing->id)->first();
                    $this->versioning->snapshot($entity, (int) $existing->id, $nextVersion, $updated, $request->user()?->id, 'upsert');

                    return $updated;
                }
            }

            if ($this->hasColumn($table, 'version')) {
                $payload['version'] = 1;
            }

            $id = DB::table($table)->insertGetId($payload);
            $created = (array) DB::table($table)->where('id', $id)->first();
            $this->versioning->snapshot($entity, (int) $id, (int) ($created['version'] ?? 1), $created, $request->user()?->id, 'create');

            return $created;
        });

        $this->auditLogger->log($request, 'master_data', 'upsert', 'success', $request->user(), ['entity' => $entity]);

        return response()->json(['data' => $row], 201);
    }

    public function update(Request $request, string $entity, int $id): JsonResponse
    {
        $table = $this->resolveTable($entity);
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Unknown entity.', ApiResponse::requestId($request)), 404);
        }

        $existing = DB::table($table)->where('id', $id)->first();
        if ($existing === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Record not found.', ApiResponse::requestId($request)), 404);
        }

        $ifMatch = (int) $request->header('If-Match', (string) ($existing->version ?? 1));
        $currentVersion = (int) ($existing->version ?? 1);
        if ($this->hasColumn($table, 'version') && $ifMatch !== $currentVersion) {
            return response()->json(ApiResponse::error('VERSION_CONFLICT', 'Version mismatch.', ApiResponse::requestId($request)), 409);
        }

        $payload = $request->except(['id', 'created_at', 'version']);
        $payload['updated_at'] = now()->utc();
        if ($this->hasColumn($table, 'version')) {
            $payload['version'] = $currentVersion + 1;
        }

        DB::table($table)->where('id', $id)->update($payload);
        $updated = (array) DB::table($table)->where('id', $id)->first();
        $this->versioning->snapshot($entity, $id, (int) ($updated['version'] ?? 1), $updated, $request->user()?->id, 'update');

        $this->auditLogger->log($request, 'master_data', 'update', 'success', $request->user(), ['entity' => $entity, 'id' => $id]);

        return response()->json(['data' => $updated]);
    }

    public function versions(Request $request, string $entity, int $id): JsonResponse
    {
        return response()->json([
            'data' => DB::table('master_versions')
                ->where('entity', $entity)
                ->where('entity_id', $id)
                ->orderByDesc('version')
                ->get(),
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function revert(Request $request, string $entity, int $id): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'master.revert')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Only manager/admin can revert.', ApiResponse::requestId($request)), 403);
        }

        $table = $this->resolveTable($entity);
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Unknown entity.', ApiResponse::requestId($request)), 404);
        }

        $targetVersion = (int) $request->integer('version');
        $versionRow = DB::table('master_versions')
            ->where('entity', $entity)
            ->where('entity_id', $id)
            ->where('version', $targetVersion)
            ->first();

        if ($versionRow === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Version not found.', ApiResponse::requestId($request)), 404);
        }

        $snapshot = json_decode((string) $versionRow->snapshot_json, true, 512, JSON_THROW_ON_ERROR);
        unset($snapshot['id']);
        $snapshot['updated_at'] = now()->utc();
        if ($this->hasColumn($table, 'version')) {
            $currentVersion = (int) DB::table($table)->where('id', $id)->value('version');
            $snapshot['version'] = $currentVersion + 1;
        }

        DB::table($table)->where('id', $id)->update($snapshot);
        $updated = (array) DB::table($table)->where('id', $id)->first();

        $this->versioning->snapshot($entity, $id, (int) ($updated['version'] ?? $targetVersion), $updated, $user->id, 'revert');
        $this->auditLogger->log($request, 'master_data', 'revert', 'success', $user, ['entity' => $entity, 'id' => $id, 'version' => $targetVersion]);

        return response()->json(['data' => $updated]);
    }

    private function resolveTable(string $entity): ?string
    {
        return $this->entityTableMap[$entity] ?? null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, DB::getSchemaBuilder()->getColumnListing($table), true);
    }
}
