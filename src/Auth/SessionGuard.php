<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Auth;

use WP_Session_Tokens;
use WP_User;

/**
 * Records on the WordPress session that 2FA was passed, so a session that was
 * created any other way (another plugin calling wp_set_auth_cookie(), a session
 * from before the role was enforced, a future gap in the login check) can be
 * recognised and sent back to the challenge by Enforcement.
 *
 * A request can carry more than one login cookie (logged_in on the front end,
 * plus auth/secure_auth in wp-admin), each naming its own session. The request
 * only counts as verified when every cookie that WordPress validated for the
 * user points at a marked session.
 */
final class SessionGuard {

	public const SESSION_KEY = 'radish_2fa_verified_at';

	/** Sessions longer than this were created with "Remember me". */
	private const REMEMBER_THRESHOLD = 2 * DAY_IN_SECONDS;

	private static ?self $instance = null;

	/** @var array<int,string[]> Session tokens of cookies validated this request, per user. */
	private array $validated_tokens = [];

	/** @var array<int,bool> Per-request answer of request_is_verified(), per user. */
	private array $verified = [];

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'auth_cookie_valid', [ $this, 'capture_validated_token' ], 10, 2 );
		add_filter( 'attach_session_information', [ $this, 'carry_mark_over' ], 10, 2 );
	}

	/**
	 * Remember which session each validated login cookie belongs to.
	 *
	 * @param array<string,string> $cookie_elements Parsed cookie, incl. 'token'.
	 * @param WP_User|mixed        $user
	 */
	public function capture_validated_token( array $cookie_elements, $user ): void {
		$token = (string) ( $cookie_elements['token'] ?? '' );
		if ( '' === $token || ! $user instanceof WP_User ) {
			return;
		}

		$this->validated_tokens[ (int) $user->ID ][] = $token;
	}

	/**
	 * Whether every login cookie of this request points at a marked session.
	 * Remembered for the rest of the request, so a session destroyed later on
	 * (e.g. by a password change) doesn't change the answer.
	 */
	public function request_is_verified( WP_User $user ): bool {
		return $this->request_is_verified_for( (int) $user->ID );
	}

	/**
	 * Whether WordPress validated a login cookie of this user in this request,
	 * i.e. the user is logged in through a cookie rather than another mechanism.
	 */
	public function has_validated_cookie( WP_User $user ): bool {
		return [] !== ( $this->validated_tokens[ (int) $user->ID ] ?? [] );
	}

	/**
	 * A new session for a user whose current request is already verified keeps
	 * the mark. Core and WooCommerce replace the session when users change their
	 * own password; without this they'd be sent back to the challenge.
	 *
	 * @param array<string,mixed> $session
	 *
	 * @return array<string,mixed>
	 */
	public function carry_mark_over( array $session, int $user_id ): array {
		if ( self::is_verified( $session ) || ! $this->request_is_verified_for( $user_id ) ) {
			return $session;
		}

		return self::mark( $session );
	}

	/**
	 * Return a copy of the session marked as verified.
	 *
	 * @param array<string,mixed> $session
	 *
	 * @return array<string,mixed>
	 */
	public static function mark( array $session ): array {
		return array_merge( $session, [ self::SESSION_KEY => time() ] );
	}

	/**
	 * @param array<string,mixed>|null $session
	 */
	public static function is_verified( ?array $session ): bool {
		$verified_at = $session[ self::SESSION_KEY ] ?? null;

		return is_int( $verified_at ) && $verified_at > 0;
	}

	/**
	 * @param array<string,mixed> $session
	 */
	public static function was_remembered( array $session ): bool {
		$login      = (int) ( $session['login'] ?? 0 );
		$expiration = (int) ( $session['expiration'] ?? 0 );

		return $login > 0 && ( $expiration - $login ) > self::REMEMBER_THRESHOLD;
	}

	/**
	 * Log the user in with a session that carries the verification mark.
	 */
	public function set_verified_auth_cookie( WP_User $user, bool $remember ): void {
		$user_id = (int) $user->ID;
		$marker  = static function ( array $session, int $session_user_id ) use ( $user_id ): array {
			return $session_user_id === $user_id ? self::mark( $session ) : $session;
		};

		add_filter( 'attach_session_information', $marker, 10, 2 );
		wp_set_auth_cookie( $user_id, $remember, is_ssl() );
		remove_filter( 'attach_session_information', $marker, 10 );
	}

	/**
	 * The session behind the request's first login cookie, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function current_session( WP_User $user ): ?array {
		$tokens = $this->request_tokens( (int) $user->ID );
		if ( [] === $tokens ) {
			return null;
		}

		return WP_Session_Tokens::get_instance( $user->ID )->get( $tokens[0] );
	}

	public function destroy_current_session( WP_User $user ): void {
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		foreach ( $this->request_tokens( (int) $user->ID ) as $token ) {
			$manager->destroy( $token );
		}

		wp_clear_auth_cookie();
	}

	private function request_is_verified_for( int $user_id ): bool {
		return $this->verified[ $user_id ] ??= $this->all_sessions_verified( $user_id );
	}

	private function all_sessions_verified( int $user_id ): bool {
		$tokens = $this->request_tokens( $user_id );
		if ( [] === $tokens ) {
			return false;
		}

		$manager = WP_Session_Tokens::get_instance( $user_id );
		foreach ( $tokens as $token ) {
			if ( ! self::is_verified( $manager->get( $token ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tokens of the cookies validated this request. Falls back to the
	 * logged_in cookie when the user was resolved before this plugin booted.
	 *
	 * @return string[]
	 */
	private function request_tokens( int $user_id ): array {
		$tokens = $this->validated_tokens[ $user_id ] ?? [];
		if ( [] === $tokens ) {
			$fallback = wp_get_session_token();
			$tokens   = '' === $fallback ? [] : [ $fallback ];
		}

		return array_values( array_unique( $tokens ) );
	}

	private function __construct() {}
}
