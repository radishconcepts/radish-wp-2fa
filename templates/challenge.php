<?php
/**
 * @var string  $token
 * @var \WP_User $user
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
	<title><?php esc_html_e( 'Two-factor authentication', 'radish-2fa' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
<main class="r2fa-page">
	<div class="r2fa-card">
		<div class="r2fa-site"><?php echo esc_html( $site_name ); ?></div>
		<h1><?php esc_html_e( 'Enter verification code', 'radish-2fa' ); ?></h1>
		<p class="lead">
			<?php esc_html_e( 'Open your authenticator app and enter the six-digit code. No access to your app? Use a backup code.', 'radish-2fa' ); ?>
		</p>

		<?php if ( ! empty( $error ) ) : ?>
			<div class="r2fa-error"><?php echo esc_html( $error ); ?></div>
		<?php endif; ?>

		<form method="post" action="">
			<div class="r2fa-field">
				<label for="r2fa-code"><?php esc_html_e( 'Code', 'radish-2fa' ); ?></label>
				<input type="text" inputmode="numeric" autocomplete="one-time-code" id="r2fa-code" name="code" autofocus required>
			</div>
			<button type="submit" class="r2fa-button"><?php esc_html_e( 'Sign in', 'radish-2fa' ); ?></button>
		</form>

		<p class="r2fa-meta"><?php esc_html_e( 'Signed in as', 'radish-2fa' ); ?> <strong><?php echo esc_html( $user->user_login ); ?></strong></p>
	</div>
</main>
</body>
</html>
