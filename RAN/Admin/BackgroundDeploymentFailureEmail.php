<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Deployment\DeploymentAttempt;
use RAN\Deployment\DeploymentFailureNotifier;
use RAN\Deployment\DeploymentState;

/**
 * Sends one Core-style site-administrator email after a webhook attempt closes.
 */
class BackgroundDeploymentFailureEmail implements DeploymentFailureNotifier {

	public function notify( DeploymentAttempt $attempt ): bool {
		$data = $attempt->safe_data();
		if ( 'webhook' !== $data['source']
			|| ! in_array( $attempt->get_state(), array( DeploymentState::FAILED, DeploymentState::NEEDS_ATTENTION ), true )
			|| ! is_string( $data['outcome_code'] )
		) {
			return false;
		}

		$to = get_site_option( 'admin_email' );
		if ( ! is_string( $to ) || ! is_email( $to ) ) {
			return false;
		}

		$site_name = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		if ( '' === trim( $site_name ) ) {
			$site_name = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}
		$activity_url = admin_url(
			'admin.php?page=ran-booster&tab=troubleshooting&panel=activity'
			. '&attempt=' . rawurlencode( (string) $data['id'] )
			. '&reference=' . rawurlencode( (string) $data['correlation_id'] )
		);
		$subject      = sprintf(
			/* translators: %s is the WordPress site name. */
			__( '[%s] A RAN Booster automatic deployment failed', 'ran-booster' ),
			$site_name
		);
		$message = implode(
			"\n\n",
			array(
				sprintf(
					/* translators: 1: package slug, 2: package type, 3: provider code. */
					__( 'RAN Booster could not automatically deploy %1$s (%2$s) from %3$s.', 'ran-booster' ),
					(string) $data['package_slug'],
					(string) $data['package_type'],
					strtoupper( (string) $data['provider'] )
				),
				DeploymentOutcomeMessage::for_code( (string) $data['outcome_code'] ),
				sprintf(
					/* translators: %s is a random support reference. */
					__( 'Support reference: %s', 'ran-booster' ),
					(string) $data['correlation_id']
				),
				sprintf(
					/* translators: %s is the deployment activity URL. */
					__( 'Review deployment activity: %s', 'ran-booster' ),
					$activity_url
				),
			)
		);
		$email   = apply_filters(
			'ran_booster_background_deployment_failure_email',
			array(
				'to'      => $to,
				'subject' => $subject,
				'message' => $message,
				'headers' => array(),
			),
			$data
		);
		if ( ! is_array( $email )
			|| ! is_string( $email['to'] ?? null )
			|| ! is_string( $email['subject'] ?? null )
			|| ! is_string( $email['message'] ?? null )
			|| ! is_array( $email['headers'] ?? null )
		) {
			return false;
		}

		return wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}
}
