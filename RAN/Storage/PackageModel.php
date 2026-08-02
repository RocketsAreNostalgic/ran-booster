<?php

declare(strict_types=1);

namespace RAN\Storage;

use RAN\Deployment\DeploymentPolicy;
use RAN\PackageSource;
use RAN\PackageSubdirectory;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryLocator;

class PackageModel {

	protected $package;
	protected $repository;
	protected $branch;
	protected string $deployment_policy       = DeploymentPolicy::MANUAL->value;
	protected string $source                  = PackageSource::BRANCH->value;
	protected int $source_revision            = 1;
	protected ?string $provider               = null;
	protected ?string $provider_repository_id = null;
	protected int $private;
	protected ?string $credential_id = null;
	protected $subdirectory;

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
				$this->$key = DeploymentPolicy::fromDatabase( $value )->value;
				continue;
			}

			if ( 'source' === $key ) {
				$this->$key = PackageSource::fromDatabase( $value )->value;
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
				$this->$key = RepositoryLocator::requireValid( $value );
				continue;
			}

			$this->$key = sanitize_text_field( $value );
		}
	}

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
