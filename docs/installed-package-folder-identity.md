# Installed package folder identity

For an installed plugin or theme, `get_slug()` returns its installed folder.
The plugin file or theme stylesheet supplies that identity, including its case.
A repository subdirectory locates source content and never renames the target.
The unused transient installation setter and field have been removed.

Booster follows WordPress: one plugin folder is one package, even when it
contains multiple plugin files. Updating the managed package replaces the
whole folder, including other files stored in it.

New Branch installs still select a lower-case destination folder.
The shared updater-support folder rule admits mixed-case installed names up to
191 bytes. Installed identifiers retain their exact case for writes; ownership
lookups remain case-folded. The updater's `identifier_mismatch` guard still
prevents changing folders. A plugin identifier is bounded at 255 bytes in total;
an explicit initial Branch identity remains bounded at 191 bytes.
