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
lookups compare as described below. The updater's `identifier_mismatch` guard still
prevents changing folders. A plugin identifier is bounded at 255 bytes in total;
an explicit initial Branch identity remains bounded at 191 bytes.

## Comparing folder names

Core compares package folder names with the shared updater-support
`PackageDirectory` methods, apart from the exceptions listed below:

- `installed_folder_matches()` for the identity of one package: connecting an
  installed package, with or without its exact identifier, and an initial
  expected identity. Connecting by name tries the source name first, then its
  lower-case new-install form.
- `names_collide()` for locks, scopes and collisions: an install into a managed
  folder, an interrupted native update, the native-recovery directory scope, a
  theme that names itself as its parent, and Booster's own folder.
- `collision_key()` for keys: the directory entries checked after a deletion.

A value checked against its own stored or WordPress-reported spelling, such as
an adoption row, an attempt record or a native-mutation completion, stays an
exact comparison, as the library documents.

Exceptions, each commented where it is made:

- Full identifiers keep their separator handling, then fold whole with
  `collision_key()`: release-target keys and the nested-path prefix of the
  native-recovery scope.
- Native recovery decides each row in PHP, not with `LOWER(package)`: a Turkish
  or Azeri collation could miss a row and let recovery remove a folder that is
  still managed.
- The uninstall context check folds the same way inline, because
  `uninstall.php` does not load updater-support.
- Full filesystem paths, such as destination existence and child-path checks,
  are decided by the filesystem.
- Database lookups take the table collation's case rule, as the next section
  describes; the unfinished-attempt lock is commented where it is made.

## Database lookups

Package-row and attempt lookups that match `package` or `package_slug` with a
plain `=` take the table collation's case rule, which ignores case on a default
WordPress install. They keep their indexes, including the `FOR UPDATE` lock that
admits one unfinished attempt per package; the batch de-duplication keys in
front of that lock are exact. The safeguard is the exact comparison in PHP after
the read, or the token a write is bound to. `package_slug` is admitted as an
ASCII-only name (`is_valid_name()`) before it is stored, so accent folding cannot
apply. The tables are assumed to use a case-insensitive collation; nothing
verifies it.
