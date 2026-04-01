<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ImportDedupController extends Controller
{
    /**
     * @var array<string, string>
     */
    private array $entityMap = [
        'facilities' => 'facilities',
        'departments' => 'departments',
        'providers' => 'providers',
        'services' => 'services',
        'inventory_items' => 'inventory_items',
    ];

    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function validateImport(Request $request, string $entity): JsonResponse
    {
        $table = $this->entityMap[$entity] ?? null;
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Unknown import entity.', ApiResponse::requestId($request)), 404);
        }

        $rows = $request->input('rows', []);
        if (! is_array($rows)) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'rows must be an array.', ApiResponse::requestId($request)), 422);
        }

        $conflicts = 0;
        $wouldInsert = 0;
        $wouldUpdate = 0;
        foreach ($rows as $row) {
            $externalKey = (string) ($row['external_key'] ?? '');
            $existing = $externalKey !== '' ? DB::table($table)->where('external_key', $externalKey)->first() : null;
            if ($existing === null) {
                $wouldInsert++;
                continue;
            }

            $wouldUpdate++;
            $sourceVersion = isset($row['source_version']) ? (int) $row['source_version'] : null;
            if ($sourceVersion !== null && (int) ($existing->version ?? 1) !== $sourceVersion) {
                $conflicts++;
            }
        }

        return response()->json([
            'data' => [
                'would_insert' => $wouldInsert,
                'would_update' => $wouldUpdate,
                'conflicts' => $conflicts,
            ],
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function commitImport(Request $request, string $entity): JsonResponse
    {
        $table = $this->entityMap[$entity] ?? null;
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Unknown import entity.', ApiResponse::requestId($request)), 404);
        }

        $rows = $request->input('rows', []);
        $continueOnConflict = (bool) $request->boolean('continue_on_conflict', true);

        $jobId = DB::table('import_jobs')->insertGetId([
            'entity' => $entity,
            'status' => 'running',
            'continue_on_conflict' => $continueOnConflict,
            'created_by_user_id' => $request->user()?->id,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $summary = ['inserted' => 0, 'updated' => 0, 'failed' => 0, 'conflicts' => 0];

        DB::transaction(function () use ($rows, $table, $entity, $jobId, $continueOnConflict, &$summary) {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 1;
                $externalKey = (string) ($row['external_key'] ?? '');
                $sourceVersion = isset($row['source_version']) ? (int) $row['source_version'] : null;
                $existing = $externalKey !== '' ? DB::table($table)->where('external_key', $externalKey)->first() : null;

                $rowStatus = 'applied';
                $result = null;
                $errors = null;

                if ($existing !== null && $sourceVersion !== null && (int) ($existing->version ?? 1) !== $sourceVersion) {
                    $summary['conflicts']++;
                    $rowStatus = 'conflict';
                    DB::table('import_conflicts')->insert([
                        'import_job_id' => $jobId,
                        'entity' => $entity,
                        'external_key' => $externalKey,
                        'row_number' => $rowNumber,
                        'db_version' => (int) ($existing->version ?? 1),
                        'source_version' => $sourceVersion,
                        'status' => 'open',
                        'created_at' => now()->utc(),
                        'updated_at' => now()->utc(),
                    ]);

                    if (! $continueOnConflict) {
                        throw new \RuntimeException('Import aborted by conflict policy.');
                    }
                } elseif ($existing !== null) {
                    $payload = $row;
                    unset($payload['source_version']);
                    $payload['updated_at'] = now()->utc();
                    $payload['version'] = ((int) ($existing->version ?? 1)) + 1;

                    DB::table($table)->where('id', $existing->id)->update($payload);
                    $summary['updated']++;
                    $result = ['id' => $existing->id, 'action' => 'updated'];
                } else {
                    $payload = $row;
                    unset($payload['source_version']);
                    $payload['created_at'] = now()->utc();
                    $payload['updated_at'] = now()->utc();
                    if (in_array('version', DB::getSchemaBuilder()->getColumnListing($table), true)) {
                        $payload['version'] = 1;
                    }

                    $insertedId = DB::table($table)->insertGetId($payload);
                    $summary['inserted']++;
                    $result = ['id' => $insertedId, 'action' => 'inserted'];
                }

                DB::table('import_rows')->insert([
                    'import_job_id' => $jobId,
                    'row_number' => $rowNumber,
                    'entity' => $entity,
                    'external_key' => $externalKey !== '' ? $externalKey : null,
                    'source_version' => $sourceVersion,
                    'status' => $rowStatus,
                    'payload_json' => json_encode($row, JSON_THROW_ON_ERROR),
                    'errors_json' => $errors !== null ? json_encode($errors, JSON_THROW_ON_ERROR) : null,
                    'result_json' => $result !== null ? json_encode($result, JSON_THROW_ON_ERROR) : null,
                    'created_at' => now()->utc(),
                    'updated_at' => now()->utc(),
                ]);
            }
        });

        DB::table('import_jobs')->where('id', $jobId)->update([
            'status' => 'completed',
            'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
            'updated_at' => now()->utc(),
        ]);

        return response()->json([
            'import_id' => 'imp_'.$jobId,
            'summary' => $summary,
            'rows' => DB::table('import_rows')->where('import_job_id', $jobId)->get(),
        ], 201);
    }

    public function resolveConflict(Request $request, int $conflictId): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'imports.conflict.resolve')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Manager/admin required.', ApiResponse::requestId($request)), 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'in:overwrite_with_import,keep_existing,merge_fields'],
            'merge_fields' => ['nullable', 'array'],
        ]);

        $conflict = DB::table('import_conflicts')->where('id', $conflictId)->first();
        if ($conflict === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Conflict not found.', ApiResponse::requestId($request)), 404);
        }

        DB::table('import_conflicts')->where('id', $conflictId)->update([
            'status' => 'resolved',
            'resolution_action' => $validated['action'],
            'resolution_payload_json' => json_encode($validated['merge_fields'] ?? [], JSON_THROW_ON_ERROR),
            'resolved_by_user_id' => $user->id,
            'resolved_at_utc' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        return response()->json(['data' => DB::table('import_conflicts')->where('id', $conflictId)->first()]);
    }

    public function getImportReport(Request $request, string $importId): JsonResponse
    {
        $numericId = (int) str_replace('imp_', '', $importId);
        $job = DB::table('import_jobs')->where('id', $numericId)->first();

        if ($job === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Import not found.', ApiResponse::requestId($request)), 404);
        }

        return response()->json([
            'data' => $job,
            'rows' => DB::table('import_rows')->where('import_job_id', $job->id)->get(),
            'conflicts' => DB::table('import_conflicts')->where('import_job_id', $job->id)->get(),
        ]);
    }

    public function rollbackImport(Request $request, string $importId): JsonResponse
    {
        $numericId = (int) str_replace('imp_', '', $importId);
        $job = DB::table('import_jobs')->where('id', $numericId)->first();
        if ($job === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Import not found.', ApiResponse::requestId($request)), 404);
        }

        DB::table('import_jobs')->where('id', $numericId)->update([
            'status' => 'rolled_back',
            'updated_at' => now()->utc(),
        ]);

        $this->auditLogger->log($request, 'imports', 'rollback', 'success', $request->user(), ['import_job_id' => $numericId]);

        return response()->json(['data' => ['status' => 'rolled_back']]);
    }

    public function exportEntity(Request $request, string $entity): JsonResponse
    {
        $table = $this->entityMap[$entity] ?? null;
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Unknown export entity.', ApiResponse::requestId($request)), 404);
        }

        $rows = DB::table($table)->get();
        $this->auditLogger->log($request, 'exports', 'csv_export', 'success', $request->user(), ['entity' => $entity, 'count' => $rows->count()]);

        return response()->json(['data' => $rows]);
    }

    public function dedupScan(Request $request): JsonResponse
    {
        $entity = (string) $request->input('entity', 'providers');
        $table = $this->entityMap[$entity] ?? 'providers';
        $records = DB::table($table)->get();

        $groupId = DB::table('dedup_candidate_groups')->insertGetId([
            'entity' => $entity,
            'method' => 'key-field-match',
            'status' => 'open',
            'metadata' => json_encode(['record_count' => $records->count()], JSON_THROW_ON_ERROR),
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $seen = [];
        foreach ($records as $record) {
            $key = mb_strtolower((string) ($record->name ?? $record->title ?? ''));
            if ($key === '') {
                continue;
            }
            if (! isset($seen[$key])) {
                $seen[$key] = [];
            }
            $seen[$key][] = $record;
        }

        foreach ($seen as $bucket) {
            if (count($bucket) < 2) {
                continue;
            }
            foreach ($bucket as $candidate) {
                DB::table('dedup_candidates')->insert([
                    'candidate_group_id' => $groupId,
                    'record_id' => $candidate->id,
                    'score' => 1.000,
                    'fingerprint' => json_encode(['normalized_key' => 'exact_name'], JSON_THROW_ON_ERROR),
                    'created_at' => now()->utc(),
                    'updated_at' => now()->utc(),
                ]);
            }
        }

        return response()->json([
            'data' => [
                'candidate_group_id' => $groupId,
                'candidates' => DB::table('dedup_candidates')->where('candidate_group_id', $groupId)->get(),
            ],
        ]);
    }

    public function dedupMerge(Request $request, int $groupId): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'dedup.merge')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Manager/admin required.', ApiResponse::requestId($request)), 403);
        }

        DB::table('dedup_candidate_groups')->where('id', $groupId)->update([
            'status' => 'merged',
            'updated_at' => now()->utc(),
        ]);

        $this->auditLogger->log($request, 'dedup', 'merge', 'success', $user, ['candidate_group_id' => $groupId]);

        return response()->json(['data' => ['status' => 'merged', 'candidate_group_id' => $groupId]]);
    }
}
