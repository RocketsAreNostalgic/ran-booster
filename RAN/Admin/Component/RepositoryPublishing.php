<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * Shared facts and markup for the two repository publishing tabs.
 *
 * Usage is counted from the package summaries, the same data the Status view
 * reads for "Branch demand" and "Recorded release usage".
 */
final class RepositoryPublishing {

	/**
	 * @param array<array-key, mixed> $row Repository row carrying package_summaries.
	 * @return array{branch:int,releases:int}
	 */
	public static function usage( array $row ): array {
		$packages = array_values( array_filter( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array(), 'is_array' ) );
		$branch   = count( array_filter( $packages, static fn ( array $package ): bool => 'branch' === ( $package['source'] ?? null ) ) );

		return array(
			'branch'   => $branch,
			'releases' => count( $packages ) - $branch,
		);
	}

	/**
	 * Package settings link for the header: only when exactly one package uses the repository.
	 *
	 * @param array<array-key, mixed> $row
	 * @return array{url:string,label:string}
	 */
	public static function settings_link( array $row ): array {
		$packages = array_values( array_filter( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array(), 'is_array' ) );
		$summary  = 1 === count( $packages ) ? $packages[0] : array();
		$url      = is_string( $summary['settings_url'] ?? null ) ? $summary['settings_url'] : '';

		return array(
			'url'   => $url,
			'label' => 'plugin' === ( $summary['type'] ?? null ) ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ),
		);
	}

	/** Delivery badge: green Active when a package uses the method, neutral otherwise. */
	public static function delivery_badge( bool $in_use ): string {
		return StatusBadge::for_intent( $in_use ? __( 'Active', 'ran-booster' ) : __( 'Not in use', 'ran-booster' ), $in_use ? 'stable' : 'none' );
	}

	/** Card header: title, delivery badge, and the package settings link on the right. */
	public static function header( string $title, string $heading_id, bool $in_use, string $settings_url = '', string $settings_label = '' ): string {
		$html = '<header class="ran-booster-settings-section__header ran-booster-repository-release-section__header">'
			. '<div class="ran-booster-repository-publishing__heading"><h3 id="' . esc_attr( $heading_id ) . '">' . esc_html( $title ) . '</h3>' . self::delivery_badge( $in_use ) . '</div>';
		if ( '' !== $settings_url ) {
			$html .= '<a href="' . esc_url( $settings_url ) . '">' . esc_html( $settings_label ) . '</a>';
		}

		return $html . '</header>';
	}
}
