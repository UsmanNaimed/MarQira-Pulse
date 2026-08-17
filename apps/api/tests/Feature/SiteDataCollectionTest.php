<?php

use App\Models\Organization;
use App\Models\Site;
use App\Models\SitePost;
use App\Models\SiteUser;
use App\Services\Encryption\SecretEncryptor;
use App\Services\Hmac\HmacService;
use Illuminate\Support\Facades\Redis;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Redis::flushDB();
});

function makeSiteWithHmac(): array
{
    $org = Organization::factory()->create();
    $site = Site::factory()->create(['organization_id' => $org->id]);

    $secret = base64_encode(random_bytes(32));
    $encryptor = app(SecretEncryptor::class);
    $site->update([
        'site_secret_encrypted' => $encryptor->encrypt($secret),
        'site_secret_kid' => $encryptor->keyId(),
    ]);

    return [$org, $site, $secret];
}

function buildHmacRequest(string $method, string $path, array $body, string $secret, Site $site): array
{
    $hmac = new HmacService();
    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $bodyJson = json_encode($body);

    $canonical = $hmac->buildCanonicalData($method, $path, [], $timestamp, $nonce, $bodyJson);
    $signature = $hmac->generateSignature($canonical, $secret);

    return [
        'headers' => [
            'X-MarQira-Site' => $site->uuid,
            'X-MarQira-Timestamp' => $timestamp,
            'X-MarQira-Nonce' => $nonce,
            'X-MarQira-Kid' => $site->site_secret_kid,
            'X-MarQira-Signature' => $signature,
        ],
        'body' => $body,
    ];
}

test('user snapshots are received and stored via HMAC-authenticated endpoint', function () {
    [$org, $site, $secret] = makeSiteWithHmac();

    $payload = [
        'snapshot_at' => now()->toIso8601String(),
        'users' => [
            [
                'wp_user_id' => 1,
                'user_login' => 'admin',
                'user_email' => 'admin@example.com',
                'display_name' => 'Administrator',
                'user_registered' => '2024-01-01 00:00:00',
                'roles' => ['administrator'],
            ],
            [
                'wp_user_id' => 2,
                'user_login' => 'editor',
                'user_email' => 'editor@example.com',
                'display_name' => 'Editor User',
                'user_registered' => '2024-01-15 10:00:00',
                'roles' => ['editor'],
                'last_login_at' => '2024-01-20 12:00:00',
            ],
        ],
    ];

    $req = buildHmacRequest('POST', '/api/v1/sites/users', $payload, $secret, $site);

    $response = $this->postJson('/api/v1/sites/users', $req['body'], $req['headers']);

    $response->assertStatus(200);
    $response->assertJson(['success' => true, 'inserted' => 2]);

    $users = SiteUser::where('site_id', $site->id)->get();
    expect($users)->toHaveCount(2);
    expect($users[0]->wp_user_id)->toBe(1);
    expect($users[0]->user_login)->toBe('admin');
    expect($users[0]->roles)->toBe(['administrator']);
    expect($users[1]->wp_user_id)->toBe(2);
    expect($users[1]->last_login_at)->not->toBeNull();
});

test('post snapshots are received and stored via HMAC-authenticated endpoint', function () {
    [$org, $site, $secret] = makeSiteWithHmac();

    $payload = [
        'snapshot_at' => now()->toIso8601String(),
        'posts' => [
            [
                'wp_post_id' => 1,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_title' => 'Hello World',
                'post_date' => '2024-01-01 12:00:00',
                'post_modified' => '2024-01-02 10:00:00',
                'post_author_id' => 1,
                'post_author_name' => 'Admin',
                'guid' => 'https://example.com/?p=1',
                'metadata' => [
                    'categories' => ['News'],
                    'tags' => ['announcement'],
                ],
            ],
            [
                'wp_post_id' => 5,
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'About Us',
                'post_date' => '2024-01-10 14:00:00',
                'post_modified' => '2024-01-10 14:00:00',
                'post_author_id' => 1,
                'post_author_name' => 'Admin',
                'guid' => 'https://example.com/?page_id=5',
            ],
        ],
    ];

    $req = buildHmacRequest('POST', '/api/v1/sites/posts', $payload, $secret, $site);

    $response = $this->postJson('/api/v1/sites/posts', $req['body'], $req['headers']);

    $response->assertStatus(200);
    $response->assertJson(['success' => true, 'inserted' => 2]);

    $posts = SitePost::where('site_id', $site->id)->orderBy('wp_post_id')->get();
    expect($posts)->toHaveCount(2);
    expect($posts[0]->wp_post_id)->toBe(1);
    expect($posts[0]->post_type)->toBe('post');
    expect($posts[0]->post_title)->toBe('Hello World');
    expect($posts[0]->metadata)->toHaveKey('categories');
    expect($posts[1]->wp_post_id)->toBe(5);
    expect($posts[1]->post_type)->toBe('page');
});

test('user endpoint validates required fields', function () {
    [$org, $site, $secret] = makeSiteWithHmac();

    $payload = [
        'snapshot_at' => now()->toIso8601String(),
        'users' => [
            [
                // Missing wp_user_id and user_login
                'user_email' => 'test@example.com',
            ],
        ],
    ];

    $req = buildHmacRequest('POST', '/api/v1/sites/users', $payload, $secret, $site);
    $response = $this->postJson('/api/v1/sites/users', $req['body'], $req['headers']);

    $response->assertStatus(422);
    $response->assertJsonStructure(['error', 'messages']);
});

test('post endpoint validates required fields', function () {
    [$org, $site, $secret] = makeSiteWithHmac();

    $payload = [
        'snapshot_at' => now()->toIso8601String(),
        'posts' => [
            [
                // Missing wp_post_id and post_type
                'post_title' => 'Test Post',
            ],
        ],
    ];

    $req = buildHmacRequest('POST', '/api/v1/sites/posts', $payload, $secret, $site);
    $response = $this->postJson('/api/v1/sites/posts', $req['body'], $req['headers']);

    $response->assertStatus(422);
    $response->assertJsonStructure(['error', 'messages']);
});

test('data collection endpoints require HMAC authentication', function () {
    $payload = [
        'snapshot_at' => now()->toIso8601String(),
        'users' => [['wp_user_id' => 1, 'user_login' => 'test']],
    ];

    // No HMAC headers - middleware returns 400 for missing headers
    $response = $this->postJson('/api/v1/sites/users', $payload);
    $response->assertStatus(400);

    $payload2 = [
        'snapshot_at' => now()->toIso8601String(),
        'posts' => [['wp_post_id' => 1, 'post_type' => 'post']],
    ];

    $response2 = $this->postJson('/api/v1/sites/posts', $payload2);
    $response2->assertStatus(400);
});

test('§26 IP-retention fix: heartbeat with omitted server_ip preserves existing IP', function () {
    Redis::flushDB();
    [$org, $site, $secret] = makeSiteWithHmac();

    // First heartbeat with a valid server_ip
    $site->update(['server_ip' => '203.0.113.50']);

    $payload1 = ['domain' => $site->domain, 'server_ip' => '203.0.113.100'];
    $req1 = buildHmacRequest('POST', '/api/v1/heartbeat', $payload1, $secret, $site);
    $response1 = $this->postJson('/api/v1/heartbeat', $req1['body'], $req1['headers']);
    $response1->assertStatus(200);

    $site->refresh();
    expect($site->server_ip)->toBe('203.0.113.100');

    // Second heartbeat WITHOUT server_ip (e.g. SERVER_ADDR unavailable)
    $payload2 = ['domain' => $site->domain]; // omits server_ip
    $req2 = buildHmacRequest('POST', '/api/v1/heartbeat', $payload2, $secret, $site);
    $response2 = $this->postJson('/api/v1/heartbeat', $req2['body'], $req2['headers']);
    $response2->assertStatus(200);

    $site->refresh();
    // The IP must NOT be overwritten with null — it should still be the last valid one
    expect($site->server_ip)->toBe('203.0.113.100');
});

test('§26 IP-retention fix: heartbeat with null server_ip also preserves existing IP', function () {
    Redis::flushDB();
    [$org, $site, $secret] = makeSiteWithHmac();

    $site->update(['server_ip' => '198.51.100.42']);

    // Heartbeat with explicitly null server_ip (validation allows nullable)
    $payload = ['domain' => $site->domain, 'server_ip' => null];
    $req = buildHmacRequest('POST', '/api/v1/heartbeat', $payload, $secret, $site);
    $response = $this->postJson('/api/v1/heartbeat', $req['body'], $req['headers']);
    $response->assertStatus(200);

    $site->refresh();
    expect($site->server_ip)->toBe('198.51.100.42');
});
