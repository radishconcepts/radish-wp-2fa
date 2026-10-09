<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor;

use RadishConcepts\TwoFactor\Admin\Reset;
use RadishConcepts\TwoFactor\Admin\SelfManage;
use RadishConcepts\TwoFactor\Admin\Settings;
use RadishConcepts\TwoFactor\Auth\ApiLogin;
use RadishConcepts\TwoFactor\Auth\Enforcement;
use RadishConcepts\TwoFactor\Auth\LoginInterceptor;
use RadishConcepts\TwoFactor\Auth\SessionGuard;
use RadishConcepts\TwoFactor\Cli\Commands;
use RadishConcepts\TwoFactor\Frontend\Controller;
use RadishConcepts\TwoFactor\Security\AttemptLimit;

final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'radish-2fa', false, dirname( plugin_basename( RADISH_2FA_FILE ) ) . '/languages' );

		Settings::instance()->register();
		LoginInterceptor::instance()->register();
		ApiLogin::instance()->register();
		Controller::instance()->register();
		Enforcement::instance()->register();
		SessionGuard::instance()->register();
		AttemptLimit::instance()->register();
		Reset::instance()->register();
		SelfManage::instance()->register();
		Commands::instance()->register();
	}

	private function __construct() {}
}
