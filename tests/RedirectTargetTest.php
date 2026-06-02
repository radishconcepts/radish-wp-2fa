<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Auth\RedirectTarget;

/**
 * Regression coverage for the front-end white-screen bug: a login initiated
 * from WooCommerce My Account (which omits `redirect_to`) produced an empty
 * redirect target, and wp_safe_redirect( '' ) emitted no Location header,
 * leaving the logged-in user on a blank page.
 */
final class RedirectTargetTest extends TestCase {

	public function test_honours_redirect_to_when_present(): void {
		$target = RedirectTarget::resolve(
			[ 'redirect_to' => 'https://example.test/wp-admin/profile.php' ],
			true
		);

		$this->assertSame( 'https://example.test/wp-admin/profile.php', $target );
	}

	public function test_falls_back_to_woocommerce_redirect_field(): void {
		$target = RedirectTarget::resolve(
			[ 'redirect' => 'https://example.test/mijn-account/' ],
			false
		);

		$this->assertSame( 'https://example.test/mijn-account/', $target );
	}

	public function test_redirect_to_takes_priority_over_redirect(): void {
		$target = RedirectTarget::resolve(
			[
				'redirect_to' => 'https://example.test/wp-admin/',
				'redirect'    => 'https://example.test/mijn-account/',
			],
			true
		);

		$this->assertSame( 'https://example.test/wp-admin/', $target );
	}

	public function test_frontend_login_without_target_defaults_to_home(): void {
		$target = RedirectTarget::resolve( [], false );

		$this->assertNotSame( '', $target );
		$this->assertSame( 'https://example.test/', $target );
	}

	public function test_wp_login_without_target_defaults_to_admin(): void {
		$target = RedirectTarget::resolve( [], true );

		$this->assertSame( 'https://example.test/wp-admin/', $target );
	}

	public function test_empty_redirect_to_never_leaks_an_empty_string(): void {
		// The exact regression: an empty redirect_to must not pass through.
		$target = RedirectTarget::resolve( [ 'redirect_to' => '' ], false );

		$this->assertNotSame( '', $target );
		$this->assertSame( 'https://example.test/', $target );
	}

	public function test_disallowed_host_is_rejected(): void {
		$target = RedirectTarget::resolve(
			[ 'redirect_to' => 'https://evil.example.com/phish' ],
			false
		);

		$this->assertSame( 'https://example.test/', $target );
	}

	public function test_ensure_replaces_empty_target_with_home(): void {
		$this->assertSame( 'https://example.test/', RedirectTarget::ensure( '' ) );
	}

	public function test_ensure_keeps_a_valid_target(): void {
		$this->assertSame(
			'https://example.test/wp-admin/',
			RedirectTarget::ensure( 'https://example.test/wp-admin/' )
		);
	}

	public function test_ensure_rejects_offsite_target(): void {
		$this->assertSame( 'https://example.test/', RedirectTarget::ensure( 'https://evil.example.com/' ) );
	}
}
