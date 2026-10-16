<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Storage\GitHubSelectionStore;

/**
 * Internal request composition; never adopts, repairs storage or loads plugin PHP.
 * Current reads lock selection then active_plugins; callers own transaction lifetime.
 * SecretsFile can call this while holding its sidecar lock: host transactions must
 * not acquire that sidecar after taking these rows without a consistent lock order.
 * A guard is never post-mutation completion authority: captured cleanup must survive
 * selection/active-file/native-marker changes and use its exact mutation owner.
 */
final class GitHubProviderSelection {
	/** @var array<string, mixed>|null */
	private ?array $state = null;
	/** @var array<string, array{selection_generation:int,owner_tag:string,target_type:string,installed_identity:string,phase:string}> */
	private array $native_owners = array();
	/** @var list<string> */
	private array $active = array();
	/** @var array<string, GitHubReplacementDeclaration|null> */
	private array $candidates           = array();
	private bool $collection_complete   = false;
	private ?ProviderRegistry $registry = null;

	/** The bundled semantic contract is supplied by the qualified host composition. */
	public function __construct(
		private GitHubSelectionStore $store,
		private string $plugin_root,
		private GitHubReplacementDeclaration $bundled
	) {
		try {
			$state      = $this->store->read( true );
			$active     = $this->store->active_plugins();
			$candidates = $this->discover( $active );
			if ( $state !== $this->store->read( true ) || $active !== $this->store->active_plugins() ) {
				return;
			}
			$this->state      = $state;
			$this->active     = $active;
			$this->candidates = $candidates;
		} catch ( \Throwable ) {
			// Missing, malformed or unavailable authority is never bundled approval.
			$this->state = null;
		}
	}

	/** Host binds its exact registry once, before sealing, using this object's guard. */
	public function bind_registry( ProviderRegistry $registry ): void {
		if ( null !== $this->registry || $registry->is_sealed() ) {
			throw new \LogicException( 'GitHub selection requires its unsealed host registry.' );
		}
		$this->registry = $registry;
	}

	/** @return array<string, GitHubReplacementDeclaration|null> Passive registration inputs only. */
	public function candidates(): array {
		return $this->candidates;
	}

	/** Host calls only after the public declaration action returns successfully. */
	public function complete_collection(): void {
		$this->collection_complete = true;
	}

	/** @return array{provider_code:string,selection_generation:int,implementation_fingerprint:string}|null */
	public function expectation(): ?array {
		if ( null === $this->registry || ! $this->registry->is_sealed() ) {
			return null;
		}
		$selection = $this->registry_selection();
		if ( null === $selection ) {
			return null;
		}
		unset( $selection['plugin_basename'] );
		return $selection;
	}

	/**
	 * Destination-local review identity only; success does not bypass permissions,
	 * capabilities, credentials, source/target validation or recovery requirements.
	 * Malformed/absent input requires a new review, never inferred current authority.
	 */
	public function review_refusal( mixed $expectation ): ?string {
		$expectation = self::normalize_expectation( $expectation );
		$current     = $this->expectation();
		if ( null === $current ) {
			return 'provider_unavailable';
		}
		return $current['selection_generation'] === $expectation['selection_generation'] && $current['implementation_fingerprint'] === $expectation['implementation_fingerprint'] ? null : 'provider_selection_changed';
	}

	/** @return array{provider_code:'gh',selection_generation:int,implementation_fingerprint:string} Canonical structure only, never live authority. */
	public static function normalize_expectation( mixed $expectation ): array {
		$keys = array( 'provider_code', 'selection_generation', 'implementation_fingerprint' );
		if ( ! is_array( $expectation ) || count( $expectation ) !== 3 || array_diff( $keys, array_keys( $expectation ) ) || 'gh' !== $expectation['provider_code'] || ! is_int( $expectation['selection_generation'] ) || $expectation['selection_generation'] < 0 || ! is_string( $expectation['implementation_fingerprint'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $expectation['implementation_fingerprint'] ) ) {
			throw new \InvalidArgumentException( 'Fresh provider review is required.' );
		}
		return array(
			'provider_code'              => 'gh',
			'selection_generation'       => $expectation['selection_generation'],
			'implementation_fingerprint' => $expectation['implementation_fingerprint'],
		);
	}

	public function webhook_access_allowed(): bool {
		if ( ! $this->operation_allowed() || 'github-hmac-sha256-v1' !== ( $this->state['declaration']['webhook_signing_format'] ?? null ) ) {
			return false;
		}
		try {
			return $this->registry?->get( 'gh' ) instanceof WebhookNormalizer;
		} catch ( \Throwable ) {
			return false;
		}
	}

	public function uses_connection( object $connection ): bool {
		return $this->store->uses_connection( $connection );
	}

	/** Current authority, also acquiring the first admission lock in caller's transaction. */
	public function deployment_refusal( ?int $generation, string $type, string $identity ): ?string {
		try {
			$state = $this->store->read( true );
			if ( null === $generation || $generation !== $state['selection_generation'] ) {
				return 'provider_selection_changed';
			}
			$marker = $state['native_mutation'] ?? null;
			if ( is_array( $marker ) && 'plugin' === $type ) {
				$marker['installed_identity'] = explode( '/', $marker['installed_identity'] )[0];
				$identity                     = explode( '/', $identity )[0];
			}
			if ( ! $this->operation_allowed() || ( is_array( $marker ) && $marker['target_type'] === $type && $marker['installed_identity'] === $identity ) ) {
				return 'provider_unavailable';
			}
			return null;
		} catch ( \Throwable ) {
			return 'provider_unavailable';
		}
	}

	public function operation_allowed(): bool {
		return null !== $this->expectation();
	}

	/**
	 * Internal registry wire adds basename; the destination-local public expectation does not.
	 * Native-marker ownership and operation-specific permissions remain separate checks.
	 *
	 * @return array{provider_code:string,selection_generation:int,implementation_fingerprint:string,plugin_basename:?string}|null
	 */
	public function registry_selection(): ?array {
		if ( ! $this->collection_complete || null === $this->state || null === $this->registry ) {
			return null;
		}
		try {
			if ( $this->registry->is_sealed() ) {
				$this->registry->get( 'gh' );
			}
			$state  = $this->store->read( true );
			$active = $this->store->active_plugins();
			if ( $this->selection_identity( $state ) !== $this->selection_identity( $this->state ) || $active !== $this->active || 'approved' !== $state['approval'] || ! is_array( $state['declaration'] ) || ! is_int( $state['selection_generation'] ) ) {
				return null;
			}
			$candidates = $this->discover( $active );
			$approved   = new GitHubReplacementDeclaration( $state['declaration'] );
			if ( 'bundled' === $state['selected'] ) {
				$current  = $this->bundled;
				$basename = null;
				if ( array() !== $candidates || ! in_array( $current->plugin_basename, $active, true ) ) {
					return null;
				}
			} else {
				$basename = $approved->plugin_basename;
				$current  = $candidates[ $basename ] ?? null;
				if ( 1 !== count( $candidates ) || null === $current ) {
					return null;
				}
			}
			if ( $current->implementation_fingerprint !== $approved->implementation_fingerprint || $state !== $this->store->read( true ) || $active !== $this->store->active_plugins() ) {
				return null;
			}
			return array(
				'provider_code'              => 'gh',
				'selection_generation'       => $state['selection_generation'],
				'implementation_fingerprint' => $approved->implementation_fingerprint,
				'plugin_basename'            => $basename,
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function selection_identity( array $state ): array {
		unset( $state['native_mutation'] );
		return $state;
	}

	/** @param callable():bool $owns_lock Exact captured updater ownership, never acquisition. */
	public function begin_native_mutation( string $type, string $identity, callable $owns_lock ): string {
		$marker                                      = $this->store->native_mutation_transaction(
			function () use ( $type, $identity, $owns_lock ): array {
				$state = $this->store->read( true );
				if ( isset( $state['native_mutation'] ) || ! $this->owns_native_lock( $owns_lock ) || ! $this->operation_allowed() || ! is_int( $state['selection_generation'] ) ) {
						throw new \RuntimeException( 'GitHub native mutation is unavailable.' );
				}
				$marker                  = array(
					'selection_generation' => $state['selection_generation'],
					'owner_tag'            => bin2hex( random_bytes( 16 ) ),
					'target_type'          => $type,
					'installed_identity'   => $identity,
					'phase'                => 'started',
				);
				$next                    = $state;
				$next['native_mutation'] = $marker;
				if ( ! $this->owns_native_lock( $owns_lock ) || ! $this->store->compare_and_swap( $state, $next ) ) {
					throw new \RuntimeException( 'GitHub native mutation changed.' );
				}
				return $marker;
			}
		);
		$this->native_owners[ $marker['owner_tag'] ] = $marker;
		return $marker['owner_tag'];
	}

	/**
	 * @param callable():bool $owns_lock
	 * @phpstan-impure The captured lock can change between current reads.
	 */
	private function owns_native_lock( callable $owns_lock ): bool {
		return true === $owns_lock();
	}

	/** Captured completion survives guard loss and revocation; it never restores approval. */
	public function complete_native_mutation( string $owner, string $type, string $identity, string $outcome ): void {
		$marker = $this->native_owners[ $owner ] ?? null;
		if ( null === $marker || $marker['target_type'] !== $type || $marker['installed_identity'] !== $identity || ! in_array( $outcome, array( 'verified_success', 'uncertain' ), true ) ) {
			throw new \RuntimeException( 'GitHub native completion owner changed.' );
		}
		unset( $this->native_owners[ $owner ] );
		if ( 'uncertain' === $outcome ) {
			return;
		}
		$this->store->native_mutation_transaction(
			function () use ( $marker ): void {
				$state = $this->store->read( true );
				if ( ( $state['native_mutation'] ?? null ) !== $marker ) {
						throw new \RuntimeException( 'GitHub native completion owner changed.' );
				}
				$next = $state;
				unset( $next['native_mutation'] );
				if ( ! $this->store->compare_and_swap( $state, $next ) ) {
					throw new \RuntimeException( 'GitHub native completion changed.' );
				}
			}
		);
	}

	/** @param array{kind:mixed,configuration:mixed}|null $metadata */
	public function credential_access_allowed( ?array $metadata ): bool {
		if ( null === $this->registry_selection() ) {
			return false;
		}
		if ( null === $metadata ) {
			return true;
		}
		$format  = self::credential_format( $metadata );
		$formats = $this->state['approved_api_formats'] ?? null;
		return null !== $format && is_array( $formats ) && in_array( $format, $formats, true );
	}

	/**
	 * Recognizes normalized records, never token prefixes or provider-executed normalization.
	 * This closed interpretation still requires satellite-owner agreement before activation.
	 *
	 * @param array<string,mixed>|null $metadata
	 */
	public static function credential_format( ?array $metadata ): ?string {
		$configuration = $metadata['configuration'] ?? null;
		if ( ! is_array( $configuration ) || array_keys( $configuration ) !== array( 'owner' ) || ! is_string( $configuration['owner'] ) ) {
			return null;
		}
		if ( 'classic' === ( $metadata['kind'] ?? null ) && '' === $configuration['owner'] ) {
			return 'github-classic-pat-v1';
		}
		if ( 'fine-grained' === ( $metadata['kind'] ?? null ) && 1 === preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?\z/D', $configuration['owner'] ) ) {
			return 'github-fine-grained-pat-v1';
		}
		return null;
	}

	/**
	 * @param list<string> $active
	 * @return array<string, GitHubReplacementDeclaration|null>
	 */
	private function discover( array $active ): array {
		$candidates = array();
		foreach ( $active as $basename ) {
			try {
				$declaration = GitHubReplacementDeclaration::from_plugin_file( $this->plugin_root, $basename );
				if ( null !== $declaration ) {
					$candidates[ $basename ] = $declaration;
				}
			} catch ( \Throwable ) {
				// An unreadable active plugin cannot safely be classified as not a candidate.
				$candidates[ $basename ] = null;
			}
		}
		return $candidates;
	}
}
