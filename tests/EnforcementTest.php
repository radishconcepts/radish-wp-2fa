<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Tests;

use PHPUnit\Framework\TestCase;
use RadishConcepts\TwoFactor\Auth\Enforcement;

/**
 * Regression: Enforcement used to hook on `init` priority 999 and skip 2FA
 * pages with `get_query_var( Routes::QUERY_VAR )`. That check returns empty on
 * `init` (parse_query has not run yet), so any logged-in user with 2FA
 * enforcement looped on `/2fa/setup/` — each request created a fresh nonce and
 * redirected again ("redirected too many times" in the browser).
 *
 * The fix moves the hook to `template_redirect` priority 11 so:
 * 1. Query vars are parsed; the skip guard works.
 * 2. Frontend\Controller::route runs first (priority 10) and exits on 2FA URLs.
 */
final class EnforcementTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_actions;
		$rt2fa_test_actions = [];
	}

	public function test_register_hooks_on_template_redirect_not_init(): void {
		global $rt2fa_test_actions;

		Enforcement::instance()->register();

		self::assertCount( 1, $rt2fa_test_actions );
		self::assertSame( 'template_redirect', $rt2fa_test_actions[0]['hook'] );
		self::assertNotSame( 'init', $rt2fa_test_actions[0]['hook'] );
	}

	public function test_register_priority_runs_after_controller_route(): void {
		global $rt2fa_test_actions;

		Enforcement::instance()->register();

		self::assertGreaterThan(
			10,
			$rt2fa_test_actions[0]['priority'],
			'Enforcement must run after Frontend\\Controller::route (template_redirect priority 10) so route() can exit on 2FA URLs before Enforcement triggers a redirect.'
		);
	}

	public function test_register_callback_targets_maybe_force_setup(): void {
		global $rt2fa_test_actions;

		Enforcement::instance()->register();

		$callback = $rt2fa_test_actions[0]['callback'];
		self::assertIsArray( $callback );
		self::assertInstanceOf( Enforcement::class, $callback[0] );
		self::assertSame( 'maybe_force_setup', $callback[1] );
	}
}
