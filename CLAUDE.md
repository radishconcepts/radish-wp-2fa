# Radish 2FA — project conventions

## Internationalisation (i18n)

**Always treat English as the source language and Dutch (`nl_NL`) as a
first-class translation target.**

Whenever you add or change a user-facing string anywhere in the codebase
(PHP `__()`/`_e()`/`esc_html_e()`/`esc_html__()` calls, template strings,
admin notices, error messages, button labels, …):

1. Write the source string in English with the `radish-2fa` text domain.
2. Regenerate the POT file:
   ```
   wp i18n make-pot . languages/radish-2fa.pot --slug=radish-2fa --domain=radish-2fa
   ```
3. Sync the Dutch catalog and add Dutch translations for every new entry:
   ```
   msgmerge --update --backup=none --no-fuzzy-matching \
     languages/radish-2fa-nl_NL.po languages/radish-2fa.pot
   msgattrib --untranslated languages/radish-2fa-nl_NL.po
   # …fill in msgstr values, then…
   msgfmt --check --statistics \
     -o languages/radish-2fa-nl_NL.mo languages/radish-2fa-nl_NL.po
   ```
4. Verify `msgattrib --untranslated` reports zero non-header entries
   before considering the change complete.

The Dutch translations are part of the deliverable; an English-only PR
is incomplete.

### Tone for Dutch translations

Tutoyeer (use **je**/**jou**, not **u**) — match the existing catalog.
Keep terminology consistent with the rest of `radish-2fa-nl_NL.po`
(e.g. *tweestapsverificatie*, *methode*, *back-upcodes*, *inlog*).

## Tests

`vendor/bin/phpunit` from the project root. PHP 8.1+ required.
