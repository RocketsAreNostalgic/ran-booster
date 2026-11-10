<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use RuntimeException;

/** An edit tried to move an installed package to a different Git provider or provider repository. */
final class PackageRepositoryChangeRejected extends RuntimeException {
}
