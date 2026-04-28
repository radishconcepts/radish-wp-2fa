<?php
/**
 * @var string $css_url
 * @var string $site_name
 */
?><!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php esc_html_e( 'Session expired', 'radish-2fa' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
<main class="r2fa-page">
	<div class="r2fa-card">
		<div class="r2fa-site"><?php echo esc_html( $site_name ); ?></div>
		<h1><?php esc_html_e( 'This link has expired', 'radish-2fa' ); ?></h1>
		<p class="lead">
			<?php esc_html_e( 'Your verification link has expired or has already been used. Sign in again to continue.', 'radish-2fa' ); ?>
		</p>
		<p class="r2fa-meta">
			<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Go to login page', 'radish-2fa' ); ?></a>
		</p>
	</div>
</main>
</body>
</html>
