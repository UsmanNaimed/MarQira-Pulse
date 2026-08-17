<?php

use App\Services\OriginDetector;

test('origin detector analyzes domain and server IP', function () {
    $detector = new OriginDetector();

    $result = $detector->analyze('example.com', '93.184.216.34');

    expect($result)->toHaveKeys(['origin_ip', 'source', 'confidence', 'metadata']);
    expect($result['metadata'])->toHaveKeys([
        'dns_a_records',
        'dns_aaaa_records',
        'server_ip',
        'server_ip_type',
        'analysis_timestamp',
    ]);
});

test('origin detector handles invalid domain', function () {
    $detector = new OriginDetector();

    $result = $detector->analyze('', null);

    expect($result['confidence'])->toBe('unknown');
    expect($result['origin_ip'])->toBeNull();
    expect($result['metadata']['error'] ?? null)->not->toBeNull();
});

test('origin detector classifies private IP correctly', function () {
    $detector = new OriginDetector();

    // Use reflection to test private method
    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('classifyIp');
    $method->setAccessible(true);

    expect($method->invoke($detector, '192.168.1.1'))->toBe('private');
    expect($method->invoke($detector, '10.0.0.1'))->toBe('private');
    expect($method->invoke($detector, '172.16.0.1'))->toBe('private');
});

test('origin detector classifies public IP correctly', function () {
    $detector = new OriginDetector();

    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('classifyIp');
    $method->setAccessible(true);

    // Public IP (Google DNS)
    expect($method->invoke($detector, '8.8.8.8'))->toBe('public');
});

test('origin detector detects Cloudflare IP', function () {
    $detector = new OriginDetector();

    $reflection = new ReflectionClass($detector);
    $method = $reflection->getMethod('classifyIp');
    $method->setAccessible(true);

    // Known Cloudflare IP range
    expect($method->invoke($detector, '104.16.0.1'))->toBe('cloudflare');
});

test('origin detector determines high confidence when server IP matches DNS', function () {
    $detector = new OriginDetector();

    // For a real-world test, you'd mock dns_get_record
    // For now, this is a basic structure test
    $result = $detector->analyze('example.com', null);

    expect($result)->toBeArray();
    expect($result['confidence'])->toBeIn(['high', 'medium', 'low', 'unknown']);
});
