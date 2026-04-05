<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\EntityVersioningService;
use App\Support\FacilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

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
        'content_items' => 'content_items',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly EntityVersioningService $versioning,
    )
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
        $invalidRows = 0;
        $rowErrors = [];

        foreach ($rows as $index => $rawRow) {
            $rowNumber = $index + 1;
            if (! is_array($rawRow)) {
                $invalidRows++;
                $rowErrors[] = ['row_number' => $rowNumber, 'errors' => ['Row payload must be an object-like structure.']];
                continue;
            }

            $row = $this->normalizeImportRow($rawRow);
            $validator = Validator::make($row, $this->importValidationRules($entity));
            if ($validator->fails()) {
                $invalidRows++;
                $rowErrors[] = ['row_number' => $rowNumber, 'errors' => $validator->errors()->all()];
                continue;
            }

            if (isset($row['facility_id']) && ! FacilityScope::canAccessFacility($request->user(), (int) $row['facility_id'])) {
                $invalidRows++;
                $rowErrors[] = ['row_number' => $rowNumber, 'errors' => ['facility_id is outside the current user facility scope.']];
                continue;
            }

            $externalKey = (string) ($row['external_key'] ?? '');
            $existing = null;
            if ($externalKey !== '' && $this->tableHasColumn($table, 'external_key')) {
                $existing = DB::table($table)->where('external_key', $externalKey)->first();
            }
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
                'invalid_rows' => $invalidRows,
                'row_errors' => $rowErrors,
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
        if (! is_array($rows)) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'rows must be an array.', ApiResponse::requestId($request)), 422);
        }

        $continueOnConflict = (bool) $request->boolean('continue_on_conflict', true);

        // Collect facility IDs for object-level authorization scope
        $facilityIds = collect($rows)
            ->filter(fn ($row) => is_array($row) && isset($row['facility_id']))
            ->pluck('facility_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $jobId = DB::table('import_jobs')->insertGetId([
            'entity' => $entity,
            'status' => 'running',
            'continue_on_conflict' => $continueOnConflict,
            'created_by_user_id' => $request->user()?->id,
            'facility_scope_json' => json_encode($facilityIds, JSON_THROW_ON_ERROR),
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $summary = ['inserted' => 0, 'updated' => 0, 'failed' => 0, 'conflicts' => 0];
        $jobStatus = 'completed';

        try {
            DB::transaction(function () use ($request, $rows, $table, $entity, $jobId, $continueOnConflict, &$summary) {
                foreach ($rows as $index => $rawRow) {
                    $rowNumber = $index + 1;
                    $row = is_array($rawRow) ? $this->normalizeImportRow($rawRow) : [];

                    $rowStatus = 'applied';
                    $result = null;
                    $errors = [];

                    if (! is_array($rawRow)) {
                        $rowStatus = 'failed';
                        $errors[] = 'Row payload must be an object-like structure.';
                    }

                    if ($rowStatus === 'applied') {
                        $validator = Validator::make($row, $this->importValidationRules($entity));
                        if ($validator->fails()) {
                            $rowStatus = 'failed';
                            $errors = $validator->errors()->all();
                        }
                    }

                    if ($rowStatus === 'applied' && isset($row['facility_id']) && ! FacilityScope::canAccessFacility($request->user(), (int) $row['facility_id'])) {
                        $rowStatus = 'failed';
                        $errors[] = 'facility_id is outside the current user facility scope.';
                    }

                    $externalKey = (string) ($row['external_key'] ?? '');
                    $sourceVersion = isset($row['source_version']) ? (int) $row['source_version'] : null;
                    $existing = null;
                    if ($externalKey !== '' && $this->tableHasColumn($table, 'external_key')) {
                        $existing = DB::table($table)->where('external_key', $externalKey)->first();
                    }

                    if ($rowStatus === 'applied' && $existing !== null && $sourceVersion !== null && (int) ($existing->version ?? 1) !== $sourceVersion) {
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
                    } elseif ($rowStatus === 'applied' && $existing !== null) {
                        $previous = (array) $existing;
                        $payload = $this->prepareImportPayload($table, $row);
                        $payload['updated_at'] = now()->utc();
                        if (in_array('version', DB::getSchemaBuilder()->getColumnListing($table), true)) {
                            $payload['version'] = ((int) ($existing->version ?? 1)) + 1;
                        }

                        DB::table($table)->where('id', $existing->id)->update($payload);
                        $updated = (array) DB::table($table)->where('id', $existing->id)->first();
                        if (isset($updated['version'])) {
                            $this->versioning->snapshot($entity, (int) $existing->id, (int) $updated['version'], $updated, $request->user()?->id, 'import_update');
                        }

                        $summary['updated']++;
                        $result = ['id' => $existing->id, 'action' => 'updated', 'previous_snapshot' => $previous];
                    } elseif ($rowStatus === 'applied') {
                        $payload = $this->prepareImportPayload($table, $row);
                        $payload['created_at'] = now()->utc();
                        $payload['updated_at'] = now()->utc();
                        if (in_array('version', DB::getSchemaBuilder()->getColumnListing($table), true)) {
                            $payload['version'] = 1;
                        }

                        $insertedId = DB::table($table)->insertGetId($payload);
                        $inserted = (array) DB::table($table)->where('id', $insertedId)->first();
                        if (isset($inserted['version'])) {
                            $this->versioning->snapshot($entity, (int) $insertedId, (int) $inserted['version'], $inserted, $request->user()?->id, 'import_insert');
                        }

                        $summary['inserted']++;
                        $result = ['id' => $insertedId, 'action' => 'inserted'];
                    }

                    if ($rowStatus === 'failed') {
                        $summary['failed']++;
                    }

                    DB::table('import_rows')->insert([
                        'import_job_id' => $jobId,
                        'row_number' => $rowNumber,
                        'entity' => $entity,
                        'external_key' => $externalKey !== '' ? $externalKey : null,
                        'source_version' => $sourceVersion,
                        'status' => $rowStatus,
                        'payload_json' => json_encode($row, JSON_THROW_ON_ERROR),
                        'errors_json' => $errors !== [] ? json_encode($errors, JSON_THROW_ON_ERROR) : null,
                        'result_json' => $result !== null ? json_encode($result, JSON_THROW_ON_ERROR) : null,
                        'created_at' => now()->utc(),
                        'updated_at' => now()->utc(),
                    ]);
                }
            });
        } catch (\RuntimeException $exception) {
            $jobStatus = 'conflict_blocked';
            $this->auditLogger->log($request, 'imports', 'commit', 'failure', $request->user(), [
                'import_job_id' => $jobId,
                'reason' => $exception->getMessage(),
            ]);
        }

        DB::table('import_jobs')->where('id', $jobId)->update([
            'status' => $jobStatus,
            'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
            'updated_at' => now()->utc(),
        ]);

        if ($jobStatus !== 'completed') {
            return response()->json([
                'import_id' => 'imp_'.$jobId,
                'summary' => $summary,
                'rows' => DB::table('import_rows')->where('import_job_id', $jobId)->get(),
                'request_id' => ApiResponse::requestId($request),
            ], 409);
        }

        $this->auditLogger->log($request, 'imports', 'commit', 'success', $request->user(), [
            'import_job_id' => $jobId,
            'summary' => $summary,
        ]);

        return response()->json([
            'import_id' => 'imp_'.$jobId,
            'summary' => $summary,
            'rows' => DB::table('import_rows')->where('import_job_id', $jobId)->get(),
            'request_id' => ApiResponse::requestId($request),
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

        if ((string) $conflict->status !== 'open') {
            return response()->json(ApiResponse::error('CONFLICT_ALREADY_RESOLVED', 'Conflict is already resolved.', ApiResponse::requestId($request)), 409);
        }

        $job = DB::table('import_jobs')->where('id', (int) $conflict->import_job_id)->first();
        if ($job === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Import job not found.', ApiResponse::requestId($request)), 404);
        }

        $table = $this->entityMap[$conflict->entity] ?? null;
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Conflict entity is not supported.', ApiResponse::requestId($request)), 404);
        }

        $importRow = DB::table('import_rows')
            ->where('import_job_id', (int) $conflict->import_job_id)
            ->where('row_number', (int) $conflict->row_number)
            ->first();

        if ($importRow === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Import row for conflict not found.', ApiResponse::requestId($request)), 404);
        }

        $rowPayload = json_decode((string) $importRow->payload_json, true);
        if (! is_array($rowPayload)) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'Conflict row payload is invalid.', ApiResponse::requestId($request)), 422);
        }

        if (isset($rowPayload['facility_id']) && ! FacilityScope::canAccessFacility($user, (int) $rowPayload['facility_id'])) {
            return FacilityScope::denyResponse($request);
        }

        $externalKey = (string) ($conflict->external_key ?? '');
        $existing = null;
        if ($externalKey !== '' && $this->tableHasColumn($table, 'external_key')) {
            $existing = DB::table($table)->where('external_key', $externalKey)->first();
        }

        if ($existing === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Target record for conflict resolution not found.', ApiResponse::requestId($request)), 404);
        }

        $updatedRecord = null;
        if ($validated['action'] !== 'keep_existing') {
            $updatePayload = $this->prepareImportPayload($table, $rowPayload);
            if ($validated['action'] === 'merge_fields') {
                $mergeFields = collect($validated['merge_fields'] ?? [])->map(fn ($field) => (string) $field)->filter()->values()->all();
                if ($mergeFields === []) {
                    return response()->json(ApiResponse::error('VALIDATION_ERROR', 'merge_fields is required for merge_fields action.', ApiResponse::requestId($request)), 422);
                }

                $existingArray = (array) $existing;
                $mergedPayload = $existingArray;
                foreach ($mergeFields as $field) {
                    if (array_key_exists($field, $updatePayload)) {
                        $mergedPayload[$field] = $updatePayload[$field];
                    }
                }

                $updatePayload = $this->prepareImportPayload($table, $mergedPayload);
            }

            $updatePayload['updated_at'] = now()->utc();
            if ($this->tableHasColumn($table, 'version')) {
                $updatePayload['version'] = ((int) ($existing->version ?? 1)) + 1;
            }

            DB::table($table)->where('id', (int) $existing->id)->update($updatePayload);
            $updatedRecord = (array) DB::table($table)->where('id', (int) $existing->id)->first();
            if (isset($updatedRecord['version'])) {
                $this->versioning->snapshot(
                    $conflict->entity,
                    (int) $existing->id,
                    (int) $updatedRecord['version'],
                    $updatedRecord,
                    $user->id,
                    'import_conflict_resolve'
                );
            }
        }

        DB::table('import_conflicts')->where('id', $conflictId)->update([
            'status' => 'resolved',
            'resolution_action' => $validated['action'],
            'resolution_payload_json' => json_encode($validated['merge_fields'] ?? [], JSON_THROW_ON_ERROR),
            'resolved_by_user_id' => $user->id,
            'resolved_at_utc' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        DB::table('import_rows')
            ->where('id', (int) $importRow->id)
            ->update([
                'status' => 'resolved',
                'result_json' => json_encode([
                    'action' => $validated['action'],
                    'resolved_record_id' => (int) $existing->id,
                ], JSON_THROW_ON_ERROR),
                'updated_at' => now()->utc(),
            ]);

        $summary = json_decode((string) ($job->summary_json ?? '{}'), true);
        if (is_array($summary) && array_key_exists('conflicts', $summary) && (int) $summary['conflicts'] > 0) {
            $summary['conflicts'] = max(((int) $summary['conflicts']) - 1, 0);
            DB::table('import_jobs')->where('id', (int) $job->id)->update([
                'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
                'updated_at' => now()->utc(),
            ]);
        }

        $this->auditLogger->log($request, 'imports', 'resolve_conflict', 'success', $user, [
            'conflict_id' => $conflictId,
            'action' => $validated['action'],
            'entity' => $conflict->entity,
            'external_key' => $externalKey,
        ]);

        return response()->json([
            'data' => DB::table('import_conflicts')->where('id', $conflictId)->first(),
            'resolved_record' => $updatedRecord,
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function getImportReport(Request $request, string $importId): JsonResponse
    {
        $numericId = (int) str_replace('imp_', '', $importId);
        $job = DB::table('import_jobs')->where('id', $numericId)->first();

        if ($job === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Import not found.', ApiResponse::requestId($request)), 404);
        }

        // Object-level authorization: non-admins can only access imports within their facility scope
        if (! FacilityScope::isSystemAdmin($request->user())) {
            $userFacilityIds = FacilityScope::userFacilityIds($request->user());
            $importFacilityIds = [];
            if (! empty($job->facility_scope_json)) {
                $decoded = json_decode((string) $job->facility_scope_json, true);
                if (is_array($decoded)) {
                    $importFacilityIds = array_map('intval', $decoded);
                }
            }

            // If the import has facility scope, user must have access to at least one
            // If import has no facility scope (global), only the creator or admin can see it
            $hasAccess = false;
            if ($importFacilityIds !== []) {
                $hasAccess = collect($importFacilityIds)->contains(fn (int $id) => in_array($id, $userFacilityIds, true));
            }

            // Also allow access if user created the import
            if (! $hasAccess && (int) ($job->created_by_user_id ?? 0) === (int) ($request->user()?->id ?? -1)) {
                $hasAccess = true;
            }

            if (! $hasAccess) {
                return FacilityScope::denyResponse($request);
            }
        }

        return response()->json([
            'data' => $job,
            'rows' => DB::table('import_rows')->where('import_job_id', $job->id)->get(),
            'conflicts' => DB::table('import_conflicts')->where('import_job_id', $job->id)->get(),
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function rollbackImport(Request $request, string $importId): JsonResponse
    {
        $numericId = (int) str_replace('imp_', '', $importId);
        $job = DB::table('import_jobs')->where('id', $numericId)->first();
        if ($job === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Import not found.', ApiResponse::requestId($request)), 404);
        }

        $table = $this->entityMap[$job->entity] ?? null;
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Import entity not supported for rollback.', ApiResponse::requestId($request)), 404);
        }

        $rolledBack = ['inserted_removed' => 0, 'updated_restored' => 0];

        DB::transaction(function () use ($numericId, $table, &$rolledBack) {
            $rows = DB::table('import_rows')
                ->where('import_job_id', $numericId)
                ->where('status', 'applied')
                ->orderByDesc('row_number')
                ->get();

            foreach ($rows as $row) {
                $result = json_decode((string) $row->result_json, true);
                if (! is_array($result) || ! isset($result['action'], $result['id'])) {
                    continue;
                }

                $recordId = (int) $result['id'];
                if ($result['action'] === 'inserted') {
                    DB::table($table)->where('id', $recordId)->delete();
                    $rolledBack['inserted_removed']++;
                    continue;
                }

                if ($result['action'] === 'updated' && isset($result['previous_snapshot']) && is_array($result['previous_snapshot'])) {
                    $snapshot = $result['previous_snapshot'];
                    unset($snapshot['id']);
                    $snapshot['updated_at'] = now()->utc();
                    DB::table($table)->where('id', $recordId)->update($snapshot);
                    $rolledBack['updated_restored']++;
                }
            }
        });

        DB::table('import_jobs')->where('id', $numericId)->update([
            'status' => 'rolled_back',
            'summary_json' => json_encode($rolledBack, JSON_THROW_ON_ERROR),
            'updated_at' => now()->utc(),
        ]);

        $this->auditLogger->log($request, 'imports', 'rollback', 'success', $request->user(), [
            'import_job_id' => $numericId,
            'rolled_back' => $rolledBack,
        ]);

        return response()->json(['data' => ['status' => 'rolled_back', 'details' => $rolledBack]]);
    }

    public function exportEntity(Request $request, string $entity): JsonResponse
    {
        $table = $this->entityMap[$entity] ?? null;
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Unknown export entity.', ApiResponse::requestId($request)), 404);
        }

        $query = DB::table($table);
        if ($this->tableHasColumn($table, 'facility_id')) {
            $query = FacilityScope::applyToQuery($query, $request->user());
        }

        $rows = $query->get();
        $this->auditLogger->log($request, 'exports', 'csv_export', 'success', $request->user(), ['entity' => $entity, 'count' => $rows->count()]);

        return response()->json(['data' => $rows, 'request_id' => ApiResponse::requestId($request)]);
    }

    public function dedupScan(Request $request): JsonResponse
    {
        $entity = (string) $request->input('entity', 'providers');
        $table = $this->entityMap[$entity] ?? null;
        if ($table === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Unknown dedup entity.', ApiResponse::requestId($request)), 404);
        }

        $query = DB::table($table);
        if ($this->tableHasColumn($table, 'facility_id')) {
            $query = FacilityScope::applyToQuery($query, $request->user());
        }

        $records = $query->get();
        $method = $entity === 'content_items' ? 'simhash_minhash' : 'key-field-match';

        $groupId = DB::table('dedup_candidate_groups')->insertGetId([
            'entity' => $entity,
            'method' => $method,
            'status' => 'open',
            'metadata' => json_encode(['record_count' => $records->count()], JSON_THROW_ON_ERROR),
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        if ($entity === 'content_items') {
            $candidates = [];
            foreach ($records as $record) {
                $normalized = $this->normalizeText((string) ($record->title ?? '').' '.(string) ($record->body ?? ''));
                if ($normalized === '') {
                    continue;
                }

                $candidates[] = [
                    'record' => $record,
                    'normalized' => $normalized,
                    'simhash' => $this->simhash($normalized),
                    'minhash' => $this->minhash($normalized),
                ];
            }

            $insertedRecordIds = [];
            $count = count($candidates);
            for ($left = 0; $left < $count; $left++) {
                for ($right = $left + 1; $right < $count; $right++) {
                    $leftCandidate = $candidates[$left];
                    $rightCandidate = $candidates[$right];

                    $hamming = $this->hammingDistance($leftCandidate['simhash'], $rightCandidate['simhash']);
                    $minhashScore = $this->minhashSimilarity($leftCandidate['minhash'], $rightCandidate['minhash']);
                    $nearDuplicate = $hamming <= 8 || $minhashScore >= 0.85;
                    if (! $nearDuplicate) {
                        continue;
                    }

                    $score = max(1 - ($hamming / 64), $minhashScore);
                    foreach ([$leftCandidate, $rightCandidate] as $candidate) {
                        $recordId = (int) $candidate['record']->id;
                        if (isset($insertedRecordIds[$recordId])) {
                            continue;
                        }

                        DB::table('dedup_candidates')->insert([
                            'candidate_group_id' => $groupId,
                            'record_id' => $recordId,
                            'score' => round($score, 3),
                            'fingerprint' => json_encode([
                                'method' => 'simhash_minhash',
                                'simhash' => $candidate['simhash'],
                                'minhash' => $candidate['minhash'],
                                'normalized' => $candidate['normalized'],
                            ], JSON_THROW_ON_ERROR),
                            'created_at' => now()->utc(),
                            'updated_at' => now()->utc(),
                        ]);
                        $insertedRecordIds[$recordId] = true;
                    }
                }
            }
        } else {
            $seen = [];
            foreach ($records as $record) {
                $key = $this->normalizedKeyField($record);
                if ($key === '') {
                    continue;
                }
                if (! isset($seen[$key])) {
                    $seen[$key] = [];
                }
                $seen[$key][] = $record;
            }

            foreach ($seen as $normalizedKey => $bucket) {
                if (count($bucket) < 2) {
                    continue;
                }
                foreach ($bucket as $candidate) {
                    DB::table('dedup_candidates')->insert([
                        'candidate_group_id' => $groupId,
                        'record_id' => $candidate->id,
                        'score' => 1.000,
                        'fingerprint' => json_encode(['normalized_key' => $normalizedKey], JSON_THROW_ON_ERROR),
                        'created_at' => now()->utc(),
                        'updated_at' => now()->utc(),
                    ]);
                }
            }
        }

        $this->auditLogger->log($request, 'dedup', 'scan', 'success', $request->user(), [
            'entity' => $entity,
            'candidate_group_id' => $groupId,
        ]);

        return response()->json([
            'data' => [
                'candidate_group_id' => $groupId,
                'candidates' => DB::table('dedup_candidates')->where('candidate_group_id', $groupId)->get(),
            ],
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function dedupMerge(Request $request, int $groupId): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'dedup.merge')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Manager/admin required.', ApiResponse::requestId($request)), 403);
        }

        $group = DB::table('dedup_candidate_groups')->where('id', $groupId)->first();
        if ($group === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Dedup group not found.', ApiResponse::requestId($request)), 404);
        }

        if ((string) $group->status === 'merged') {
            return response()->json(ApiResponse::error('ALREADY_MERGED', 'This dedup group has already been merged.', ApiResponse::requestId($request)), 409);
        }

        $validated = Validator::make($request->all(), [
            'canonical_record_id' => ['required', 'integer'],
            'merge_record_ids' => ['required', 'array', 'min:1'],
            'merge_record_ids.*' => ['integer'],
            'field_resolution' => ['nullable', 'array'],
            'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        $entity = (string) $group->entity;
        $table = $this->entityMap[$entity] ?? null;
        if ($table === null) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'Unsupported entity for merge.', ApiResponse::requestId($request)), 422);
        }

        $candidateRecordIds = DB::table('dedup_candidates')
            ->where('candidate_group_id', $groupId)
            ->pluck('record_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $canonicalId = (int) $validated['canonical_record_id'];
        $mergeIds = collect($validated['merge_record_ids'])->map(fn ($id) => (int) $id)->all();

        if (! in_array($canonicalId, $candidateRecordIds, true)) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'canonical_record_id must belong to the dedup group.', ApiResponse::requestId($request)), 422);
        }

        foreach ($mergeIds as $mergeId) {
            if (! in_array($mergeId, $candidateRecordIds, true)) {
                return response()->json(ApiResponse::error('VALIDATION_ERROR', 'All merge_record_ids must belong to the dedup group.', ApiResponse::requestId($request)), 422);
            }
        }

        if (in_array($canonicalId, $mergeIds, true)) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'canonical_record_id must not appear in merge_record_ids.', ApiResponse::requestId($request)), 422);
        }

        $canonicalRecord = DB::table($table)->where('id', $canonicalId)->first();
        if ($canonicalRecord === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Canonical record not found.', ApiResponse::requestId($request)), 404);
        }

        $fieldResolution = $validated['field_resolution'] ?? [];

        DB::transaction(function () use ($table, $entity, $canonicalId, $canonicalRecord, $mergeIds, $fieldResolution, $validated, $groupId, $user) {
            $canonicalBefore = (array) $canonicalRecord;

            // Apply field_resolution to canonical record
            if ($fieldResolution !== []) {
                $updatePayload = [];
                foreach ($fieldResolution as $field => $value) {
                    if ($field !== 'id' && in_array($field, DB::getSchemaBuilder()->getColumnListing($table), true)) {
                        $updatePayload[$field] = $value;
                    }
                }
                if ($updatePayload !== []) {
                    $updatePayload['updated_at'] = now()->utc();
                    DB::table($table)->where('id', $canonicalId)->update($updatePayload);
                }
            }

            $canonicalAfter = (array) DB::table($table)->where('id', $canonicalId)->first();

            // Create merge event
            $mergeEventId = DB::table('dedup_merge_events')->insertGetId([
                'candidate_group_id' => $groupId,
                'entity' => $entity,
                'canonical_record_id' => $canonicalId,
                'actor_user_id' => $user->id,
                'reason' => $validated['reason'],
                'field_resolution_json' => json_encode($fieldResolution, JSON_THROW_ON_ERROR),
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            // Provenance for canonical
            DB::table('dedup_merge_event_items')->insert([
                'merge_event_id' => $mergeEventId,
                'source_record_id' => $canonicalId,
                'target_record_id' => $canonicalId,
                'before_snapshot_json' => json_encode($canonicalBefore, JSON_THROW_ON_ERROR),
                'after_snapshot_json' => json_encode($canonicalAfter, JSON_THROW_ON_ERROR),
                'action' => 'canonical_updated',
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

            // Process merged records: archive/supersede them
            foreach ($mergeIds as $mergeId) {
                $mergeRecord = DB::table($table)->where('id', $mergeId)->first();
                if ($mergeRecord === null) {
                    continue;
                }

                $mergeBefore = (array) $mergeRecord;

                // Mark merged records as superseded if they have a status column
                if (in_array('status', DB::getSchemaBuilder()->getColumnListing($table), true)) {
                    DB::table($table)->where('id', $mergeId)->update([
                        'status' => 'merged',
                        'updated_at' => now()->utc(),
                    ]);
                }

                $mergeAfter = (array) DB::table($table)->where('id', $mergeId)->first();

                DB::table('dedup_merge_event_items')->insert([
                    'merge_event_id' => $mergeEventId,
                    'source_record_id' => $mergeId,
                    'target_record_id' => $canonicalId,
                    'before_snapshot_json' => json_encode($mergeBefore, JSON_THROW_ON_ERROR),
                    'after_snapshot_json' => json_encode($mergeAfter, JSON_THROW_ON_ERROR),
                    'action' => 'merged_into_canonical',
                    'created_at' => now()->utc(),
                    'updated_at' => now()->utc(),
                ]);
            }

            // Update group status
            DB::table('dedup_candidate_groups')->where('id', $groupId)->update([
                'status' => 'merged',
                'updated_at' => now()->utc(),
            ]);
        });

        $this->auditLogger->log($request, 'dedup', 'merge', 'success', $user, [
            'candidate_group_id' => $groupId,
            'canonical_record_id' => $canonicalId,
            'merge_record_ids' => $mergeIds,
        ]);

        return response()->json([
            'data' => [
                'status' => 'merged',
                'candidate_group_id' => $groupId,
                'canonical_record_id' => $canonicalId,
                'merge_record_ids' => $mergeIds,
                'merge_event' => DB::table('dedup_merge_events')->where('candidate_group_id', $groupId)->latest('id')->first(),
                'provenance' => DB::table('dedup_merge_event_items')
                    ->whereIn('merge_event_id', DB::table('dedup_merge_events')->where('candidate_group_id', $groupId)->pluck('id'))
                    ->get(),
            ],
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function importValidationRules(string $entity): array
    {
        return match ($entity) {
            'facilities' => [
                'external_key' => ['required', 'string', 'max:120'],
                'name' => ['required', 'string', 'max:120'],
                'code' => ['required', 'string', 'max:32'],
                'source_version' => ['nullable', 'integer', 'min:1'],
                'reference_url' => ['nullable', 'string', 'max:1024'],
            ],
            'departments' => [
                'external_key' => ['required', 'string', 'max:120'],
                'facility_id' => ['required', 'integer', 'exists:facilities,id'],
                'name' => ['required', 'string', 'max:120'],
                'status' => ['nullable', 'string', 'in:active,inactive'],
                'source_version' => ['nullable', 'integer', 'min:1'],
                'reference_url' => ['nullable', 'string', 'max:1024'],
            ],
            'providers' => [
                'external_key' => ['required', 'string', 'max:120'],
                'facility_id' => ['required', 'integer', 'exists:facilities,id'],
                'department_id' => ['nullable', 'integer', 'exists:departments,id'],
                'name' => ['required', 'string', 'max:120'],
                'status' => ['nullable', 'string', 'in:active,inactive'],
                'source_version' => ['nullable', 'integer', 'min:1'],
                'reference_url' => ['nullable', 'string', 'max:1024'],
            ],
            'services' => [
                'external_key' => ['required', 'string', 'max:120'],
                'facility_id' => ['required', 'integer', 'exists:facilities,id'],
                'name' => ['required', 'string', 'max:120'],
                'reservation_strategy' => ['nullable', 'string', 'in:reserve_on_order_create,deduct_on_order_close'],
                'status' => ['nullable', 'string', 'in:active,inactive'],
                'source_version' => ['nullable', 'integer', 'min:1'],
                'reference_url' => ['nullable', 'string', 'max:1024'],
            ],
            'inventory_items' => [
                'external_key' => ['required', 'string', 'max:120'],
                'sku' => ['required', 'string', 'max:120'],
                'name' => ['required', 'string', 'max:255'],
                'uom' => ['nullable', 'string', 'max:30'],
                'safety_stock_days' => ['nullable', 'numeric', 'min:0'],
                'source_version' => ['nullable', 'integer', 'min:1'],
                'reference_url' => ['nullable', 'string', 'max:1024'],
            ],
            'content_items' => [
                'external_key' => ['required', 'string', 'max:120'],
                'content_type' => ['required', 'string', 'in:announcement,homepage_carousel'],
                'title' => ['required', 'string', 'max:255'],
                'body' => ['nullable', 'string'],
                'source_version' => ['nullable', 'integer', 'min:1'],
                'reference_url' => ['nullable', 'string', 'max:1024'],
            ],
            default => [
                'external_key' => ['required', 'string', 'max:120'],
                'source_version' => ['nullable', 'integer', 'min:1'],
                'reference_url' => ['nullable', 'string', 'max:1024'],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeImportRow(array $row): array
    {
        $normalized = $row;
        if (isset($normalized['reference_url']) && is_string($normalized['reference_url'])) {
            $normalized['reference_url'] = $this->normalizeUrl($normalized['reference_url']);
        }

        if (isset($normalized['name']) && is_string($normalized['name'])) {
            $normalized['name'] = trim($normalized['name']);
        }
        if (isset($normalized['title']) && is_string($normalized['title'])) {
            $normalized['title'] = trim($normalized['title']);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function prepareImportPayload(string $table, array $row): array
    {
        $writableColumns = collect(DB::getSchemaBuilder()->getColumnListing($table))
            ->reject(fn (string $column): bool => in_array($column, ['id', 'created_at', 'updated_at'], true))
            ->values()
            ->all();

        $payload = collect($row)
            ->except(['source_version'])
            ->filter(fn ($_value, string $key): bool => in_array($key, $writableColumns, true))
            ->all();

        return is_array($payload) ? $payload : [];
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        return in_array($column, DB::getSchemaBuilder()->getColumnListing($table), true);
    }

    /**
     * @param  object  $record
     */
    private function normalizedKeyField(object $record): string
    {
        $name = mb_strtolower(trim((string) ($record->name ?? $record->title ?? '')));
        $referenceUrl = isset($record->reference_url)
            ? $this->normalizeUrl((string) $record->reference_url)
            : '';

        return trim($name.' '.$referenceUrl);
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = isset($parts['scheme']) ? mb_strtolower((string) $parts['scheme']) : 'http';
        $host = mb_strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = isset($parts['path']) ? '/'.trim((string) $parts['path'], '/') : '';
        $path = $path === '/' ? '' : $path;

        $queryString = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str((string) $parts['query'], $queryValues);
            $queryValues = collect($queryValues)
                ->reject(fn ($_value, string $key): bool => str_starts_with(mb_strtolower($key), 'utm_'))
                ->sortKeys()
                ->all();
            $queryString = http_build_query($queryValues);
        }

        $defaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $portPart = ($port !== null && ! $defaultPort) ? ':'.$port : '';

        return sprintf('%s://%s%s%s%s', $scheme, $host, $portPart, $path, $queryString !== '' ? '?'.$queryString : '');
    }

    private function normalizeText(string $text): string
    {
        $collapsed = preg_replace('/\s+/', ' ', mb_strtolower(trim($text))) ?? '';

        return trim($collapsed);
    }

    private function simhash(string $text): string
    {
        $tokens = preg_split('/\W+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $vector = array_fill(0, 64, 0);

        foreach ($tokens as $token) {
            $hash = hash('sha256', $token);
            $binary = base_convert(substr($hash, 0, 16), 16, 2);
            $binary = str_pad($binary, 64, '0', STR_PAD_LEFT);
            for ($i = 0; $i < 64; $i++) {
                $vector[$i] += $binary[$i] === '1' ? 1 : -1;
            }
        }

        $result = '';
        for ($i = 0; $i < 64; $i++) {
            $result .= $vector[$i] >= 0 ? '1' : '0';
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function minhash(string $text): array
    {
        $tokens = preg_split('/\W+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return [];
        }

        $seeds = [17, 29, 37, 43, 53, 61, 71, 83];
        $signature = [];
        foreach ($seeds as $seed) {
            $minValue = null;
            foreach ($tokens as $token) {
                $value = hash('sha256', $seed.'|'.$token);
                if ($minValue === null || strcmp($value, $minValue) < 0) {
                    $minValue = $value;
                }
            }
            $signature[] = (string) $minValue;
        }

        return $signature;
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     */
    private function minhashSimilarity(array $left, array $right): float
    {
        $len = min(count($left), count($right));
        if ($len === 0) {
            return 0.0;
        }

        $matches = 0;
        for ($i = 0; $i < $len; $i++) {
            if ($left[$i] === $right[$i]) {
                $matches++;
            }
        }

        return $matches / $len;
    }

    private function hammingDistance(string $left, string $right): int
    {
        $len = min(mb_strlen($left), mb_strlen($right));
        $distance = abs(mb_strlen($left) - mb_strlen($right));
        for ($i = 0; $i < $len; $i++) {
            if ($left[$i] !== $right[$i]) {
                $distance++;
            }
        }

        return $distance;
    }
}
