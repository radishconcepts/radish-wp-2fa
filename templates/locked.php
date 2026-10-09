<?php
/**
 * @var string $css_url
 * @var string $site_name
 * @var int    $minutes
 */
?><!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php esc_html_e( 'Too many attempts', 'radish-2fa' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
<main class="r2fa-page">
	<div class="r2fa-card">
		<div class="r2fa-site"><?php echo esc_html( $site_name ); ?></div>
		<h1><?php esc_html_e( 'Too many incorrect codes', 'radish-2fa' ); ?></h1>
		<p class="lead">
			<?php
			echo esc_html( sprintf(
				/* translators: %d: number of minutes to wait */
				_n(
					'For your security, verification is paused. Wait %d minute, then sign in again.',
					'For your security, verification is paused. Wait %d minutes, then sign in again.',
					$minutes,
					'radish-2fa'
				),
				$minutes
			) );
			?>
		</p>
		<p class="r2fa-meta">
			<a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'Go to login page', 'radish-2fa' ); ?></a>
		</p>
	</div>
</main>
</body>
</html>
