<?php

declare(strict_types=1);

namespace RAN\Storage;

use RAN\Deployment\DeploymentPolicy;
use RAN\PackageSource;
use RAN\PackageSubdirectory;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryLocator;

/**
 * Validated row attributes exposed by __get(), without public write access.
 *
 * Optional attributes remain null when absent from the constructor input.
 * A supplied package identity is a string after constructor validation succeeds.
 *
 * @template-covariant TAttributes of array<string, mixed> = array<string, mixed>
 * @property-read (TAttributes is array{package: mixed} ? string : string|null) $package
 * @property-read string|null $repository
 * @property-read string|null $branch
 * @property-read string $deployment_policy
 * @property-read string $source
 * @property-read int $source_revision
 * @property-read string|null $provider
 * @property-read string|null $provider_repository_id
 * @property-read int|null $private
 * @property-read string|null $credential_id
 * @property-read string|null $subdirectory
 */
class PackageModel {

	/** @var string|null */
	protected $package;
	/** @var string|null */
	protected $repository;
	/** @var string|null */
	protected $branch;
	protected string $deployment_policy       = DeploymentPolicy::MANUAL->value;
	protected string $source                  = PackageSource::BRANCH->value;
	protected int $source_revision            = 1;
	protected ?string $provider               = null;
	protected ?string $provider_repository_id = null;
	protected int $private;
	protected ?string $credential_id = null;
	/** @var string|null */
	protected $subdirectory;

	/** @param TAttributes $attributes Untrusted row values validated during hydration. */
	public function __construct( array $attributes ) {
		foreach ( $attributes as $key => $value ) {
			if ( ! property_exists( $this, $key ) ) {
				continue;
			}

			if ( 'package' === $key ) {
				if ( ! is_string( $value )
					|| '' === $value
					|| trim( $value ) !== $value
					|| strlen( $value ) > 255
					|| preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
					throw new \InvalidArgumentException( 'The managed package identity is invalid.' );
				}
				$this->package = $value;
				continue;
			}

			if ( 'provider_repository_id' === $key ) {
				$value = is_scalar( $value ) ? (string) $value : '';
				if ( strlen( $value ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
					throw new \InvalidArgumentException( 'The provider repository identity is invalid.' );
				}
				$this->$key = '' === $value ? null : $value;
				continue;
			}

			if ( 'provider' === $key ) {
				$value      = is_string( $value ) ? trim( $value ) : '';
				$this->$key = '' === $value ? null : ProviderCode::parse( $value )->value;
				continue;
			}

			if ( 'credential_id' === $key ) {
				$value = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( '' !== $value && 1 !== preg_match( '/^[A-Za-z0-9_-]{3,64}$/', $value ) ) {
					throw new \InvalidArgumentException( 'The repository credential identity is invalid.' );
				}
				$this->$key = '' === $value ? null : $value;
				continue;
			}

			if ( 'subdirectory' === $key ) {
				$this->$key = PackageSubdirectory::normalize( $value );
				continue;
			}

			if ( 'deployment_policy' === $key ) {
				$value      = is_string( $value ) ? $value : '';
				$this->$key = DeploymentPolicy::from_database( $value )->value;
				continue;
			}

			if ( 'source' === $key ) {
				$this->$key = PackageSource::from_database( $value )->value;
				continue;
			}

			if ( 'source_revision' === $key ) {
				if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
					$value = filter_var( $value, FILTER_VALIDATE_INT );
				}
				if ( ! is_int( $value ) || $value < 1 ) {
					throw new \InvalidArgumentException( 'The managed package source revision is invalid.' );
				}
				$this->$key = $value;
				continue;
			}

			if ( 'private' === $key ) {
				$value = is_string( $value ) ? trim( $value ) : $value;

				$this->$key = match ( $value ) {
					false, 0, '0' => 0,
					true, 1, '1' => 1,
					default => throw new \InvalidArgumentException( 'The repository privacy setting is invalid.' ),
				};
				continue;
			}

			if ( 'repository' === $key ) {
				$this->$key = RepositoryLocator::require_valid( $value );
				continue;
			}

			$this->$key = sanitize_text_field( $value );
		}
	}

	/**
	 * @param string $name PHP magic-property name.
	 * @return mixed Existing subclass getters may expose their own value types.
	 */
	public function __get( $name ) {
		$method = 'get' . ucfirst( $name );

		if ( method_exists( $this, $method ) ) {
			return $this->$method();
		}

		if ( isset( $this->$name ) ) {
			return $this->$name;
		}
	}
}
