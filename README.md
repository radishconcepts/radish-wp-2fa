# Radish 2FA

[![Tests](https://github.com/radishconcepts/radish-wp-2fa/actions/workflows/tests.yml/badge.svg)](https://github.com/radishconcepts/radish-wp-2fa/actions/workflows/tests.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL_v2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-8892BF.svg)](https://www.php.net/)

Two-factor authentication (TOTP) for WordPress with **hard role-based enforcement** and a friendly frontend setup flow for clients — no QR codes hidden on the profile page, no instructions that confuse editors.

## Features

- **Settings page** to choose which WP roles must use 2FA (network admin on multisite, otherwise Settings → Radish 2FA).
- **Frontend setup flow** at `/2fa/setup` and `/2fa/challenge` instead of a wp-admin profile page. Theme overrides via `your-theme/radish-2fa/{template}.php`.
- **Truly mandatory**: without completed 2FA no auth cookie is issued, and existing sessions are terminated as soon as you add a role to enforcement. There is no "skip" option.
- **Multisite-ready**: settings and user meta are network-wide.
- **Backup codes**: 10 codes, shown once at setup, bcrypt-hashed at rest.
- **TOTP secret encrypted at rest** with `sodium_crypto_secretbox`; the key is derived from `AUTH_KEY + SECURE_AUTH_KEY`.
- **API protection**: REST/XML-RPC password logins are blocked for 2FA users; Application Passwords keep working.
- **Lockout recovery**: constant in `wp-config.php`, WP-CLI command, or via wp-admin.

## Installation

### Option 1 — Composer (recommended)

In your site's `composer.json`:

```json
{
    "require": {
        "radishconcepts/radish-wp-2fa": "^0.1",
        "composer/installers": "^2.0"
    },
    "repositories": [
        { "type": "vcs", "url": "https://github.com/radishconcepts/radish-wp-2fa" }
    ],
    "extra": {
        "installer-paths": {
            "wp-content/plugins/{$name}/": [ "type:wordpress-plugin" ]
        }
    }
}
```

```bash
composer require radishconcepts/radish-wp-2fa
```

The plugin installs into `wp-content/plugins/radish-2fa/` (the `installer-name` in the plugin's `composer.json` ensures the directory is named `radish-2fa`, not `radish-wp-2fa`).

### Option 2 — Manual installation

```bash
git clone https://github.com/radishconcepts/radish-wp-2fa.git wp-content/plugins/radish-2fa
cd wp-content/plugins/radish-2fa
composer install --no-dev --optimize-autoloader
```

### Activation

Activate the plugin via WordPress (network-activate on multisite).

**Important**: deactivate any other 2FA plugins (Two Factor, miniOrange, etc.) — their login hooks will conflict.

## Configuration

1. Go to **Network Settings → Radish 2FA** (multisite) or **Settings → Radish 2FA** (single site).
2. Tick the roles that must use 2FA.
3. On multisite: optionally enable "Require 2FA for all super admins" (strongly recommended).
4. Click **Save**.

When you save, active sessions for users newly falling under enforcement are terminated immediately — so they get caught by the enforcement flow on their next request.

## How it works

```
       wp-login.php (username + password)
                   │
                   ▼
   Does their role require 2FA? ───── no ────▶  Normal login
                   │
                  yes
                   │
                   ▼
   Auth cookie is suppressed (no session)
                   │
        ┌──────────┴──────────┐
        │                     │
   no TOTP yet?           already enrolled?
        │                     │
        ▼                     ▼
  /2fa/setup             /2fa/challenge
  (QR + verify           (6-digit OR
   + backup codes)        backup code)
        │                     │
        └──────────┬──────────┘
                   ▼
         wp_set_auth_cookie()
         redirect to redirect_to
```

Tokens live for 5 minutes, are single-use, and are stored in `site_transient` under `sha256(token)` so a database dump never reveals the token itself.

## Lockout recovery

Three ways to get someone out of a 2FA deadlock.

### 1. `wp-config.php` constant

```php
// Single user
define( 'RADISH_2FA_DISABLE_FOR_USER_ID', 1 );

// Multiple users
define( 'RADISH_2FA_DISABLE_FOR_USER_ID', [ 1, 2, 5 ] );
```

For users in this list, the plugin skips **all** enforcement — they log in without 2FA. Only use this for emergencies or service accounts. Remove as soon as it's no longer needed.

### 2. WP-CLI

```bash
# Show the current status of a user
wp radish-2fa status arjan
wp radish-2fa status arjan@example.com
wp radish-2fa status 5

# Reset 2FA for a user (clears secret + backup codes, terminates sessions)
wp radish-2fa disable arjan
```

`<user>` accepts a user ID, login, or email. On multisite, add `--url=…` or `--network` where relevant.

### 3. Via wp-admin (super admin)

Go to the **user-edit page** of the affected user (`Users → All Users → Edit`). At the bottom there's a **Two-factor authentication** block showing the current status (last enrolled, backup codes remaining, last used). Click **Reset two-factor authentication** and confirm — the secret and backup codes are wiped and all sessions for that user are terminated.

On multisite `is_super_admin()` is required; on single site the `edit_users` capability is enough.

## Overriding templates

Place your own version of a template in your theme:

```
your-theme/radish-2fa/setup.php
your-theme/radish-2fa/challenge.php
your-theme/radish-2fa/backup-codes.php
your-theme/radish-2fa/expired.php
```

The plugin checks the theme version first via `locate_template()`. Use the files in `radish-2fa/templates/` as a starting point.

## Translation

The source code is English. A Dutch translation ships in `languages/radish-2fa-nl_NL.po` (plus the compiled `.mo`). For other languages:

```bash
# Add a new language
cp languages/radish-2fa.pot languages/radish-2fa-de_DE.po
# … translate via Poedit, then:
msgfmt -o languages/radish-2fa-de_DE.mo languages/radish-2fa-de_DE.po
```

WordPress loads the correct `.mo` automatically based on the site locale.

## Hooks

| Hook | Type | Args | Purpose |
|------|------|------|---------|
| `radish_2fa_totp_issuer` | filter | `(string) $issuer` | Customize the issuer name shown in the TOTP app (default: site name). |

## Tests

```bash
composer install
composer test
```

Unit tests (PHPUnit 10) cover Crypto, Totp, BackupCodes, Nonce, Routes, and Roles. Lightweight WordPress function stubs in `tests/bootstrap.php` — no full WordPress test suite required.

## Requirements

- PHP **8.1+** (libsodium is built in from 7.2)
- WordPress **6.2+**
- `pragmarx/google2fa` ^8.0
- `bacon/bacon-qr-code` ^2.0 || ^3.0

## Troubleshooting

**"This link has expired" after logging in** — The token is older than 5 minutes or already used. Log in again.

**404 on `/2fa/setup`** — Rewrite rules haven't been flushed. Visit the homepage of that (sub)site once (auto-flush via version check), or go to Settings → Permalinks → Save.

**A user is locked out** — See [Lockout recovery](#lockout-recovery).

**Two Factor / miniOrange interferes** — Deactivate both before using Radish 2FA. Their `wp_login` hooks conflict.

**An API script no longer works** — 2FA users can no longer authenticate against REST/XML-RPC with their password. Generate an **Application Password** (Users → Profile → Application Passwords) and use that instead of the account password.

## Security model

- **TOTP secrets** are encrypted at rest (sodium secretbox, key derived from `AUTH_KEY + SECURE_AUTH_KEY` via HKDF-SHA256). A database leak alone is not enough to recover secrets.
- **Backup codes** are bcrypt-hashed via `wp_hash_password`. Worst-case verify time for an incorrect code is ~800ms (10 hashes × ~80ms) — a natural rate limit.
- **Login nonce**: 128 bits of entropy (`bin2hex(random_bytes(16))`), 5-minute TTL, stored under `sha256(token)`, single-use.
- **Auth cookie suspension**: during the password step `send_auth_cookies → __return_false`; the session token is grabbed from the cookie stream via the `auth_cookie` filter and destroyed immediately. No cookie ever leaves the server before 2FA is complete.
- **`<meta name="referrer" content="no-referrer">`** on every 2FA page prevents token leaks via the Referer header.

## Contributing

See [CONTRIBUTING.md](.github/CONTRIBUTING.md) for development setup, coding standards, and the pull request workflow. Security issues should be reported privately — see [SECURITY.md](.github/SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
