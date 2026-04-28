<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Admin;

use RadishConcepts\TwoFactor\Storage\UserMeta;
use WP_Session_Tokens;
use WP_User;

/**
 * Adds a two-factor section to the user-edit screen so super admins (or admins
 * with edit_users on single-site) can reset another user's 2FA. The reset
 * wipes the secret + backup codes and destroys all sessions for that user.
 *
 * Self-reset is intentionally NOT exposed here — use WP-CLI for that.
 */
final class Reset {

	private const NONCE_ACTION    = 'radish_2fa_reset_user';
	private const POST_ACTION     = 'radish_2fa_reset_user';
	private const FLAG_QUERY_ARG  = 'radish_2fa_reset';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'edit_user_profile', [ $this, 'render_section' ] );
		add_action( 'admin_post_' . self::POST_ACTION, [ $this, 'handle_reset' ] );
	}

	public function render_section( WP_User $user ): void {
		if ( ! $this->current_user_can_reset() ) {
			return;
		}

		$is_enrolled = UserMeta::instance()->is_enrolled( $user->ID );
		$codes_left  = UserMeta::instance()->count_backup_codes( $user->ID );
		$enrolled_at = (int) get_user_meta( $user->ID, UserMeta::META_ENROLLED_AT, true );
		$last_used   = (int) get_user_meta( $user->ID, UserMeta::META_LAST_USED_AT, true );
		$just_reset  = ! empty( $_GET[ self::FLAG_QUERY_ARG ] );

		?>
		<h2><?php esc_html_e( 'Two-factor authentication', 'radish-2fa' ); ?></h2>

		<?php if ( $just_reset ) : ?>
			<div class="notice notice-success inline">
				<p><?php esc_html_e( 'Two-factor authentication has been reset. The user must set it up again on next login.', 'radish-2fa' ); ?></p>
			</div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'radish-2fa' ); ?></th>
				<td>
					<?php if ( $is_enrolled ) : ?>
						<p><strong style="color:#1a7f37;"><?php esc_html_e( 'Active', 'radish-2fa' ); ?></strong></p>
						<p class="description">
							<?php if ( $enrolled_at > 0 ) : ?>
								<?php
								printf(
									/* translators: %s: date */
									esc_html__( 'Set up on %s.', 'radish-2fa' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $enrolled_at ) )
								);
								?>
								<br>
							<?php endif; ?>
							<?php
							echo esc_html( sprintf(
								/* translators: %d: number of backup codes */
								_n( '%d backup code available.', '%d backup codes available.', $codes_left, 'radish-2fa' ),
								$codes_left
							) );
							?>
							<?php if ( $last_used > 0 ) : ?>
								<br>
								<?php
								printf(
									/* translators: %s: date/time */
									esc_html__( 'Last used: %s.', 'radish-2fa' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_used ) )
								);
								?>
							<?php endif; ?>
						</p>
					<?php else : ?>
						<p><em><?php esc_html_e( 'Not set up.', 'radish-2fa' ); ?></em></p>
					<?php endif; ?>
				</td>
			</tr>

			<?php if ( $is_enrolled ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reset', 'radish-2fa' ); ?></th>
					<td>
						<?php
						$reset_url = wp_nonce_url(
							add_query_arg(
								[
									'action'  => self::POST_ACTION,
									'user_id' => $user->ID,
								],
								admin_url( 'admin-post.php' )
							),
							self::NONCE_ACTION
						);
						?>
						<a href="<?php echo esc_url( $reset_url ); ?>"
							class="button button-secondary"
							onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to reset 2FA for this user? All sessions will be terminated and the user must set up 2FA again on next login.', 'radish-2fa' ) ); ?>');">
							<?php esc_html_e( 'Reset two-factor authentication', 'radish-2fa' ); ?>
						</a>
						<p class="description">
							<?php esc_html_e( 'Wipes the TOTP secret and backup codes and terminates all active sessions for this user.', 'radish-2fa' ); ?>
						</p>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	public function handle_reset(): void {
		if ( ! $this->current_user_can_reset() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'radish-2fa' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$user_id = isset( $_REQUEST['user_id'] ) ? (int) $_REQUEST['user_id'] : 0;
		$user    = $user_id > 0 ? get_userdata( $user_id ) : null;

		if ( ! $user instanceof WP_User ) {
			wp_die( esc_html__( 'Invalid user.', 'radish-2fa' ), 400 );
		}

		UserMeta::instance()->clear( $user->ID );
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();

		$redirect = add_query_arg( self::FLAG_QUERY_ARG, '1', get_edit_user_link( $user->ID ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	private function current_user_can_reset(): bool {
		return is_multisite() ? is_super_admin() : current_user_can( 'edit_users' );
	}

	private function __construct() {}
}
