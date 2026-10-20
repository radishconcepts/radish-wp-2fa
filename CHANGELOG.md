# Changelog

All notable changes to **Radish 2FA** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.1] - 2026-10-20

### Security
- A 2FA token now only opens the route of its own step. The challenge token issued after the password step to users who already have 2FA could open `/2fa/setup/` instead, enrol a new authenticator over the user's own secret and backup codes, and sign in, so the password alone was enough. `Controller::route()` now checks the token's mode against the route (new `Routes::action_for_mode()` and `Routes::url_for_action()`) and redirects a token on the wrong route to its own. Setup and backup tokens belong to setup, challenge tokens to the challenge; a token with an unknown mode gets the expired page.
- A setup token of a user who has enrolled since it was issued is spent and shows the expired page, so setup can never replace an existing secret.

### Tests
- `RoutesTest` covers the mode-to-route mapping and the route URLs.

## [0.3.0] - 2026-10-09

### Security
- Wrong verification codes are now limited. A 2FA token allows 5 wrong codes, a user 10 in a rolling 15 minutes (new `Security\AttemptLimit`, site transients `r2fa_fail_{user_id}` and `r2fa_tfail_{sha256(token)}`). When either limit is hit, the token is consumed and a new **Too many incorrect codes** page is shown (HTTP 429, theme-overridable as `radish-2fa/locked.php`). While a user is locked, even a correct code is refused and a fresh password login lands on the same page. Applies to setup and challenge, for TOTP, email and backup codes. Empty submissions are not counted. Each check runs under a per-user MySQL `GET_LOCK()`, so parallel requests can't each get a guess checked against the same count. Each wrong code is written to the PHP error log and fires the new `radish_2fa_verification_failed` action (`$user_id`, `$count`). A password reset, the admin **Reset 2FA** action and `wp radish-2fa disable` clear the lock.
- REST and XML-RPC password logins for 2FA users could get past the block by sending any `Authorization: Basic` header. `Auth\ApiLogin` now only lets a login through when WordPress core authenticated that same user with an application password in the same request (`application_password_did_authenticate`).
- A session now records that it passed 2FA (`radish_2fa_verified_at` on the session token, new `Auth\SessionGuard`). This catches every way to get a session without the challenge, such as another plugin calling `wp_set_auth_cookie()` or a session from before the role was enforced. For a user who requires 2FA, a session without the mark is handled as follows:
  - Page views on the front end and in wp-admin (`template_redirect`, `admin_init`): the session is ended and the user is sent to the challenge, then back to the page they were on.
  - Form submissions in wp-admin: the session is ended and a **Verification required** page explains that the changes were not saved, with a link to the challenge.
  - AJAX and `admin-post.php`: refused with HTTP 401, so the session can't fetch a REST nonce or run actions.
  - Cookie-authenticated REST requests: refused with HTTP 401 (`radish_2fa_verification_required`). Application passwords are unaffected.
- Every login cookie that WordPress validates in a request (logged_in, auth, secure_auth) must point at a marked session, so pairing a verified cookie with an unverified one does not pass.
- The new `radish_2fa_require_verified_session` filter (`$required`, `$user`, `$session`) lets a site exempt sessions it creates on purpose, for example a user-switching plugin.

### Changed
- Users who require 2FA but have not set it up are now also sent to setup from wp-admin, not only from the front end. After resetting their own 2FA on the profile screen, users go straight to setup.
- When users change their own password, WordPress and WooCommerce replace their session. The new session keeps the 2FA mark, so they are not asked for their code again.
- After upgrading, users who require 2FA are asked for their code once more on their next page view, because their existing sessions do not carry the new mark yet. A form submitted in wp-admin at that moment is not saved.

### Tests
- `AttemptLimitTest` covers the per-user window, the per-token counter (separate key, token hashed, 30-minute lifetime), both limits, expiry of old failures, clearing, the logging action and the database lock (per user, name within MySQL's 64 characters, timeout, databases without named locks).
- `SessionGuardTest` and `SessionGuardRequestTest` cover marking (without mutating the input), verification of marked, unmarked, missing and bogus sessions, requests with several login cookies, carrying the mark over to a new session of the same user only, and the "Remember me" detection.
- `ApiLoginTest` covers non-API logins, blocked password logins, the dummy Basic header, a real application password, and an application password of another user.
- `EnforcementTest` now expects the `template_redirect`, `admin_init` and `rest_authentication_errors` hooks.

### i18n
- Dutch (`nl_NL`) translations for the locked page and the verification-required messages; `radish-2fa.pot` regenerated and `.mo` recompiled.

## [0.2.4] - 2026-10-09

### Security
- On multisite, `Roles::user_requires_2fa()` only looked at the user's roles on the site where they logged in, while the auth cookie is valid on every site of the network. A user with an enforced role on one site could skip 2FA by logging in on another site (or the root site) where they had no enforced role, then open the admin of the first site. The check now collects the user's roles on every site they belong to, including archived, spammed and deleted sites, so a role anywhere on the network triggers 2FA. Single-site installs are unaffected.

### Tests
- `RolesTest` covers multisite: an enforced role on another site requires 2FA, unenforced roles on every site do not, a role on an archived site still counts, revoked capability flags are ignored, and the current site's in-memory roles still count.

## [0.2.3] - 2026-07-27

### Fixed
- "This link has expired" was shown after a *successful* 2FA verification, making the sign-in look failed while the user was in fact logged in (visiting wp-admin manually proved it). `complete_login()` deleted the nonce, so any repeat of the request that finished the flow — a double-clicked **Sign in** button, the browser re-sending the POST after back/reload, a restored tab, a retried slow request — found no nonce and was indistinguishable from an expired link. `Nonce::complete()` now leaves a short-lived receipt (15 min, `user_id` + validated `redirect_to`, no secrets) so the repeat resolves to the destination instead. Answering the repeated POST with a redirect also stops the browser from resubmitting it.
- A stale 2FA link opened by a visitor whose session is already valid now redirects instead of reporting an expired session. Users who require 2FA but aren't enrolled land back on `/2fa/setup` with a fresh nonce via `Auth\Enforcement`.
- The 5-minute nonce TTL was absolute from the moment the password was accepted, so waiting for a mail to arrive or fetching a phone could expire the flow mid-way. It is now a 5-minute *idle* window, restarted on every 2FA page view (`Nonce::touch()`), capped by a hard 30-minute ceiling.

### Tests
- `NonceTest` covers the completion receipt (nonce consumed, token still recognisable, receipt carries no secrets, key stays hashed) and the idle window (restart, clamp to ceiling, drop past ceiling, no-op on unknown tokens).
- `RedirectTargetTest` covers `after_stale_token()`: receipt target honoured and host-validated, logged-in visitors sent onward, expired screen only when there is nothing to resume.

## [0.2.2] - 2026-06-02

### Fixed
- Front-end logins (e.g. the WooCommerce **My Account** form) showed a blank white page after a successful 2FA verification. The auth cookie was set, but `LoginInterceptor::resolve_redirect_to()` only read `redirect_to` — which WooCommerce omits in favour of a `redirect` field — and passed the empty string to `wp_validate_redirect()`. WordPress only swaps in the fallback for a *disallowed host*, so the empty value propagated unchanged and `complete_login()` called `wp_safe_redirect( '' )`, which emits no `Location` header. wp-login.php logins were unaffected because they always send a populated `redirect_to`.
- Redirect resolution is now centralised in the new `Auth\RedirectTarget` helper: it honours `redirect_to` then `redirect`, defaults admin logins to wp-admin and front-end logins to the site home, and always returns a non-empty, host-validated target. `complete_login()` runs every redirect through `RedirectTarget::ensure()` as a final safety net.

### Tests
- `RedirectTargetTest` covers the empty-target regression, the WooCommerce `redirect` fallback, host validation, and the admin/front-end defaults.

## [0.2.1] - 2026-04-29

### Changed
- Self-service method switch on the profile screen is now a radio-button row inside the form-table, saved with the regular **Update profile** button. The change is queued via a new `_radish_2fa_pending_method` user-meta and only applied at the user's next sign-in (`wp_login` priority 5 clears the old enrollment so `LoginInterceptor` routes them straight to `/2fa/setup`). The current session keeps using the active method.
- An info notice on the profile screen surfaces a queued method change so the user knows what to expect at next sign-in.

### Fixed
- The old **Change method** / **Reset 2FA** buttons silently no-op'd: they were rendered as `<form>` elements nested inside WordPress's profile form (which HTML strips), so clicks fell through to the outer profile form. The change-method flow is now part of the profile form itself; **Reset 2FA** is a nonce-protected admin-post link.
- Dutch (`nl_NL`) translations added for the new self-service strings; `radish-2fa.pot` regenerated and `.mo` recompiled.

## [0.2.0] - 2026-04-29

### Added
- Email-based 2FA as an opt-in alternative to TOTP. Admins enable it under **Settings → Two-Factor → Authentication methods**; users with multiple methods available pick one on the setup chooser.
- `Methods\Method` value object centralising the supported method identifiers (`totp`, `email`) and their translatable labels.
- `Security\EmailOtp` (6-digit numeric code, 10-minute TTL, bcrypt-hashed at rest) and `Security\EmailMailer` (responsive HTML + plain-text multipart message, filterable subject/body via `radish_2fa_email_subject` / `radish_2fa_email_html` / `radish_2fa_email_alt_body`).
- `Security\EmailRateLimit` enforcing a 30-second cooldown and max 5 sends per rolling hour per user, backed by site transients (multisite-safe).
- `Admin\SelfManage` self-service section on the user's own profile screen: shows status, current method, enrolled-at, last-used and remaining backup codes, with **Change method** and **Reset 2FA** buttons.
- Theme-overridable templates `setup-method-chooser.php`, `setup-email.php`, and `challenge-email.php`.
- `UserMeta::META_METHOD` plus `get_method()`, `set_method()`, and `enroll_email()` helpers; `wp radish-2fa status` now reports the active method.
- Tests covering `Method`, `EmailOtp`, `EmailRateLimit`, and the new method-aware `UserMeta` paths.
- Dutch translations for all new email-method, method-chooser, and self-service strings; `radish-2fa.pot` regenerated.

### Changed
- Disabling a previously enabled method now destroys the sessions of users currently enrolled in it, forcing them through the setup chooser on the next request.
- `Auth\Enforcement` and `Auth\LoginInterceptor` no longer pre-generate a TOTP secret on the setup nonce — the secret is created only after the user picks TOTP on the chooser, so abandoning halfway never wastes a secret.
- `is_enrolled()` is now method-aware (TOTP requires a stored secret; email enrolment is sufficient on its own) with a back-compat path for users from pre-method releases.

## [0.1.2] - 2026-04-29

### Fixed
- Resolve `ERR_TOO_MANY_REDIRECTS` on `/2fa/setup/` for users who had an active session when the plugin was activated. `Auth\Enforcement` now hooks on `template_redirect` (priority 11) instead of `init` (priority 999); on `init` the skip-on-2FA-pages guard `get_query_var( Routes::QUERY_VAR )` always returned empty (parse_query had not run yet), so every request created a fresh nonce and redirected again.

## [0.1.1] - 2026-04-28

### Changed
- Bump `pragmarx/google2fa` requirement from `^8.0` to `^9.0`. The library's default secret-key length changed from 16 to 32 characters; this plugin already requested 32 explicitly, so behavior is unchanged.
- Bump `actions/checkout` from v4 to v6 and `actions/cache` from v4 to v5 in the CI workflow (Node.js 24 runtime).

## [0.1.0] - 2026-04-28

### Added
- Per-role two-factor authentication enforcement with no skip option.
- Frontend setup and challenge flow on `/2fa/setup` and `/2fa/challenge`.
- 10 single-use backup codes, bcrypt-hashed at rest.
- TOTP secrets encrypted at rest using `sodium_crypto_secretbox` with a key derived from `AUTH_KEY` and `SECURE_AUTH_KEY` via HKDF-SHA256.
- Multisite-ready settings and per-user metadata.
- REST and XML-RPC password-login protection for 2FA users; Application Passwords remain supported.
- Lockout recovery via `RADISH_2FA_DISABLE_FOR_USER_ID` constant, the `wp radish-2fa` WP-CLI command, or the wp-admin user-edit screen.
- Theme-overridable templates for setup, challenge, backup codes, and expired-token pages.
- `radish_2fa_totp_issuer` filter for customizing the TOTP app issuer label.
- Dutch translation; `.pot` file shipped for additional locales.

[Unreleased]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.3.1...HEAD
[0.3.1]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.2.4...v0.3.0
[0.2.4]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.2.2...v0.2.3
[0.2.2]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/radishconcepts/radish-wp-2fa/releases/tag/v0.1.0
