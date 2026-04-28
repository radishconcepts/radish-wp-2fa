# Changelog

All notable changes to **Radish 2FA** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/radishconcepts/radish-wp-2fa/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/radishconcepts/radish-wp-2fa/releases/tag/v0.1.0
