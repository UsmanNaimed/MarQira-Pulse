<?php

use App\Services\Hmac\HmacService;

test('signature generation is deterministic for same inputs', function () {
    $service = new HmacService();
    
    $canonicalData = "POST\n/api/v1/heartbeat\n\n1704110400\nabc123\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    $secret = 'test-secret-key';
    
    $sig1 = $service->generateSignature($canonicalData, $secret);
    $sig2 = $service->generateSignature($canonicalData, $secret);
    
    expect($sig1)->toBe($sig2);
});

test('signature verification succeeds with correct secret', function () {
    $service = new HmacService();
    
    $canonicalData = "POST\n/api/v1/heartbeat\n\n1704110400\nabc123\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    $secret = 'test-secret-key';
    
    $signature = $service->generateSignature($canonicalData, $secret);
    
    expect($service->verifySignature($signature, $canonicalData, $secret))->toBeTrue();
});

test('signature verification fails with wrong secret', function () {
    $service = new HmacService();
    
    $canonicalData = "POST\n/api/v1/heartbeat\n\n1704110400\nabc123\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    $secret = 'test-secret-key';
    $wrongSecret = 'wrong-secret';
    
    $signature = $service->generateSignature($canonicalData, $secret);
    
    expect($service->verifySignature($signature, $canonicalData, $wrongSecret))->toBeFalse();
});

test('canonical data format is correct', function () {
    $service = new HmacService();
    
    $canonical = $service->buildCanonicalData(
        'POST',
        '/api/v1/heartbeat',
        [],
        '1704110400',
        'abc123',
        ''
    );
    
    $expected = "POST\n/api/v1/heartbeat\n\n1704110400\nabc123\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
    
    expect($canonical)->toBe($expected);
});

test('canonical query string is sorted and encoded', function () {
    $service = new HmacService();
    
    $result = $service->canonicalizeQueryString(['b' => '2', 'a' => '1']);
    
    expect($result)->toBe('a=1&b=2');
});

test('timestamp validation works within tolerance', function () {
    $service = new HmacService();
    
    // Current time should be valid
    expect($service->isTimestampValid(time()))->toBeTrue();
    
    // 4 minutes ago should be valid
    expect($service->isTimestampValid(time() - 240))->toBeTrue();
    
    // 6 minutes ago should be invalid (tolerance is ±5 min)
    expect($service->isTimestampValid(time() - 360))->toBeFalse();
});
