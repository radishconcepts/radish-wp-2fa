<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Security\Crypto;

final class CryptoTest extends TestCase {

	public function test_encrypt_decrypt_round_trip(): void {
		$plain = 'JBSWY3DPEHPK3PXP';

		$cipher = Crypto::encrypt( $plain );
		self::assertSame( $plain, Crypto::decrypt( $cipher ) );
	}

	public function test_two_encryptions_produce_different_ciphertexts(): void {
		$plain = 'JBSWY3DPEHPK3PXP';

		self::assertNotSame( Crypto::encrypt( $plain ), Crypto::encrypt( $plain ), 'Nonce must randomise ciphertext' );
	}

	public function test_decrypt_returns_null_for_garbage(): void {
		self::assertNull( Crypto::decrypt( 'not-a-payload' ) );
		self::assertNull( Crypto::decrypt( '' ) );
	}

	public function test_decrypt_returns_null_for_unknown_version(): void {
		self::assertNull( Crypto::decrypt( 'r2favOLD:abcdef' ) );
	}

	public function test_decrypt_returns_null_when_payload_is_truncated(): void {
		$cipher = Crypto::encrypt( 'secret' );
		// Tamper: cut the last 10 chars.
		self::assertNull( Crypto::decrypt( substr( $cipher, 0, -10 ) ) );
	}

	public function test_decrypt_returns_null_when_payload_is_tampered(): void {
		$cipher = Crypto::encrypt( 'secret' );
		// Flip a single byte in the base64 part.
		$tampered = substr_replace( $cipher, $cipher[ -3 ] === 'A' ? 'B' : 'A', -3, 1 );
		self::assertNull( Crypto::decrypt( $tampered ) );
	}
}
