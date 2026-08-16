<?php
/**
 * Authenticated encryption for MarQira Connector credentials.
 *
 * Uses AES-256-GCM (authenticated encryption with associated data) so that any
 * tampering with the stored ciphertext is detected and rejected (fail closed).
 *
 * Payload format (versioned):
 *
 *     "MQG1:" . base64( IV(12 bytes) . TAG(16 bytes) . CIPHERTEXT )
 *
 * The "MQG1" marker identifies MarQira GCM v1. The version prefix lets future
 * formats be introduced without ambiguity, and lets decrypt() reject anything
 * that is not a v1 GCM payload (e.g. the legacy AES-256-CBC blobs).
 *
 * Encryption-key preference:
 *   1. MARQIRA_SECRET_KEY constant from wp-config.php (recommended).
 *   2. Fallback: a key derived from the WordPress authentication salts.
 *
 * Limitation of the salt-derived fallback: it mainly protects against a
 * database-only compromise. It does NOT protect against an attacker who has
 * BOTH the database and wp-config.php (the salts live in wp-config.php). Define
 * MARQIRA_SECRET_KEY out-of-band for stronger separation.
 *
 * PHP 7.4 compatible. Never logs plaintext, keys, IVs, or tags.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_Crypto
 */
class Marqira_Crypto {

	/**
	 * Versioned payload prefix (MarQira GCM v1).
	 */
	const VERSION_PREFIX = 'MQG1:';

	/**
	 * Cipher algorithm.
	 */
	const CIPHER = 'aes-256-gcm';

	/**
	 * IV length in bytes (96-bit nonce, the recommended size for GCM).
	 */
	const IV_LENGTH = 12;

	/**
	 * GCM authentication tag length in bytes.
	 */
	const TAG_LENGTH = 16;

	/**
	 * Encrypt plaintext with AES-256-GCM.
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string|false Versioned base64 payload, or false on failure.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! is_string( $plaintext ) ) {
			return false;
		}

		$key = self::get_key();
		if ( false === $key ) {
			return false;
		}

		$iv  = self::random_iv();
		if ( false === $iv ) {
			return false;
		}

		$tag = '';
		// $tag is populated by reference. GCM available since PHP 7.1.
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if ( false === $ciphertext || ! is_string( $tag ) || self::TAG_LENGTH !== strlen( $tag ) ) {
			return false;
		}

		return self::VERSION_PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a versioned AES-256-GCM payload.
	 *
	 * Fails closed: returns false if the version marker is missing, the base64
	 * is invalid, the payload is too short, or the authentication tag does not
	 * verify (tampering).
	 *
	 * @param string $payload Versioned base64 payload from encrypt().
	 * @return string|false Plaintext, or false on any failure.
	 */
	public static function decrypt( $payload ) {
		if ( ! is_string( $payload ) || '' === $payload ) {
			return false;
		}

		// Require the exact version marker (rejects legacy CBC blobs).
		$prefix_len = strlen( self::VERSION_PREFIX );
		if ( 0 !== strncmp( $payload, self::VERSION_PREFIX, $prefix_len ) ) {
			return false;
		}

		$b64  = substr( $payload, $prefix_len );
		$data = base64_decode( $b64, true ); // Strict mode.

		if ( false === $data ) {
			return false;
		}

		$min_length = self::IV_LENGTH + self::TAG_LENGTH;
		if ( strlen( $data ) <= $min_length ) {
			return false;
		}

		$key = self::get_key();
		if ( false === $key ) {
			return false;
		}

		$iv         = substr( $data, 0, self::IV_LENGTH );
		$tag        = substr( $data, self::IV_LENGTH, self::TAG_LENGTH );
		$ciphertext = substr( $data, self::IV_LENGTH + self::TAG_LENGTH );

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		// openssl_decrypt returns false if the GCM tag fails to authenticate.
		if ( false === $plaintext ) {
			return false;
		}

		return $plaintext;
	}

	/**
	 * Detect whether a stored value is a v1 GCM payload.
	 *
	 * @param string $payload Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $payload ) {
		return is_string( $payload )
			&& 0 === strncmp( $payload, self::VERSION_PREFIX, strlen( self::VERSION_PREFIX ) );
	}

	/**
	 * Resolve the 32-byte (256-bit) encryption key.
	 *
	 * @return string|false Raw 32-byte key, or false if none can be derived.
	 */
	private static function get_key() {
		// 1. Explicit application key from wp-config.php.
		if ( defined( 'MARQIRA_SECRET_KEY' ) && is_string( MARQIRA_SECRET_KEY ) && '' !== MARQIRA_SECRET_KEY ) {
			$raw     = MARQIRA_SECRET_KEY;
			$decoded = base64_decode( $raw, true );

			if ( false !== $decoded && 32 === strlen( $decoded ) ) {
				return $decoded;
			}

			// Not a base64 32-byte key — derive a 32-byte key deterministically.
			return hash( 'sha256', $raw, true );
		}

		// 2. Fallback: derive from WordPress salts.
		$salts = array(
			defined( 'AUTH_KEY' ) ? AUTH_KEY : '',
			defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '',
			defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '',
			defined( 'NONCE_KEY' ) ? NONCE_KEY : '',
		);

		$salt = implode( '|', $salts );

		// Fail closed if no key material is available at all.
		if ( '' === trim( $salt, '|' ) ) {
			return false;
		}

		return hash( 'sha256', $salt, true );
	}

	/**
	 * Generate a cryptographically secure random IV/nonce.
	 *
	 * @return string|false
	 */
	private static function random_iv() {
		try {
			return random_bytes( self::IV_LENGTH );
		} catch ( \Exception $e ) {
			// CSPRNG unavailable — fail closed rather than use a weak IV.
			return false;
		}
	}
}
