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

	public function shouldRender(): bool {
		if ( ! current_user_can( 'manage_options' ) || null === $this->monitor ) {
			return false;
		}
		$fingerprint = $this->monitor->fingerprint( $this->snapshot() );
		$userId      = get_current_user_id();

		return null !== $fingerprint && $userId > 0
			&& ! hash_equals( $fingerprint, (string) get_user_meta( $userId, self::USER_META_KEY, true ) );
	}

	public function render(): void {
		if ( $this->rendered || ! $this->shouldRender() ) {
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
		$credentialLink = is_string( $primary['credential_id'] ) && '' !== $primary['credential_id']
			? '<a class="button" href="' . esc_url( $this->credentialUrl( $primary ) ) . '">' . esc_html( __( 'Replace credential', 'ran-booster' ) ) . '</a>'
			: '';
		echo '<div class="notice notice-error is-dismissible" data-ran-booster-background-failure-notice><p><strong>'
			. esc_html( __( 'RAN Booster automatic deployment failed:', 'ran-booster' ) ) . '</strong> '
			. esc_html( $summary ) . ' '
			. esc_html( (string) $primary['package_slug'] . ' (' . (string) $primary['provider_label'] . '): ' . DeploymentOutcomeMessage::forCode( (string) $primary['outcome_code'] ) )
			. '</p><p><a class="button button-primary" href="' . esc_url( $this->activityUrl( $primary ) ) . '">' . esc_html( __( 'Review deployment', 'ran-booster' ) ) . '</a>'
			. $credentialLink . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic value is escaped above.
	}

	/** @return array{message: array<string, string>, context: array<string, string>}|null */
	public function deploymentFailure( mixed $outcomeCode, mixed $reference, string $operation ): ?array {
		if ( ! is_string( $outcomeCode ) || ! is_string( $reference ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $reference ) ) {
			return null;
		}
		status_header( 400 );
		/* translators: 1: safe deployment result, 2: random support reference, 3: activity page URL. */
		$message = sprintf( __( '%1$s Reference: <code>%2$s</code>. <a href="%3$s">View deployment activity</a>.', 'ran-booster' ), DeploymentOutcomeMessage::forCode( $outcomeCode ), $reference, admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' ) );

		return $this->outcome( 'error', 'ran_booster_deployment_failed', $message, $reference, $operation, $outcomeCode );
	}

	/** @return array{message: array<string, string>, context: array<string, string>}|null */
	public function activeDeployment( DeploymentStorageFailure $failure, string $operation ): ?array {
		$attempt = $failure->getActiveAttempt();
		if ( null === $attempt ) {
			return null;
		}
		$reference   = (string) $attempt['correlation_id'];
		$state       = (string) $attempt['state'];
		$packageType = (string) $attempt['package_type'];
		$packageSlug = (string) $attempt['package_slug'];
		$activityUrl = admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' )
			. '&attempt=' . rawurlencode( (string) $attempt['id'] ) . '&reference=' . rawurlencode( $reference );
		if ( 'needs_attention' === $state ) {
			/* translators: 1: package type, 2: package slug, 3: activity record link. */
			$message = sprintf( __( 'An earlier deployment for the %1$s %2$s could not be verified and must be acknowledged before retrying. It is not currently running. <a href="%3$s">Open its recovery details</a>.', 'ran-booster' ), esc_html( $packageType ), esc_html( $packageSlug ), esc_url( $activityUrl ) );
		} else {
			/* translators: 1: package type, 2: package slug, 3: deployment state, 4: activity record link. */
			$message = sprintf( __( 'Booster is already tracking the %1$s %2$s in state %3$s. <a href="%4$s">Review this deployment activity record</a> before trying again.', 'ran-booster' ), esc_html( $packageType ), esc_html( $packageSlug ), esc_html( $state ), esc_url( $activityUrl ) );
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
		$hasAttempt             = $this->queryHasKey( 'attempt' );
		$hasRef                 = $this->queryHasKey( 'reference' );
		$base['mode']           = $hasAttempt || $hasRef ? 'detail' : 'list';
		if ( null === $this->attempts ) {
			return $base;
		}
		$base['package_settings_urls'] = $this->packageSettingsUrls();
		$attempt                       = $this->queryValue( 'attempt' );
		$attemptId                     = null === $attempt ? null : $this->positiveInteger( $attempt );
		$reference                     = $this->queryValue( 'reference' );
		$reference                     = null !== $reference && 1 === preg_match( '/^[a-f0-9]{32}$/D', $reference ) ? $reference : null;
		if ( $hasAttempt || $hasRef ) {
			if ( null === $attemptId || null === $reference ) {
				return $base;
			}
			try {
				$detail         = $this->attempts->findExact( $attemptId );
				$base['detail'] = null !== $detail && hash_equals( $detail->getCorrelationId(), $reference ) ? $detail : null;
				if ( null !== $base['detail'] && 'restoration_uncertain' === $base['detail']->getOutcome()?->getCode() ) {
					$data    = $base['detail']->safeData();
					$summary = $this->attempts->packageActivitySummary( (string) $data['package_type'], (string) $data['package_slug'] );
					if ( null !== $summary['last_successful'] && $summary['last_successful']->getId() > $base['detail']->getId() ) {
						$base['later_verified_attempt'] = $summary['last_successful'];
					}
				}
			} catch ( Throwable $failure ) {
				$this->logReadFailure( 'deployment activity detail unavailable', $failure, 'deployment_activity_detail' );
				$base['unavailable'] = true;
			}

			return $base;
		}
		$before             = $this->queryValue( 'before' );
		$base['has_cursor'] = $this->queryHasKey( 'before' );
		if ( $base['has_cursor'] && ( null === $before || '' === $before ) ) {
			$base['unavailable'] = true;
			return $base;
		}
		try {
			$beforeId = null === $before ? null : $this->positiveInteger( $before );
			if ( $base['has_cursor'] && null === $beforeId ) {
				$base['unavailable'] = true;
				return $base;
			}
			$items               = $this->attempts->recentHistory( self::PAGE_SIZE + 1, $beforeId );
			$hasMore             = count( $items ) > self::PAGE_SIZE;
			$items               = $hasMore ? array_slice( $items, 0, self::PAGE_SIZE ) : $items;
			$last                = end( $items );
			$base['items']       = $items;
			$base['next_cursor'] = $hasMore && false !== $last ? $last->getId() : null;
			$base['unavailable'] = false;
		} catch ( Throwable $failure ) {
			$this->logReadFailure( 'deployment activity history unavailable', $failure, 'deployment_activity_history' );
			$base['unavailable'] = true;
		}

		return $base;
	}

	/** @param list<Package> $packages */
	public function packageActivity( array $packages, string $type ): array {
		if ( null === $this->attempts || count( $packages ) > 50 ) {
			return $this->packageActivityResult();
		}
		$items = array();
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package || ! is_string( $package->getIdentifier() ) ) {
				return $this->packageActivityResult();
			}
			if ( PackageSource::RELEASE_ASSET === $package->getSource() ) {
				continue;
			}
			try {
				$items[ $package->getIdentifier() ] = $this->attempts->packageActivitySummary( $type, (string) $package->getSlug() );
			} catch ( Throwable $failure ) {
				$this->logReadFailure( 'package deployment activity unavailable', $failure, 'package_activity_summary', 'read-' . $type . '-package-activity' );
				return $this->packageActivityResult();
			}
		}

		return $this->packageActivityResult( $items, false );
	}

	/** @return list<array<string, int|string|null>> */
	private function snapshot(): array {
		return $this->failures ??= $this->monitor?->failures() ?? array();
	}

	/** @param array<string, int|string|null> $failure */
	private function activityUrl( array $failure ): string {
		return admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity&attempt=' . rawurlencode( (string) $failure['attempt_id'] ) . '&reference=' . rawurlencode( (string) $failure['correlation_id'] ) );
	}

	/** @param array<string, int|string|null> $failure */
	private function credentialUrl( array $failure ): string {
		return admin_url( 'admin.php?page=ran-booster&tab=' . rawurlencode( (string) $failure['provider'] ) . '&replace_credential=' . rawurlencode( (string) $failure['credential_id'] ) );
	}

	/** @return array<'plugin'|'theme', array<string, string>> */
	private function packageSettingsUrls(): array {
		$urls = array(
			'plugin' => array(),
			'theme'  => array(),
		);
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			try {
				$packages = 'plugin' === $type ? $this->plugins?->allDeploymentPlugins() : $this->themes?->allDeploymentThemes();
				$view     = 'plugin' === $type ? PackagePagePresenter::plugin() : PackagePagePresenter::theme();
				$seen     = array();
				foreach ( $packages ?? array() as $package ) {
					if ( ! $package instanceof Package ) {
						continue;
					}
					$slug = (string) $package->getSlug();
					if ( '' === $slug || isset( $seen[ $slug ] ) ) {
						unset( $urls[ $type ][ $slug ] );
						$seen[ $slug ] = true;
						continue;
					}
					$seen[ $slug ]          = true;
					$query                  = array();
					$query['page']          = $view->getPageSlug();
					$query['package']       = (string) $package->getIdentifier();
					$urls[ $type ][ $slug ] = add_query_arg( $query, is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) );
				}
			} catch ( Throwable $failure ) {
				$this->logReadFailure( 'deployment activity package settings links unavailable', $failure, 'deployment_activity_package_links' );
			}
		}

		return $urls;
	}

	private function queryHasKey( string $key ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence keeps malformed detail identities from broadening into a list query.
		return array_key_exists( $key, $_GET );
	}

	private function queryValue( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, subsequently validated activity state.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? (string) wp_unslash( $_GET[ $key ] ) : null;
	}

	private function positiveInteger( string $value ): ?int {
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) ) {
			return null;
		}
		$integer = (int) $value;

		return $integer > 0 && (string) $integer === $value ? $integer : null;
	}

	private function outcome( string $type, string $code, string $message, string $correlationId, string $operation, ?string $outcomeCode = null ): array {
		$correlation_id = $correlationId;
		$step           = 'manual_package_operation';
		$context        = compact( 'correlation_id', 'operation', 'step' );
		if ( null !== $outcomeCode ) {
			$context['outcome_code'] = $outcomeCode;
		}
		$message = compact( 'type', 'code', 'message' );
		return compact( 'message', 'context' );
	}

	/** @param array<string, mixed> $items */
	private function packageActivityResult( array $items = array(), bool $unavailable = true ): array {
		return compact( 'items', 'unavailable' );
	}

	private function logReadFailure( string $message, Throwable $failure, string $step, ?string $operation = null, mixed $attemptId = null ): void {
		$source     = 'admin';
		$attempt_id = $attemptId;
		$context    = array_filter( compact( 'source', 'step', 'operation', 'attempt_id' ), static fn ( mixed $value ): bool => null !== $value );
		BoosterLogger::logException( $message, $failure, $context );
	}
}
