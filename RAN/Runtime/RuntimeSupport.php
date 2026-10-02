<?php

declare(strict_types=1);

namespace RAN\Runtime;

/**
 * The request-wide WordPress topology supported by this Booster release.
 */
enum RuntimeSupport: string {
	case SINGLE_SITE_SUPPORTED = 'single_site_supported';
	case MULTISITE_UNSUPPORTED = 'multisite_unsupported';

	public static function current(): self {
		if ( function_exists( __NAMESPACE__ . '\\is_multisite' ) ) {
			return self::from_multisite( is_multisite() );
		}

		return self::from_multisite( function_exists( 'is_multisite' ) && \is_multisite() );
	}

	public static function from_multisite( bool $multisite ): self {
		return $multisite ? self::MULTISITE_UNSUPPORTED : self::SINGLE_SITE_SUPPORTED;
	}

	public function allows_managed_operations(): bool {
		return self::SINGLE_SITE_SUPPORTED === $this;
	}

	public static function assert_managed_operations_allowed(): void {
		if ( ! self::current()->allows_managed_operations() ) {
			throw UnsupportedRuntimeException::multisite();
		}
	}
}
