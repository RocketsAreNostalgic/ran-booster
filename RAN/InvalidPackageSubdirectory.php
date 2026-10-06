<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use InvalidArgumentException;

/** A submitted package subdirectory is not a safe repository-relative path. */
final class InvalidPackageSubdirectory extends InvalidArgumentException {
}
