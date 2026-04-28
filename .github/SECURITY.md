# Security Policy

Radish 2FA enforces login security for WordPress sites, so we take vulnerability reports seriously.

## Supported Versions

Security fixes are only backported to the latest minor release. Earlier versions are not patched.

| Version | Supported          |
| ------- | ------------------ |
| 0.1.x   | :white_check_mark: |
| < 0.1   | :x:                |

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security problems.** Public reports give attackers a head start before sites can patch.

Use one of the private channels below:

1. **GitHub Private Vulnerability Reporting** (preferred) — open an advisory at <https://github.com/radishconcepts/radish-wp-2fa/security/advisories/new>.
2. **Email** — send a description to **security@radishconcepts.com**. PGP available on request.

Include, at minimum:

- The affected version(s) and a description of the vulnerability.
- Reproduction steps or a proof-of-concept.
- The impact you believe the issue has (auth bypass, secret disclosure, privilege escalation, etc.).

## Response Timeline

We aim to:

- Acknowledge your report within **3 business days**.
- Confirm or dispute the issue within **7 business days**.
- Ship a fix within **30 days** for confirmed High/Critical vulnerabilities.

If you have not heard back within 5 business days, please re-send and CC `arjan@radishconcepts.com`.

## Disclosure

We coordinate disclosure with the reporter. Once a fix is released, we publish a GitHub Security Advisory crediting the reporter (unless they prefer to remain anonymous).

## Out of Scope

- Vulnerabilities in WordPress core, third-party plugins, or themes — report those upstream.
- Issues that require a malicious site administrator (already inside the trust boundary).
- Reports based on outdated dependencies without a working exploit.
