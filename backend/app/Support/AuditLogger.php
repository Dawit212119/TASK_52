<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workstation;
use Illuminate\Http\Request;

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
    }

    private function resolveWorkstation(Request $request): ?Workstation
    {
        $stableLocalId = trim((string) $request->header('X-Workstation-Id', ''));
        if ($stableLocalId === '') {
            return null;
        }

        return Workstation::query()->firstOrCreate(
            ['stable_local_id' => $stableLocalId],
            ['last_seen_at' => now()->utc()],
        );
    }

    private function partitionKey(?int $facilityId): string
    {
        $month = now()->utc()->format('Y_m');
        return sprintf('%s_facility_%s', $month, $facilityId ?? 'none');
    }
}
