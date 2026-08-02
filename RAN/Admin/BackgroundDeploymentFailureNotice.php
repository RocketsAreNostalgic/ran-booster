<?php

declare(strict_types=1);

namespace RAN\Admin;

/**
 * Renders one request-deduplicated administrator notice for current failures.
 */
final class BackgroundDeploymentFailureNotice {

	public const USER_META_KEY = '_ran_booster_background_failure_notice_fingerprint';

	private bool $rendered = false;

	/** @var list<array<string, int|string|null>>|null */
	private ?array $failures = null;

	public function __construct( private BackgroundDeploymentFailureMonitor $monitor ) {
	}

	public function shouldRender(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$fingerprint = $this->monitor->fingerprint( $this->snapshot() );
		if ( null === $fingerprint ) {
			return false;
		}

		$userId = get_current_user_id();

		return $userId > 0
			&& ! hash_equals( $fingerprint, (string) get_user_meta( $userId, self::USER_META_KEY, true ) );
	}

	public function render(): void {
		if ( $this->rendered || ! $this->shouldRender() ) {
			return;
		}
		$this->rendered = true;

		$failures = $this->snapshot();
		$primary  = $failures[0];
		$count    = count( $failures );
		?>
		<div class="notice notice-error is-dismissible" data-ran-booster-background-failure-notice>
			<p>
				<strong><?php esc_html_e( 'RAN Booster automatic deployment failed:', 'ran-booster' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d is the number of managed packages with a current background deployment failure. */
						_n( '%d managed package needs attention.', '%d managed packages need attention.', $count, 'ran-booster' ),
						$count
					)
				);
				?>
				<?php echo esc_html( (string) $primary['package_slug'] . ' (' . (string) $primary['provider_label'] . '): ' . DeploymentOutcomeMessage::forCode( (string) $primary['outcome_code'] ) ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $this->activityUrl( $primary ) ); ?>"><?php esc_html_e( 'Review deployment', 'ran-booster' ); ?></a>
				<?php if ( is_string( $primary['credential_id'] ) && '' !== $primary['credential_id'] ) { ?>
					<a class="button" href="<?php echo esc_url( $this->credentialUrl( $primary ) ); ?>"><?php esc_html_e( 'Replace credential', 'ran-booster' ); ?></a>
				<?php } ?>
			</p>
		</div>
		<?php
	}

	/** @return list<array<string, int|string|null>> */
	private function snapshot(): array {
		if ( null === $this->failures ) {
			$this->failures = $this->monitor->failures();
		}

		return $this->failures;
	}

	/** @param array<string, int|string|null> $failure */
	private function activityUrl( array $failure ): string {
		return admin_url(
			'admin.php?page=ran-booster&tab=troubleshooting&panel=activity'
			. '&attempt=' . rawurlencode( (string) $failure['attempt_id'] )
			. '&reference=' . rawurlencode( (string) $failure['correlation_id'] )
		);
	}

	/** @param array<string, int|string|null> $failure */
	private function credentialUrl( array $failure ): string {
		return admin_url(
			'admin.php?page=ran-booster&tab=' . rawurlencode( (string) $failure['provider'] )
			. '&replace_credential=' . rawurlencode( (string) $failure['credential_id'] )
		);
	}
}
