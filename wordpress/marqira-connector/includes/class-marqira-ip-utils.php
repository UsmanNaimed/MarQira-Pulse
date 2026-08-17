<?php
/**
 * IP and CIDR utility helpers.
 *
 * @package Marqira_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Marqira_IP_Utils
 *
 * Provides static helpers for validating and comparing IPv4/IPv6
 * addresses and CIDR ranges using binary comparison.
 */
class Marqira_IP_Utils {

	/**
	 * Normalize an IP address string.
	 *
	 * Strips IPv6 brackets and handles IPv4-mapped IPv6 addresses
	 * (e.g. ::ffff:192.0.2.1 becomes 192.0.2.1).
	 *
	 * @param string $ip Raw IP address.
	 * @return string|false Normalized IP address, or false if invalid.
	 */
	public static function normalize( $ip ) {
		if ( ! is_string( $ip ) ) {
			return false;
		}

		$ip = trim( $ip );

		if ( '' === $ip ) {
			return false;
		}

		// Strip IPv6 brackets, e.g. [::1] -> ::1.
		if ( isset( $ip[0] ) && '[' === $ip[0] ) {
			$close = strpos( $ip, ']' );
			if ( false !== $close ) {
				$ip = substr( $ip, 1, $close - 1 );
			}
		}

		// Strip a trailing zone id, e.g. fe80::1%eth0 -> fe80::1.
		$percent = strpos( $ip, '%' );
		if ( false !== $percent ) {
			$ip = substr( $ip, 0, $percent );
		}

		// Handle IPv4-mapped IPv6 addresses (::ffff:192.0.2.1).
		if ( false !== stripos( $ip, '::ffff:' ) ) {
			$candidate = substr( $ip, strripos( $ip, ':' ) + 1 );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$ip = $candidate;
			}
		}

		if ( ! self::is_valid( $ip ) ) {
			return false;
		}

		return $ip;
	}

	/**
	 * Canonicalize an untrusted "IP-ish" string into a valid IP address.
	 *
	 * Handles the messy values seen in real-world $_SERVER entries and proxy
	 * headers, then delegates final validation to normalize():
	 *   - surrounding whitespace       "  192.0.2.1 "        -> 192.0.2.1
	 *   - comma-separated proxy list   "203.0.113.1, 70.0.0.1" -> 203.0.113.1
	 *   - IPv4 with port               "203.0.113.1:443"     -> 203.0.113.1
	 *   - bracketed IPv6               "[2001:db8::1]"        -> 2001:db8::1
	 *   - bracketed IPv6 with port     "[2001:db8::1]:443"    -> 2001:db8::1
	 *   - IPv6 zone id                 "fe80::1%eth0"         -> fe80::1
	 *   - IPv4-mapped IPv6             "::ffff:192.0.2.1"     -> 192.0.2.1
	 *
	 * Rejects hostnames, empty strings, the "unknown" sentinel, and anything
	 * that is not a syntactically valid IP after cleanup (returns false).
	 *
	 * @param string $raw Raw IP-ish value.
	 * @return string|false Normalized IP address, or false if not a valid IP.
	 */
	public static function sanitize_ip( $raw ) {
		if ( ! is_string( $raw ) ) {
			return false;
		}

		$value = trim( $raw );

		if ( '' === $value ) {
			return false;
		}

		// Take the first entry from a comma-separated proxy list.
		if ( false !== strpos( $value, ',' ) ) {
			$parts = explode( ',', $value );
			$value = trim( $parts[0] );

			if ( '' === $value ) {
				return false;
			}
		}

		if ( isset( $value[0] ) && '[' === $value[0] ) {
			// Bracketed IPv6, optionally with a trailing port: [::1]:443 -> ::1.
			$close = strpos( $value, ']' );
			if ( false !== $close ) {
				$value = substr( $value, 1, $close - 1 );
			}
		} elseif ( 1 === substr_count( $value, ':' ) ) {
			// Exactly one colon means IPv4:port or host:port. A bare IPv6
			// address always has multiple colons, so a single colon can only
			// be a port suffix that must be stripped.
			$value = substr( $value, 0, strpos( $value, ':' ) );
		}

		// Delegate zone-id / IPv4-mapped handling + final validation.
		return self::normalize( $value );
	}

	/**
	 * Determine whether a string is a valid IP address.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_valid( $ip ) {
		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * Determine whether a string is a valid IPv4 address.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_ipv4( $ip ) {
		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	}

	/**
	 * Determine whether a string is a valid IPv6 address.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_ipv6( $ip ) {
		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
	}

	/**
	 * Determine whether an IP address falls within a CIDR range.
	 *
	 * Works for both IPv4 and IPv6 using inet_pton and bitwise comparison.
	 *
	 * @param string $ip   IP address to test.
	 * @param string $cidr CIDR range (e.g. 192.168.1.0/24 or 2400:cb00::/32).
	 * @return bool
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		$ip = self::normalize( $ip );
		if ( false === $ip ) {
			return false;
		}

		$cidr = is_string( $cidr ) ? trim( $cidr ) : '';
		if ( '' === $cidr ) {
			return false;
		}

		// A plain IP without a mask is treated as an exact match.
		if ( false === strpos( $cidr, '/' ) ) {
			$subnet_ip = self::normalize( $cidr );
			if ( false === $subnet_ip ) {
				return false;
			}
			return self::binary_equal( $ip, $subnet_ip );
		}

		list( $subnet, $mask_bits ) = explode( '/', $cidr, 2 );

		$subnet = self::normalize( $subnet );
		if ( false === $subnet ) {
			return false;
		}

		if ( '' === $mask_bits || ! ctype_digit( (string) $mask_bits ) ) {
			return false;
		}

		$mask_bits = (int) $mask_bits;

		$ip_bin     = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$subnet_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}

		// IP and subnet must be the same address family (same byte length).
		if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$max_bits = strlen( $ip_bin ) * 8;
		if ( $mask_bits < 0 || $mask_bits > $max_bits ) {
			return false;
		}

		if ( 0 === $mask_bits ) {
			return true;
		}

		$mask = self::build_binary_mask( strlen( $ip_bin ), $mask_bits );

		return ( $ip_bin & $mask ) === ( $subnet_bin & $mask );
	}

	/**
	 * Determine whether an IP matches any entry in a list.
	 *
	 * Each entry may be a plain IP address or a CIDR range.
	 *
	 * @param string $ip   IP address to test.
	 * @param array  $list List of IPs and/or CIDR ranges.
	 * @return bool
	 */
	public static function ip_in_list( $ip, $list ) {
		if ( ! is_array( $list ) || empty( $list ) ) {
			return false;
		}

		$ip = self::normalize( $ip );
		if ( false === $ip ) {
			return false;
		}

		foreach ( $list as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}

			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}

			if ( false !== strpos( $entry, '/' ) ) {
				if ( self::ip_in_cidr( $ip, $entry ) ) {
					return true;
				}
				continue;
			}

			$normalized_entry = self::normalize( $entry );
			if ( false !== $normalized_entry && self::binary_equal( $ip, $normalized_entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse a raw newline/comma separated list of IPs and CIDRs.
	 *
	 * Skips blank lines and comments beginning with '#'. Returns only
	 * valid IP addresses and CIDR ranges.
	 *
	 * @param string $raw Raw list.
	 * @return array List of valid IPs/CIDR entries.
	 */
	public static function parse_ip_list( $raw ) {
		if ( ! is_string( $raw ) ) {
			return array();
		}

		$normalized = str_replace( array( "\r\n", "\r", ',' ), "\n", $raw );
		$lines      = explode( "\n", $normalized );
		$result     = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}

			// Strip inline comments.
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = trim( substr( $line, 0, $hash ) );
			}

			if ( '' === $line ) {
				continue;
			}

			if ( false !== strpos( $line, '/' ) ) {
				if ( self::is_valid_cidr( $line ) ) {
					$result[] = $line;
				}
				continue;
			}

			$normalized_ip = self::normalize( $line );
			if ( false !== $normalized_ip ) {
				$result[] = $normalized_ip;
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Validate a CIDR string.
	 *
	 * @param string $cidr CIDR range.
	 * @return bool
	 */
	public static function is_valid_cidr( $cidr ) {
		if ( ! is_string( $cidr ) || false === strpos( $cidr, '/' ) ) {
			return false;
		}

		list( $subnet, $mask_bits ) = explode( '/', $cidr, 2 );

		$subnet = self::normalize( $subnet );
		if ( false === $subnet ) {
			return false;
		}

		if ( '' === $mask_bits || ! ctype_digit( (string) $mask_bits ) ) {
			return false;
		}

		$mask_bits = (int) $mask_bits;
		$max_bits  = self::is_ipv6( $subnet ) ? 128 : 32;

		return $mask_bits >= 0 && $mask_bits <= $max_bits;
	}

	/**
	 * Compare two normalized IPs for binary equality.
	 *
	 * @param string $ip_a First IP.
	 * @param string $ip_b Second IP.
	 * @return bool
	 */
	private static function binary_equal( $ip_a, $ip_b ) {
		$bin_a = @inet_pton( $ip_a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$bin_b = @inet_pton( $ip_b ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $bin_a || false === $bin_b ) {
			return false;
		}

		return $bin_a === $bin_b;
	}

	/**
	 * Build a binary network mask.
	 *
	 * @param int $byte_length Total length in bytes (4 for IPv4, 16 for IPv6).
	 * @param int $mask_bits   Number of network bits.
	 * @return string Binary mask.
	 */
	private static function build_binary_mask( $byte_length, $mask_bits ) {
		$mask       = '';
		$full_bytes = intdiv( $mask_bits, 8 );
		$remainder  = $mask_bits % 8;

		for ( $i = 0; $i < $byte_length; $i++ ) {
			if ( $i < $full_bytes ) {
				$mask .= chr( 0xFF );
			} elseif ( $i === $full_bytes && $remainder > 0 ) {
				$mask .= chr( ( 0xFF << ( 8 - $remainder ) ) & 0xFF );
			} else {
				$mask .= chr( 0x00 );
			}
		}

		return $mask;
	}
}
