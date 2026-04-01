<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_health_endpoint_returns_vetops_conventions(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.currency.code', 'USD')
            ->assertJsonPath('data.currency.amount_format', 'integer_cents')
            ->assertJsonStructure([
                'data' => ['service', 'status', 'timestamp_utc'],
                'request_id',
            ]);
    }
}
