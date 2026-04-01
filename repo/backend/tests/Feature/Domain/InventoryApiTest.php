<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_stocktake_variance_requires_approval_and_q5_reservations_work(): void
    {
        $token = $this->login('inventory.clerk');
        $itemId = (int) DB::table('inventory_items')->value('id');
        $storeroomId = (int) DB::table('storerooms')->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/receipts', [
                'item_id' => $itemId,
                'facility_id' => 1,
                'storeroom_id' => $storeroomId,
                'qty' => 100,
            ])
            ->assertCreated();

        $stocktake = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/stocktakes', [
                'facility_id' => 1,
                'storeroom_id' => $storeroomId,
            ])
            ->assertCreated();

        $stocktakeId = (int) $stocktake->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/stocktakes/'.$stocktakeId.'/lines', [
                'lines' => [
                    ['item_id' => $itemId, 'counted_qty' => 88, 'variance_reason' => 'Breakage and expiry'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $managerToken = $this->login('clinic.manager');
        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->postJson('/api/v1/inventory/stocktakes/'.$stocktakeId.'/approve-variance', ['reason' => 'Validated'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $serviceId = DB::table('services')->insertGetId([
            'facility_id' => 1,
            'name' => 'Vaccination',
            'external_key' => 'svc.vac',
            'version' => 1,
            'reservation_strategy' => 'deduct_on_order_close',
            'status' => 'active',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/1001/reserve', [
                'service_id' => $serviceId,
                'storeroom_id' => $storeroomId,
                'lines' => [['item_id' => $itemId, 'qty' => 2]],
            ])
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/1001/close')
            ->assertOk();
    }

    private function login(string $username): string
    {
        $res = $this->withHeader('X-Workstation-Id', 'ws-frontdesk-01')
            ->postJson('/api/v1/auth/login', [
                'username' => $username,
                'password' => 'VetOpsSecure123',
            ])
            ->assertOk();

        return (string) $res->json('token');
    }
}
