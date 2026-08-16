<?php

use App\Services\Hmac\NonceManager;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::flushDB();
});

test('first nonce claim succeeds', function () {
    $manager = new NonceManager();
    
    $result = $manager->claimNonce('site-uuid-123', 'nonce-abc');
    
    expect($result)->toBeTrue();
});

test('second claim of same nonce fails', function () {
    $manager = new NonceManager();
    
    $manager->claimNonce('site-uuid-123', 'nonce-abc');
    $result = $manager->claimNonce('site-uuid-123', 'nonce-abc');
    
    expect($result)->toBeFalse();
});

test('nonce exists after claim', function () {
    $manager = new NonceManager();
    
    $manager->claimNonce('site-uuid-123', 'nonce-abc');
    
    expect($manager->nonceExists('site-uuid-123', 'nonce-abc'))->toBeTrue();
});

test('different site can use same nonce value', function () {
    $manager = new NonceManager();
    
    $result1 = $manager->claimNonce('site-1', 'nonce-abc');
    $result2 = $manager->claimNonce('site-2', 'nonce-abc');
    
    expect($result1)->toBeTrue();
    expect($result2)->toBeTrue();
});
