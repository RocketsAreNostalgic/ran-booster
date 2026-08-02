<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;

/**
 * One allowlisted Booster admin destination.
 */
final readonly class AdminTab {

	private const ALLOWED_VIEWS = array(
		'onboarding.php',
		'provider.php',
		'documentation.php',
		'troubleshooting.php',
		'portability.php',
	);

	private string $key;

	private string $label;

	private string $view;

	private function __construct(
		string $key,
		string $label,
		string $view,
		private AdminTabKind $kind,
		private ?ProviderCode $provider = null
	) {
		$key   = trim( $key );
		$label = trim( $label );

		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $key ) ) {
			throw new InvalidArgumentException( 'Admin tab keys must be short lowercase identifiers.' );
		}

		if ( '' === $label || strlen( $label ) > 100 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $label ) ) {
			throw new InvalidArgumentException( 'Admin tab labels must be short display values.' );
		}

		if ( ! in_array( $view, self::ALLOWED_VIEWS, true ) ) {
			throw new InvalidArgumentException( 'Admin tabs must use an allowlisted view.' );
		}

		if ( AdminTabKind::PROVIDER === $kind && null === $provider ) {
			throw new InvalidArgumentException( 'Provider tabs require a provider code.' );
		}

		if ( AdminTabKind::PAGE === $kind && null !== $provider ) {
			throw new InvalidArgumentException( 'Page tabs cannot carry a provider code.' );
		}

		$this->key   = $key;
		$this->label = $label;
		$this->view  = $view;
	}

	public static function provider( ProviderMetadata $metadata ): self {
		return new self(
			$metadata->code->value,
			$metadata->label,
			'provider.php',
			AdminTabKind::PROVIDER,
			$metadata->code
		);
	}

	public static function page( string $key, string $label, string $view ): self {
		return new self( $key, $label, $view, AdminTabKind::PAGE );
	}

	public function getKey(): string {
		return $this->key;
	}

	public function getLabel(): string {
		return $this->label;
	}

	public function getView(): string {
		return $this->view;
	}

	public function getKind(): AdminTabKind {
		return $this->kind;
	}

	public function getProvider(): ?ProviderCode {
		return $this->provider;
	}

	public function isProvider(): bool {
		return AdminTabKind::PROVIDER === $this->kind;
	}
}
