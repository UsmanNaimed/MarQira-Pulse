<?php

use App\Models\Organization;
use App\Services\TenantContext;

it('throws a RuntimeException when no context is set', function () {
    $context = new TenantContext();

    expect(fn () => $context->organization())->toThrow(RuntimeException::class);
});

it('returns the organization after setOrganization', function () {
    $context = new TenantContext();
    $org = new Organization(['name' => 'Acme', 'slug' => 'acme']);
    $org->id = 42;

    $context->setOrganization($org);

    expect($context->organization())->toBe($org);
});

it('returns the correct organization id', function () {
    $context = new TenantContext();
    $org = new Organization(['name' => 'Acme', 'slug' => 'acme']);
    $org->id = 99;

    $context->setOrganization($org);

    expect($context->organizationId())->toBe(99);
});

it('reports context presence correctly across lifecycle', function () {
    $context = new TenantContext();
    expect($context->hasContext())->toBeFalse();

    $org = new Organization(['name' => 'Acme', 'slug' => 'acme']);
    $org->id = 1;
    $context->setOrganization($org);
    expect($context->hasContext())->toBeTrue();

    $context->clear();
    expect($context->hasContext())->toBeFalse();
});
