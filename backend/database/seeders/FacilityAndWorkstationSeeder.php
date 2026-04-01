<?php

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\Workstation;
use Illuminate\Database\Seeder;

class FacilityAndWorkstationSeeder extends Seeder
{
    public function run(): void
    {
        $facility = Facility::query()->updateOrCreate(
            ['code' => 'HQ'],
            ['name' => 'VetOps Main Facility'],
        );

        Workstation::query()->updateOrCreate(
            ['stable_local_id' => 'ws-frontdesk-01'],
            [
                'facility_id' => $facility->id,
                'display_name' => 'Front Desk Workstation',
                'last_seen_at' => now()->utc(),
            ],
        );
    }
}
