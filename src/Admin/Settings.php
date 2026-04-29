<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Admin;

use RadishConcepts\TwoFactor\Methods\Method;
use RadishConcepts\TwoFactor\Storage\UserMeta;
use WP_Roles;
use WP_Session_Tokens;

final class Settings {

	public const OPTION_KEY = 'radish_2fa_settings';
	public const PAGE_SLUG  = 'radish-2fa';
	public const NONCE_KEY  = 'radish_2fa_settings_nonce';

	private const DEFAULTS = [
		'enforced_roles'       => [],
		'enforce_super_admins' => true,
		'enabled_methods'      => [ Method::TOTP ],
	];

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', [ $this, 'add_menu_network' ] );
			add_action( 'admin_post_radish_2fa_save_settings', [ $this, 'handle_save' ] );
		} else {
			add_action( 'admin_menu', [ $this, 'add_menu_site' ] );
			add_action( 'admin_post_radish_2fa_save_settings', [ $this, 'handle_save' ] );
		}
	}

	public function add_menu_network(): void {
		add_submenu_page(
			'settings.php',
			__( 'Radish 2FA', 'radish-2fa' ),
			__( 'Radish 2FA', 'radish-2fa' ),
			'manage_network_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function add_menu_site(): void {
		add_options_page(
			__( 'Radish 2FA', 'radish-2fa' ),
			__( 'Radish 2FA', 'radish-2fa' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'radish-2fa' ), 403 );
		}

		$settings = $this->get();
		$roles    = $this->get_all_roles();
		$saved    = isset( $_GET['updated'] ) && '1' === $_GET['updated'];

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Radish 2FA', 'radish-2fa' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'radish-2fa' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $this->form_action_url() ); ?>">
				<?php wp_nonce_field( self::NONCE_KEY ); ?>
				<input type="hidden" name="action" value="radish_2fa_save_settings">

				<h2><?php esc_html_e( 'Authentication methods', 'radish-2fa' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Choose which two-factor methods users can pick from. Authenticator app is always available; email is opt-in.', 'radish-2fa' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Methods', 'radish-2fa' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Methods', 'radish-2fa' ); ?></legend>
								<label style="display:block; margin-bottom:.4em;">
									<input type="checkbox" checked disabled>
									<?php esc_html_e( 'Authenticator app (TOTP)', 'radish-2fa' ); ?>
									<code style="margin-left:.4em; font-size:.85em; color:#666;">totp</code>
									<span class="description" style="display:block; margin-left:1.6em;"><?php esc_html_e( 'Always available.', 'radish-2fa' ); ?></span>
								</label>
								<label style="display:block; margin-bottom:.4em;">
									<input type="checkbox" name="enabled_methods[]" value="<?php echo esc_attr( Method::EMAIL ); ?>" <?php checked( in_array( Method::EMAIL, $settings['enabled_methods'], true ) ); ?>>
									<?php esc_html_e( 'Email', 'radish-2fa' ); ?>
									<code style="margin-left:.4em; font-size:.85em; color:#666;">email</code>
									<span class="description" style="display:block; margin-left:1.6em;"><?php esc_html_e( 'Send a one-time code to the user’s WordPress profile email.', 'radish-2fa' ); ?></span>
								</label>
							</fieldset>
						</td>
					</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Enforced roles', 'radish-2fa' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Users with a checked role must set up two-factor authentication before they can access the site.', 'radish-2fa' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Roles', 'radish-2fa' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Roles', 'radish-2fa' ); ?></legend>
								<?php foreach ( $roles as $role_key => $role_name ) : ?>
									<label style="display:block; margin-bottom:.4em;">
										<input type="checkbox" name="enforced_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $settings['enforced_roles'], true ) ); ?>>
										<?php echo esc_html( $role_name ); ?>
										<code style="margin-left:.4em; font-size:.85em; color:#666;"><?php echo esc_html( $role_key ); ?></code>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>

					<?php if ( is_multisite() ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Super admins', 'radish-2fa' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enforce_super_admins" value="1" <?php checked( $settings['enforce_super_admins'] ); ?>>
									<?php esc_html_e( 'Require 2FA for all super admins (strongly recommended)', 'radish-2fa' ); ?>
								</label>
							</td>
						</tr>
					<?php endif; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save', 'radish-2fa' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! $this->current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'radish-2fa' ), 403 );
		}

		check_admin_referer( self::NONCE_KEY );

		$enforced_roles = isset( $_POST['enforced_roles'] ) && is_array( $_POST['enforced_roles'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['enforced_roles'] ) )
			: [];

		$valid_role_keys = array_keys( $this->get_all_roles() );
		$enforced_roles  = array_values( array_intersect( $enforced_roles, $valid_role_keys ) );

		$enforce_super_admins = ! empty( $_POST['enforce_super_admins'] );

		$enabled_methods = isset( $_POST['enabled_methods'] ) && is_array( $_POST['enabled_methods'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['enabled_methods'] ) )
			: [];
		$enabled_methods = array_values( array_intersect( $enabled_methods, Method::all() ) );
		// TOTP is always available — guarantee it stays in the list.
		if ( ! in_array( Method::TOTP, $enabled_methods, true ) ) {
			array_unshift( $enabled_methods, Method::TOTP );
		}

		$old = $this->get();
		$new = [
			'enforced_roles'       => $enforced_roles,
			'enforce_super_admins' => $enforce_super_admins,
			'enabled_methods'      => $enabled_methods,
		];

		$this->update( $new );

		$this->kill_sessions_for_newly_enforced( $old, $new );
		$this->kill_sessions_for_disabled_methods( $old, $new );

		wp_safe_redirect( add_query_arg( 'updated', '1', $this->settings_page_url() ) );
		exit;
	}

	/**
	 * When enforcement scope grows, destroy active sessions for affected users
	 * so their next request gets caught by Enforcement and redirected to setup.
	 */
	private function kill_sessions_for_newly_enforced( array $old, array $new ): void {
		$user_ids = [];

		if ( is_multisite() && ! empty( $new['enforce_super_admins'] ) && empty( $old['enforce_super_admins'] ) ) {
			foreach ( get_super_admins() as $login ) {
				$user = get_user_by( 'login', $login );
				if ( $user ) {
					$user_ids[ $user->ID ] = true;
				}
			}
		}

		$added_roles = array_diff( $new['enforced_roles'] ?? [], $old['enforced_roles'] ?? [] );
		if ( ! empty( $added_roles ) ) {
			$sites = is_multisite()
				? get_sites( [ 'number' => 0 ] )
				: [ (object) [ 'blog_id' => get_current_blog_id() ] ];

			foreach ( $sites as $site ) {
				if ( is_multisite() ) {
					switch_to_blog( (int) $site->blog_id );
				}

				foreach ( $added_roles as $role ) {
					$ids = get_users( [
						'role'   => $role,
						'fields' => 'ID',
						'number' => -1,
					] );
					foreach ( $ids as $id ) {
						$user_ids[ (int) $id ] = true;
					}
				}

				if ( is_multisite() ) {
					restore_current_blog();
				}
			}
		}

		foreach ( array_keys( $user_ids ) as $user_id ) {
			WP_Session_Tokens::get_instance( (int) $user_id )->destroy_all();
		}
	}

	/**
	 * Kill sessions for users enrolled in a method that was just disabled, so
	 * their next request lands on the setup chooser with only the still-allowed
	 * methods.
	 */
	private function kill_sessions_for_disabled_methods( array $old, array $new ): void {
		$removed = array_diff( (array) ( $old['enabled_methods'] ?? [] ), (array) ( $new['enabled_methods'] ?? [] ) );
		if ( empty( $removed ) ) {
			return;
		}

		$sites = is_multisite()
			? get_sites( [ 'number' => 0 ] )
			: [ (object) [ 'blog_id' => get_current_blog_id() ] ];

		foreach ( $sites as $site ) {
			if ( is_multisite() ) {
				switch_to_blog( (int) $site->blog_id );
			}

			$ids = get_users( [
				'fields'      => 'ID',
				'number'      => -1,
				'meta_key'    => UserMeta::META_METHOD,
				'meta_value'  => $removed,
				'meta_compare' => 'IN',
			] );

			foreach ( $ids as $id ) {
				WP_Session_Tokens::get_instance( (int) $id )->destroy_all();
			}

			if ( is_multisite() ) {
				restore_current_blog();
			}
		}
	}

	public function enabled_methods(): array {
		$settings = $this->get();
		$methods  = (array) ( $settings['enabled_methods'] ?? [] );

		return array_values( array_intersect( $methods, Method::all() ) ) ?: [ Method::TOTP ];
	}

	public function is_method_enabled( string $method ): bool {
		return in_array( $method, $this->enabled_methods(), true );
	}

	public function get(): array {
		$saved = is_multisite()
			? get_site_option( self::OPTION_KEY, [] )
			: get_option( self::OPTION_KEY, [] );

		return wp_parse_args( is_array( $saved ) ? $saved : [], self::DEFAULTS );
	}

	private function update( array $settings ): void {
		$merged = wp_parse_args( $settings, self::DEFAULTS );

		if ( is_multisite() ) {
			update_site_option( self::OPTION_KEY, $merged );
		} else {
			update_option( self::OPTION_KEY, $merged, false );
		}
	}

	private function current_user_can_manage(): bool {
		return is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
	}

	private function form_action_url(): string {
		return admin_url( 'admin-post.php' );
	}

	private function settings_page_url(): string {
		$base = is_multisite()
			? network_admin_url( 'settings.php' )
			: admin_url( 'options-general.php' );

		return add_query_arg( 'page', self::PAGE_SLUG, $base );
	}

	/**
	 * @return array<string,string> role-key => label
	 */
	private function get_all_roles(): array {
		$wp_roles = wp_roles();
		if ( ! $wp_roles instanceof WP_Roles ) {
			return [];
		}

		$out = [];
		foreach ( $wp_roles->roles as $key => $role ) {
			$out[ $key ] = translate_user_role( $role['name'] ?? $key );
		}

		return $out;
	}
}
