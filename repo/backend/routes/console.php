<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('vetops:rentals:mark-overdue', function () {
    $affected = DB::table('rental_assets')
        ->whereIn('id', function ($query) {
            $query->select('asset_id')
                ->from('rental_checkouts')
                ->whereNull('returned_at')
                ->where('expected_return_at', '<', now()->utc()->subHours(2));
        })
        ->whereNotIn('status', ['deactivated', 'maintenance'])
        ->update(['status' => 'overdue', 'updated_at' => now()->utc()]);

    $this->info("Marked {$affected} assets overdue.");
})->purpose('Auto-transition rentals to overdue status at +2h');

Artisan::command('vetops:analytics:snapshot', function () {
    $groups = DB::table('reviews')
        ->selectRaw('facility_id, provider_id, AVG(rating) as average_score, SUM(CASE WHEN rating <= 2 THEN 1 ELSE 0 END) as negatives, COUNT(*) as total')
        ->groupBy('facility_id', 'provider_id')
        ->get();

    foreach ($groups as $group) {
        DB::table('analytics_review_snapshots')->insert([
            'facility_id' => $group->facility_id,
            'provider_id' => $group->provider_id,
            'snapshot_date' => now()->toDateString(),
            'average_score' => round((float) $group->average_score, 3),
            'negative_review_rate' => $group->total > 0 ? round(((int) $group->negatives / (int) $group->total) * 100, 3) : 0,
            'median_response_time_minutes' => 0,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }

    $this->info('Analytics snapshot complete.');
})->purpose('Build daily analytics snapshots');

Artisan::command('vetops:audit:archive', function () {
    $threshold = now()->utc()->subMonths(18)->format('Y-m');
    $affected = DB::table('audit_event_partitions')
        ->where('month_utc', '<', $threshold)
        ->where('storage_tier', '!=', 'archive')
        ->update([
            'storage_tier' => 'archive',
            'archived_at_utc' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

    $this->info("Archived {$affected} partitions.");
})->purpose('Archive old audit partitions with retention guard');

Artisan::command('vetops:integrity:check-uploads', function () {
    $media = DB::table('review_media')->get();
    foreach ($media as $row) {
        $status = 'missing';
        if (is_file($row->path)) {
            $status = hash_file('sha256', $row->path) === $row->checksum_sha256 ? 'ok' : 'mismatch';
        }

        DB::table('file_integrity_checks')->insert([
            'file_path' => $row->path,
            'checksum_sha256' => $row->checksum_sha256,
            'status' => $status,
            'checked_at_utc' => now()->utc(),
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }

    $this->info('Integrity check complete.');
})->purpose('Run checksum integrity checks on uploaded media');

Schedule::command('vetops:rentals:mark-overdue')->everyFiveMinutes();
Schedule::command('vetops:analytics:snapshot')->dailyAt('00:15');
Schedule::command('vetops:audit:archive')->monthlyOn(1, '01:15');
Schedule::command('vetops:integrity:check-uploads')->dailyAt('02:00');
