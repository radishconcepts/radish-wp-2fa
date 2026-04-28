<?php
/**
 * @var string  $token
 * @var \WP_User $user
 * @var string  $secret
 * @var string  $qr_svg
 * @var ?string $error
 * @var string  $css_url
 * @var string  $site_name
 */
?><!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php esc_html_e( 'Set up two-factor authentication', 'radish-2fa' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
<main class="r2fa-page">
	<div class="r2fa-card">
		<div class="r2fa-site"><?php echo esc_html( $site_name ); ?></div>
		<h1><?php esc_html_e( 'Secure your account', 'radish-2fa' ); ?></h1>
		<p class="lead">
			<?php esc_html_e( 'Scan the QR code below with an authenticator app (such as Google Authenticator, 1Password, or Authy) and enter the six-digit code to complete setup.', 'radish-2fa' ); ?>
		</p>

		<?php if ( ! empty( $error ) ) : ?>
			<div class="r2fa-error"><?php echo esc_html( $error ); ?></div>
		<?php endif; ?>

		<div class="r2fa-qr"><?php echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bacon QR generates safe SVG. ?></div>
		<div class="r2fa-secret" title="<?php esc_attr_e( 'Manual entry key for your app', 'radish-2fa' ); ?>"><?php echo esc_html( $secret ); ?></div>

		<form method="post" action="">
			<div class="r2fa-field">
				<label for="r2fa-code"><?php esc_html_e( 'Verification code', 'radish-2fa' ); ?></label>
				<input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" id="r2fa-code" name="code" autofocus required>
			</div>
			<button type="submit" class="r2fa-button"><?php esc_html_e( 'Confirm and activate', 'radish-2fa' ); ?></button>
		</form>

		<p class="r2fa-meta"><?php esc_html_e( 'Signed in as', 'radish-2fa' ); ?> <strong><?php echo esc_html( $user->user_login ); ?></strong></p>
	</div>
</main>
</body>
</html>
