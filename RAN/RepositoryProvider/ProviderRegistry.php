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

	/**
	 * @param iterable<RepositoryProvider> $providers Initial providers.
	 */
	public function __construct(
		iterable $providers = array(),
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?ProviderSecretPolicyCatalog $secretPolicies = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $credentialStoreFactory = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?callable $deliveryEvidenceReaderFactory = null,
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		?ProviderRegistrationContext $registrationContext = null
	) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->secret_policies = $secretPolicies ?? new ProviderSecretPolicyCatalog();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->credential_store_factory = null === $credentialStoreFactory
			? null
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $credentialStoreFactory );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->delivery_evidence_reader_factory = null === $deliveryEvidenceReaderFactory
			? null
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
			: \Closure::fromCallable( $deliveryEvidenceReaderFactory );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Retain the public named-parameter contract.
		$this->registration_context = $registrationContext ?? new ProviderRegistrationContext(
			static fn (): int => PackageArtifactLimit::resolve()
		);

		foreach ( $providers as $provider ) {
			$this->register( $provider );
		}
	}

	/**
	 * Register a credential-bearing provider with a read-only store restricted
	 * to the requested provider code.
	 *
	 * The factory must construct its aggregate locally without network or other
	 * side effects. Registration remains atomic after the aggregate is returned.
	 *
	 * Provider API 13 factories must declare a non-variadic, by-value third
	 * parameter typed exactly ProviderRegistrationContext. The registry validates
	 * that callable contract before invoking the factory.
	 *
	 * @param callable $factory Provider factory.
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected API names remain deferred to their connected caller cohort under #167.
	public function registerWithCredentialStore( ProviderCode|string $code, callable $factory ): void {
		$this->begin_registration();

		try {
			$code = $this->normalize_code( $code );
			$this->assert_can_register_code( $code );

			$this->assert_provider_factory_signature( $factory );

			if ( null === $this->credential_store_factory ) {
				throw InvalidProviderPolicy::credentialStoreUnavailable();
			}
			if ( null === $this->delivery_evidence_reader_factory ) {
				throw InvalidProviderPolicy::deliveryEvidenceReaderUnavailable();
			}

			try {
				$credentials = ( $this->credential_store_factory )( $code );
			} catch ( \Throwable $exception ) {
				BoosterLogger::logException( 'provider registration credential store factory failed', $exception, array( 'step' => 'provider_credential_store_factory' ) );
				throw InvalidProviderPolicy::invalidCredentialStoreFactory();
			}

			if ( ! $credentials instanceof ProviderCredentialStore ) {
				throw InvalidProviderPolicy::invalidCredentialStoreFactory();
			}
			try {
				$delivery_evidence = ( $this->delivery_evidence_reader_factory )( $code );
			} catch ( \Throwable $exception ) {
				BoosterLogger::logException( 'provider registration delivery evidence factory failed', $exception, array( 'step' => 'provider_delivery_evidence_factory' ) );
				throw InvalidProviderPolicy::invalidDeliveryEvidenceReaderFactory();
			}

			if ( ! $delivery_evidence instanceof AuthenticatedWebhookDeliveryEvidenceReader ) {
				throw InvalidProviderPolicy::invalidDeliveryEvidenceReaderFactory();
			}

			try {
				$provider = $factory( $credentials, $delivery_evidence, $this->registration_context );
			} catch ( \Throwable $exception ) {
				BoosterLogger::logException( 'provider registration provider factory failed', $exception, array( 'step' => 'provider_factory' ) );
				throw InvalidProviderPolicy::invalidProviderFactory();
			}

			if ( ! $provider instanceof RepositoryProvider ) {
				throw InvalidProviderPolicy::invalidProviderFactory();
			}

			$metadata = $this->read_metadata( $provider );

			if ( $code->value !== $metadata->code->value ) {
				throw InvalidProviderPolicy::mismatchedFactoryProvider();
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
			BoosterLogger::logException( 'provider registration diagnostics unavailable', $exception, array( 'step' => 'provider_diagnostics' ) );
			throw new LogicException( 'Repository provider diagnostics could not be supplied.' );
		}

		$admin             = $metadata->admin;
		$credential_policy = null;
		$webhook_policy    = null;

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the public property or external DTO contract.
		if ( null !== $admin && array() !== $admin->credentialKinds && ! $provider instanceof ProviderCredentialPolicySupplier ) {
			throw InvalidProviderPolicy::missingCredentialPolicy();
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Retain the public property or external DTO contract.
		if ( null !== $admin && array() !== $admin->webhookScopes && ! $provider instanceof WebhookNormalizer ) {
			throw InvalidProviderPolicy::missingWebhookPolicy();
		}

		if ( $provider instanceof ProviderCredentialPolicySupplier ) {
			try {
				$credential_policy = $provider->get_credential_policy();
			} catch ( \Throwable $exception ) {
				BoosterLogger::logException( 'provider registration credential policy unavailable', $exception, array( 'step' => 'provider_credential_policy' ) );
				throw InvalidProviderPolicy::unavailableCredentialPolicy();
			}
		}

		if ( $provider instanceof WebhookNormalizer ) {
			try {
				$webhook_policy = $provider->get_webhook_policy();
			} catch ( \Throwable $exception ) {
				BoosterLogger::logException( 'provider registration webhook policy unavailable', $exception, array( 'step' => 'provider_webhook_policy' ) );
				throw InvalidProviderPolicy::unavailableWebhookPolicy();
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
		$this->sealed = true;
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected API names remain deferred to their connected caller cohort under #167.
	public function isSealed(): bool {
		return $this->sealed;
	}

	public function get( ProviderCode|string $code ): RepositoryProvider {
		$code = $this->normalize_code( $code );

		if ( ! isset( $this->providers[ $code->value ] ) ) {
			throw UnknownProvider::forCode();
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
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected API names remain deferred to their connected caller cohort under #167.
	public function administrationMetadata(): array {
		return array_values(
			array_filter(
				$this->orderedMetadata(),
				static fn ( ProviderMetadata $metadata ): bool => null !== $metadata->admin
			)
		);
	}

	/** @return list<ProviderMetadata> */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected API names remain deferred to their connected caller cohort under #167.
	public function orderedMetadata(): array {
		return ( new ProviderNavigationOrderer() )->orderMetadata( $this->provider_metadata );
	}

	/**
	 * Resolve a provider only when it implements a known optional capability.
	 *
	 * @template TCapability of object
	 * @param ProviderCode|string       $code       Provider code.
	 * @param class-string<TCapability> $capability Capability contract.
	 * @return TCapability
	 */
	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public and protected API names remain deferred to their connected caller cohort under #167.
	public function requireCapability( ProviderCode|string $code, string $capability ): object {
		if ( RepositoryProvider::class === $capability
			|| ProviderCapabilityContract::class === $capability
			|| ! interface_exists( $capability )
			|| ! is_a( $capability, ProviderCapabilityContract::class, true ) ) {
			throw UnsupportedProviderCapability::unknownContract();
		}

		$provider = $this->get( $code );

		if ( ! $provider instanceof $capability ) {
			throw UnsupportedProviderCapability::forProvider();
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
			BoosterLogger::logException( 'provider registration metadata unavailable', $exception, array( 'step' => 'provider_metadata' ) );
			throw InvalidProviderPolicy::unavailableMetadata();
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
			throw InvalidProviderPolicy::invalidProviderFactorySignature();
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
				throw InvalidProviderPolicy::invalidProviderFactorySignature();
			}
		}
	}


	private function assert_can_register_code( ProviderCode $code ): void {
		$this->assert_not_sealed();

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
