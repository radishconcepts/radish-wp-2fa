<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Security\BackupCodes;

final class BackupCodesTest extends TestCase {

	public function test_generate_returns_correct_count(): void {
		$codes = BackupCodes::instance()->generate( 10 );

		self::assertCount( 10, $codes );
	}

	public function test_generate_codes_have_dash_separated_format(): void {
		$codes = BackupCodes::instance()->generate( 5 );

		foreach ( $codes as $code ) {
			self::assertMatchesRegularExpression( '/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code );
		}
	}

	public function test_generate_codes_are_unique(): void {
		$codes = BackupCodes::instance()->generate( 20 );

		self::assertCount( 20, array_unique( $codes ) );
	}

	public function test_normalize_strips_separators_and_uppercases(): void {
		self::assertSame( 'ABCD1234', BackupCodes::normalize( 'abcd-1234' ) );
		self::assertSame( 'ABCD1234', BackupCodes::normalize( ' ab cd 12 34 ' ) );
		self::assertSame( '', BackupCodes::normalize( '' ) );
	}

	public function test_hash_all_produces_one_hash_per_code(): void {
		$plain  = [ 'AAAA-BBBB', 'CCCC-DDDD' ];
		$hashes = BackupCodes::instance()->hash_all( $plain );

		self::assertCount( 2, $hashes );
		foreach ( $hashes as $hash ) {
			self::assertNotEmpty( $hash );
			self::assertNotContains( $hash, $plain, 'Hash must not equal plaintext' );
		}
	}

	public function test_find_match_returns_index_for_matching_code(): void {
		$plain  = BackupCodes::instance()->generate( 5 );
		$hashes = BackupCodes::instance()->hash_all( $plain );

		$index = BackupCodes::instance()->find_match( $plain[2], $hashes );
		self::assertSame( 2, $index );
	}

	public function test_find_match_normalises_input(): void {
		$plain  = BackupCodes::instance()->generate( 3 );
		$hashes = BackupCodes::instance()->hash_all( $plain );

		// Strip dash + lowercase + add spaces — should still match.
		$mangled = strtolower( str_replace( '-', '  ', $plain[1] ) );

		self::assertSame( 1, BackupCodes::instance()->find_match( $mangled, $hashes ) );
	}

	public function test_find_match_returns_null_for_non_matching(): void {
		$plain  = BackupCodes::instance()->generate( 3 );
		$hashes = BackupCodes::instance()->hash_all( $plain );

		self::assertNull( BackupCodes::instance()->find_match( 'WRONG-CODE', $hashes ) );
		self::assertNull( BackupCodes::instance()->find_match( '', $hashes ) );
	}

	public function test_find_match_skips_empty_hashes(): void {
		$plain  = BackupCodes::instance()->generate( 2 );
		$hashes = BackupCodes::instance()->hash_all( $plain );
		// Insert an empty hash slot — should be skipped, not crash.
		array_splice( $hashes, 1, 0, [ '' ] );

		self::assertSame( 0, BackupCodes::instance()->find_match( $plain[0], $hashes ) );
		self::assertSame( 2, BackupCodes::instance()->find_match( $plain[1], $hashes ) );
	}
}
