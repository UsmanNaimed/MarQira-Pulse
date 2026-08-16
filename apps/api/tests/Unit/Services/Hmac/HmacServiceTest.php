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

/*
|--------------------------------------------------------------------------
| Cross-implementation known-answer vector
|--------------------------------------------------------------------------
| This fixed vector is shared with the WordPress connector test
| (wordpress/marqira-connector/tests/test-hmac-vector.php). Both the API and
| the plugin must key HMAC-SHA256 with the site secret used verbatim (the
| base64 text issued at enrollment, NOT base64-decoded). If either side ever
| changes the canonical string or the key handling, this vector breaks.
*/
test('HMAC matches the shared cross-implementation known vector', function () {
    $service = new HmacService();

    $canonical = $service->buildCanonicalData(
        'POST',
        '/api/v1/heartbeat',
        [],
        '1704110400',
        'fixednonce123',
        '' // empty body -> sha256 of empty string
    );

    $secret = 'bWFycWlyYS10ZXN0LXNlY3JldC0zMi1ieXRlcy1rZXkxMjM0NQ==';
    $expected = '9ccd841ddab2b814c9090915eec726ab6211d3ab48c01f480f1f7ffa1200d011';

    expect($service->generateSignature($canonical, $secret))->toBe($expected);
    expect($service->verifySignature($expected, $canonical, $secret))->toBeTrue();
});
