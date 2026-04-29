<?php
/**
 * @var string   $token
 * @var \WP_User $user
 * @var string[] $methods       Available method IDs (e.g. ['totp', 'email']).
 * @var ?string  $error
 * @var string   $css_url
 * @var string   $site_name
 */

use RadishConcepts\TwoFactor\Methods\Method;

?><!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php esc_html_e( 'Choose a verification method', 'radish-2fa' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
<main class="r2fa-page">
	<div class="r2fa-card">
		<div class="r2fa-site"><?php echo esc_html( $site_name ); ?></div>
		<h1><?php esc_html_e( 'Choose a verification method', 'radish-2fa' ); ?></h1>
		<p class="lead">
			<?php esc_html_e( 'How would you like to receive verification codes? You can change this later from your profile.', 'radish-2fa' ); ?>
		</p>

		<?php if ( ! empty( $error ) ) : ?>
			<div class="r2fa-error"><?php echo esc_html( $error ); ?></div>
		<?php endif; ?>

		<form method="post" action="">
			<div class="r2fa-methods">
				<?php foreach ( $methods as $i => $method_id ) : ?>
					<label class="r2fa-method">
						<input type="radio" name="method" value="<?php echo esc_attr( $method_id ); ?>" <?php checked( 0 === $i ); ?> required>
						<span class="r2fa-method-label">
							<strong><?php echo esc_html( Method::label( $method_id ) ); ?></strong>
							<small>
								<?php
								if ( Method::TOTP === $method_id ) {
									esc_html_e( 'Use an authenticator app (Google Authenticator, 1Password, Authy, …).', 'radish-2fa' );
								} elseif ( Method::EMAIL === $method_id ) {
									esc_html_e( 'Receive a one-time code by email.', 'radish-2fa' );
								}
								?>
							</small>
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<button type="submit" class="r2fa-button"><?php esc_html_e( 'Continue', 'radish-2fa' ); ?></button>
		</form>

		<p class="r2fa-meta"><?php esc_html_e( 'Signed in as', 'radish-2fa' ); ?> <strong><?php echo esc_html( $user->user_login ); ?></strong></p>
	</div>
</main>
</body>
</html>
