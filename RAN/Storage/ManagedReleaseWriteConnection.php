<?php

declare(strict_types=1);

namespace RAN\Storage;

/** Native opt-in for managed-release transactions and conditional updates. */
interface ManagedReleaseWriteConnection extends SqlReadConnection {

	public function query( string $query ): mixed;

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( string $table, array $data, array $where ): mixed;
}
