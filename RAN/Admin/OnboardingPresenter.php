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
	public function build( array $tabs, string $install_plugin_url, string $install_theme_url ): array {
		$provider_links = array();
		$page_links     = array();

		foreach ( $tabs as $tab ) {
			if ( $tab['provider'] ) {
				$provider_links[] = array(
					'label' => $tab['label'],
					'url'   => $tab['url'],
				);
				continue;
			}

			$page_links[ $tab['key'] ] = $tab['url'];
		}

		foreach ( array( 'portability', 'documentation', 'troubleshooting' ) as $required_page ) {
			if ( ! isset( $page_links[ $required_page ] ) ) {
				throw new LogicException( 'The onboarding panel requires every fixed admin destination.' );
			}
		}

		return array(
			'provider_links'      => $provider_links,
			'install_plugin_url'  => $install_plugin_url,
			'install_theme_url'   => $install_theme_url,
			'portability_url'     => $page_links['portability'],
			'documentation_url'   => $page_links['documentation'],
			'troubleshooting_url' => $page_links['troubleshooting'],
		);
	}
}
