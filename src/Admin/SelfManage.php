<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Admin;

use RadishConcepts\TwoFactor\Auth\Nonce;
use RadishConcepts\TwoFactor\Methods\Method;
use RadishConcepts\TwoFactor\Routes;
use RadishConcepts\TwoFactor\Storage\UserMeta;
use WP_Session_Tokens;
use WP_User;

/**
 * Self-service section on the user's own profile screen. Lets an enrolled user:
 *  - see which 2FA method is active
 *  - switch method (kicks off a fresh setup nonce; the old method stays active
 *    until the new one is verified, so abandoning halfway never locks the user out)
 *  - reset their own 2FA (will force re-enrollment on next request)
 *
 * Admin-driven reset for *other* users lives in `Admin\Reset`.
 */
final class SelfManage {

	private const NONCE_CHANGE = 'radish_2fa_self_change_method';
	private const NONCE_RESET  = 'radish_2fa_self_reset';
	private const ACTION_CHANGE = 'radish_2fa_change_method';
	private const ACTION_RESET  = 'radish_2fa_self_reset';
	private const FLAG_QUERY_ARG = 'radish_2fa_self';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'render_section' ] );
		add_action( 'admin_post_' . self::ACTION_CHANGE, [ $this, 'handle_change_method' ] );
		add_action( 'admin_post_' . self::ACTION_RESET, [ $this, 'handle_reset' ] );
	}

	public function render_section( WP_User $user ): void {
		if ( get_current_user_id() !== $user->ID ) {
			return;
		}

		$is_enrolled    = UserMeta::instance()->is_enrolled( $user->ID );
		$current_method = UserMeta::instance()->get_method( $user->ID );
		$enrolled_at    = (int) get_user_meta( $user->ID, UserMeta::META_ENROLLED_AT, true );
		$last_used      = (int) get_user_meta( $user->ID, UserMeta::META_LAST_USED_AT, true );
		$codes_left     = UserMeta::instance()->count_backup_codes( $user->ID );
		$enabled        = Settings::instance()->enabled_methods();
		$can_change     = $is_enrolled && count( $enabled ) > 1;

		$flag = isset( $_GET[ self::FLAG_QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::FLAG_QUERY_ARG ] ) ) : '';

		?>
		<h2><?php esc_html_e( 'Two-factor authentication', 'radish-2fa' ); ?></h2>

		<?php if ( 'reset' === $flag ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'Two-factor authentication has been reset. You will be asked to set it up again on your next sign-in.', 'radish-2fa' ); ?></p>
			</div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'radish-2fa' ); ?></th>
					<td>
						<?php if ( $is_enrolled ) : ?>
							<strong><?php esc_html_e( 'Enabled', 'radish-2fa' ); ?></strong>
						<?php else : ?>
							<em><?php esc_html_e( 'Not enabled — you will be asked to set it up at next sign-in if your role requires it.', 'radish-2fa' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>

				<?php if ( $is_enrolled && $current_method ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Method', 'radish-2fa' ); ?></th>
						<td><?php echo esc_html( Method::label( $current_method ) ); ?></td>
					</tr>
				<?php endif; ?>

				<?php if ( $is_enrolled && $enrolled_at ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enrolled at', 'radish-2fa' ); ?></th>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $enrolled_at ) ); ?></td>
					</tr>
				<?php endif; ?>

				<?php if ( $is_enrolled && $last_used ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last used', 'radish-2fa' ); ?></th>
						<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $last_used ) ); ?></td>
					</tr>
				<?php endif; ?>

				<?php if ( $is_enrolled ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Backup codes', 'radish-2fa' ); ?></th>
						<td><?php echo esc_html( (string) $codes_left ); ?> <?php esc_html_e( 'remaining', 'radish-2fa' ); ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $can_change ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<?php wp_nonce_field( self::NONCE_CHANGE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CHANGE ); ?>">
				<?php submit_button( __( 'Change method', 'radish-2fa' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<?php if ( $is_enrolled ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-left:.6em;" onsubmit="return confirm('<?php echo esc_js( __( 'This will turn off two-factor authentication for your account. You can set it up again afterwards. Continue?', 'radish-2fa' ) ); ?>');">
				<?php wp_nonce_field( self::NONCE_RESET ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESET ); ?>">
				<?php submit_button( __( 'Reset 2FA', 'radish-2fa' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	public function handle_change_method(): void {
		check_admin_referer( self::NONCE_CHANGE );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_die( esc_html__( 'You must be signed in.', 'radish-2fa' ), 403 );
		}

		$enabled = Settings::instance()->enabled_methods();
		if ( count( $enabled ) < 2 ) {
			wp_safe_redirect( self_admin_url( 'profile.php' ) );
			exit;
		}

		$token = Nonce::instance()->create(
			$user_id,
			Nonce::MODE_SETUP,
			self_admin_url( 'profile.php?' . self::FLAG_QUERY_ARG . '=changed' ),
			[ 'remember' => true ]
		);

		wp_safe_redirect( Routes::setup_url( $token ) );
		exit;
	}

	public function handle_reset(): void {
		check_admin_referer( self::NONCE_RESET );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_die( esc_html__( 'You must be signed in.', 'radish-2fa' ), 403 );
		}

		UserMeta::instance()->clear( $user_id );
		WP_Session_Tokens::get_instance( $user_id )->destroy_others( wp_get_session_token() );

		wp_safe_redirect( self_admin_url( 'profile.php?' . self::FLAG_QUERY_ARG . '=reset' ) );
		exit;
	}

	private function __construct() {}
}
