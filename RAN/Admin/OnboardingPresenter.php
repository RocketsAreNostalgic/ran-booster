<?php

declare(strict_types=1);

namespace RAN\Admin;

use LogicException;
use RAN\Activity\ActivityStore;
use RAN\AdoptionIdentity;
use RAN\Deployment\DeploymentPolicy;
use RAN\Settings\GeneralPreferenceStore;
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
	 * @param array<string, array{admitted:bool,selection:?string,source_kind?:?string,source_name?:?string,replacement_detected?:bool}> $provider_facts Local admission and selection facts.
	 * @param array{status:string,total:?int,items:list<array{provider_label:string,label:string,badge_label:string,stage:string,url:string}>}|null $credential_expiry This request's captured expiry reminders.
	 * @param array<string,array{plugin:int,theme:int}> $paused_counts Managed packages each source setting pauses.
	 * @return array<string, mixed>
	 */
	public function build_overview( array $tabs, string $install_plugin_url, string $install_theme_url, PluginRepository $plugins, ThemeRepository $themes, ?ActivityStore $activity, ?array $storage_summary = null, array $provider_facts = array(), ?array $credential_expiry = null, array $paused_counts = array() ): array {
		$overview         = $this->build( $tabs, $install_plugin_url, $install_theme_url );
		$choice           = get_option( self::PRESENTATION_OPTION );
		$overview['view'] = 'onboarding' === $choice ? 'onboarding' : 'dashboard';
		$plugin_count     = null;
		$theme_count      = null;
		$disabled         = 0;
		$managed          = array();
		try {
			$managed_plugins = $plugins->all_booster_plugins();
			$plugin_count    = count( $managed_plugins );
			foreach ( $managed_plugins as $package ) {
				$disabled += DeploymentPolicy::DISABLED === $package->get_deployment_policy() ? 1 : 0;
				$managed[] = array( 'plugin', $package );
			}
		} catch ( Throwable $failure ) {
			$plugin_count = null;
		}
		try {
			$managed_themes = $themes->all_booster_themes();
			$theme_count    = count( $managed_themes );
			foreach ( $managed_themes as $package ) {
				$disabled += DeploymentPolicy::DISABLED === $package->get_deployment_policy() ? 1 : 0;
				$managed[] = array( 'theme', $package );
			}
		} catch ( Throwable $failure ) {
			$theme_count = null;
		}
		$complete = null !== $plugin_count && null !== $theme_count;
		if ( 'automatic' === $choice && $complete && 0 === $plugin_count && 0 === $theme_count ) {
			$overview['view'] = 'onboarding';
		}
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
		$overview['provider_summaries'] = array();
		foreach ( $tabs as $tab ) {
			if ( ! $tab['provider'] ) {
				continue;
			}
			$fact                             = $provider_facts[ $tab['key'] ] ?? null;
			$overview['provider_summaries'][] = array(
				'code'                 => $tab['key'],
				'label'                => $tab['label'],
				'url'                  => $tab['url'],
				'admitted'             => $fact['admitted'] ?? null,
				'selection'            => $fact['selection'] ?? null,
				'source_kind'          => $fact['source_kind'] ?? null,
				'source_name'          => $fact['source_name'] ?? null,
				'replacement_detected' => $fact['replacement_detected'] ?? false,
			);
		}
		$overview['storage_summary']   = $storage_summary;
		$overview['overview_url']      = admin_url( 'admin.php?page=ran-booster&tab=overview' );
		$overview['plugins_url']       = admin_url( 'admin.php?page=ran-booster-plugins' );
		$overview['themes_url']        = admin_url( 'admin.php?page=ran-booster-themes' );
		$overview['activity_url']      = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' );
		$overview['credential_expiry'] = $credential_expiry ?? array(
			'status' => 'unknown',
			'total'  => null,
			'items'  => array(),
		);
		$overview['paused']            = $this->paused_lines( $paused_counts );
		$overview['attention']         = $this->attention( $managed, $activity, $overview['credential_expiry'], $overview['activity_url'], $overview['paused'] );
		return $overview;
	}

	/**
	 * One line per source setting that pauses a managed package. A saved "on" bound to another site
	 * address is marked lapsed: that site setting was not turned off here.
	 *
	 * @param array<string,array{plugin:int,theme:int}> $counts
	 * @return list<array{setting:string,title:string,url:string,parts:list<array{label:string,url:string}>,lapsed:bool}>
	 */
	private function paused_lines( array $counts ): array {
		$lines = array();
		foreach ( array(
			'branch_sources'     => __( 'Branch sources', 'ran-booster' ),
			'prerelease_sources' => __( 'Preview tracks', 'ran-booster' ),
		) as $setting => $title ) {
			$parts = array();
			foreach ( array( 'plugin', 'theme' ) as $type ) {
				$count = $counts[ $setting ][ $type ] ?? 0;
				if ( $count > 0 ) {
					$parts[] = array(
						/* translators: %d: number of paused plugins or themes. */
						'label' => sprintf( 'plugin' === $type ? _n( '%d plugin', '%d plugins', $count, 'ran-booster' ) : _n( '%d theme', '%d themes', $count, 'ran-booster' ), $count ),
						'url'   => admin_url( 'admin.php?page=ran-booster-' . $type . 's&attention=paused' ),
					);
				}
			}
			if ( array() === $parts ) {
				continue;
			}
			try {
				$lapsed = 'address_changed' === ( ( new GeneralPreferenceStore() )->projection()[ $setting ]['error'] ?? null );
			} catch ( \RuntimeException $failure ) {
				$lapsed = false; // PackageSourceGate already logged this request's unreadable setting.
			}
			$lines[] = array(
				'setting' => $setting,
				'title'   => $title,
				'url'     => admin_url( 'admin.php?page=ran-booster-settings#ran-booster-general-' . $setting ),
				'parts'   => $parts,
				'lapsed'  => $lapsed,
			);
		}
		return $lines;
	}

	/**
	 * Needs attention: each managed package's failed or uncertain Core update for its current adoption, a
	 * row per source setting whose saved "on" was for another site address, then credential reminders.
	 * Critical rows sort first; five are shown, and the count opens the hidden updates' filtered list.
	 *
	 * @param list<array{0:'plugin'|'theme',1:\RAN\Package}> $managed
	 * @param array{status:string,total:?int,items:list<array{provider_label:string,label:string,badge_label:string,stage:string,url:string}>} $credentials
	 * @param list<array{setting:string,title:string,url:string,parts:list<array{label:string,url:string}>,lapsed:bool}> $paused
	 * @return array{updates_available:bool,items:list<array{label:string,url:string,badge:string,critical:bool,type:string}>,total:int,hidden_critical:int,more_url:string}
	 */
	private function attention( array $managed, ?ActivityStore $activity, array $credentials, string $activity_url, array $paused ): array {
		$packages = array();
		$names    = array();
		$types    = array();
		foreach ( $managed as list( $type, $package ) ) {
			$token      = AdoptionIdentity::of( $package );
			$identifier = $package->get_identifier();
			if ( null !== $token && is_string( $identifier ) ) {
				$packages[] = array(
					'subject_type'     => $type,
					'subject_identity' => $identifier,
					'adoption_token'   => $token,
				);
				$names[]    = $package->get_display_name();
				$types[]    = $type;
			}
		}
		$read = array(
			'available' => array() === $packages,
			'outcomes'  => array(),
		);
		try {
			$read = array() === $packages || null === $activity ? $read : $activity->decisive_update_outcomes( $packages );
		} catch ( Throwable $failure ) {
			$read['available'] = false;
		}
		$items = array();
		foreach ( $read['outcomes'] as $index => $outcome ) {
			$record = $outcome['record'];
			if ( 'clear' === $outcome['state'] || null === $record ) {
				continue;
			}
			$review  = 'needs_review' === $outcome['state'];
			$facts   = json_decode( (string) $record['facts'], true );
			$items[] = array(
				'label'    => $names[ $index ],
				'url'      => $review
					? $activity_url . '&attempt=' . rawurlencode( (string) ( is_array( $facts ) ? $facts['deployment_attempt_id'] ?? '' : '' ) ) . '&reference=' . rawurlencode( (string) $record['correlation'] )
					: $activity_url . '&activity_package=' . rawurlencode( (string) wp_json_encode( array( $record['provider'], $record['subject_type'], $record['subject_identity'] ) ) ),
				'badge'    => $review ? __( 'Needs review', 'ran-booster' ) : __( 'Update failed', 'ran-booster' ),
				'critical' => true,
				'type'     => $types[ $index ],
			);
		}
		foreach ( array_filter( $paused, static fn ( array $line ): bool => $line['lapsed'] ) as $line ) {
			$items[] = array(
				'label'    => sprintf(
					'branch_sources' === $line['setting']
						/* translators: %s: numbers of paused plugins and themes. */
						? __( 'Branch sources were allowed for another site address: %s paused', 'ran-booster' )
						/* translators: %s: numbers of paused plugins and themes. */
						: __( 'Preview tracks were allowed for another site address: %s paused', 'ran-booster' ),
					implode( ', ', array_column( $line['parts'], 'label' ) )
				),
				'url'      => $line['url'],
				'badge'    => __( 'Paused', 'ran-booster' ),
				'critical' => false,
				'type'     => '',
			);
		}
		foreach ( 'captured' === $credentials['status'] ? $credentials['items'] : array() as $item ) {
			$items[] = array(
				'label'    => $item['provider_label'] . ': ' . $item['label'],
				'url'      => $item['url'],
				'badge'    => $item['badge_label'],
				'critical' => in_array( $item['stage'], array( 'expired', 'urgent' ), true ),
				'type'     => '',
			);
		}
		usort( $items, static fn ( array $a, array $b ): int => $b['critical'] <=> $a['critical'] );
		$hidden = array_column( array_slice( $items, 5 ), 'type' );
		return array(
			'updates_available' => $read['available'],
			'items'             => array_slice( $items, 0, 5 ),
			'total'             => count( $items ),
			'hidden_critical'   => count( array_filter( array_slice( $items, 5 ), static fn ( array $item ): bool => $item['critical'] ) ),
			// One list can be opened: plugins first, then themes; hidden credential rows alone keep Activity.
			'more_url'          => in_array( 'plugin', $hidden, true ) ? admin_url( 'admin.php?page=ran-booster-plugins&attention=failed' ) : ( in_array( 'theme', $hidden, true ) ? admin_url( 'admin.php?page=ran-booster-themes&attention=failed' ) : $activity_url ),
		);
	}
}
