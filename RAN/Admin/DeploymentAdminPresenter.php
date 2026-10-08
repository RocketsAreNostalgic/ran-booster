<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Deployment\{DeploymentAttemptRepository, DeploymentStorageFailure};
use RAN\Logging\BoosterLogger;
use RAN\{Package, PackageSource};
use RAN\Storage\{PluginRepository, ThemeRepository};
use Throwable;

/** @internal Core deployment activity and failure-notice presenter. */
final class DeploymentAdminPresenter {

	public const USER_META_KEY = '_ran_booster_background_failure_notice_fingerprint';
	private const PAGE_SIZE    = 50;
	private bool $rendered     = false;
	/** @var list<array<string, int|string|null>>|null */
	private ?array $failures = null;

	public function __construct(
		private ?BackgroundDeploymentFailureMonitor $monitor = null,
		private ?DeploymentAttemptRepository $attempts = null,
		private ?PluginRepository $plugins = null,
		private ?ThemeRepository $themes = null
	) {
	}

	public function should_render(): bool {
		if ( ! current_user_can( 'manage_options' ) || null === $this->monitor ) {
			return false;
		}
		$fingerprint = $this->monitor->fingerprint( $this->snapshot() );
		$user_id     = get_current_user_id();

		return null !== $fingerprint && $user_id > 0
			&& ! hash_equals( $fingerprint, (string) get_user_meta( $user_id, self::USER_META_KEY, true ) );
	}

	public function render(): void {
		if ( $this->rendered || ! $this->should_render() ) {
			return;
		}
		$this->rendered = true;
		$failures       = $this->snapshot();
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

	/** @return array<string, mixed> */
	public function activity(): array {
		$mode                   = 'list';
		$items                  = array();
		$unavailable            = null === $this->attempts;
		$has_cursor             = false;
		$next_cursor            = null;
		$later_verified_attempt = null;
		$package_settings_urls  = array();
		$base                   = compact( 'mode', 'items', 'unavailable', 'has_cursor', 'next_cursor', 'later_verified_attempt', 'package_settings_urls' );
		$has_attempt            = $this->query_has_key( 'attempt' );
		$has_ref                = $this->query_has_key( 'reference' );
		$base['mode']           = $has_attempt || $has_ref ? 'detail' : 'list';
		if ( null === $this->attempts ) {
			return $base;
		}
		$base['package_settings_urls'] = $this->package_settings_urls();
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
					$summary = $this->attempts->package_activity_summary( (string) $data['package_type'], (string) $data['package_slug'] );
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
		$before             = $this->query_value( 'before' );
		$base['has_cursor'] = $this->query_has_key( 'before' );
		if ( $base['has_cursor'] && ( null === $before || '' === $before ) ) {
			$base['unavailable'] = true;
			return $base;
		}
		try {
			$before_id = null === $before ? null : $this->positive_integer( $before );
			if ( $base['has_cursor'] && null === $before_id ) {
				$base['unavailable'] = true;
				return $base;
			}
			$items               = $this->attempts->recent_history( self::PAGE_SIZE + 1, $before_id );
			$has_more            = count( $items ) > self::PAGE_SIZE;
			$items               = $has_more ? array_slice( $items, 0, self::PAGE_SIZE ) : $items;
			$last                = end( $items );
			$base['items']       = $items;
			$base['next_cursor'] = $has_more && false !== $last ? $last->get_id() : null;
			$base['unavailable'] = false;
		} catch ( Throwable $failure ) {
			$this->log_read_failure( 'deployment activity history unavailable', $failure, 'deployment_activity_history' );
			$base['unavailable'] = true;
		}

		return $base;
	}

	/**
	 * @param list<Package> $packages
	 * @return array{items: array<array-key, array{latest: \RAN\Deployment\DeploymentAttempt|null, last_successful: \RAN\Deployment\DeploymentAttempt|null}>, unavailable: bool}
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
			if ( PackageSource::RELEASE_ASSET === $package->get_source() ) {
				continue;
			}
			try {
				$items[ $package->get_identifier() ] = $this->attempts->package_activity_summary( $type, (string) $package->get_slug() );
			} catch ( Throwable $failure ) {
				$this->log_read_failure( 'package deployment activity unavailable', $failure, 'package_activity_summary', 'read-' . $type . '-package-activity' );
				return $this->package_activity_result();
			}
		}

		return $this->package_activity_result( $items, false );
	}

	/** @return list<array<string, int|string|null>> */
	private function snapshot(): array {
		return $this->failures ??= $this->monitor?->failures() ?? array();
	}

	/** @param array<string, int|string|null> $failure */
	private function activity_url( array $failure ): string {
		return admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity&attempt=' . rawurlencode( (string) $failure['attempt_id'] ) . '&reference=' . rawurlencode( (string) $failure['correlation_id'] ) );
	}

	/** @param array<string, int|string|null> $failure */
	private function credential_url( array $failure ): string {
		return admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( (string) $failure['provider'] ) . '&replace_credential=' . rawurlencode( (string) $failure['credential_id'] ) );
	}

	/** @return array<'plugin'|'theme', array<string, string>> */
	private function package_settings_urls(): array {
		$urls = array(
			'plugin' => array(),
			'theme'  => array(),
		);
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
					$seen[ $slug ]          = true;
					$query                  = array();
					$query['page']          = $view->get_page_slug();
					$query['package']       = (string) $package->get_identifier();
					$urls[ $type ][ $slug ] = add_query_arg( $query, is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) );
				}
			} catch ( Throwable $failure ) {
				$this->log_read_failure( 'deployment activity package settings links unavailable', $failure, 'deployment_activity_package_links' );
			}
		}

		return $urls;
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
	 * @param array<array-key, array{latest: \RAN\Deployment\DeploymentAttempt|null, last_successful: \RAN\Deployment\DeploymentAttempt|null}> $items
	 * @return array{items: array<array-key, array{latest: \RAN\Deployment\DeploymentAttempt|null, last_successful: \RAN\Deployment\DeploymentAttempt|null}>, unavailable: bool}
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- compact() reads items and unavailable to build the package activity result.
	private function package_activity_result( array $items = array(), bool $unavailable = true ): array {
		return compact( 'items', 'unavailable' );
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- compact() reads step and operation for the exception logging context.
	private function log_read_failure( string $message, Throwable $failure, string $step, ?string $operation = null, mixed $attempt_id_input = null ): void {
		$source     = 'admin';
		$attempt_id = $attempt_id_input;
		$context    = array_filter( compact( 'source', 'step', 'operation', 'attempt_id' ), static fn ( mixed $value ): bool => null !== $value );
		BoosterLogger::log_exception( $message, $failure, $context );
	}
}
