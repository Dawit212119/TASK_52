<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workstation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        Request $request,
        string $eventType,
        string $action,
        string $status,
        ?User $actor = null,
        array $metadata = [],
    ): void {
        $workstation = $this->resolveWorkstation($request);

        AuditEvent::query()->create([
            'actor_user_id' => $actor?->id,
            'workstation_id' => $workstation?->id,
            'facility_id' => $workstation?->facility_id,
            'partition_key' => $this->partitionKey($workstation?->facility_id),
            'event_type' => $eventType,
            'action' => $action,
            'status' => $status,
            'request_id' => ApiResponse::requestId($request),
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'ip_address' => $request->ip(),
            'metadata' => $metadata,
            'created_at' => now()->utc(),
        ]);

        $partitionKey = $this->partitionKey($workstation?->facility_id);
        DB::table('audit_event_partitions')->updateOrInsert(
            ['partition_key' => $partitionKey],
            [
                'month_utc' => now()->utc()->format('Y-m'),
                'facility_id' => $workstation?->facility_id,
                'storage_tier' => 'hot',
                'updated_at' => now()->utc(),
                'created_at' => now()->utc(),
            ],
        );
        DB::table('audit_event_partitions')->where('partition_key', $partitionKey)->increment('row_count');
    }

    private function resolveWorkstation(Request $request): ?Workstation
    {
        $stableLocalId = trim((string) $request->header('X-Workstation-Id', ''));
        if ($stableLocalId === '') {
            return null;
        }

        $workstation = Workstation::query()->firstOrCreate(
            ['stable_local_id' => $stableLocalId],
            ['last_seen_at' => now()->utc()],
        );

        $facilityHeader = (int) $request->header('X-Facility-Id', 0);
        $workstation->forceFill([
            'last_seen_at' => now()->utc(),
            'facility_id' => $workstation->facility_id ?: ($facilityHeader > 0 ? $facilityHeader : null),
        ])->save();

        return $workstation;
    }

    private function partitionKey(?int $facilityId): string
    {
        $month = now()->utc()->format('Y_m');
        return sprintf('%s_facility_%s', $month, $facilityId ?? 'none');
    }
}
