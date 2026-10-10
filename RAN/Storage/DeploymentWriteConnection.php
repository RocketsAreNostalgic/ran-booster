<?php

declare(strict_types=1);

namespace RAN\Storage;

/** Native opt-in for deployment transaction and insert operations. */
interface DeploymentWriteConnection extends SqlReadConnection {

	public function query( string $query ): mixed;

	/** @param array<string, mixed> $data */
	public function insert( string $table, array $data ): mixed;
}
