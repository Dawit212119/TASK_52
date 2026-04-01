<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
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
            $query->where('facility_id', (int) $request->query('facility_id'));
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

        return response()->json([
            'data' => [
                'average_score' => $average,
                'negative_review_rate' => $negativeRate,
                'median_response_time_minutes' => $median,
            ],
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $items = app(InventoryController::class)->items($request)->getData(true);
        return response()->json(['data' => collect($items['data'])->filter(fn ($row) => $row['low_stock'])->values()->all()]);
    }

    public function overdueRentals(): JsonResponse
    {
        $rows = DB::table('rental_checkouts')
            ->whereNull('returned_at')
            ->where('expected_return_at', '<', now()->utc()->subHours(2))
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $query = DB::table('audit_events');
        foreach (['actor_user_id', 'action', 'event_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return response()->json(['data' => $query->orderByDesc('id')->get()]);
    }

    public function auditLogById(int $id): JsonResponse
    {
        return response()->json(['data' => DB::table('audit_events')->where('id', $id)->first()]);
    }

    public function auditPartitions(): JsonResponse
    {
        return response()->json(['data' => DB::table('audit_event_partitions')->orderByDesc('month_utc')->get()]);
    }

    public function auditArchive(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! Gate::forUser($user)->allows('permission', 'audit.archive')) {
            return response()->json(ApiResponse::error('FORBIDDEN', 'Admin role required.', ApiResponse::requestId($request)), 403);
        }

        $beforeMonth = (string) $request->input('before_month');
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
        DB::table('audit_event_partitions')->where('partition_key', $partitionKey)->update([
            'updated_at' => now()->utc(),
        ]);

        return response()->json(['data' => ['status' => 'reindexed', 'partition_key' => $partitionKey]]);
    }
}
