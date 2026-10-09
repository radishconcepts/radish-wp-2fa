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
 *
 * Since 0.3.0 it also hooks on `admin_init`, where no 2FA page can be served,
 * and on `rest_authentication_errors` for cookie-authenticated REST requests.
 */
final class EnforcementTest extends TestCase {

	protected function setUp(): void {
		global $rt2fa_test_actions;
		$rt2fa_test_actions = [];
	}

	public function test_register_hooks_on_template_redirect_and_admin_init_not_init(): void {
		Enforcement::instance()->register();

		$hooks = array_column( $this->registered(), 'hook' );

		self::assertSame( [ 'template_redirect', 'admin_init', 'rest_authentication_errors' ], $hooks );
		self::assertNotContains( 'init', $hooks );
	}

	public function test_rest_check_runs_after_core_cookie_check(): void {
		Enforcement::instance()->register();

		$rest = $this->registered()[2];

		self::assertSame( 'block_unverified_rest', $rest['callback'][1] );
		self::assertGreaterThan( 100, $rest['priority'], 'Core validates the REST cookie nonce at priority 100.' );
	}

	public function test_register_priority_runs_after_controller_route(): void {
		Enforcement::instance()->register();

		self::assertGreaterThan(
			10,
			$this->registered()[0]['priority'],
			'Enforcement must run after Frontend\\Controller::route (template_redirect priority 10) so route() can exit on 2FA URLs before Enforcement triggers a redirect.'
		);
	}

	public function test_both_page_hooks_target_enforce(): void {
		Enforcement::instance()->register();

		foreach ( array_slice( $this->registered(), 0, 2 ) as $action ) {
			self::assertIsArray( $action['callback'] );
			self::assertInstanceOf( Enforcement::class, $action['callback'][0] );
			self::assertSame( 'enforce', $action['callback'][1] );
		}
	}

	/**
	 * @return array<int,array{hook:string,callback:mixed,priority:int}>
	 */
	private function registered(): array {
		global $rt2fa_test_actions;

		return $rt2fa_test_actions;
	}
}
