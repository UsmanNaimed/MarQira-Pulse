<?php

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAuditLog(): AuditLog
{
    return AuditLog::record([
        'organization_id' => null,
        'actor_type' => 'system',
        'event' => 'test_event',
    ]);
}

it('creates an append-only audit record with a uuid and created_at', function () {
    $log = makeAuditLog();

    expect($log->exists)->toBeTrue();
    expect($log->uuid)->not->toBeEmpty();
    expect($log->created_at)->not->toBeNull();
});

it('throws when deleting an audit record', function () {
    $log = makeAuditLog();

    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

it('throws when updating an existing audit record', function () {
    $log = makeAuditLog();

    $log->event = 'mutated_event';

    expect(fn () => $log->save())->toThrow(LogicException::class);
});
