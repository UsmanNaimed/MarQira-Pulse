<?php

namespace App\Services\Hmac;

use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed nonce manager for HMAC replay protection.
 *
 * Uses atomic SET NX EX to claim nonces exactly once.
 */
class NonceManager
{
    /**
     * Nonce TTL in seconds (10 minutes).
     * Should be >= timestamp tolerance to prevent edge cases.
     */
    private int $ttl = 600;

    /**
     * Attempt to claim a nonce atomically.
     *
     * Returns true if this is the first use of the nonce.
     * Returns false if the nonce was already used.
     *
     * Uses Redis SET NX EX (Set if Not eXists with EXpiry) for atomicity.
     *
     * @param string $siteUuid Site UUID
     * @param string $nonce Nonce value
     * @return bool True if nonce claimed successfully, false if already used
     */
    public function claimNonce(string $siteUuid, string $nonce): bool
    {
        $key = $this->buildKey($siteUuid, $nonce);

        // SET key value NX EX seconds
        // Returns true if key was set (first use), false if key already exists
        $result = Redis::set($key, '1', 'EX', $this->ttl, 'NX');

        return $result === true;
    }

    /**
     * Check if a nonce exists (for testing).
     *
     * @param string $siteUuid
     * @param string $nonce
     * @return bool
     */
    public function nonceExists(string $siteUuid, string $nonce): bool
    {
        $key = $this->buildKey($siteUuid, $nonce);
        return Redis::exists($key) > 0;
    }

    /**
     * Build Redis key for a nonce.
     *
     * @param string $siteUuid
     * @param string $nonce
     * @return string
     */
    private function buildKey(string $siteUuid, string $nonce): string
    {
        return "marqira:nonce:{$siteUuid}:{$nonce}";
    }

    /**
     * Set custom TTL (for testing).
     *
     * @param int $seconds
     * @return void
     */
    public function setTtl(int $seconds): void
    {
        $this->ttl = $seconds;
    }
}
