# Contributing to Radish 2FA

Thanks for taking the time to contribute! This document covers how to set up the project locally, the conventions we follow, and what to expect from the review process.

## Code of Conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md). By participating, you agree to uphold it.

## Reporting Issues

- **Security vulnerabilities** — see [SECURITY.md](SECURITY.md). **Do not** open a public issue.
- **Bug reports** — use the [bug report issue template](https://github.com/radishconcepts/radish-wp-2fa/issues/new?template=01-bug-report.yml).
- **Feature requests** — use the [feature request issue template](https://github.com/radishconcepts/radish-wp-2fa/issues/new?template=02-feature-request.yml).
- **Support questions** — see [SUPPORT.md](SUPPORT.md).

## Development Setup

### Prerequisites

- PHP **8.1 or higher** (libsodium is built in from 7.2)
- Composer 2
- A WordPress site to test against (Laravel Herd, Local, wp-env, etc.)

### Clone and install

```bash
git clone git@github.com:radishconcepts/radish-wp-2fa.git
cd radish-wp-2fa
composer install
```

Symlink (or `composer require` from path) the plugin into your test site's `wp-content/plugins/radish-2fa/` directory.

### Run the test suite

```bash
composer test
```

Tests use a lightweight WordPress function-stub bootstrap (`tests/bootstrap.php`) — no full WordPress test suite is required. The CI matrix runs against PHP 8.1, 8.2, 8.3, and 8.4.

## Coding Standards

- **PHP version target**: 8.1 (avoid 8.2+ syntax in `src/`).
- **Namespacing**: `RadishConcepts\TwoFactor\` (PSR-4, autoloaded from `src/`).
- **Strict types**: every PHP file starts with `declare( strict_types=1 );`.
- **Style**: WordPress-flavoured PSR (tabs for indentation, Yoda conditionals are fine but not required, spaces inside parentheses for function calls).
- **Hooks**: prefix every action/filter with `radish_2fa_`.
- **Translatable strings**: use the `radish-2fa` text domain.

## Tests Are Required

Every behavioural change needs at least one test. The existing suites cover Crypto, Totp, BackupCodes, Nonce, Routes, and Roles — pick the closest fit or add a new one in `tests/`.

If you can't see how to test something, open the PR as a draft and ask — we'll work through it together.

## Pull Request Process

1. Create a topic branch from `main` (e.g. `feat/email-recovery`, `fix/multisite-flush`).
2. Keep commits focused; rebase or squash trivia before review.
3. Update the **Unreleased** section of [CHANGELOG.md](../CHANGELOG.md).
4. Make sure `composer test` passes locally.
5. Open the PR and fill in the template — link the related issue with `Closes #XXX`.
6. CI must be green before review. We aim to respond within 5 business days.

## Commit Messages

Conventional Commits are encouraged but not required:

```
feat: allow custom TOTP issuer per site
fix(routes): flush rewrite rules on multisite activation
docs: clarify lockout-recovery flow
```

Types we use: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`.

## Releasing (maintainers only)

1. Bump the version in `radish-2fa.php` (the `Plugin Version` header and the `RADISH_2FA_VERSION` constant) and in `readme.txt` (`Stable tag`).
2. Move the **Unreleased** section of `CHANGELOG.md` under a new version heading.
3. Tag the release: `git tag -a v0.2.0 -m "Release 0.2.0" && git push --tags`.
4. Draft the GitHub Release from the tag, pasting the changelog entry.
