<?php

namespace Tests\Feature\Domain;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RentalsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_double_booking_prevention_and_transfer_workflow(): void
    {
        $token = $this->login('clinic.manager');

        $asset = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/rentals/assets', [
                'facility_id' => 1,
                'asset_code' => 'ASSET-1001',
                'name' => 'Infusion Pump X',
                'replacement_cost_cents' => 100000,
            ])
            ->assertCreated();

        $assetId = (int) $asset->json('data.id');

        $payload = [
            'asset_id' => $assetId,
            'renter_type' => 'department',
            'renter_id' => 1,
            'checked_out_at' => now()->utc()->toISOString(),
            'expected_return_at' => now()->utc()->addDay()->toISOString(),
            'pricing_mode' => 'daily',
            'deposit_cents' => 20000,
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/rentals/checkouts', $payload)
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/rentals/checkouts', $payload)
            ->assertStatus(409);

        $transfer = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/rentals/assets/'.$assetId.'/transfer', [
                'to_facility_id' => 1,
                'requested_effective_at' => now()->utc()->addHour()->toISOString(),
                'reason' => 'Capacity balancing',
            ])
            ->assertStatus(409);

        $checkoutId = (int) DB::table('rental_checkouts')->where('asset_id', $assetId)->value('id');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/rentals/checkouts/'.$checkoutId.'/return')
            ->assertNoContent();

        $transfer = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/rentals/assets/'.$assetId.'/transfer', [
                'to_facility_id' => 1,
                'requested_effective_at' => now()->utc()->addHour()->toISOString(),
                'reason' => 'Capacity balancing',
            ])
            ->assertCreated();

        $transferId = (int) $transfer->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/rentals/transfers/'.$transferId.'/approve')
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
