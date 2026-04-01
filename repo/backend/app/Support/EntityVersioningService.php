<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class EntityVersioningService
{
    public function __construct(private readonly ?ConnectionInterface $connection = null)
    {
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function snapshot(string $entity, int $entityId, int $version, array $snapshot, ?int $userId, string $changeType): void
    {
        $this->db()->table('master_versions')->insert([
            'entity' => $entity,
            'entity_id' => $entityId,
            'version' => $version,
            'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'changed_by_user_id' => $userId,
            'change_type' => $changeType,
            'changed_at_utc' => now()->utc(),
        ]);
    }

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
