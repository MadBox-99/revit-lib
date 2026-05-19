# Languages directory

This directory is reserved for the plugin's translation files.

## Generating the POT file

The source strings inside the plugin are mostly Hungarian (matching the customer-facing copy). To extract them into a `.pot` template:

```bash
# Using WP-CLI (recommended)
wp i18n make-pot . languages/cegem360-revit-library.pot \
    --domain=cegem360-revit-library \
    --headers='{"Last-Translator":"Cegem360","Language-Team":"Cegem360"}'
```

Or use [Poedit](https://poedit.net/) → New from POT/PO file → Update from sources, pointing it at the plugin root.

## Creating a translation

1. Copy `cegem360-revit-library.pot` → `cegem360-revit-library-hu_HU.po`
2. Open in Poedit, set Language=Hungarian, translate each `msgstr`
3. Save → Poedit auto-generates the `.mo` file
4. Drop both `.po` and `.mo` into this directory

WordPress automatically loads `cegem360-revit-library-{locale}.mo` when the site locale matches.

## Notes

Since source strings are already in Hungarian, a `hu_HU` translation can have `msgstr` identical to `msgid` (technically a no-op, but it lets WP recognize the translation as available — sometimes preferable to relying on the source fallback).

For English fallback (`en_US`), each `msgstr` would translate the Hungarian source to English.
