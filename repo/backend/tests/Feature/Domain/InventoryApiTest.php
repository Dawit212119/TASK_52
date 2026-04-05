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

    public function test_facility_scope_blocks_inventory_mutation_outside_user_facility(): void
    {
        $token = $this->login('inventory.clerk');

        $facilityTwo = DB::table('facilities')->insertGetId([
            'name' => 'Branch Facility',
            'code' => 'BR2',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $storeroomTwo = DB::table('storerooms')->insertGetId([
            'facility_id' => $facilityTwo,
            'name' => 'Branch Storeroom',
            'code' => 'BR2-MAIN',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $itemId = (int) DB::table('inventory_items')->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/receipts', [
                'item_id' => $itemId,
                'facility_id' => $facilityTwo,
                'storeroom_id' => $storeroomTwo,
                'qty' => 5,
            ])
            ->assertForbidden();
    }

    public function test_low_stock_analytics_respects_facility_scope(): void
    {
        $managerToken = $this->login('clinic.manager');

        $facilityTwo = DB::table('facilities')->insertGetId([
            'name' => 'Branch Facility B',
            'code' => 'BR3',
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$managerToken)
            ->getJson('/api/v1/analytics/inventory/low-stock?facility_id='.$facilityTwo)
            ->assertForbidden();
    }

    // --- Issue F: Per-service-order reservation strategy tests ---

    public function test_order_strategy_reserve_on_create_with_reserve_and_release(): void
    {
        $token = $this->login('inventory.clerk');
        $itemId = (int) DB::table('inventory_items')->value('id');
        $storeroomId = (int) DB::table('storerooms')->value('id');

        // Stock up
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/receipts', [
                'item_id' => $itemId, 'facility_id' => 1,
                'storeroom_id' => $storeroomId, 'qty' => 50,
            ])->assertCreated();

        $serviceId = DB::table('services')->insertGetId([
            'facility_id' => 1, 'name' => 'Reserve Svc',
            'external_key' => 'svc.reserve', 'version' => 1,
            'reservation_strategy' => 'reserve_on_order_create',
            'status' => 'active',
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);

        // Create order with reserve_on_order_create
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/2001/reserve', [
                'service_id' => $serviceId,
                'storeroom_id' => $storeroomId,
                'lines' => [['item_id' => $itemId, 'qty' => 5]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.strategy', 'reserve_on_order_create');

        // Verify strategy persisted on order
        $this->assertDatabaseHas('service_orders', [
            'id' => 2001,
            'reservation_strategy' => 'reserve_on_order_create',
        ]);

        // Reserve event created
        $this->assertDatabaseHas('inventory_reservation_events', [
            'service_order_id' => 2001,
            'event_type' => 'reserve',
            'strategy' => 'reserve_on_order_create',
        ]);

        // Close order -> should create release + outbound
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/2001/close')
            ->assertOk();

        $this->assertDatabaseHas('inventory_reservation_events', [
            'service_order_id' => 2001,
            'event_type' => 'release',
        ]);
    }

    public function test_order_strategy_deduct_on_close_with_plan_and_consume(): void
    {
        $token = $this->login('inventory.clerk');
        $itemId = (int) DB::table('inventory_items')->value('id');
        $storeroomId = (int) DB::table('storerooms')->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/receipts', [
                'item_id' => $itemId, 'facility_id' => 1,
                'storeroom_id' => $storeroomId, 'qty' => 50,
            ])->assertCreated();

        $serviceId = DB::table('services')->insertGetId([
            'facility_id' => 1, 'name' => 'Deduct Svc',
            'external_key' => 'svc.deduct', 'version' => 1,
            'reservation_strategy' => 'deduct_on_order_close',
            'status' => 'active',
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/3001/reserve', [
                'service_id' => $serviceId,
                'storeroom_id' => $storeroomId,
                'lines' => [['item_id' => $itemId, 'qty' => 3]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.strategy', 'deduct_on_order_close');

        $this->assertDatabaseHas('service_orders', [
            'id' => 3001,
            'reservation_strategy' => 'deduct_on_order_close',
        ]);

        $this->assertDatabaseHas('inventory_reservation_events', [
            'service_order_id' => 3001,
            'event_type' => 'plan',
            'strategy' => 'deduct_on_order_close',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/3001/close')
            ->assertOk();

        $this->assertDatabaseHas('inventory_reservation_events', [
            'service_order_id' => 3001,
            'event_type' => 'consume',
        ]);
    }

    public function test_service_default_change_does_not_alter_existing_order(): void
    {
        $token = $this->login('inventory.clerk');
        $itemId = (int) DB::table('inventory_items')->value('id');
        $storeroomId = (int) DB::table('storerooms')->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/receipts', [
                'item_id' => $itemId, 'facility_id' => 1,
                'storeroom_id' => $storeroomId, 'qty' => 50,
            ])->assertCreated();

        $serviceId = DB::table('services')->insertGetId([
            'facility_id' => 1, 'name' => 'Flip Svc',
            'external_key' => 'svc.flip', 'version' => 1,
            'reservation_strategy' => 'reserve_on_order_create',
            'status' => 'active',
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);

        // Create order -> strategy locked to reserve_on_order_create
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/4001/reserve', [
                'service_id' => $serviceId,
                'storeroom_id' => $storeroomId,
                'lines' => [['item_id' => $itemId, 'qty' => 2]],
            ])
            ->assertCreated();

        // Change service default to deduct_on_order_close
        DB::table('services')->where('id', $serviceId)->update([
            'reservation_strategy' => 'deduct_on_order_close',
        ]);

        // Close order should still use the order's stored strategy (reserve_on_order_create)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/4001/close')
            ->assertOk();

        // It used the order's stored strategy: reserve -> release + outbound
        $this->assertDatabaseHas('inventory_reservation_events', [
            'service_order_id' => 4001,
            'event_type' => 'release',
            'strategy' => 'reserve_on_order_create',
        ]);

        // No consume event (that would be deduct_on_order_close behavior)
        $this->assertDatabaseMissing('inventory_reservation_events', [
            'service_order_id' => 4001,
            'event_type' => 'consume',
        ]);
    }

    public function test_strategy_mismatch_on_existing_order_returns_409(): void
    {
        $token = $this->login('inventory.clerk');
        $itemId = (int) DB::table('inventory_items')->value('id');
        $storeroomId = (int) DB::table('storerooms')->value('id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/receipts', [
                'item_id' => $itemId, 'facility_id' => 1,
                'storeroom_id' => $storeroomId, 'qty' => 50,
            ])->assertCreated();

        $serviceId = DB::table('services')->insertGetId([
            'facility_id' => 1, 'name' => 'Mismatch Svc',
            'external_key' => 'svc.mismatch', 'version' => 1,
            'reservation_strategy' => 'reserve_on_order_create',
            'status' => 'active',
            'created_at' => now()->utc(), 'updated_at' => now()->utc(),
        ]);

        // Create order with reserve_on_order_create
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/5001/reserve', [
                'service_id' => $serviceId,
                'storeroom_id' => $storeroomId,
                'lines' => [['item_id' => $itemId, 'qty' => 2]],
            ])
            ->assertCreated();

        // Try to add with different strategy -> 409
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/inventory/service-orders/5001/reserve', [
                'service_id' => $serviceId,
                'storeroom_id' => $storeroomId,
                'strategy' => 'deduct_on_order_close',
                'lines' => [['item_id' => $itemId, 'qty' => 1]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STRATEGY_MISMATCH');
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
