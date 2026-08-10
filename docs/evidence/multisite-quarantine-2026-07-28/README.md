# Sanitized Multisite quarantine evidence

This directory preserves the reusable, non-secret proof output from the `multi-site-test` RAN Booster quarantine/uninstall matrix captured on 2026-07-28.

The retained scenario files are limited to timestamps, plugin inventories, state summaries, and package-file checksums. The source installation remains available as the deliberate Multisite fixture, but these copies allow later retirement without losing the historical proof.

The following source material is intentionally excluded:

- `S0-restore/` in full, including its database, `wp-config.php`, private-storage bundle, and checksums
- `.sidecar-path`
- `A0-before-ambiguous-uninstall/wp-config.backup`
- `S0-single-site/cron.json`
- any Booster private sidecar, provider token, raw database dump, or credential-bearing configuration

These are evidence snapshots, not fixtures to restore. Recreate future test state from maintained package source and fresh disposable credentials.
