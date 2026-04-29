<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Admin;

use RadishConcepts\TwoFactor\Methods\Method;
use RadishConcepts\TwoFactor\Storage\UserMeta;
use WP_Session_Tokens;
use WP_User;

/**
 * Self-service section on the user's own profile screen. Lets an enrolled user:
 *  - see which 2FA method is active
 *  - switch method via inline radio buttons; the change is queued and only
 *    takes effect at the next sign-in, so the current session never breaks
 *    mid-flow
 *  - reset their own 2FA (will force re-enrollment on next request)
 *
 * Admin-driven reset for *other* users lives in `Admin\Reset`.
 */
final class SelfManage {

	private const NONCE_RESET    = 'radish_2fa_self_reset';
	private const ACTION_RESET   = 'radish_2fa_self_reset';
	private const FLAG_QUERY_ARG = 'radish_2fa_self';
	private const FIELD_METHOD   = 'radish_2fa_method';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'render_section' ] );
		add_action( 'personal_options_update', [ $this, 'handle_profile_save' ] );
		add_action( 'admin_post_' . self::ACTION_RESET, [ $this, 'handle_reset' ] );
		// Priority 5 keeps us ahead of LoginInterceptor::redirect_to_2fa (PHP_INT_MAX),
		// so it sees the freshly cleared enrollment and routes the user to /2fa/setup.
		add_action( 'wp_login', [ $this, 'apply_pending_method_change' ], 5, 2 );
	}

	public function render_section( WP_User $user ): void {
		if ( get_current_user_id() !== $user->ID ) {
			return;
		}

		$is_enrolled    = UserMeta::instance()->is_enrolled( $user->ID );
		$current_method = UserMeta::instance()->get_method( $user->ID );
		$pending_method = $this->get_pending_method( $user->ID );
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

		<?php if ( $pending_method ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: human-readable label of the queued 2FA method */
						esc_html__( 'A method change to %s is queued. You will be asked to set it up at your next sign-in; your current session keeps working with the active method.', 'radish-2fa' ),
						'<strong>' . esc_html( Method::label( $pending_method ) ) . '</strong>'
					);
					?>
				</p>
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
						<td>
							<?php if ( $can_change ) : ?>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Verification method', 'radish-2fa' ); ?></legend>
									<?php $selected = $pending_method ?: $current_method; ?>
									<?php foreach ( $enabled as $method_id ) : ?>
										<label style="display:block; margin-bottom:.4em;">
											<input type="radio" name="<?php echo esc_attr( self::FIELD_METHOD ); ?>" value="<?php echo esc_attr( $method_id ); ?>" <?php checked( $selected, $method_id ); ?>>
											<?php echo esc_html( Method::label( $method_id ) ); ?>
											<?php if ( $method_id === $current_method ) : ?>
												<em style="color:#666; margin-left:.4em;"><?php esc_html_e( '(active)', 'radish-2fa' ); ?></em>
											<?php endif; ?>
										</label>
									<?php endforeach; ?>
									<p class="description">
										<?php esc_html_e( 'Pick a different method and save your profile to queue the change. The new method only takes effect on your next sign-in.', 'radish-2fa' ); ?>
									</p>
								</fieldset>
							<?php else : ?>
								<?php echo esc_html( Method::label( $current_method ) ); ?>
							<?php endif; ?>
						</td>
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

		<?php if ( $is_enrolled ) : ?>
			<p class="submit" style="padding-top:0;">
				<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', self::ACTION_RESET, admin_url( 'admin-post.php' ) ), self::NONCE_RESET ) ); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'This will turn off two-factor authentication for your account. You can set it up again afterwards. Continue?', 'radish-2fa' ) ); ?>');">
					<?php esc_html_e( 'Reset 2FA', 'radish-2fa' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	public function handle_profile_save( int $user_id ): void {
		if ( get_current_user_id() !== $user_id ) {
			return;
		}

		if ( ! isset( $_POST[ self::FIELD_METHOD ] ) ) {
			return;
		}

		if ( ! UserMeta::instance()->is_enrolled( $user_id ) ) {
			return;
		}

		$picked = sanitize_key( wp_unslash( (string) $_POST[ self::FIELD_METHOD ] ) );
		if ( ! Method::is_valid( $picked ) || ! Settings::instance()->is_method_enabled( $picked ) ) {
			return;
		}

		$current = UserMeta::instance()->get_method( $user_id );

		if ( $picked === $current ) {
			// Picking the active method again clears any queued change.
			delete_user_meta( $user_id, UserMeta::META_PENDING_METHOD );

			return;
		}

		update_user_meta( $user_id, UserMeta::META_PENDING_METHOD, $picked );
	}

	public function apply_pending_method_change( string $user_login, WP_User $user ): void {
		$pending = $this->get_pending_method( $user->ID );
		if ( null === $pending ) {
			delete_user_meta( $user->ID, UserMeta::META_PENDING_METHOD );

			return;
		}

		// Wipe current enrollment so LoginInterceptor (and Enforcement on later
		// requests) routes the user through the setup chooser, where they'll
		// pick and verify the new method.
		UserMeta::instance()->clear( $user->ID );
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

	private function get_pending_method( int $user_id ): ?string {
		$pending = get_user_meta( $user_id, UserMeta::META_PENDING_METHOD, true );
		if ( ! is_string( $pending ) || ! Method::is_valid( $pending ) ) {
			return null;
		}

		if ( ! Settings::instance()->is_method_enabled( $pending ) ) {
			return null;
		}

		return $pending;
	}

	private function __construct() {}
}
