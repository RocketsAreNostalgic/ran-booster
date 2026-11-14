<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Admin\Component\NoticeIcon;
use RAN\Secrets\SecretsStorageUnavailable;

/**
 * Renders one administrator-only, request-deduplicated expiry notice.
 */
final class CredentialExpiryNotice {

	public const USER_META_KEY = '_ran_booster_credential_expiry_notice_fingerprint';

	private bool $rendered = false;

	/** @var list<array{provider:string,provider_label:string,id:string,label:string,editable:bool,effective_expires_at:string,source:string,observed_at:?string,stage:string,days:int,badge_tone:string,badge_label:string,event_marker:string}>|null */
	private ?array $affected = null;

	private bool $storage_unavailable = false;

	public function __construct( private CredentialExpiryReminder $reminders, private ?ProviderTabLinks $links = null ) {
	}

	/**
	 * Project only facts already captured by the ordinary notice request.
	 *
	 * @phpstan-impure Authority and login are rechecked at each call.
	 *
	 * @return array{status:'captured'|'unknown'|'unavailable',total:?int,items:list<array{provider:string,provider_label:string,label:string,badge_label:string,stage:string,effective_expires_at:string,source:string,observed_at:?string,url:string}>}
	 */
	public function overview_summary(): array {
		if ( ! current_user_can( 'manage_options' ) || get_current_user_id() < 1 || $this->storage_unavailable ) {
			return array(
				'status' => 'unavailable',
				'total'  => null,
				'items'  => array(),
			);
		}
		if ( null === $this->affected ) {
			return array(
				'status' => 'unknown',
				'total'  => null,
				'items'  => array(),
			);
		}
		$base_url = function_exists( 'is_network_admin' ) && is_network_admin()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$items    = array();
		foreach ( array_slice( $this->affected, 0, 5 ) as $row ) {
			$url     = ProviderTabLinks::link( $this->links, $base_url, $row['provider'], '&view=credentials' . ( $row['editable'] && '' !== $row['id'] ? '&replace_credential=' . rawurlencode( $row['id'] ) : '' ) );
			$items[] = array(
				'provider'             => $row['provider'],
				'provider_label'       => $row['provider_label'],
				'label'                => $row['label'],
				'badge_label'          => $row['badge_label'],
				'stage'                => $row['stage'],
				'effective_expires_at' => $row['effective_expires_at'],
				'source'               => $row['source'],
				'observed_at'          => $row['observed_at'],
				'url'                  => $url,
			);
		}
		return array(
			'status' => 'captured',
			'total'  => count( $this->affected ),
			'items'  => $items,
		);
	}

	public function should_render(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$affected = $this->visible( $this->snapshot() );
		if ( $this->storage_unavailable ) {
			return true;
		}

		if ( array() === $affected ) {
			return false;
		}

		return get_current_user_id() > 0;
	}

	public function should_load_dismissal_script(): bool {
		return $this->should_render() && ! $this->storage_unavailable;
	}

	public function render(): void {
		if ( $this->rendered || ! $this->should_render() ) {
			return;
		}
		$this->rendered = true;

		if ( $this->storage_unavailable ) {
			$this->render_storage_unavailable();
			return;
		}

		$affected  = $this->visible( $this->snapshot() );
		$scope     = BoosterNoticeScope::is_booster_screen() ? 'booster' : 'global';
		$state     = wp_create_nonce( $this->reminders->state_action( $affected, $scope ) );
		$primary   = $affected[0];
		$count     = count( $affected );
		$class     = 'warning' === $primary['stage'] ? 'notice-warning' : 'notice-error';
		$base_url  = function_exists( 'is_network_admin' ) && is_network_admin()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		$providers = array();
		foreach ( $affected as $status ) {
			$providers[ $status['provider'] ] = $status['provider_label'];
		}
		?>
		<div class="notice <?php echo esc_attr( $class ); ?><?php echo BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-<?php echo esc_attr( substr( $class, 7 ) ); ?>" data-ran-booster-credential-expiry-notice data-ran-booster-notice-preference="expiry" data-ran-booster-notice-state="<?php echo esc_attr( $state ); ?>" data-ran-booster-notice-scope="<?php echo esc_attr( $scope ); ?>" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="<?php echo esc_attr( substr( $class, 7 ) ); ?>" data-ran-booster-ui-notice-scope="global"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
			<p>
				<strong><?php esc_html_e( 'Credential expiry reminder:', 'ran-booster' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d is the number of expiring credentials. */
						_n( '%d credential needs attention.', '%d credentials need attention.', $count, 'ran-booster' ),
						$count
					)
				);
				?>
			</p>
			<ul>
				<?php foreach ( $affected as $status ) { ?>
					<li>
						<?php
						/* translators: 1: credential label. 2: repository provider label. 3: credential expiry status. */
						echo esc_html( sprintf( __( '%1$s (%2$s): %3$s.', 'ran-booster' ), $status['label'], $status['provider_label'], $status['badge_label'] ) );
						?>
						<?php if ( $status['editable'] ) { ?>
							<a href="<?php echo esc_url( ProviderTabLinks::link( $this->links, $base_url, $status['provider'], '&view=credentials&replace_credential=' . rawurlencode( $status['id'] ) ) ); ?>"><?php /* translators: %s is the credential label. */ echo esc_html( sprintf( __( 'Replace %s', 'ran-booster' ), $status['label'] ) ); ?></a>
						<?php } else { ?>
							<span><?php esc_html_e( 'This credential is managed by deployment configuration; update it there.', 'ran-booster' ); ?></span>
						<?php } ?>
					</li>
				<?php } ?>
			</ul>
			<p>
				<?php foreach ( $providers as $provider => $label ) { ?>
					<a href="<?php echo esc_url( ProviderTabLinks::link( $this->links, $base_url, $provider, '&view=credentials' ) ); ?>"><?php /* translators: %s is a repository provider label. */ echo esc_html( sprintf( __( 'Review %s credentials', 'ran-booster' ), $label ) ); ?></a>
				<?php } ?>
			</p>
				<p>
					<?php if ( $this->reminders->can_snooze( $affected ) ) { ?>
						<button type="button" class="button" data-ran-booster-notice-preference-mode="snooze"><?php esc_html_e( 'Remind me in 5 days', 'ran-booster' ); ?></button>
					<?php } ?>
					<button type="button" class="button" data-ran-booster-notice-preference-mode="dismiss"><?php esc_html_e( 'Dismiss this reminder', 'ran-booster' ); ?></button>
				</p>
		</div><?php NoticeIcon::print_intent( substr( $class, 7 ) ); ?></div>
		<?php
	}

	/** @return list<array{provider:string,provider_label:string,id:string,label:string,editable:bool,effective_expires_at:string,source:string,observed_at:?string,stage:string,days:int,badge_tone:string,badge_label:string,event_marker:string}> */
	private function snapshot(): array {
		if ( null === $this->affected ) {
			try {
				$this->affected = $this->reminders->affected();
			} catch ( SecretsStorageUnavailable ) {
				$this->storage_unavailable = true;
				$this->affected            = array();
			}
		}

		return $this->affected;
	}

	/**
	 * @param list<array<string, mixed>> $affected Affected statuses.
	 * @return list<array<string, mixed>>
	 */
	private function visible( array $affected ): array {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return array();
		}
		$scope = BoosterNoticeScope::is_booster_screen() ? 'booster' : 'global';
		return $this->reminders->visible( $affected, $user_id, $scope, new NoticePreference() );
	}

	private function render_storage_unavailable(): void {
		$base_url = function_exists( 'is_network_admin' ) && is_network_admin()
			? network_admin_url( 'admin.php' )
			: admin_url( 'admin.php' );
		?>
		<div class="notice notice-error<?php echo BoosterNoticeScope::is_booster_screen() ? ' inline' : ''; ?> ran-wp-ui-notice ran-wp-ui-box-sizing is-error" data-ran-booster-secrets-storage-notice data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="error" data-ran-booster-ui-notice-scope="global"><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>
			<p>
				<strong><?php esc_html_e( 'Booster cannot read saved credentials.', 'ran-booster' ); ?></strong>
				<?php esc_html_e( 'Credential-backed operations remain paused.', 'ran-booster' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $base_url . '?page=ran-booster-settings#ran-booster-storage' ); ?>"><?php esc_html_e( 'Review Secure credential storage in Settings', 'ran-booster' ); ?></a>
			</p>
		</div><?php NoticeIcon::print_intent( 'error' ); ?></div>
		<?php
	}
}
