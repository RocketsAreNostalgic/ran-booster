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

	private string $add_on_slug;

	private string $key;

	private string $label;

	/** @var Closure(AdminAddOnContext): void */
	private Closure $renderer;

	/** @param callable(AdminAddOnContext): void $renderer */
	public function __construct(
		string $add_on_slug,
		string $key,
		string $label,
		callable $renderer,
		private int $minimum_core_api_version = 1,
		private ?int $maximum_core_api_version = null,
		private int $minimum_add_on_api_version = 1,
		private ?int $maximum_add_on_api_version = null,
		private ?string $facade_name = null
	) {
		$add_on_slug = trim( $add_on_slug );
		$key         = trim( $key );
		$label       = trim( $label );

		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $add_on_slug ) ) {
			throw new InvalidArgumentException( 'Add-on slugs must be short lowercase identifiers.' );
		}

		if ( 1 !== preg_match( '/^[a-z][a-z0-9-]{0,31}$/', $key ) ) {
			throw new InvalidArgumentException( 'Add-on tab keys must be short lowercase identifiers.' );
		}

		if ( '' === $label || strlen( $label ) > 64 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $label ) ) {
			throw new InvalidArgumentException( 'Add-on tab labels must be short display values.' );
		}

		if ( $minimum_core_api_version < 1
			|| ( null !== $maximum_core_api_version && $maximum_core_api_version < $minimum_core_api_version )
			|| $minimum_add_on_api_version < 1
			|| ( null !== $maximum_add_on_api_version && $maximum_add_on_api_version < $minimum_add_on_api_version ) ) {
			throw new InvalidArgumentException( 'Add-on API compatibility bounds are invalid.' );
		}

		if ( null !== $facade_name && 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $facade_name ) ) {
			throw new InvalidArgumentException( 'Add-on facade names must be short lowercase identifiers.' );
		}

		$this->add_on_slug = $add_on_slug;
		$this->key         = $key;
		$this->label       = $label;
		$this->renderer    = Closure::fromCallable( $renderer );
	}

	public function add_on_slug(): string {
		return $this->add_on_slug;
	}

	public function key(): string {
		return $this->key;
	}

	public function label(): string {
		return $this->label;
	}

	public function supports( AdminAddOnContext $context ): bool {
		return $this->supports_api_versions( $context->core_api_version(), $context->add_on_api_version() );
	}

	public function supports_api_versions( int $core_api_version, int $add_on_api_version ): bool {
		return $core_api_version >= $this->minimum_core_api_version
			&& ( null === $this->maximum_core_api_version || $core_api_version <= $this->maximum_core_api_version )
			&& $add_on_api_version >= $this->minimum_add_on_api_version
			&& ( null === $this->maximum_add_on_api_version || $add_on_api_version <= $this->maximum_add_on_api_version );
	}

	public function facade_name(): ?string {
		return $this->facade_name;
	}

	public function render( AdminAddOnContext $context ): void {
		if ( $this->key !== $context->tab_key() ) {
			throw new LogicException( 'Add-on tab rendering requires its matching context.' );
		}

		if ( ! $this->supports( $context ) ) {
			throw new LogicException( 'Add-on tab is incompatible with this Booster API.' );
		}

		( $this->renderer )( $context );
	}
}
