<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement\Installation;

interface InstallationStore {
	public const WRITE_APPLIED = 'applied';

	public const WRITE_UNCHANGED = 'unchanged';

	public const WRITE_CONFLICT = 'conflict';

	public const WRITE_FAILED = 'failed';

	/** @return array<string, InstallationRecord> */
	public function all(): array;

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter contracts.
	public function find( string $providerCode, string $repositoryId ): ?InstallationRecord;

	/** @return self::WRITE_APPLIED|self::WRITE_UNCHANGED|self::WRITE_CONFLICT|self::WRITE_FAILED */
	public function save_if_current( InstallationRecord $record, ?InstallationRecord $expected ): string;

	/** @return self::WRITE_APPLIED|self::WRITE_UNCHANGED|self::WRITE_CONFLICT|self::WRITE_FAILED */
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Preserve public named-parameter contracts.
	public function delete_if_current( string $providerCode, string $repositoryId, ?InstallationRecord $expected ): string;
}
