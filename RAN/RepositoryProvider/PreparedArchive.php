<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface PreparedArchive {

	public function getUrl(): string;

	/**
	 * Return the immutable provider revision used by the archive URL.
	 */
	public function getResolvedRef(): string;

	/**
	 * Re-check an automatic deployment immediately before mutation.
	 *
	 * Manual preparations implement this as a no-op. This method must remain
	 * callable after cleanup() has removed one-request archive authentication.
	 */
	public function verifyCurrentHead(): void;

	/**
	 * Remove any temporary request authentication or hooks.
	 *
	 * Implementations must make repeated cleanup calls safe.
	 */
	public function cleanup(): void;
}
