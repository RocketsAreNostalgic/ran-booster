# Translating RAN Booster

RAN Booster uses WordPress gettext internationalisation with the text domain
`ran-booster`. The source catalogue is
[`languages/ran-booster.pot`](../languages/ran-booster.pot). It contains
translatable strings from both PHP and JavaScript.

This guide explains how to create or update a translation, build the runtime
files WordPress needs, test the result, and submit it as a pull request.

## What translators work from

Always start from `languages/ran-booster.pot`. Do not extract strings from the
source yourself and do not edit the POT as part of a translation-only change.
The project regenerates and verifies the POT from the PHP and JavaScript source.

Use a WordPress locale such as `fr_FR`, `de_DE`, or `es_ES`. A translation uses
that locale in its file names:

```text
languages/ran-booster-fr_FR.po
languages/ran-booster-fr_FR.mo
languages/ran-booster-fr_FR-<source-hash>.json
```

The `.po` file is the editable translation source. The `.mo` file is used by
PHP. WordPress JavaScript translations are split into one Jed-formatted `.json`
file per JavaScript source file, so one completed translation can produce
several JSON files.

Do not rename the generated JSON files. Their source hashes are how WordPress
matches a translation to the JavaScript file that needs it.

## Tools

You can translate the PO file with any gettext-compatible editor. Poedit is a
common graphical option. Command-line contributors can use GNU gettext tools.

RAN Booster uses WP-CLI 2.12.0 for reproducible internationalisation tooling.
The repository's existing test-fixture generator uses these WP-CLI commands:

- `wp i18n make-mo`
- `wp i18n make-json`

For contributor updates, `wp i18n update-po` is the merge command used to bring
new messages from the committed POT into an existing PO file.

WP-CLI documents these commands at
<https://developer.wordpress.org/cli/commands/i18n/>.

## Create a new translation

With Poedit, open `languages/ran-booster.pot`, create a new translation for your
locale, and save it as `languages/ran-booster-<locale>.po`.

With GNU gettext, the equivalent starting point is:

```sh
locale=fr_FR
msginit \
  --input=languages/ran-booster.pot \
  --locale="${locale}.UTF-8" \
  --output-file="languages/ran-booster-${locale}.po"
```

Replace `fr_FR` with the WordPress locale you are translating.

Complete the PO header as well as the messages. In particular, check that its
`Language` and `Plural-Forms` values are correct for the locale.

## Update an existing translation

When `languages/ran-booster.pot` changes, merge its current messages into the
existing PO before continuing the translation:

```sh
locale=fr_FR
wp i18n update-po \
  languages/ran-booster.pot \
  "languages/ran-booster-${locale}.po"
```

Review new and changed messages after the merge. Do not assume a previous
translation is still correct if the English source text changed.

## Translation rules

Translate for meaning and for the WordPress administration context rather than
word-for-word. Keep these details intact:

- **Product and organisation names:** keep `RAN Booster` and `Rockets Are
  Nostalgic` as names. Likewise, do not translate established product names
  such as WordPress, GitHub, or Bitbucket.
- **Placeholders:** preserve placeholders such as `%s`, `%1$s`, `%d`, and named
  tokens such as `{queued}`, `{running}`, and `{skipped}`. A translated string
  must contain the placeholders the application expects.
- **Plural forms:** translate every plural form required by the locale. Do not
  replace a plural-aware message with a single fixed form.
- **Context:** gettext `msgctxt` entries distinguish identical English words
  used for different purposes. Translate each entry according to its context.
- **Translator comments:** comments beginning with `translators:` explain
  placeholders or intent. Read them before translating the associated message.
- **Technical values:** keep URLs, code identifiers, command names, file names,
  repository references, and other literal technical values unchanged unless
  the source text clearly asks for prose around them to be translated.
- **Markup:** preserve any HTML or other markup contained in a message while
  translating its human-readable text.

If a source string is ambiguous or appears difficult to translate safely, open
an issue or mention it in the pull request rather than guessing around a
placeholder or technical contract.

## Build the runtime translation files

After editing the PO, generate both the PHP and JavaScript runtime files. Build
into a clean temporary directory first so an updated locale cannot leave stale
tracked JSON catalogues behind. The commands below use Bash, matching the
repository's fixture-generation script.

```bash
locale=fr_FR
po="languages/ran-booster-${locale}.po"
temporary_dir=$(mktemp -d)
trap 'rm -rf "$temporary_dir"' EXIT HUP INT TERM

wp i18n make-mo \
  "$po" \
  "$temporary_dir/ran-booster-${locale}.mo"

wp i18n make-json \
  "$po" \
  "$temporary_dir" \
  --no-purge

shopt -s nullglob
existing_json=( languages/ran-booster-"${locale}"-*.json )
generated_json=( "$temporary_dir"/ran-booster-"${locale}"-*.json )
shopt -u nullglob

mv "$temporary_dir/ran-booster-${locale}.mo" \
  "languages/ran-booster-${locale}.mo"
rm -f -- "${existing_json[@]}"
if (( ${#generated_json[@]} > 0 )); then
  mv "${generated_json[@]}" languages/
fi
```

`--no-purge` is important: it leaves the JavaScript messages in the PO while
also generating their Jed JSON files.

Generating into a clean directory and replacing the complete locale JSON set is
also important. If a translated JavaScript source disappears from the PO,
`make-json` will not generate a replacement for its old hashed catalogue; the
explicit cleanup above prevents that obsolete JSON file from continuing to ship.

After `make-json`, expect one or more files named like:

```text
languages/ran-booster-fr_FR-0740f819f199a9141dd38d191f417a06.json
```

The exact hashes depend on the JavaScript source files represented by the
translation. Generate them with WP-CLI; do not calculate or edit the hashes by
hand.

Whenever the PO changes, regenerate the MO and JSON files so the submitted
runtime files match the editable source.

## Test the translation

For a local test installation, place the PO, MO, and generated JSON files in the
installed plugin's `languages` directory, then switch WordPress to the target
site language.

Check more than one Booster screen. PHP and JavaScript use different runtime
catalogues, so seeing one translated page does not prove that both paths work.
At minimum, exercise:

- a normal Booster administration page with PHP-rendered labels;
- a repository/package screen that changes text in JavaScript;
- Transporter if the translation contains Transporter messages;
- release-management controls if the translation contains release-management
  messages.

Watch the browser console for JavaScript errors and confirm that placeholders,
plural forms, buttons, status messages, and accessible labels still make sense
in context.

Maintainers can use the repository's existing localisation fixtures and
installed WordPress smoke tests as reference for how Booster verifies PHP and
JavaScript translation loading. Those fixtures under `tests/fixtures/i18n/` are
test data, not translations to edit or copy as a starting catalogue.

## Submit a translation pull request

RAN Booster is distributed through verified GitHub Release archives rather than
WordPress.org. A translation intended to ship with Booster therefore needs the
runtime files that the release can carry; a PO by itself does not translate the
JavaScript interface.

For a completed locale, prepare a focused branch such as:

```text
i18n/fr_FR
```

A translation pull request should contain:

1. `languages/ran-booster-<locale>.po` — the editable translation source.
2. `languages/ran-booster-<locale>.mo` — generated from that PO for PHP.
3. Every `languages/ran-booster-<locale>-<source-hash>.json` file generated by
   `wp i18n make-json` for JavaScript.

Do not include unrelated source, formatting, or documentation changes. Do not
modify `languages/ran-booster.pot` unless the pull request also intentionally
changes the source strings and regenerates the catalogue.

Use a release-triggering Conventional Commit-style pull request title, for
example:

```text
feat(i18n): add French (fr_FR) translation
```

In the pull request description, state:

- the locale and language;
- whether the translation is complete or which areas remain untranslated;
- how it was reviewed, including whether a native speaker reviewed it;
- the WP-CLI version used to generate the MO and JSON files;
- which Booster screens were tested locally.

Open the pull request against `main` and follow the general contribution rules
in [`CONTRIBUTING.md`](../CONTRIBUTING.md).

At the time this guide was added, the repository did not yet contain bundled
community translations. `tests/LocalisationCatalogContractTest.php` currently
requires `languages/` to contain only `ran-booster.pot`, so the first locale PR
cannot pass the repository's catalogue contract until a maintainer updates that
contract to admit the new runtime files. Translation contributors do not need to
change unrelated PHP tests or runtime code unless a maintainer asks for that
companion change during review.

## Maintainer catalogue commands

Translators normally work from the committed POT rather than regenerating it.
For maintainers, Booster exposes its source-catalogue commands through Composer:

```sh
composer i18n:pot
composer i18n:check
```

`i18n:pot` regenerates `languages/ran-booster.pot` from the production PHP and
JavaScript source. `i18n:check` verifies that the committed POT is current and
that runtime strings consistently use the `ran-booster` text domain.

The implementation lives in [`scripts/make-pot.sh`](../scripts/make-pot.sh).
