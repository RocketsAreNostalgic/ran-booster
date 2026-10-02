<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

interface PreparedArchive {

	public function get_url(): string;

	/**
	 * Return the immutable provider revision used by the archive URL.
	 */
	public function get_resolved_ref(): string;

	/**
	 * Re-check an automatic deployment immediately before mutation.
	 *
	 * Manual preparations implement this as a no-op. This method must remain
	 * callable after cleanup() has removed one-request archive authentication.
	 */
	public function verify_current_head(): void;

	/**
	 * Remove any temporary request authentication or hooks.
	 *
	 * Implementations must make repeated cleanup calls safe.
	 */
	public function cleanup(): void;
}
