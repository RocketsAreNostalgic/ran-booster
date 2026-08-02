<?php

declare(strict_types=1);

namespace RAN\Admin;

use LogicException;

/**
 * Builds Overview links from the allowlisted admin navigation.
 */
final readonly class OnboardingPresenter {

	/**
	 * @param list<array{key: string, label: string, url: string, active: bool, provider: bool}> $tabs Allowlisted admin navigation.
	 *
	 * @return array{
	 *     provider_links: list<array{label: string, url: string}>,
	 *     install_plugin_url: string,
	 *     install_theme_url: string,
	 *     portability_url: string,
	 *     documentation_url: string,
	 *     troubleshooting_url: string
	 * }
	 */
	public function build( array $tabs, string $installPluginUrl, string $installThemeUrl ): array {
		$providerLinks = array();
		$pageLinks     = array();

		foreach ( $tabs as $tab ) {
			if ( $tab['provider'] ) {
				$providerLinks[] = array(
					'label' => $tab['label'],
					'url'   => $tab['url'],
				);
				continue;
			}

			$pageLinks[ $tab['key'] ] = $tab['url'];
		}

		foreach ( array( 'portability', 'documentation', 'troubleshooting' ) as $requiredPage ) {
			if ( ! isset( $pageLinks[ $requiredPage ] ) ) {
				throw new LogicException( 'The onboarding panel requires every fixed admin destination.' );
			}
		}

		return array(
			'provider_links'      => $providerLinks,
			'install_plugin_url'  => $installPluginUrl,
			'install_theme_url'   => $installThemeUrl,
			'portability_url'     => $pageLinks['portability'],
			'documentation_url'   => $pageLinks['documentation'],
			'troubleshooting_url' => $pageLinks['troubleshooting'],
		);
	}
}
