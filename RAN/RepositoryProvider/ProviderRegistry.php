<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use LogicException;
use RAN\Logging\BoosterLogger;
use RAN\PackageArtifactLimit;
use RAN\Provider\ProviderCapability as ProviderCapabilityContract;
use RAN\RepositoryProvider\Admin\ProviderNavigationOrderer;

final class ProviderRegistry {

	/**
	 * @var array<string, RepositoryProvider>
	 */
	private array $providers = array();

	/**
	 * @var array<string, ProviderMetadata>
	 */
	private array $provider_metadata       = array();
	private bool $sealed                   = false;
	private bool $registration_in_progress = false;
	private ProviderSecretPolicyCatalog $secret_policies;
	private ?\Closure $credential_store_factory;
	private ?\Closure $delivery_evidence_reader_factory;
	private ProviderRegistrationContext $registration_context;
	/** @var \Closure(ProviderCode): \RAN\Activity\ActivityReporter|null */
	private ?\Closure $activity_reporter_factory;
	/** @var \Closure(): ?array{provider_code: string, selection_generation: int, implementation_fingerprint: string, plugin_basename: ?string}|null */
	private ?\Closure $github_selection_guard;
	/** @var array<string, GitHubReplacementDeclaration|null> */
	private array $github_candidates;
	/** @var array<string, \Closure> */
	private array $github_factories = array();
	private ?\Closure $bundled_github_factory;
	private bool $github_collection_invalid                         = false;
	private bool $github_finalizing                                 = false;
	private ?GitHubReplacementDeclaration $github_final_declaration = null;

	/**
	 * @param iterable<RepositoryProvider> $providers Initial providers.
	 * @param callable(ProviderCode): \RAN\Activity\ActivityReporter|null $activity_reporter_factory Bound optional reporting service.
	 * @param callable(): ?array{provider_code: string, selection_generation: int, implementation_fingerprint: string, plugin_basename: ?string}|null $github_selection_guard Host-only fresh selection and passive-evidence check; must return null until collection successfully completes.
	 * @param array<string, GitHubReplacementDeclaration|null> $github_candidates Passive active candidates, including invalid candidates as null.
	 * @param callable|null $bundled_github_factory Deferred host factory; never supplied through the public action.
	 */
	public function __construct(
		iterable $providers = array(),
		?ProviderSecretPolicyCatalog $secret_policies = null,
		?callable $credential_store_factory = null,
		?callable $delivery_evidence_reader_factory = null,
		?ProviderRegistrationContext $registration_context = null,
		?callable $activity_reporter_factory = null,
		?callable $github_selection_guard = null,
		array $github_candidates = array(),
		?callable $bundled_github_factory = null
	) {
		$this->github_selection_guard           = null === $github_selection_guard ? null : \Closure::fromCallable( $github_selection_guard );
		$this->github_candidates                = $github_candidates;
		$this->bundled_github_factory           = null === $bundled_github_factory ? null : \Closure::fromCallable( $bundled_github_factory );
		$this->activity_reporter_factory        = null === $activity_reporter_factory ? null : \Closure::fromCallable( $activity_reporter_factory );
		$this->secret_policies                  = $secret_policies ?? new ProviderSecretPolicyCatalog();
		$this->credential_store_factory         = null === $credential_store_factory
			? null
			: \Closure::fromCallable( $credential_store_factory );
		$this->delivery_evidence_reader_factory = null === $delivery_evidence_reader_factory
			? null
			: \Closure::fromCallable( $delivery_evidence_reader_factory );
		$this->registration_context             = $registration_context ?? new ProviderRegistrationContext(
			static fn (): int => PackageArtifactLimit::resolve()
		);

		foreach ( $providers as $provider ) {
			$this->register( $provider );
		}
	}

	/** Return a reporter bound to an accepted registration, including after sealing. */
	public function activity_reporter( ProviderCode|string $code ): \RAN\Activity\ActivityReporter {
		$this->assert_not_registering();
		$code = $this->normalize_code( $code );
		$this->get( $code );
		if ( null !== $this->activity_reporter_factory ) {
			return ( $this->activity_reporter_factory )( $code );
		}
		return new \RAN\Activity\BoundActivityReporter( new \RAN\Activity\ActivityStore(), (string) $code, static fn (): ?array => null );
	}

	/**
	 * Register a credential-bearing provider with a read-only store restricted
	 * to the requested provider code.
	 *
	 * The factory must construct its aggregate locally without network or other
	 * side effects. Registration remains atomic after the aggregate is returned.
	 *
	 * Provider API 15 factories must declare a non-variadic, by-value third
	 * parameter typed exactly ProviderRegistrationContext. The registry validates
	 * that callable contract before invoking the factory.
	 *
	 * @param callable $factory Provider factory.
	 */

	public function register_with_credential_store( ProviderCode|string $code, callable $factory ): void {
		$this->begin_registration();

		try {
			$code = $this->normalize_code( $code );
			$this->assert_can_register_code( $code );

			$this->assert_provider_factory_signature( $factory );

			if ( null === $this->credential_store_factory ) {
				throw InvalidProviderPolicy::credential_store_unavailable();
			}
			if ( null === $this->delivery_evidence_reader_factory ) {
				throw InvalidProviderPolicy::delivery_evidence_reader_unavailable();
			}

			try {
				$credentials = ( $this->credential_store_factory )( $code );
			} catch ( \Throwable $exception ) {
				BoosterLogger::log_exception( 'provider registration credential store factory failed', $exception, array( 'step' => 'provider_credential_store_factory' ) );
				throw InvalidProviderPolicy::invalid_credential_store_factory();
			}

			if ( ! $credentials instanceof ProviderCredentialStore ) {
				throw InvalidProviderPolicy::invalid_credential_store_factory();
			}
			try {
				$delivery_evidence = ( $this->delivery_evidence_reader_factory )( $code );
			} catch ( \Throwable $exception ) {
				BoosterLogger::log_exception( 'provider registration delivery evidence factory failed', $exception, array( 'step' => 'provider_delivery_evidence_factory' ) );
				throw InvalidProviderPolicy::invalid_delivery_evidence_reader_factory();
			}

			if ( ! $delivery_evidence instanceof AuthenticatedWebhookDeliveryEvidenceReader ) {
				throw InvalidProviderPolicy::invalid_delivery_evidence_reader_factory();
			}

			if ( $this->github_finalizing && ! $this->registration_context->operation_allowed() ) {
				throw new LogicException( 'GitHub selection changed before factory construction.' );
			}
			try {
				$provider = $factory( $credentials, $delivery_evidence, $this->registration_context );
			} catch ( \Throwable $exception ) {
				BoosterLogger::log_exception( 'provider registration provider factory failed', $exception, array( 'step' => 'provider_factory' ) );
				throw InvalidProviderPolicy::invalid_provider_factory();
			}

			if ( $this->github_finalizing && ! $this->registration_context->operation_allowed() ) {
				throw new LogicException( 'GitHub selection changed during factory construction.' );
			}
			if ( ! $provider instanceof RepositoryProvider ) {
				throw InvalidProviderPolicy::invalid_provider_factory();
			}

			$metadata = $this->read_metadata( $provider );

			if ( $code->value !== $metadata->code->value ) {
				throw InvalidProviderPolicy::mismatched_factory_provider();
			}

			if ( $this->github_finalizing && null !== $this->github_final_declaration ) {
				if ( 'https://github.com/' !== $metadata->repository_url_base ) {
					throw new LogicException( 'GitHub replacement authority does not match.' );
				}
				foreach ( GitHubReplacementDeclaration::FACETS as $facet ) {
					if ( ( $provider instanceof $facet ) !== in_array( $facet, $this->github_final_declaration->facets, true ) ) {
						throw new LogicException( 'GitHub replacement facets do not match.' );
					}
				}
			}
			$this->register_provider( $provider, $metadata );
		} finally {
			$this->registration_in_progress = false;
		}
	}

	public function register( RepositoryProvider $provider ): void {
		$this->begin_registration();

		try {
			$this->assert_not_sealed();
			$this->register_provider( $provider, $this->read_metadata( $provider ) );
		} finally {
			$this->registration_in_progress = false;
		}
	}

	private function register_provider( RepositoryProvider $provider, ProviderMetadata $metadata ): void {
		$code = $metadata->code;
		$this->assert_can_register_code( $code );

		try {
			$provider->get_provider_diagnostics();
		} catch ( \Throwable $exception ) {
			BoosterLogger::log_exception( 'provider registration diagnostics unavailable', $exception, array( 'step' => 'provider_diagnostics' ) );
			throw new LogicException( 'Repository provider diagnostics could not be supplied.' );
		}

		$admin             = $metadata->admin;
		$credential_policy = null;
		$webhook_policy    = null;
		if ( null !== $admin && array() !== $admin->credential_kinds && ! $provider instanceof ProviderCredentialPolicySupplier ) {
			throw InvalidProviderPolicy::missing_credential_policy();
		}
		if ( null !== $admin && array() !== $admin->webhook_scopes && ! $provider instanceof WebhookNormalizer ) {
			throw InvalidProviderPolicy::missing_webhook_policy();
		}

		if ( $provider instanceof ProviderCredentialPolicySupplier ) {
			try {
				$credential_policy = $provider->get_credential_policy();
			} catch ( \Throwable $exception ) {
				BoosterLogger::log_exception( 'provider registration credential policy unavailable', $exception, array( 'step' => 'provider_credential_policy' ) );
				throw InvalidProviderPolicy::unavailable_credential_policy();
			}
		}

		if ( $provider instanceof WebhookNormalizer ) {
			try {
				$webhook_policy = $provider->get_webhook_policy();
			} catch ( \Throwable $exception ) {
				BoosterLogger::log_exception( 'provider registration webhook policy unavailable', $exception, array( 'step' => 'provider_webhook_policy' ) );
				throw InvalidProviderPolicy::unavailable_webhook_policy();
			}
		}

		// Policy registration performs every provider callback before publishing
		// its validated record. No registry state changes before it succeeds.
		$this->secret_policies->register( $code, $credential_policy, $webhook_policy );

		$this->providers[ $code->value ]         = $provider;
		$this->provider_metadata[ $code->value ] = $metadata;
	}

	public function seal(): void {
		$this->assert_not_registering();
		if ( $this->sealed ) {
			return;
		}
		try {
			$this->finalize_github_registration();
		} finally {
			$this->sealed = true;
		}
	}

	/** Collect a declaration without constructing an aggregate or disclosing credentials. */
	public function declare_github_replacement( GitHubReplacementDeclaration $declaration, callable $factory ): void {
		$this->assert_not_registering();
		$this->assert_not_sealed();
		try {
			$basename = $declaration->plugin_basename;
			$passive  = $this->github_candidates[ $basename ] ?? null;
			if ( null === $this->github_selection_guard || null === $passive
				|| $passive->implementation_fingerprint !== $declaration->implementation_fingerprint
				|| isset( $this->github_factories[ $basename ] ) ) {
				throw new LogicException( 'GitHub replacement declaration does not match passive discovery.' );
			}
			$this->assert_provider_factory_signature( $factory );
			$this->github_factories[ $basename ] = \Closure::fromCallable( $factory );
		} catch ( \Throwable ) {
			$this->github_collection_invalid = true;
		}
	}

	private function finalize_github_registration(): void {
		if ( null === $this->github_selection_guard || $this->github_collection_invalid || count( $this->github_candidates ) > 1 ) {
			return;
		}
		$guard = $this->github_selection_guard;
		try {
			$selected = $guard();
		} catch ( \Throwable ) {
			return;
		}
		if ( null === $selected || 'gh' !== $selected['provider_code'] || $selected['selection_generation'] < 0 ) {
			return;
		}
		$basename = $selected['plugin_basename'];
		$factory  = $this->bundled_github_factory;
		if ( null === $basename ) {
			if ( array() !== $this->github_candidates ) {
				return;
			}
		} else {
			$declaration = $this->github_candidates[ $basename ] ?? null;
			$factory     = $this->github_factories[ $basename ] ?? null;
			if ( null === $declaration || $declaration->implementation_fingerprint !== $selected['implementation_fingerprint'] ) {
				return;
			}
			$this->github_final_declaration = $declaration;
		}
		if ( null === $factory ) {
			return;
		}
		$context = $this->registration_context;
		$bound   = new ProviderRegistrationContext(
			static fn (): int => $context->maximum_artifact_bytes(),
			static fn (): bool => $selected === $guard()
		);
		if ( ! $bound->operation_allowed() ) {
			return;
		}
		$this->registration_context = $bound;
		$this->github_finalizing    = true;
		try {
			$this->register_with_credential_store( 'gh', $factory );
		} catch ( \Throwable ) {
			$this->github_collection_invalid = true;
		} finally {
			$this->registration_context = $context;
			$this->github_finalizing    = false;
		}
	}


	public function is_sealed(): bool {
		return $this->sealed;
	}

	public function get( ProviderCode|string $code ): RepositoryProvider {
		$code = $this->normalize_code( $code );

		if ( ! isset( $this->providers[ $code->value ] ) ) {
			throw UnknownProvider::for_code();
		}

		return $this->providers[ $code->value ];
	}

	/**
	 * @return array<string, RepositoryProvider>
	 */
	public function all(): array {
		return $this->providers;
	}

	/**
	 * @return array<string, ProviderMetadata>
	 */
	public function metadata(): array {
		return $this->provider_metadata;
	}

	/**
	 * Return provider metadata in the one stable order used by every
	 * administrator-facing provider surface.
	 *
	 * @return list<ProviderMetadata>
	 */

	public function administration_metadata(): array {
		return array_values(
			array_filter(
				$this->ordered_metadata(),
				static fn ( ProviderMetadata $metadata ): bool => null !== $metadata->admin
			)
		);
	}

	/** @return list<ProviderMetadata> */

	public function ordered_metadata(): array {
		return ( new ProviderNavigationOrderer() )->order_metadata( $this->provider_metadata );
	}

	/**
	 * Resolve a provider only when it implements a known optional capability.
	 *
	 * @template TCapability of object
	 * @param ProviderCode|string       $code       Provider code.
	 * @param string $capability Capability contract name, validated before resolution.
	 * @return ($capability is class-string<TCapability> ? TCapability : object)
	 */

	public function require_capability( ProviderCode|string $code, string $capability ): object {
		if ( RepositoryProvider::class === $capability
			|| ProviderCapabilityContract::class === $capability
			|| ! interface_exists( $capability )
			|| ! is_a( $capability, ProviderCapabilityContract::class, true ) ) {
			throw UnsupportedProviderCapability::unknown_contract();
		}

		$provider = $this->get( $code );

		if ( ! $provider instanceof $capability ) {
			throw UnsupportedProviderCapability::for_provider();
		}

		return $provider;
	}

	private function normalize_code( ProviderCode|string $code ): ProviderCode {
		return $code instanceof ProviderCode ? $code : ProviderCode::parse( $code );
	}

	private function read_metadata( RepositoryProvider $provider ): ProviderMetadata {
		try {
			return $provider->get_metadata();
		} catch ( \Throwable $exception ) {
			BoosterLogger::log_exception( 'provider registration metadata unavailable', $exception, array( 'step' => 'provider_metadata' ) );
			throw InvalidProviderPolicy::unavailable_metadata();
		}
	}

	private function assert_provider_factory_signature( callable $factory ): void {
		$parameters = ( new \ReflectionFunction( \Closure::fromCallable( $factory ) ) )->getParameters();
		$types      = array(
			ProviderCredentialStore::class,
			AuthenticatedWebhookDeliveryEvidenceReader::class,
			ProviderRegistrationContext::class,
		);

		if ( count( $types ) !== count( $parameters ) ) {
			throw InvalidProviderPolicy::invalid_provider_factory_signature();
		}

		foreach ( $parameters as $index => $parameter ) {
			$type = $parameter->getType();
			if ( $parameter->isOptional()
				|| $parameter->isVariadic()
				|| $parameter->isPassedByReference()
				|| ! $type instanceof \ReflectionNamedType
				|| $type->isBuiltin()
				|| $type->allowsNull()
				|| $types[ $index ] !== $type->getName()
			) {
				throw InvalidProviderPolicy::invalid_provider_factory_signature();
			}
		}
	}


	private function assert_can_register_code( ProviderCode $code ): void {
		$this->assert_not_sealed();
		if ( 'gh' === $code->value && null !== $this->github_selection_guard && ! $this->github_finalizing ) {
			throw new LogicException( 'GitHub is reserved for host selection.' );
		}

		if ( isset( $this->providers[ $code->value ] ) ) {
			throw new LogicException( 'Repository provider is already registered.' );
		}
	}

	private function begin_registration(): void {
		$this->assert_not_registering();
		$this->registration_in_progress = true;
	}

	private function assert_not_registering(): void {
		if ( $this->registration_in_progress ) {
			throw new LogicException( 'Repository provider registration is already in progress.' );
		}
	}

	private function assert_not_sealed(): void {
		if ( $this->sealed ) {
			throw new LogicException( 'Repository provider registration is closed.' );
		}
	}
}
