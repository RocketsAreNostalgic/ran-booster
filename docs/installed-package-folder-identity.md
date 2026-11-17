# Installed package folder identity

For an installed plugin or theme, `get_slug()` returns its installed folder.
The plugin file or theme stylesheet supplies that identity, including its case.
A repository subdirectory locates source content and never renames the target.
A transient installation slug cannot override an installed plugin or theme.
Generic packages without a runtime identity retain the explicit transient fallback.

New Branch installs still select a lower-case destination folder.
Part 1 keeps the lower-case request and stored-attempt guards unchanged.
The updater's `identifier_mismatch` guard still prevents changing folders.
Mixed-case deployment admission awaits the coordinated dependency work in #999.
