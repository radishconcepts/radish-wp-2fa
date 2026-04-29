<?php

declare( strict_types=1 );

namespace RadishConcepts\TwoFactor\Security;

use WP_User;

final class EmailMailer {

	private static ?self $instance = null;

	private ?string $pending_alt_body = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function send( WP_User $user, string $code ): bool {
		$to = (string) $user->user_email;
		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		$site_name   = $this->resolve_site_name();
		$ttl_minutes = (int) ceil( EmailOtp::TTL / MINUTE_IN_SECONDS );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Your verification code', 'radish-2fa' ),
			$site_name
		);
		$subject = (string) apply_filters( 'radish_2fa_email_subject', $subject, $user, $site_name );

		$html = (string) apply_filters(
			'radish_2fa_email_html',
			$this->build_html_body( $user, $code, $ttl_minutes, $site_name ),
			$user,
			$code,
			$site_name
		);

		$alt = (string) apply_filters(
			'radish_2fa_email_alt_body',
			$this->build_text_body( $user, $code, $ttl_minutes ),
			$user,
			$code,
			$site_name
		);

		$this->pending_alt_body = $alt;
		add_action( 'phpmailer_init', [ $this, 'inject_alt_body' ] );

		$sent = (bool) wp_mail(
			$to,
			$subject,
			$html,
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);

		remove_action( 'phpmailer_init', [ $this, 'inject_alt_body' ] );
		$this->pending_alt_body = null;

		return $sent;
	}

	/**
	 * Hooked into `phpmailer_init` for the duration of one send so the message
	 * goes out as multipart/alternative (HTML + plain text fallback).
	 *
	 * @param object $phpmailer  PHPMailer instance.
	 */
	public function inject_alt_body( $phpmailer ): void {
		if ( null === $this->pending_alt_body ) {
			return;
		}
		if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
			$phpmailer->AltBody = $this->pending_alt_body;
		}
	}

	private function build_html_body( WP_User $user, string $code, int $ttl_minutes, string $site_name ): string {
		$font          = 'Arial, Helvetica, sans-serif';
		$bg            = '#f3f4f6';
		$card_bg       = '#ffffff';
		$border        = '#e5e7eb';
		$text          = '#111827';
		$muted         = '#6b7280';
		$accent        = '#4f46e5';
		$code_bg       = '#eef2ff';

		$greeting = sprintf(
			/* translators: %s: user login */
			__( 'Hi %s,', 'radish-2fa' ),
			$user->user_login
		);
		$intro      = __( 'Use the code below to finish signing in.', 'radish-2fa' );
		$expiry     = sprintf(
			/* translators: %d: minutes */
			_n( 'This code expires in %d minute.', 'This code expires in %d minutes.', $ttl_minutes, 'radish-2fa' ),
			$ttl_minutes
		);
		$ignore     = __( 'If you did not try to sign in, you can ignore this email.', 'radish-2fa' );
		$do_not     = __( 'Never share this code with anyone — not even support staff.', 'radish-2fa' );
		$preheader  = __( 'Your one-time verification code is inside.', 'radish-2fa' );

		ob_start();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $site_name ); ?></title>
</head>
<body style="margin:0; padding:0; background:<?php echo esc_attr( $bg ); ?>; font-family:<?php echo esc_attr( $font ); ?>; color:<?php echo esc_attr( $text ); ?>; -webkit-font-smoothing:antialiased;">
<span style="display:none !important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden;"><?php echo esc_html( $preheader ); ?></span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo esc_attr( $bg ); ?>; padding:32px 16px;">
	<tr>
		<td align="center">
			<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:480px; width:100%; background:<?php echo esc_attr( $card_bg ); ?>; border:1px solid <?php echo esc_attr( $border ); ?>; border-radius:12px;">
				<tr>
					<td style="padding:32px 32px 24px;">
						<div style="font-family:<?php echo esc_attr( $font ); ?>; font-size:12px; letter-spacing:0.08em; text-transform:uppercase; color:<?php echo esc_attr( $muted ); ?>; margin-bottom:8px;">
							<?php echo esc_html( $site_name ); ?>
						</div>
						<h1 style="font-family:<?php echo esc_attr( $font ); ?>; font-size:22px; line-height:1.3; margin:0 0 16px; color:<?php echo esc_attr( $text ); ?>; font-weight:700;">
							<?php esc_html_e( 'Your verification code', 'radish-2fa' ); ?>
						</h1>
						<p style="font-family:<?php echo esc_attr( $font ); ?>; font-size:15px; line-height:1.6; margin:0 0 8px; color:<?php echo esc_attr( $text ); ?>;">
							<?php echo esc_html( $greeting ); ?>
						</p>
						<p style="font-family:<?php echo esc_attr( $font ); ?>; font-size:15px; line-height:1.6; margin:0 0 24px; color:<?php echo esc_attr( $text ); ?>;">
							<?php echo esc_html( $intro ); ?>
						</p>
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
							<tr>
								<td align="center" style="background:<?php echo esc_attr( $code_bg ); ?>; border:1px solid #c7d2fe; border-radius:10px; padding:20px 16px;">
									<div style="font-family:<?php echo esc_attr( $font ); ?>; font-size:32px; line-height:1.1; letter-spacing:0.4em; font-weight:700; color:<?php echo esc_attr( $accent ); ?>;">
										<?php echo esc_html( $code ); ?>
									</div>
								</td>
							</tr>
						</table>
						<p style="font-family:<?php echo esc_attr( $font ); ?>; font-size:13px; line-height:1.6; margin:20px 0 0; color:<?php echo esc_attr( $muted ); ?>;">
							<?php echo esc_html( $expiry ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<td style="padding:0 32px 28px;">
						<hr style="border:0; border-top:1px solid <?php echo esc_attr( $border ); ?>; margin:0 0 16px;">
						<p style="font-family:<?php echo esc_attr( $font ); ?>; font-size:12px; line-height:1.6; margin:0 0 6px; color:<?php echo esc_attr( $muted ); ?>;">
							<?php echo esc_html( $ignore ); ?>
						</p>
						<p style="font-family:<?php echo esc_attr( $font ); ?>; font-size:12px; line-height:1.6; margin:0; color:<?php echo esc_attr( $muted ); ?>;">
							<?php echo esc_html( $do_not ); ?>
						</p>
					</td>
				</tr>
			</table>
			<div style="font-family:<?php echo esc_attr( $font ); ?>; font-size:11px; color:<?php echo esc_attr( $muted ); ?>; margin-top:16px;">
				<?php echo esc_html( $site_name ); ?>
			</div>
		</td>
	</tr>
</table>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	private function build_text_body( WP_User $user, string $code, int $ttl_minutes ): string {
		$lines = [
			sprintf(
				/* translators: %s: user login */
				__( 'Hi %s,', 'radish-2fa' ),
				$user->user_login
			),
			'',
			__( 'Use the code below to finish signing in:', 'radish-2fa' ),
			'',
			$code,
			'',
			sprintf(
				/* translators: %d: minutes */
				_n( 'This code expires in %d minute.', 'This code expires in %d minutes.', $ttl_minutes, 'radish-2fa' ),
				$ttl_minutes
			),
			__( 'If you did not try to sign in, you can ignore this email.', 'radish-2fa' ),
			__( 'Never share this code with anyone — not even support staff.', 'radish-2fa' ),
		];

		return implode( "\n", $lines );
	}

	private function resolve_site_name(): string {
		$name = is_multisite()
			? ( get_network()->site_name ?? get_bloginfo( 'name' ) )
			: get_bloginfo( 'name' );

		return (string) $name;
	}

	private function __construct() {}
}
