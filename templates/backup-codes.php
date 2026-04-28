<?php
/**
 * @var string   $token
 * @var string[] $plain_codes
 * @var string   $css_url
 * @var string   $site_name
 */
?><!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php esc_html_e( 'Save your backup codes', 'radish-2fa' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
<main class="r2fa-page">
	<div class="r2fa-card">
		<div class="r2fa-site"><?php echo esc_html( $site_name ); ?></div>
		<h1><?php esc_html_e( 'Save your backup codes', 'radish-2fa' ); ?></h1>
		<p class="lead">
			<?php esc_html_e( 'Store these codes in a safe place (a password manager or printed paper). Each code works once, in case you lose your phone or have no access to your authenticator app.', 'radish-2fa' ); ?>
		</p>

		<div class="r2fa-warn">
			<strong><?php esc_html_e( 'Note:', 'radish-2fa' ); ?></strong>
			<?php esc_html_e( 'These codes will never be shown again.', 'radish-2fa' ); ?>
		</div>

		<div class="r2fa-codes">
			<?php foreach ( $plain_codes as $code ) : ?>
				<code><?php echo esc_html( $code ); ?></code>
			<?php endforeach; ?>
		</div>

		<form method="post" action="">
			<button type="submit" class="r2fa-button"><?php esc_html_e( 'I have saved my codes — continue', 'radish-2fa' ); ?></button>
		</form>
	</div>
</main>
</body>
</html>
