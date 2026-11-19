<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use RuntimeException;

/** An installed package's folder differs from the folder its repository source would install as. */
final class PackageFolderMismatch extends RuntimeException {

	public function __construct(
		public readonly string $folder,
		public readonly string $source,
		public readonly string $slug
	) {
		parent::__construct( 'The installed package folder does not match the folder its repository source installs as.' );
	}
}
