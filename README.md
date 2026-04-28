# Radish 2FA

[![Tests](https://github.com/radishconcepts/radish-wp-2fa/actions/workflows/tests.yml/badge.svg)](https://github.com/radishconcepts/radish-wp-2fa/actions/workflows/tests.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL_v2+-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-8892BF.svg)](https://www.php.net/)

Tweestapsverificatie (TOTP) voor WordPress met **harde rol-gebaseerde enforcement** en een vriendelijke frontend setup-flow voor klanten — geen QR-codes op de profielpagina, geen instructies waarvan editors flippen.

## Belangrijkste features

- **Settings-pagina** waar je per WP-rol bepaalt wie 2FA moet gebruiken (network admin op multisite, anders Settings → Radish 2FA).
- **Frontend setup-flow** op `/2fa/setup` en `/2fa/challenge` in plaats van een wp-admin profielpagina. Theme-overschrijving via `your-theme/radish-2fa/{template}.php`.
- **Echt verplicht**: zonder voltooide 2FA wordt geen auth-cookie uitgegeven, en bestaande sessies worden afgekapt zodra je een rol aan enforcement toevoegt. Er is geen "skip" mogelijk.
- **Multisite-ready**: settings + user meta zijn network-wide.
- **Backup codes**: 10 stuks, eenmalig getoond bij setup, bcrypt-gehashed at rest.
- **TOTP-secret encrypted at rest** met `sodium_crypto_secretbox`; sleutel wordt afgeleid uit `AUTH_KEY + SECURE_AUTH_KEY`.
- **API-bescherming**: REST/XML-RPC password-logins worden geblokkeerd voor 2FA-users; Application Passwords blijven werken.
- **Lockout-recovery**: constant in `wp-config.php`, WP-CLI commando, of via wp-admin.

## Installatie

### Optie 1 — Composer (aanbevolen)

In je site's `composer.json`:

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

De plugin wordt geïnstalleerd in `wp-content/plugins/radish-2fa/` (de `installer-name` in de plugin's `composer.json` zorgt dat de directory `radish-2fa` heet, niet `radish-wp-2fa`).

### Optie 2 — Handmatige installatie

```bash
git clone https://github.com/radishconcepts/radish-wp-2fa.git wp-content/plugins/radish-2fa
cd wp-content/plugins/radish-2fa
composer install --no-dev --optimize-autoloader
```

### Activeren

Activeer de plugin via WordPress (network-activate op multisite).

**Belangrijk**: deactiveer eventuele andere 2FA-plugins (Two Factor, miniorange, etc.) — anders interfereren hun login-hooks.

## Configuratie

1. Ga naar **Network Settings → Radish 2FA** (multisite) of **Settings → Radish 2FA** (single site).
2. Vink de rollen aan die 2FA verplicht moeten gebruiken.
3. Op multisite: optioneel "Verplicht 2FA voor alle super admins" (sterk aanbevolen).
4. Klik **Save**.

Bij het opslaan worden actieve sessies van users die nu nieuw onder enforcement vallen direct beëindigd — zodat ze bij hun volgende request gevangen worden door de enforcement-flow.

## Hoe het werkt

```
       wp-login.php (gebruikersnaam + wachtwoord)
                   │
                   ▼
   Heeft hun rol 2FA verplicht? ───── nee ────▶  Normale login
                   │
                  ja
                   │
                   ▼
   Auth-cookie wordt onderdrukt (geen sessie)
                   │
        ┌──────────┴──────────┐
        │                     │
   nog geen TOTP?         al enrolled?
        │                     │
        ▼                     ▼
  /2fa/setup             /2fa/challenge
  (QR + verify           (6-digit OF
   + backup codes)        backup code)
        │                     │
        └──────────┬──────────┘
                   ▼
         wp_set_auth_cookie()
         redirect naar redirect_to
```

Tokens leven 5 minuten, zijn eenmalig, en worden in `site_transient` opgeslagen onder `sha256(token)` zodat een DB-dump het token zelf niet onthult.

## Lockout-recovery

Drie manieren om iemand uit een 2FA-deadlock te halen.

### 1. `wp-config.php` constant

```php
// Eén user
define( 'RADISH_2FA_DISABLE_FOR_USER_ID', 1 );

// Meerdere users
define( 'RADISH_2FA_DISABLE_FOR_USER_ID', [ 1, 2, 5 ] );
```

Voor de users in deze lijst slaat de plugin **alle** enforcement over — zij loggen in zonder 2FA. Gebruik dit alleen tijdens noodgevallen of voor service-accounts. Verwijder zodra niet meer nodig.

### 2. WP-CLI

```bash
# Toon de huidige status van een user
wp radish-2fa status arjan
wp radish-2fa status arjan@example.com
wp radish-2fa status 5

# Reset 2FA voor een user (wist secret + backup codes, beëindigt sessies)
wp radish-2fa disable arjan
```

`<user>` accepteert user-ID, login of email. Voor multisite voeg je `--url=…` of `--network` toe waar relevant.

### 3. Via wp-admin (super admin)

Ga naar de **user-edit pagina** van de getroffen gebruiker (`Users → All Users → Edit`). Onderaan staat een blok **Two-factor authentication** met de actuele status (laatst ingesteld, aantal back-upcodes over, laatst gebruikt). Klik **Reset two-factor authentication** en bevestig — de secret + backup codes worden gewist en alle sessies van die user worden beëindigd.

Op multisite is alleen `is_super_admin()` voldoende; op single-site volstaat `edit_users` capability.

## Templates overschrijven

Plaats een eigen versie van een template in je theme:

```
your-theme/radish-2fa/setup.php
your-theme/radish-2fa/challenge.php
your-theme/radish-2fa/backup-codes.php
your-theme/radish-2fa/expired.php
```

De plugin pakt eerst de theme-versie via `locate_template()`. Als startpunt kun je de bestanden uit `radish-2fa/templates/` kopiëren.

## Vertalen

De broncode is Engels. Een Nederlandse vertaling zit in `languages/radish-2fa-nl_NL.po` (+ gecompileerde `.mo`). Voor andere talen:

```bash
# Nieuwe taal toevoegen
cp languages/radish-2fa.pot languages/radish-2fa-de_DE.po
# … vertaal via Poedit, dan:
msgfmt -o languages/radish-2fa-de_DE.mo languages/radish-2fa-de_DE.po
```

WordPress laadt de juiste `.mo` automatisch op basis van site-locale.

## Hooks

| Hook | Type | Args | Doel |
|------|------|------|------|
| `radish_2fa_totp_issuer` | filter | `(string) $issuer` | Pas de issuer-naam in de TOTP-app aan (default: site name). |

## Tests

```bash
composer install
composer test
```

Unit-tests (PHPUnit 10) dekken Crypto, Totp, BackupCodes, Nonce, Routes en Roles. Lichtgewicht WP function-stubs in `tests/bootstrap.php` — geen volledige WordPress test-suite nodig.

## Vereisten

- PHP **8.1+** (libsodium ingebouwd vanaf 7.2)
- WordPress **6.2+**
- `pragmarx/google2fa` ^8.0
- `bacon/bacon-qr-code` ^2.0 || ^3.0

## Troubleshooting

**"Deze link is verlopen" na inloggen** — Token is ouder dan 5 minuten of al gebruikt. Log opnieuw in.

**404 op `/2fa/setup`** — Rewrite rules zijn niet geflusht. Bezoek de homepage van die (sub)site één keer (auto-flush via version-check), of ga naar Settings → Permalinks → Save.

**Gebruiker is locked-out** — Zie [Lockout-recovery](#lockout-recovery).

**Two Factor / miniorange interfereert** — Deactiveer beide voor je Radish 2FA gaat gebruiken. Hun `wp_login` hooks botsen.

**API-script werkt niet meer** — 2FA-users kunnen niet meer via wachtwoord op REST/XML-RPC. Genereer een **Application Password** (Users → Profile → Application Passwords) en gebruik die in plaats van het account-wachtwoord.

## Beveiligingsmodel

- **TOTP-secret** at-rest encrypted (sodium secretbox, key uit `AUTH_KEY + SECURE_AUTH_KEY` via HKDF-SHA256). DB-lek alleen is niet genoeg om secrets te recoveren.
- **Backup codes** bcrypt-gehashed via `wp_hash_password`. Worst-case verify-tijd voor verkeerde code = ~800ms (10 hashes × ~80ms) — natural rate-limit.
- **Login-nonce**: 128 bits entropie (`bin2hex(random_bytes(16))`), 5-min TTL, opgeslagen onder `sha256(token)`, eenmalig consumeerbaar.
- **Auth-cookie suspending**: tijdens password-step `send_auth_cookies → __return_false`; sessie-token uit cookie-stream gegrepen via `auth_cookie` filter en direct weer vernietigd. Geen cookie verlaat ooit de server zonder voltooide 2FA.
- **`<meta name="referrer" content="no-referrer">`** op alle 2FA-pagina's voorkomt token-leak via Referer-headers.

## License

GPL-2.0-or-later.
