<?php
/**
 * @var string   $token
 * @var \WP_User $user
 * @var string   $masked_email
 * @var ?string  $error
 * @var ?string  $info
 * @var int      $cooldown_seconds   0 if a new code can be requested now.
 * @var bool     $can_choose_again
 * @var string   $chooser_url
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
	<title><?php esc_html_e( 'Confirm your email', 'radish-2fa' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
<main class="r2fa-page">
	<div class="r2fa-card">
		<div class="r2fa-site"><?php echo esc_html( $site_name ); ?></div>
		<h1><?php esc_html_e( 'Confirm your email', 'radish-2fa' ); ?></h1>
		<p class="lead">
			<?php
			printf(
				/* translators: %s: masked email address */
				esc_html__( 'We sent a six-digit code to %s. Enter it below to finish setting up email verification.', 'radish-2fa' ),
				'<strong>' . esc_html( $masked_email ) . '</strong>'
			);
			?>
		</p>

		<?php if ( ! empty( $error ) ) : ?>
			<div class="r2fa-error"><?php echo esc_html( $error ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $info ) ) : ?>
			<div class="r2fa-info"><?php echo esc_html( $info ); ?></div>
		<?php endif; ?>

		<form method="post" action="">
			<input type="hidden" name="r2fa_action" value="verify">
			<div class="r2fa-field">
				<label for="r2fa-code"><?php esc_html_e( 'Verification code', 'radish-2fa' ); ?></label>
				<input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" id="r2fa-code" name="code" autofocus required>
			</div>
			<button type="submit" class="r2fa-button"><?php esc_html_e( 'Confirm and activate', 'radish-2fa' ); ?></button>
		</form>

		<?php
		/* translators: %d: seconds */
		$r2fa_wait_tpl  = __( 'Send a new code (wait %ds)', 'radish-2fa' );
		$r2fa_ready     = __( 'Send a new code', 'radish-2fa' );
		$r2fa_initial   = $cooldown_seconds > 0
			? sprintf( $r2fa_wait_tpl, (int) $cooldown_seconds )
			: $r2fa_ready;
		?>
		<form method="post" action="" class="r2fa-resend">
			<input type="hidden" name="r2fa_action" value="resend">
			<button type="submit"
				class="r2fa-link"
				data-r2fa-cooldown="<?php echo (int) $cooldown_seconds; ?>"
				data-r2fa-wait-template="<?php echo esc_attr( $r2fa_wait_tpl ); ?>"
				data-r2fa-ready-label="<?php echo esc_attr( $r2fa_ready ); ?>"
				<?php disabled( $cooldown_seconds > 0 ); ?>><?php echo esc_html( $r2fa_initial ); ?></button>
		</form>
		<script>
			(function () {
				var btn = document.querySelector('[data-r2fa-cooldown]');
				if (!btn) return;
				var remaining = parseInt(btn.getAttribute('data-r2fa-cooldown'), 10) || 0;
				if (remaining <= 0) return;
				var waitTpl = btn.getAttribute('data-r2fa-wait-template') || '';
				var ready   = btn.getAttribute('data-r2fa-ready-label') || '';
				var tick = function () {
					remaining -= 1;
					if (remaining <= 0) {
						btn.disabled = false;
						btn.textContent = ready;
						return;
					}
					btn.textContent = waitTpl.replace('%d', remaining);
					setTimeout(tick, 1000);
				};
				setTimeout(tick, 1000);
			})();
		</script>

		<?php if ( $can_choose_again ) : ?>
			<p class="r2fa-meta">
				<a href="<?php echo esc_url( $chooser_url ); ?>"><?php esc_html_e( 'Use a different method', 'radish-2fa' ); ?></a>
			</p>
		<?php endif; ?>

		<p class="r2fa-meta"><?php esc_html_e( 'Signed in as', 'radish-2fa' ); ?> <strong><?php echo esc_html( $user->user_login ); ?></strong></p>
	</div>
</main>
</body>
</html>
