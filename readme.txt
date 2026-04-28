=== Radish 2FA ===
Contributors: radishconcepts
Tags: two-factor-authentication, 2fa, totp, security, multisite
Requires at least: 6.2
Tested up to: 6.7
Stable tag: 0.1.0
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Require two-factor authentication for selected WordPress roles. Frontend setup flow, hard enforcement, no skip option.

== Description ==

If you've ever asked an editor to scan a QR code from their wp-admin profile page, you know how that ends. Radish 2FA puts **two-factor authentication** on a dedicated frontend page, makes it mandatory by role, and gives users no skip button — so the people who need 2FA actually finish setting it up.

Pick which roles must use 2FA from a single settings screen. Existing sessions for newly-enforced users are terminated immediately, so nobody slips through. New logins are caught at the password step: no auth cookie is issued until TOTP setup or verification completes on `/2fa/setup` or `/2fa/challenge`.

Built for agencies and teams who manage real client sites — multisite-ready, theme-overridable, and audit-friendly out of the box.

== What you get ==

* **Role-based hard enforcement** — pick the WordPress roles that must use two-factor authentication; everyone else logs in normally. No per-user opt-out.
* **Frontend setup flow** — users enroll on `/2fa/setup` and verify on `/2fa/challenge`. No QR codes buried in the wp-admin profile page.
* **Theme template overrides** — drop `your-theme/radish-2fa/setup.php` (or `challenge.php`, `backup-codes.php`, `expired.php`) to match your site's design.
* **Multisite-ready** — settings and user metadata are network-wide. Optional "require 2FA for all super admins" toggle.
* **Backup codes** — 10 single-use codes generated at setup, shown once, bcrypt-hashed at rest.
* **Encrypted TOTP secrets** — secrets are encrypted at rest with `sodium_crypto_secretbox`, using a key derived from `AUTH_KEY` and `SECURE_AUTH_KEY` via HKDF-SHA256. A database leak alone won't reveal them.
* **API protection** — REST and XML-RPC password logins are blocked for 2FA users. Application Passwords keep working for legitimate API clients.
* **Three lockout-recovery paths** — `wp-config.php` constant for emergencies, `wp radish-2fa` WP-CLI commands, or a "Reset two-factor authentication" button on the user-edit screen for super admins.
* **Translation-ready** — Dutch translation included; `.pot` file ships in `/languages` for any locale.

= Why a frontend setup flow? =

The default WordPress profile page is overwhelming for non-technical editors. A dedicated 2FA route with theme support means clients see one focused page that matches the site they already know — not a wall of admin fields where the QR code is hidden between Yoast and Gravatar settings.

= Hard enforcement, explained =

When a user with an enforced role signs in, the auth cookie is suppressed at the `send_auth_cookies` and `auth_cookie` filters until 2FA is verified. Login tokens live for 5 minutes, are single-use, and are stored under `sha256(token)` in `site_transient` so a database dump never exposes the token itself. There is no "skip" button and no per-user override.

== Installation ==

= Automatic installation =

1. Go to Plugins > Add New in your WordPress dashboard.
2. Search for "Radish 2FA".
3. Click Install Now, then Activate. On multisite, use Network Activate.

= Manual installation =

1. Download the plugin ZIP from WordPress.org.
2. Go to Plugins > Add New > Upload Plugin.
3. Upload the ZIP file and click Install Now.
4. Activate the plugin (Network Activate on multisite).

= Composer installation =

Add the plugin to your site's `composer.json` as a `wordpress-plugin` package and run `composer require radishconcepts/radish-wp-2fa`. The plugin installs into `wp-content/plugins/radish-2fa/`.

= Post-activation =

1. Deactivate any other 2FA plugins (Two Factor, miniOrange, etc.) — their login hooks will conflict.
2. Go to **Network Settings → Radish 2FA** (multisite) or **Settings → Radish 2FA** (single site).
3. Tick the roles that must use two-factor authentication.
4. Click **Save**. Active sessions for newly-enforced users will be terminated immediately.

== Frequently Asked Questions ==

= Does this work on multisite? =

Yes. Settings and user secrets are stored network-wide, and there's a separate "Require 2FA for all super admins" toggle on the network settings page.

= What happens if a user loses their phone and their backup codes? =

Three recovery paths: define `RADISH_2FA_DISABLE_FOR_USER_ID` in `wp-config.php` to bypass enforcement for one user, run `wp radish-2fa disable <user>` from the command line, or have a super admin click "Reset two-factor authentication" on the user-edit screen.

= Will my REST API or XML-RPC scripts still work? =

Password-based REST and XML-RPC requests are blocked for users with 2FA enabled — that's the point. For legitimate API clients, generate an **Application Password** under Users → Profile → Application Passwords and use that instead of the account password.

= Can users choose to skip 2FA? =

No. That's a deliberate design decision. If a role is enforced, every user with that role must complete TOTP setup before they can access the site. There is no skip button and no admin override at the user level.

= Does it conflict with other 2FA plugins? =

Yes — deactivate Two Factor, miniOrange, WP 2FA, or any similar plugin before activating Radish 2FA. They all hook into `wp_login` and will fight each other.

= How are TOTP secrets stored? =

Encrypted at rest with `sodium_crypto_secretbox`. The encryption key is derived from your site's `AUTH_KEY` and `SECURE_AUTH_KEY` constants via HKDF-SHA256, so a stolen database alone is not enough to recover any secret.

= Can I customize the 2FA setup and challenge pages? =

Yes. Place your own templates in `your-theme/radish-2fa/setup.php`, `challenge.php`, `backup-codes.php`, or `expired.php`. The plugin uses `locate_template()` and prefers the theme version. Use the files in `radish-2fa/templates/` as a starting point.

= What WordPress and PHP versions are required? =

WordPress 6.2 or higher and PHP 8.1 or higher. Libsodium is built into PHP from 7.2 onwards, so no extra extension is needed.

== Screenshots ==

<!-- TODO: Add screenshots. Recommended: (1) the role-enforcement settings page, (2) the frontend /2fa/setup page with QR code, (3) the /2fa/challenge page, (4) the backup codes screen, (5) the user-edit reset block. Name files screenshot-1.png, screenshot-2.png, etc. in the /assets/ directory. -->

== Changelog ==

= 0.1.0 =
* Feature: Initial release.
* Feature: Per-role two-factor authentication enforcement with no skip option.
* Feature: Frontend setup and challenge flow on `/2fa/setup` and `/2fa/challenge`.
* Feature: 10 single-use backup codes, bcrypt-hashed at rest.
* Feature: TOTP secrets encrypted at rest using `sodium_crypto_secretbox`.
* Feature: Multisite-ready settings and per-user metadata.
* Feature: REST and XML-RPC password-login protection for 2FA users; Application Passwords supported.
* Feature: Lockout recovery via `wp-config.php` constant, WP-CLI, or wp-admin user-edit screen.
* Feature: Theme-overridable templates for setup, challenge, backup codes, and expired-token pages.
* Feature: `radish_2fa_totp_issuer` filter for customizing the TOTP app issuer label.
* Feature: Dutch translation included; `.pot` file shipped for additional locales.

== Upgrade Notice ==

= 0.1.0 =
Initial release of Radish 2FA — enforce two-factor authentication on WordPress logins by role, with a frontend setup flow.
