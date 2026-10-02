<?php

declare(strict_types=1);

namespace Tests\WordPress;

use RAN\PackageSource;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;

final class RuntimeReleaseStore extends ManagedReleaseStore {

	/** @var list<array<string, mixed>> */
	public array $transitions = array();

	/** @var list<array<string, mixed>> */
	public array $channel_changes = array();

	public ?\Throwable $transition_failure     = null;
	public ?\Throwable $channel_change_failure = null;

	/** @param array<string, ManagedReleaseConfiguration> $configurations */
	public function __construct( private array $configurations = array() ) {
	}

	public function configuration( string $type, string $identifier ): ?ManagedReleaseConfiguration {
		return $this->configurations[ $type . "\0" . $identifier ] ?? null;
	}

	public function replace_configuration( string $type, string $identifier, ManagedReleaseConfiguration $configuration ): void {
		$this->configurations[ $type . "\0" . $identifier ] = $configuration;
	}

	public function transition(
		string $type,
		string $identifier,
		PackageSource $expected_source,
		int $expected_revision,
		PackageSource $new_source,
		?ManagedReleaseConfiguration $configuration,
		int $user_id
	): bool {
		if ( null !== $this->transition_failure ) {
			throw $this->transition_failure;
		}
		$this->transitions[] = array(
			'type'              => $type,
			'identifier'        => $identifier,
			'expected_source'   => $expected_source,
			'expected_revision' => $expected_revision,
			'new_source'        => $new_source,
			'configuration'     => $configuration,
			'user_id'           => $user_id,
		);

		return true;
	}

	public function change_channel(
		string $type,
		string $identifier,
		int $expected_revision,
		string $channel,
		int $user_id
	): bool {
		if ( null !== $this->channel_change_failure ) {
			throw $this->channel_change_failure;
		}
		$this->channel_changes[] = array(
			'type'              => $type,
			'identifier'        => $identifier,
			'expected_revision' => $expected_revision,
			'channel'           => $channel,
			'user_id'           => $user_id,
		);

		return true;
	}
}
