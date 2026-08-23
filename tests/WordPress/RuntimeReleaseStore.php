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
	public array $channelChanges = array();

	public ?\Throwable $transitionFailure    = null;
	public ?\Throwable $channelChangeFailure = null;

	/** @param array<string, ManagedReleaseConfiguration> $configurations */
	public function __construct( private array $configurations = array() ) {
	}

	public function configuration( string $type, string $identifier ): ?ManagedReleaseConfiguration {
		return $this->configurations[ $type . "\0" . $identifier ] ?? null;
	}

	public function replaceConfiguration( string $type, string $identifier, ManagedReleaseConfiguration $configuration ): void {
		$this->configurations[ $type . "\0" . $identifier ] = $configuration;
	}

	public function transition(
		string $type,
		string $identifier,
		PackageSource $expectedSource,
		int $expectedRevision,
		PackageSource $newSource,
		?ManagedReleaseConfiguration $configuration,
		int $userId
	): bool {
		if ( null !== $this->transitionFailure ) {
			throw $this->transitionFailure;
		}
		$this->transitions[] = array(
			'type'              => $type,
			'identifier'        => $identifier,
			'expected_source'   => $expectedSource,
			'expected_revision' => $expectedRevision,
			'new_source'        => $newSource,
			'configuration'     => $configuration,
			'user_id'           => $userId,
		);

		return true;
	}

	public function changeChannel(
		string $type,
		string $identifier,
		int $expectedRevision,
		string $channel,
		int $userId
	): bool {
		if ( null !== $this->channelChangeFailure ) {
			throw $this->channelChangeFailure;
		}
		$this->channelChanges[] = array(
			'type'              => $type,
			'identifier'        => $identifier,
			'expected_revision' => $expectedRevision,
			'channel'           => $channel,
			'user_id'           => $userId,
		);

		return true;
	}
}
