<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use App\Support\FacilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AnalyticsAuditController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function reviewSummary(Request $request): JsonResponse
    {
        $query = DB::table('reviews');
        if ($request->filled('facility_id')) {
            $facilityId = (int) $request->query('facility_id');
            if (! FacilityScope::canAccessFacility($request->user(), $facilityId)) {
                return FacilityScope::denyResponse($request);
            }

            $query->where('facility_id', $facilityId);
        }
        if (! FacilityScope::isSystemAdmin($request->user()) && ! $request->filled('facility_id')) {
            $query->whereIn('facility_id', FacilityScope::userFacilityIds($request->user()));
        }
        if ($request->filled('provider_id')) {
            $query->where('provider_id', (int) $request->query('provider_id'));
        }

        $reviews = $query->get();
        $average = round((float) ($reviews->avg('rating') ?? 0), 3);
        $negativeRate = $reviews->count() > 0
            ? round(($reviews->where('rating', '<=', 2)->count() / $reviews->count()) * 100, 3)
            : 0.0;

        $driver = DB::connection()->getDriverName();
        $minutesExpr = match ($driver) {
            'sqlite' => "(cast(strftime('%s', review_responses.created_at_utc) as integer) - cast(strftime('%s', reviews.created_at) as integer)) / 60",
            'pgsql' => 'EXTRACT(EPOCH FROM (review_responses.created_at_utc - reviews.created_at)) / 60',
            default => 'TIMESTAMPDIFF(MINUTE, reviews.created_at, review_responses.created_at_utc)',
        };

        $responseTimes = DB::table('review_responses')
            ->join('reviews', 'reviews.id', '=', 'review_responses.review_id')
            ->when($request->filled('facility_id'), fn ($q) => $q->where('reviews.facility_id', (int) $request->query('facility_id')))
            ->when(! FacilityScope::isSystemAdmin($request->user()) && ! $request->filled('facility_id'), fn ($q) => $q->whereIn('reviews.facility_id', FacilityScope::userFacilityIds($request->user())))
            ->when($request->filled('provider_id'), fn ($q) => $q->where('reviews.provider_id', (int) $request->query('provider_id')))
            ->selectRaw("{$minutesExpr} as response_mins")
            ->pluck('response_mins')
            ->filter()
            ->sort()
            ->values();

        $median = 0;
        if ($responseTimes->count() > 0) {
            $middle = intdiv($responseTimes->count(), 2);
            $median = (int) $responseTimes[$middle];
        }

        $breakdown = $reviews
            ->groupBy(fn ($review) => sprintf('%s|%s', $review->facility_id ?? 'none', $review->provider_id ?? 'none'))
            ->map(function ($group) {
                $count = $group->count();
                $negative = $count > 0 ? ($group->where('rating', '<=', 2)->count() / $count) * 100 : 0;

                return [
                    'facility_id' => $group->first()->facility_id,
                    'provider_id' => $group->first()->provider_id,
                    'average_score' => round((float) ($group->avg('rating') ?? 0), 3),
                    'negative_review_rate' => round((float) $negative, 3),
                    'reviews_count' => $count,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'average_score' => $average,
                'negative_review_rate' => $negativeRate,
                'median_response_time_minutes' => $median,
                'breakdown' => $breakdown,
            ],
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $inventoryResponse = app(InventoryController::class)->items($request);
        if ($inventoryResponse->getStatusCode() !== 200) {
            return $inventoryResponse;
        }

        $items = $inventoryResponse->getData(true);

        return response()->json([
            'data' => collect($items['data'] ?? [])->filter(fn ($row) => ($row['low_stock'] ?? false) === true)->values()->all(),
            'request_id' => ApiResponse::requestId($request),
        ]);
    }

    public function overdueRentals(Request $request): JsonResponse
    {
        $rows = DB::table('rental_checkouts')
            ->join('rental_assets', 'rental_assets.id', '=', 'rental_checkouts.asset_id')
            ->whereNull('returned_at')
            ->where('expected_return_at', '<', now()->utc()->subHours(2))
            ->when(! FacilityScope::isSystemAdmin($request->user()), fn ($q) => $q->whereIn('rental_assets.facility_id', FacilityScope::userFacilityIds($request->user())))
            ->select('rental_checkouts.*')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $query = DB::table('audit_events');
        if (! FacilityScope::isSystemAdmin($request->user())) {
            $query->whereIn('facility_id', FacilityScope::userFacilityIds($request->user()));
        }

        if ($request->filled('facility_id')) {
            $requestedFacilityId = (int) $request->query('facility_id');
            if (! FacilityScope::canAccessFacility($request->user(), $requestedFacilityId)) {
                return FacilityScope::denyResponse($request);
            }

            $query->where('facility_id', $requestedFacilityId);
        }

        foreach (['actor_user_id', 'action', 'event_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return response()->json(['data' => $query->orderByDesc('id')->get()]);
    }

    public function auditLogById(Request $request, int $id): JsonResponse
    {
        $row = DB::table('audit_events')->where('id', $id)->first();
        if ($row === null) {
            return response()->json(ApiResponse::error('NOT_FOUND', 'Audit event not found.', ApiResponse::requestId($request)), 404);
        }

        if (! FacilityScope::isSystemAdmin($request->user())) {
            // Non-admin cannot access audit events with null facility_id (system-wide events)
            if ($row->facility_id === null) {
                return FacilityScope::denyResponse($request);
            }
            if (! FacilityScope::canAccessFacility($request->user(), (int) $row->facility_id)) {
                return FacilityScope::denyResponse($request);
            }
        }

        return response()->json(['data' => $row, 'request_id' => ApiResponse::requestId($request)]);
    }

    public function auditPartitions(Request $request): JsonResponse
    {
        $query = DB::table('audit_event_partitions')->orderByDesc('month_utc');
        if (! FacilityScope::isSystemAdmin($request->user())) {
            $query->whereIn('facility_id', FacilityScope::userFacilityIds($request->user()));
        }

        return response()->json(['data' => $query->get(), 'request_id' => ApiResponse::requestId($request)]);
    }

    public function auditArchive(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'audit.archive')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Admin role required.', ApiResponse::requestId($request)), 403);
        }

        $beforeMonth = (string) $request->input('before_month');
        if (! preg_match('/^\d{4}-\d{2}$/', $beforeMonth)) {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'before_month must use YYYY-MM format.', ApiResponse::requestId($request)), 422);
        }

        DB::table('audit_event_partitions')
            ->where('month_utc', '<', $beforeMonth)
            ->update([
                'storage_tier' => 'archive',
                'archived_at_utc' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);

        $this->auditLogger->log($request, 'audit', 'archive', 'success', $user, ['before_month' => $beforeMonth]);

        return response()->json(['data' => ['status' => 'archived']]);
    }

    public function auditReindex(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'audit.reindex')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Admin role required.', ApiResponse::requestId($request)), 403);
        }

        $partitionKey = (string) $request->input('partition_key');
        if ($partitionKey === '') {
            return response()->json(ApiResponse::error('VALIDATION_ERROR', 'partition_key is required.', ApiResponse::requestId($request)), 422);
        }

        DB::table('audit_event_partitions')->where('partition_key', $partitionKey)->update([
            'updated_at' => now()->utc(),
        ]);

        return response()->json(['data' => ['status' => 'reindexed', 'partition_key' => $partitionKey]]);
    }
}
