<?php

namespace App\Services\Hmac;

use RuntimeException;

/**
 * HMAC-SHA256 signature service for MarQira plugin authentication.
 *
 * Protocol: HMAC v1
 * Algorithm: HMAC-SHA256
 */
class HmacService
{
    /**
     * Build the canonical data string for HMAC signature.
     *
     * Format:
     * METHOD + "\n" +
     * PATH + "\n" +
     * CANONICAL_QUERY_STRING + "\n" +
     * TIMESTAMP + "\n" +
     * NONCE + "\n" +
     * SHA256(BODY)
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $path Request path (e.g., /api/v1/heartbeat)
     * @param array $queryParams Query parameters (will be canonicalized)
     * @param string $timestamp Unix timestamp (seconds)
     * @param string $nonce Unique nonce
     * @param string $body Request body (empty string for GET)
     * @return string
     */
    public function buildCanonicalData(
        string $method,
        string $path,
        array $queryParams,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        $canonicalQuery = $this->canonicalizeQueryString($queryParams);
        $bodyHash = hash('sha256', $body);

        return implode("\n", [
            strtoupper($method),
            $path,
            $canonicalQuery,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);
    }

    /**
     * Canonicalize query parameters for signing.
     *
     * Sorts by key, URL-encodes keys and values, joins with &.
     *
     * @param array $params Query parameters
     * @return string Canonical query string (may be empty)
     */
    public function canonicalizeQueryString(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }

    /**
     * Generate HMAC-SHA256 signature.
     *
     * @param string $canonicalData Canonical data string
     * @param string $secret Site secret (base64-decoded if needed)
     * @return string Hex-encoded signature
     */
    public function generateSignature(string $canonicalData, string $secret): string
    {
        return hash_hmac('sha256', $canonicalData, $secret);
    }

    /**
     * Verify HMAC signature (constant-time comparison).
     *
     * @param string $expectedSignature Expected signature (hex)
     * @param string $canonicalData Canonical data string
     * @param string $secret Site secret
     * @return bool True if signature matches
     */
    public function verifySignature(
        string $expectedSignature,
        string $canonicalData,
        string $secret
    ): bool {
        $computedSignature = $this->generateSignature($canonicalData, $secret);

        // Constant-time comparison to prevent timing attacks
        return hash_equals($computedSignature, $expectedSignature);
    }

    /**
     * Validate timestamp is within acceptable tolerance.
     *
     * Tolerance: ±5 minutes from current server time.
     *
     * @param string|int $timestamp Unix timestamp (seconds)
     * @return bool True if within tolerance
     */
    public function isTimestampValid($timestamp): bool
    {
        $timestamp = (int) $timestamp;
        $now = time();
        $tolerance = 300; // 5 minutes in seconds

        $diff = abs($now - $timestamp);

        return $diff <= $tolerance;
    }

    /**
     * Extract request body from Laravel request.
     *
     * @param \Illuminate\Http\Request $request
     * @return string Raw request body
     */
    public function getRequestBody($request): string
    {
        return $request->getContent() ?: '';
    }
}
