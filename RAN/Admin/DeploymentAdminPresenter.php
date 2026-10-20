<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Deployment\{DeploymentAttemptRepository, DeploymentStorageFailure};
use RAN\Logging\BoosterLogger;
use RAN\Activity\ActivityStore;
use RAN\{Package, PackageSource};
use RAN\Storage\{PluginRepository, ThemeRepository};
use Throwable;

/** @internal Core deployment activity and failure-notice presenter. */
final class DeploymentAdminPresenter {

	public const USER_META_KEY = '_ran_booster_background_failure_notice_fingerprint';
	private bool $rendered     = false;

	public function __construct(
		private ?BackgroundDeploymentFailureMonitor $monitor = null,
		private ?DeploymentAttemptRepository $attempts = null,
		private ?PluginRepository $plugins = null,
		private ?ThemeRepository $themes = null,
		private ?ActivityStore $activity_store = null
	) {
	}

	public function should_render(): bool {
		if ( ! current_user_can( 'manage_options' ) || null === $this->monitor ) {
			return false;
		}
		$fingerprint = $this->monitor->fingerprint();
		$user_id     = get_current_user_id();

		return null !== $fingerprint && $user_id > 0
			&& ! hash_equals( $fingerprint, (string) get_user_meta( $user_id, self::USER_META_KEY, true ) );
	}

	public function render(): void {
		if ( $this->rendered || ! $this->should_render() ) {
			return;
		}
		$this->rendered = true;
		$failures       = $this->monitor?->failures() ?? array();
		$primary        = $failures[0];
		$count          = count( $failures );
		$summary        = sprintf(
			/* translators: %d is the number of managed packages with a current background deployment failure. */
			_n( '%d managed package needs attention.', '%d managed packages need attention.', $count, 'ran-booster' ),
			$count
		);
		$credential_link = is_string( $primary['credential_id'] ) && '' !== $primary['credential_id']
			? '<a class="button" href="' . esc_url( $this->credential_url( $primary ) ) . '">' . esc_html( __( 'Replace credential', 'ran-booster' ) ) . '</a>'
			: '';
		echo '<div class="notice notice-error is-dismissible" data-ran-booster-background-failure-notice><p><strong>'
			. esc_html( __( 'RAN Booster automatic deployment failed:', 'ran-booster' ) ) . '</strong> '
			. esc_html( $summary ) . ' '
			. esc_html( (string) $primary['package_slug'] . ' (' . (string) $primary['provider_label'] . '): ' . DeploymentOutcomeMessage::for_code( (string) $primary['outcome_code'] ) )
			. '</p><p><a class="button button-primary" href="' . esc_url( $this->activity_url( $primary ) ) . '">' . esc_html( __( 'Review deployment', 'ran-booster' ) ) . '</a>'
			. $credential_link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic value is escaped above.
	}

	/** @return array{message: array<string, string>, context: array<string, string>}|null */

	public function deployment_failure( mixed $outcome_code, mixed $reference, string $operation ): ?array {

		if ( ! is_string( $outcome_code ) || ! is_string( $reference ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $reference ) ) {
			return null;
		}
		status_header( 400 );
		/* translators: 1: safe deployment result, 2: random support reference, 3: activity page URL. */
		$message = sprintf( __( '%1$s Reference: <code>%2$s</code>. <a href="%3$s">View deployment activity</a>.', 'ran-booster' ), DeploymentOutcomeMessage::for_code( $outcome_code ), $reference, admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' ) );

		return $this->outcome( 'error', 'ran_booster_deployment_failed', $message, $reference, $operation, $outcome_code );
	}

	/** @return array{message: array<string, string>, context: array<string, string>}|null */
	public function active_deployment( DeploymentStorageFailure $failure, string $operation ): ?array {
		$attempt = $failure->get_active_attempt();
		if ( null === $attempt ) {
			return null;
		}
		$reference    = (string) $attempt['correlation_id'];
		$state        = (string) $attempt['state'];
		$package_type = (string) $attempt['package_type'];
		$package_slug = (string) $attempt['package_slug'];
		$activity_url = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' )
			. '&attempt=' . rawurlencode( (string) $attempt['id'] ) . '&reference=' . rawurlencode( $reference );
		if ( 'needs_attention' === $state ) {
			/* translators: 1: package type, 2: package slug, 3: activity record link. */
			$message = sprintf( __( 'Booster could not confirm how an earlier deployment of the %1$s %2$s ended, so it has paused retries. No deployment is currently running. <a href="%3$s">Check the package and allow another attempt</a>.', 'ran-booster' ), esc_html( $package_type ), esc_html( $package_slug ), esc_url( $activity_url ) );
		} else {
			/* translators: 1: package type, 2: package slug, 3: deployment state, 4: activity record link. */
			$message = sprintf( __( 'Booster is already tracking the %1$s %2$s in state %3$s. <a href="%4$s">Review this deployment activity record</a> before trying again.', 'ran-booster' ), esc_html( $package_type ), esc_html( $package_slug ), esc_html( $state ), esc_url( $activity_url ) );
		}
		status_header( 409 );

		return $this->outcome( 'needs_attention' === $state ? 'error' : 'info', 'ran_booster_deployment_active', $message, $reference, $operation );
	}

	/**
	 * @param array<string, string> $provider_labels
	 * @return array<string, mixed>
	 */
	public function activity( array $provider_labels = array() ): array {
		$mode                   = 'list';
		$items                  = array();
		$unavailable            = null === $this->attempts;
		$has_cursor             = false;
		$next_cursor            = null;
		$activity_filter_key    = null;
		$later_verified_attempt = null;
		$package_settings_urls  = array();
		$package_labels         = array();
		$filter_options         = array(
			'providers' => array(),
			'packages'  => array(),
		);
		$filter_state           = array(
			'provider' => '',
			'package'  => '',
		);
		$filter_notice          = null;
		$base                   = compact( 'mode', 'items', 'unavailable', 'has_cursor', 'next_cursor', 'activity_filter_key', 'later_verified_attempt', 'package_settings_urls', 'package_labels', 'filter_options', 'filter_state', 'filter_notice' );
		$has_attempt            = $this->query_has_key( 'attempt' );
		$has_ref                = $this->query_has_key( 'reference' );
		$base['mode']           = $has_attempt || $has_ref ? 'detail' : 'list';
		if ( null === $this->attempts ) {
			return $base;
		}
		$inventory                     = $this->package_activity_inventory();
		$base['package_settings_urls'] = $inventory['urls'];
		$base['package_labels']        = $inventory['labels'];
		$attempt                       = $this->query_value( 'attempt' );
		$attempt_id                    = null === $attempt ? null : $this->positive_integer( $attempt );
		$reference                     = $this->query_value( 'reference' );
		$reference                     = null !== $reference && 1 === preg_match( '/^[a-f0-9]{32}$/D', $reference ) ? $reference : null;
		if ( $has_attempt || $has_ref ) {
			if ( null === $attempt_id || null === $reference ) {
				return $base;
			}
			try {
				$detail         = $this->attempts->find_exact( $attempt_id );
				$base['detail'] = null !== $detail && hash_equals( $detail->get_correlation_id(), $reference ) ? $detail : null;
				if ( null !== $base['detail'] && 'restoration_uncertain' === $base['detail']->get_outcome()?->get_code() ) {
					$data    = $base['detail']->safe_data();
					$summary = $this->attempts->package_activity_summary( (string) $data['package_type'], (string) $data['package_slug'], $base['detail']->get_request()->adoption_token );
					if ( null !== $summary['last_successful'] && $summary['last_successful']->get_id() > $base['detail']->get_id() ) {
						$base['later_verified_attempt'] = $summary['last_successful'];
					}
				}
			} catch ( Throwable $failure ) {
				$this->log_read_failure( 'deployment activity detail unavailable', $failure, 'deployment_activity_detail' );
				$base['unavailable'] = true;
			}

			return $base;
		}
		return $this->shared_history( $base, $provider_labels );
	}

	/**
	 * @param array<string, mixed> $base
	 * @param array<string, string> $provider_labels
	 * @return array<string, mixed>
	 */
	private function shared_history( array $base, array $provider_labels ): array {
		$base['has_cursor'] = $this->query_has_key( 'before' );
		$base['items']      = array();
		try {
			$choices = $this->activity_store?->activity_filter_choices();
			if ( null === $choices || ! $choices['available'] ) {
				throw new \RuntimeException( 'Activity storage is unavailable.' );
			}
			$selection              = $this->activity_filter_selection( $choices['providers'], $choices['packages'], $provider_labels, $base['package_labels'] );
			$base['filters']        = $selection['filters'];
			$base['filter_options'] = $selection['options'];
			$base['filter_state']   = $selection['state'];
			$base['filter_notice']  = $selection['notice'];
			if ( $selection['reset_cursor'] ) {
				$base['has_cursor'] = false;
			}
			ksort( $base['filters'], SORT_STRING );
			$filter_key                  = hash( 'sha256', http_build_query( $base['filters'], '', '&', PHP_QUERY_RFC3986 ) );
			$base['activity_filter_key'] = $filter_key;
			$cursor                      = null;
			if ( $base['has_cursor'] ) {
				$raw = $this->query_value( 'before' );
				if ( null === $raw || 1 !== preg_match( '/\Av1\|([a-f0-9]{64})\|(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(activity|deployment)\|([1-9][0-9]*)\z/', $raw, $parts ) || null === $this->positive_integer( $parts[4] ) ) {
					throw new \InvalidArgumentException( 'Invalid activity cursor.' );
				}
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $parts[2] );
				if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $parts[2] ) {
					throw new \InvalidArgumentException( 'Invalid activity cursor date.' );
				}
				$cursor = array(
					'time'   => $parts[2],
					'source' => $parts[3],
					'id'     => (int) $parts[4],
				);
				if ( ! hash_equals( $filter_key, $parts[1] ) ) {
					$cursor             = null;
					$base['has_cursor'] = false;
				}
			}
			$page = $this->activity_store?->activity_history( $base['filters'], $cursor, 26 );
			if ( null === $page || ! $page['available'] || null === $this->attempts ) {
				throw new \RuntimeException( 'Activity storage is unavailable.' );
			}
			$rows = array();
			foreach ( $page['records'] as $record ) {
				$facts       = is_string( $record['facts'] ?? null ) ? json_decode( $record['facts'], true ) : null;
				$attempt_id  = is_array( $facts ) && is_int( $facts['deployment_attempt_id'] ?? null ) ? $facts['deployment_attempt_id'] : null;
				$state       = is_array( $facts ) && in_array( $facts['deployment_state'] ?? null, array( 'queued', 'running', 'succeeded', 'failed', 'needs_attention' ), true ) ? $facts['deployment_state'] : $record['status'];
				$source      = is_array( $facts ) && in_array( $facts['deployment_source'] ?? null, array( 'manual', 'webhook' ), true ) ? $facts['deployment_source'] : null;
				$slug        = is_array( $facts ) && is_string( $facts['deployment_package_slug'] ?? null ) && '' !== $facts['deployment_package_slug'] && strlen( $facts['deployment_package_slug'] ) <= 191 && 1 === preg_match( '//u', $facts['deployment_package_slug'] ) && ! preg_match( '/[\x00-\x1F\x7F]/', $facts['deployment_package_slug'] ) ? $facts['deployment_package_slug'] : $record['subject_identity'];
				$requested   = is_array( $facts ) && is_string( $facts['deployment_requested_ref'] ?? null ) && strlen( $facts['deployment_requested_ref'] ) <= 255 && 1 === preg_match( '//u', $facts['deployment_requested_ref'] ) && ! preg_match( '/[\x00-\x1F\x7F]/', $facts['deployment_requested_ref'] ) ? $facts['deployment_requested_ref'] : null;
				$resolved    = is_array( $facts ) && is_string( $facts['deployment_resolved_ref'] ?? null ) && strlen( $facts['deployment_resolved_ref'] ) <= 191 && 1 === preg_match( '//u', $facts['deployment_resolved_ref'] ) && ! preg_match( '/[\x00-\x1F\x7F]/', $facts['deployment_resolved_ref'] ) ? $facts['deployment_resolved_ref'] : null;
				$mutation    = is_array( $facts ) && is_string( $facts['deployment_mutation_started_at'] ?? null ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $facts['deployment_mutation_started_at'] ) ? $facts['deployment_mutation_started_at'] : null;
				$finished    = is_array( $facts ) && is_string( $facts['deployment_finished_at'] ?? null ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $facts['deployment_finished_at'] ) ? $facts['deployment_finished_at'] : ( $record['completed_at'] ?? null );
				$outcome     = is_array( $facts ) && is_string( $facts['deployment_outcome'] ?? null ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $facts['deployment_outcome'] ) ? $facts['deployment_outcome'] : ( $record['reason'] ?? null );
				$resolved_at = is_array( $facts ) && is_string( $facts['deployment_resolved_at'] ?? null ) && 1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $facts['deployment_resolved_at'] ) ? $facts['deployment_resolved_at'] : null;
				$resolved_by = is_array( $facts ) && is_int( $facts['deployment_resolved_by'] ?? null ) && $facts['deployment_resolved_by'] > 0 ? $facts['deployment_resolved_by'] : null;
				$rows[]      = array_merge(
					$record,
					array(
						'record_source'       => null === $attempt_id ? 'activity' : 'deployment',
						'activity_record_id'  => $record['id'],
						'created_at'          => $record['sort_at'],
						'state'               => $state,
						'package_type'        => $record['subject_type'],
						'package_slug'        => $slug,
						'correlation_id'      => $record['correlation'],
						'id'                  => $attempt_id ?? $record['id'],
						'source'              => $source,
						'requested_ref'       => $requested,
						'resolved_ref'        => $resolved,
						'mutation_started_at' => $mutation,
						'finished_at'         => $finished,
						'outcome_code'        => $outcome,
						'resolved_at'         => $resolved_at,
						'resolved_by'         => $resolved_by,
						'provider_label'      => $provider_labels[ (string) ( $record['provider'] ?? '' ) ] ?? ( 'gh' === ( $record['provider'] ?? '' ) ? __( 'GitHub', 'ran-booster' ) : ( 'bb' === ( $record['provider'] ?? '' ) ? __( 'Bitbucket', 'ran-booster' ) : (string) ( $record['provider'] ?? '' ) ) ),
					)
				);
			}
			$base['items']       = array_slice( $rows, 0, 25 );
			$last                = end( $base['items'] );
			$base['next_cursor'] = count( $page['records'] ) > 25 && false !== $last ? 'v1|' . $filter_key . '|' . $last['created_at'] . '|activity|' . $last['activity_record_id'] : null;
			$base['unavailable'] = false;
		} catch ( Throwable $failure ) {
			$this->log_read_failure( 'shared activity history unavailable', $failure, 'activity_history' );
			$base['unavailable'] = true;
			$base['items']       = array();
		}
		return $base;
	}

	/**
	 * Normalize the two browse controls against retained choices before querying history.
	 * @param list<array{provider:string,has_non_package:bool}> $providers
	 * @param list<array{provider:string,subject_type:string,subject_identity:string}> $packages
	 * @param array<string, string> $provider_labels
	 * @param array<string, string> $package_labels
	 * @return array{filters:array<string,string>,options:array{providers:list<array{value:string,label:string}>,packages:list<array<string,string>>},state:array{provider:string,package:string},notice:?string,reset_cursor:bool}
	 */
	private function activity_filter_selection( array $providers, array $packages, array $provider_labels, array $package_labels ): array {
		$notice = null;
		foreach ( array( 'activity_subject_type', 'activity_subject_identity', 'activity_operation', 'activity_outcome' ) as $legacy ) {
			if ( $this->query_has_key( $legacy ) ) {
				$notice = __( 'Older activity filters were cleared. Use Provider and Activity subject to filter activity.', 'ran-booster' );
				break;
			}
		}
		$provider_options = array();
		foreach ( $providers as $provider ) {
			$provider_options[] = array(
				'value' => $provider['provider'],
				'label' => $provider_labels[ $provider['provider'] ] ?? ( 'gh' === $provider['provider'] ? __( 'GitHub', 'ran-booster' ) : ( 'bb' === $provider['provider'] ? __( 'Bitbucket', 'ran-booster' ) : $provider['provider'] ) ),
			);
		}
		$package_options = array();
		foreach ( $packages as $package ) {
			$value = wp_json_encode( array( $package['provider'], $package['subject_type'], $package['subject_identity'] ) );
			if ( ! is_string( $value ) ) {
				continue;
			}
			$package_options[] = array(
				'value'            => $value,
				'provider'         => $package['provider'],
				'subject_type'     => $package['subject_type'],
				'subject_identity' => $package['subject_identity'],
				'label'            => sprintf( '%1$s · %2$s: %3$s', $provider_labels[ $package['provider'] ] ?? ( 'gh' === $package['provider'] ? __( 'GitHub', 'ran-booster' ) : ( 'bb' === $package['provider'] ? __( 'Bitbucket', 'ran-booster' ) : $package['provider'] ) ), 'theme' === $package['subject_type'] ? __( 'Theme', 'ran-booster' ) : __( 'Plugin', 'ran-booster' ), $package_labels[ $package['provider'] . "\0" . $package['subject_type'] . "\0" . $package['subject_identity'] ] ?? $package['subject_identity'] ),
			);
		}
		$provider = $this->activity_filter_value( 'activity_provider' );
		if ( $this->query_has_key( 'activity_provider' ) && null === $provider && '' !== $this->query_value( 'activity_provider' ) ) {
			$notice = __( 'That provider filter was cleared.', 'ran-booster' );
		} elseif ( null !== $provider && ! in_array( $provider, array_column( $providers, 'provider' ), true ) ) {
			$provider = null;
			$notice   = __( 'That provider is no longer available in retained activity.', 'ran-booster' );
		}
		$visible_packages = null === $provider
			? $package_options
			: array_values( array_filter( $package_options, static fn ( array $option ): bool => $provider === $option['provider'] ) );
		$has_non_package  = count(
			array_filter(
				$providers,
				static fn ( array $choice ): bool => ( null === $provider || $provider === $choice['provider'] ) && $choice['has_non_package']
			)
		) > 0;
		if ( $has_non_package ) {
			array_unshift(
				$visible_packages,
				array(
					'value' => 'non-package',
					'label' => __( 'Non-package events', 'ran-booster' ),
				)
			);
		}
		$package              = $this->activity_filter_value( 'activity_package' );
		$selected_package     = null;
		$selected_non_package = false;
		if ( $this->query_has_key( 'activity_package' ) && null === $package && '' !== $this->query_value( 'activity_package' ) ) {
			$notice = __( 'That Activity subject filter was cleared.', 'ran-booster' );
		} elseif ( 'non-package' === $package ) {
			if ( ! $has_non_package ) {
				$notice = __( 'That Activity subject is no longer available in retained activity.', 'ran-booster' );
			} else {
				$selected_non_package = true;
			}
		} elseif ( null !== $package ) {
			$decoded = json_decode( $package, true );
			foreach ( $package_options as $option ) {
				if ( is_array( $decoded ) && array( $option['provider'], $option['subject_type'], $option['subject_identity'] ) === $decoded ) {
					$selected_package = $option;
					break;
				}
			}
			if ( null === $selected_package ) {
				$notice = __( 'That Activity subject is no longer available in retained activity.', 'ran-booster' );
			} elseif ( null !== $provider && $provider !== $selected_package['provider'] ) {
				$selected_package = null;
				$notice           = __( 'The Activity subject filter was cleared because it does not match the selected provider.', 'ran-booster' );
			}
		}
		$reset_cursor = null !== $notice;
		$filters      = array();
		if ( null !== $provider ) {
			$filters['provider'] = $provider;
		}
		if ( $selected_non_package ) {
			$filters['non_package'] = '1';
		} elseif ( null !== $selected_package ) {
			$filters['provider']         = $selected_package['provider'];
			$filters['subject_type']     = $selected_package['subject_type'];
			$filters['subject_identity'] = $selected_package['subject_identity'];
		}
		return array(
			'filters'      => $filters,
			'options'      => array(
				'providers' => $provider_options,
				'packages'  => $visible_packages,
			),
			'state'        => array(
				'provider' => $provider ?? '',
				'package'  => $selected_non_package ? 'non-package' : ( $selected_package['value'] ?? '' ),
			),
			'notice'       => $notice,
			'reset_cursor' => $reset_cursor,
		);
	}

	private function activity_filter_value( string $key ): ?string {
		if ( ! $this->query_has_key( $key ) ) {
			return null;
		}
		$value = $this->query_value( $key );
		return null !== $value && '' !== $value && strlen( $value ) <= 1024 && ! preg_match( '/[\x00-\x1F\x7F]/', $value ) ? $value : null;
	}

	/**
	 * @param list<Package> $packages
	 * @return array{items: array<array-key, array{latest: \RAN\Deployment\DeploymentAttempt|null, last_successful: \RAN\Deployment\DeploymentAttempt|null, check?: array{available: bool, latest: array<string, int|string|null>|null, observation: array<string, int|string|null>|null}}>, unavailable: bool}
	 */
	public function package_activity( array $packages, string $type ): array {
		if ( null === $this->attempts || count( $packages ) > 50 ) {
			return $this->package_activity_result();
		}
		$items = array();
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package || ! is_string( $package->get_identifier() ) ) {
				return $this->package_activity_result();
			}
			if ( PackageSource::RELEASE_ASSET === $package->get_source() && null === $this->activity_store ) {
				continue;
			}
			try {
				$items[ $package->get_identifier() ] = $this->attempts->package_activity_summary( $type, (string) $package->get_slug(), \RAN\AdoptionIdentity::of( $package ) );
				if ( null !== $this->activity_store ) {
					$purpose                                      = PackageSource::RELEASE_ASSET === $package->get_source() ? 'release.check' : 'branch.check';
					$capture                                      = ( new RepositoryBranchCheckEvidenceStore() )->activity_context( $type, $package, $purpose );
					$items[ $package->get_identifier() ]['check'] = null === $capture['adoption_token']
						? array(
							'available'   => true,
							'latest'      => null,
							'observation' => null,
						)
						: $this->activity_store->summary( $type, $capture['subject_identity'], $capture['adoption_token'], $capture['context'], $purpose );
				}
			} catch ( Throwable $failure ) {
				$this->log_read_failure( 'package deployment activity unavailable', $failure, 'package_activity_summary', 'read-' . $type . '-package-activity' );
				return $this->package_activity_result();
			}
		}

		return $this->package_activity_result( $items, false );
	}

	/** @param array<string, int|string|null> $failure */
	private function activity_url( array $failure ): string {
		return admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity&attempt=' . rawurlencode( (string) $failure['attempt_id'] ) . '&reference=' . rawurlencode( (string) $failure['correlation_id'] ) );
	}

	/** @param array<string, int|string|null> $failure */
	private function credential_url( array $failure ): string {
		return admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( (string) $failure['provider'] ) . '&replace_credential=' . rawurlencode( (string) $failure['credential_id'] ) );
	}

	/** @return array{urls:array<'plugin'|'theme', array<string, string>>,labels:array<string,string>} */
	private function package_activity_inventory(): array {
		$urls   = array(
			'plugin' => array(),
			'theme'  => array(),
		);
		$labels = array();
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			try {
				$packages = 'plugin' === $type ? $this->plugins?->all_deployment_plugins() : $this->themes?->all_deployment_themes();
				$view     = 'plugin' === $type ? PackagePagePresenter::plugin() : PackagePagePresenter::theme();
				$seen     = array();
				foreach ( $packages ?? array() as $package ) {
					if ( ! $package instanceof Package ) {
						continue;
					}
					$slug = (string) $package->get_slug();
					if ( '' === $slug || isset( $seen[ $slug ] ) ) {
						unset( $urls[ $type ][ $slug ] );
						$seen[ $slug ] = true;
						continue;
					}
					$seen[ $slug ] = true;
					$provider      = $package->get_provider_code();
					$identifier    = $package->get_identifier();
					if ( is_string( $provider ) && '' !== $provider && is_string( $identifier ) && '' !== $identifier ) {
						$labels[ $provider . "\0" . $type . "\0" . $identifier ] = $package->get_display_name() . ' (' . $identifier . ')';
					}
					$query                  = array();
					$query['page']          = $view->get_page_slug();
					$query['package']       = (string) $package->get_identifier();
					$urls[ $type ][ $slug ] = add_query_arg( $query, is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) );
				}
			} catch ( Throwable $failure ) {
				$this->log_read_failure( 'deployment activity package settings links unavailable', $failure, 'deployment_activity_package_links' );
			}
		}

		return compact( 'urls', 'labels' );
	}

	private function query_has_key( string $key ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence keeps malformed detail identities from broadening into a list query.
		return array_key_exists( $key, $_GET );
	}

	private function query_value( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, subsequently validated activity state.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? (string) wp_unslash( $_GET[ $key ] ) : null;
	}

	private function positive_integer( string $value ): ?int {
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) ) {
			return null;
		}
		$integer = (int) $value;

		return $integer > 0 && (string) $integer === $value ? $integer : null;
	}

	/** @return array{message: array{type: string, code: string, message: string}, context: array{correlation_id: string, operation: string, step: string, outcome_code?: string}} */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- compact() reads type, code and operation to preserve the outcome and logging context keys.
	private function outcome( string $type, string $code, string $message, string $correlation_id_input, string $operation, ?string $outcome_code = null ): array {
		$correlation_id = $correlation_id_input;
		$step           = 'manual_package_operation';
		$context        = compact( 'correlation_id', 'operation', 'step' );
		if ( null !== $outcome_code ) {
			$context['outcome_code'] = $outcome_code;
		}
		$message = compact( 'type', 'code', 'message' );
		return compact( 'message', 'context' );
	}

	/**
	 * @param array<array-key, array{latest: \RAN\Deployment\DeploymentAttempt|null, last_successful: \RAN\Deployment\DeploymentAttempt|null, check?: array{available: bool, latest: array<string, int|string|null>|null, observation: array<string, int|string|null>|null}}> $items
	 * @return array{items: array<array-key, array{latest: \RAN\Deployment\DeploymentAttempt|null, last_successful: \RAN\Deployment\DeploymentAttempt|null, check?: array{available: bool, latest: array<string, int|string|null>|null, observation: array<string, int|string|null>|null}}>, unavailable: bool}
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- compact() reads items and unavailable to build the package activity result.
	private function package_activity_result( array $items = array(), bool $unavailable = true ): array {
		return compact( 'items', 'unavailable' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- compact() reads step and operation for the exception logging context.
	private function log_read_failure( string $message, Throwable $failure, string $step, ?string $operation = null ): void {
		$source  = 'admin';
		$context = array_filter( compact( 'source', 'step', 'operation' ), static fn ( mixed $value ): bool => null !== $value );
		BoosterLogger::log_exception( $message, $failure, $context );
	}
}
