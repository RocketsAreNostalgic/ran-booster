<?php

declare(strict_types=1);

namespace RAN\Admin;

use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * A trusted add-on's self-contained Booster dashboard tab.
 */
final readonly class AdminAddOnTab {

	private string $addOnSlug;

	private string $key;

	private string $label;

	/** @var Closure(AdminAddOnContext): void */
	private Closure $renderer;

	/** @param callable(AdminAddOnContext): void $renderer */
	public function __construct(
		string $addOnSlug,
		string $key,
		string $label,
		callable $renderer,
		private int $minimumCoreApiVersion = 1,
		private ?int $maximumCoreApiVersion = null,
		private int $minimumAddOnApiVersion = 1,
		private ?int $maximumAddOnApiVersion = null,
		private ?string $facadeName = null
	) {
		$addOnSlug = trim( $addOnSlug );
		$key       = trim( $key );
		$label     = trim( $label );

		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $addOnSlug ) ) {
			throw new InvalidArgumentException( 'Add-on slugs must be short lowercase identifiers.' );
		}

		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $key ) ) {
			throw new InvalidArgumentException( 'Add-on tab keys must be short lowercase identifiers.' );
		}

		if ( '' === $label || strlen( $label ) > 64 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $label ) ) {
			throw new InvalidArgumentException( 'Add-on tab labels must be short display values.' );
		}

		if ( $minimumCoreApiVersion < 1
			|| ( null !== $maximumCoreApiVersion && $maximumCoreApiVersion < $minimumCoreApiVersion )
			|| $minimumAddOnApiVersion < 1
			|| ( null !== $maximumAddOnApiVersion && $maximumAddOnApiVersion < $minimumAddOnApiVersion ) ) {
			throw new InvalidArgumentException( 'Add-on API compatibility bounds are invalid.' );
		}

		if ( null !== $facadeName && 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $facadeName ) ) {
			throw new InvalidArgumentException( 'Add-on facade names must be short lowercase identifiers.' );
		}

		$this->addOnSlug = $addOnSlug;
		$this->key       = $key;
		$this->label     = $label;
		$this->renderer  = Closure::fromCallable( $renderer );
	}

	public function addOnSlug(): string {
		return $this->addOnSlug;
	}

	public function key(): string {
		return $this->key;
	}

	public function label(): string {
		return $this->label;
	}

	public function supports( AdminAddOnContext $context ): bool {
		return $this->supportsApiVersions( $context->coreApiVersion(), $context->addOnApiVersion() );
	}

	public function supportsApiVersions( int $coreApiVersion, int $addOnApiVersion ): bool {
		return $coreApiVersion >= $this->minimumCoreApiVersion
			&& ( null === $this->maximumCoreApiVersion || $coreApiVersion <= $this->maximumCoreApiVersion )
			&& $addOnApiVersion >= $this->minimumAddOnApiVersion
			&& ( null === $this->maximumAddOnApiVersion || $addOnApiVersion <= $this->maximumAddOnApiVersion );
	}

	public function facadeName(): ?string {
		return $this->facadeName;
	}

	public function render( AdminAddOnContext $context ): void {
		if ( $this->key !== $context->tabKey() ) {
			throw new LogicException( 'Add-on tab rendering requires its matching context.' );
		}

		if ( ! $this->supports( $context ) ) {
			throw new LogicException( 'Add-on tab is incompatible with this Booster API.' );
		}

		( $this->renderer )( $context );
	}
}
