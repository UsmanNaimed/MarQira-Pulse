<?php

use App\Models\Organization;
use App\Models\OriginIpHistory;
use App\Models\Site;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Regression guard for the Phase 6 heartbeat 500 error.
 *
 * The migration creates the table as "origin_ip_history" (singular), but
 * Eloquent's default pluralization of "OriginIpHistory" is
 * "origin_ip_histories". Without an explicit $table the model queried a
 * non-existent relation and every heartbeat threw:
 *   SQLSTATE[42P01]: Undefined table: relation "origin_ip_histories" does not exist
 */
test('OriginIpHistory model maps to the origin_ip_history table', function () {
    expect((new OriginIpHistory())->getTable())->toBe('origin_ip_history');
});

test('OriginIpHistory::count() queries the correct table without throwing', function () {
    // This is the exact operation from the Tinker verification step. It must
    // return an integer (0 on an empty table) and never raise "undefined table".
    expect(OriginIpHistory::count())->toBe(0);
});

test('OriginIpHistory records can be created and read back (heartbeat insert path)', function () {
    $org = Organization::factory()->create();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    // Mirrors the OriginIpHistory::create(...) call inside HeartbeatController.
    $history = OriginIpHistory::create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'event_type' => 'detected',
        'origin_ip' => '203.0.113.10',
        'previous_origin_ip' => null,
        'source' => 'dns_a_match',
        'confidence' => 'high',
        'previous_confidence' => 'unknown',
        'verified' => false,
        'metadata' => ['dns_a_records' => ['203.0.113.10']],
        'recorded_at' => now(),
    ]);

    expect($history->exists)->toBeTrue();
    expect(OriginIpHistory::count())->toBe(1);

    $fresh = OriginIpHistory::first();
    expect($fresh->origin_ip)->toBe('203.0.113.10');
    expect($fresh->confidence)->toBe('high');
    expect($fresh->metadata)->toBe(['dns_a_records' => ['203.0.113.10']]);
});
