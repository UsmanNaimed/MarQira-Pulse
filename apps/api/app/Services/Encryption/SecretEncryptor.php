<?php

namespace App\Services\Encryption;

use RuntimeException;

/**
 * Authenticated encryption for site secrets at rest.
 *
 * Uses AES-256-GCM (AEAD) with the 32-byte key supplied via MARQIRA_SECRET_KEY
 * (base64-encoded in the environment). The sealed payload is
 * base64( iv(12) || tag(16) || ciphertext ) so that decryption both recovers
 * and verifies the plaintext — a tampered ciphertext fails verification and
 * throws rather than returning corrupted data.
 */
class SecretEncryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct(?string $base64Key = null)
    {
        $base64Key ??= config('marqira.secret_key');

        if (empty($base64Key)) {
            throw new RuntimeException(
                'SecretEncryptor: MARQIRA_SECRET_KEY is not set. ' .
                'Generate one with: php -r "echo base64_encode(random_bytes(32));"'
            );
        }

        $decoded = base64_decode($base64Key, true);

        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException(
                'SecretEncryptor: MARQIRA_SECRET_KEY must be a base64-encoded 32-byte key.'
            );
        }

        $this->key = $decoded;
    }

    /**
     * Encrypt plaintext and return a base64-encoded, self-contained payload.
     */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('SecretEncryptor: encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt and verify a payload produced by encrypt(). Throws on tamper.
     */
    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);

        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('SecretEncryptor: malformed ciphertext.');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $data = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $data,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('SecretEncryptor: decryption failed — data may be tampered or the key is wrong.');
        }

        return $plaintext;
    }

    /**
     * Stable short identifier for the active key, used for rotation tracking.
     * First 8 hex chars of SHA-256(key).
     */
    public function keyId(): string
    {
        return substr(hash('sha256', $this->key), 0, 8);
    }
}
