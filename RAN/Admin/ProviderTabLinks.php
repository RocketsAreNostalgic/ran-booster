<?php

declare(strict_types=1);

namespace RAN\Admin;

use Closure;
use Throwable;

/**
 * Builds links to a provider's admin tab, falling back to Overview when that tab
 * is not available (unregistered provider or hidden provider tab), so no notice
 * links into the not-found result.
 */
final class ProviderTabLinks {

	private ?AdminTabRegistry $tabs = null;

	/**
	 * @param Closure():AdminTabRegistry $tabs_factory Lazy tab registry.
	 * @param Closure():?GitHubVisibility $visibility_factory Lazy navigation visibility decision.
	 */
	public function __construct( private readonly Closure $tabs_factory, private readonly Closure $visibility_factory ) {
	}

	public function available( string $code ): bool {
		$this->tabs ??= ( $this->tabs_factory )();
		if ( null === $this->tabs->find( $code ) ) {
			return false;
		}
		try {
			$hidden = ( $this->visibility_factory )()?->hidden_navigation_key();
		} catch ( Throwable ) {
			$hidden = null;
		}
		return $code !== $hidden;
	}

	/**
	 * @param string $base_url Absolute admin.php URL for the current admin scope.
	 * @param string $query Additional query string beginning with an ampersand, dropped with the fallback.
	 */
	public function url( string $base_url, string $code, string $query = '' ): string {
		return $this->available( $code )
			? $base_url . '?page=ran-booster&tab=' . rawurlencode( $code ) . $query
			: $base_url . '?page=ran-booster&tab=overview';
	}

	/** Plain link when no helper is wired (isolated construction). */
	public static function link( ?self $links, string $base_url, string $code, string $query = '' ): string {
		return null !== $links
			? $links->url( $base_url, $code, $query )
			: $base_url . '?page=ran-booster&tab=' . rawurlencode( $code ) . $query;
	}
}
