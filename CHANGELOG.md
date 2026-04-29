# Changelog

All notable changes to **Radish 2FA** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.1.2...v0.2.0
[0.1.2]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/radishconcepts/radish-wp-2fa/releases/tag/v0.1.0
