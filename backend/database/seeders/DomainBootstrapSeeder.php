<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DomainBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $facilityId = (int) DB::table('facilities')->value('id');
        if ($facilityId === 0) {
            return;
        }

        DB::table('storerooms')->updateOrInsert(
            ['code' => 'HQ-MAIN'],
            [
                'facility_id' => $facilityId,
                'name' => 'Main Storeroom',
                'updated_at' => now()->utc(),
                'created_at' => now()->utc(),
            ],
        );

        DB::table('inventory_items')->updateOrInsert(
            ['sku' => 'GAUZE-4X4'],
            [
                'name' => 'Sterile Gauze 4x4',
                'uom' => 'ea',
                'safety_stock_days' => 14,
                'external_key' => 'inv.gauze.4x4',
                'version' => 1,
                'updated_at' => now()->utc(),
                'created_at' => now()->utc(),
            ],
        );

        DB::table('review_moderation_policies')->insertOrIgnore([
            ['version' => 'v1', 'category' => 'abusive_language', 'rule_text' => 'Abusive language is prohibited.', 'is_active' => true, 'created_at' => now()->utc(), 'updated_at' => now()->utc()],
            ['version' => 'v1', 'category' => 'harassment', 'rule_text' => 'Harassment is prohibited.', 'is_active' => true, 'created_at' => now()->utc(), 'updated_at' => now()->utc()],
            ['version' => 'v1', 'category' => 'privacy', 'rule_text' => 'Private data leakage is prohibited.', 'is_active' => true, 'created_at' => now()->utc(), 'updated_at' => now()->utc()],
            ['version' => 'v1', 'category' => 'spam', 'rule_text' => 'Spam is prohibited.', 'is_active' => true, 'created_at' => now()->utc(), 'updated_at' => now()->utc()],
            ['version' => 'v1', 'category' => 'other', 'rule_text' => 'Other policy violations.', 'is_active' => true, 'created_at' => now()->utc(), 'updated_at' => now()->utc()],
        ]);
    }
}
