<?php

declare(strict_types=1);

namespace RAN\Storage;

/** Native opt-in for credential-usage counts and detail reads. */
interface CredentialUsageConnection extends SqlReadConnection {

	public function get_var( string $query ): mixed;
}
