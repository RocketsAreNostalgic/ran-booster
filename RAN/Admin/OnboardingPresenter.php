<?php

declare(strict_types=1);

namespace RAN\Admin;

use LogicException;
use RAN\Activity\ActivityStore;
use RAN\Deployment\DeploymentPolicy;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use Throwable;

/**
 * Builds Overview links from the allowlisted admin navigation.
 */
final readonly class OnboardingPresenter {

	public const PRESENTATION_OPTION = 'ran_booster_overview_presentation';

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

	/**
	 * @param list<array{key: string, label: string, url: string, active: bool, provider: bool}> $tabs Allowlisted admin navigation.
	 * @param array<string, mixed>|null $storage_summary Previously inspected storage state.
	 * @return array<string, mixed>
	 */
	public function build_overview( array $tabs, string $install_plugin_url, string $install_theme_url, PluginRepository $plugins, ThemeRepository $themes, ?ActivityStore $activity, ?array $storage_summary = null ): array {
		$overview         = $this->build( $tabs, $install_plugin_url, $install_theme_url );
		$choice           = get_option( self::PRESENTATION_OPTION );
		$overview['view'] = 'onboarding' === $choice ? 'onboarding' : 'dashboard';
		$plugin_count     = null;
		$theme_count      = null;
		$disabled         = 0;
		try {
			$managed_plugins = $plugins->all_booster_plugins();
			$plugin_count    = count( $managed_plugins );
			foreach ( $managed_plugins as $package ) {
				$disabled += DeploymentPolicy::DISABLED === $package->get_deployment_policy() ? 1 : 0;
			}
		} catch ( Throwable $failure ) {
			$plugin_count = null;
		}
		try {
			$managed_themes = $themes->all_booster_themes();
			$theme_count    = count( $managed_themes );
			foreach ( $managed_themes as $package ) {
				$disabled += DeploymentPolicy::DISABLED === $package->get_deployment_policy() ? 1 : 0;
			}
		} catch ( Throwable $failure ) {
			$theme_count = null;
		}
		$complete              = null !== $plugin_count && null !== $theme_count;
		$overview['inventory'] = array(
			'plugins'  => $plugin_count,
			'themes'   => $theme_count,
			'disabled' => $complete ? $disabled : null,
			'complete' => $complete,
		);
		$overview['activity']  = array(
			'available' => false,
			'records'   => array(),
		);
		try {
			$history = $activity?->activity_history( array(), null, 5 );
			if ( null !== $history && $history['available'] ) {
				$records = array();
				foreach ( $history['records'] as $record ) {
					$records[] = array(
						'operation' => $record['operation'] ?? '',
						'status'    => $record['status'] ?? '',
						'sort_at'   => $record['sort_at'] ?? '',
					);
				}
				$overview['activity'] = array(
					'available' => true,
					'records'   => $records,
				);
			}
		} catch ( Throwable $failure ) {
			$overview['activity'] = array(
				'available' => false,
				'records'   => array(),
			);
		}
		$overview['storage_summary'] = $storage_summary;
		$overview['overview_url']    = admin_url( 'admin.php?page=ran-booster&tab=overview' );
		$overview['plugins_url']     = admin_url( 'admin.php?page=ran-booster-plugins' );
		$overview['themes_url']      = admin_url( 'admin.php?page=ran-booster-themes' );
		$overview['activity_url']    = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' );
		return $overview;
	}
}
