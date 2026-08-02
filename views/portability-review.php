<?php

defined( 'WPINC' ) || die;

// This partial receives display-safe rows from the portability controller.
$portabilityActionLabels = array(
	'install'   => array( __( 'Ready to install', 'ran-booster' ), __( 'Install package', 'ran-booster' ) ),
	'adopt'     => array( __( 'Available to adopt', 'ran-booster' ), __( 'Adopt with Booster', 'ran-booster' ) ),
	'managed'   => array( __( 'Already managed', 'ran-booster' ), __( 'No change', 'ran-booster' ) ),
	'protected' => array( __( 'Protected', 'ran-booster' ), __( 'Leave unchanged', 'ran-booster' ) ),
	'blocked'   => array( __( 'Blocked', 'ran-booster' ), __( 'Cannot apply', 'ran-booster' ) ),
);
$portabilityReviewRows   = is_array( $portabilityReviewRows ?? null )
	? array_values(
		array_filter(
			$portabilityReviewRows,
			static fn( mixed $row ): bool => is_array( $row ) && isset( $portabilityActionLabels[ $row['action'] ?? '' ] )
		)
	)
	: array();
$hasActionableRows       = array() !== array_filter(
	$portabilityReviewRows,
	static fn( array $row ): bool => in_array( $row['action'], array( 'install', 'adopt' ), true )
);

?>
<div class="ran-booster-portability__table-scroll" role="region" aria-labelledby="ran-booster-portability-review-heading" tabindex="0">
	<table class="widefat striped ran-booster-portability__review-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Packages in this import review', 'ran-booster' ); ?></caption>
		<thead><tr><th scope="col"><?php esc_html_e( 'Package', 'ran-booster' ); ?></th><th scope="col"><?php esc_html_e( 'Type', 'ran-booster' ); ?></th><th scope="col"><?php esc_html_e( 'Target state', 'ran-booster' ); ?></th><th scope="col"><label><input type="checkbox" data-portability-select-all aria-label="<?php esc_attr_e( 'Select all actionable packages', 'ran-booster' ); ?>"<?php checked( $hasActionableRows ); ?><?php disabled( ! $hasActionableRows ); ?>> <?php esc_html_e( 'Apply plan', 'ran-booster' ); ?></label></th></tr></thead>
		<tbody>
		<?php if ( array() === $portabilityReviewRows ) : ?>
			<tr><td colspan="4"><?php esc_html_e( 'Choose a Transporter Blueprint to review its packages.', 'ran-booster' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $portabilityReviewRows as $rowIndex => $row ) : ?>
				<?php
				$reviewAction = $row['action'];
				$credential   = 'blocked' === $reviewAction && is_array( $row['credential'] ?? null ) ? $row['credential'] : null;
				$credentialId = 'ran-booster-portability-target-credential-' . $rowIndex;
				?>
				<tr data-portability-row="<?php echo esc_attr( (string) $rowIndex ); ?>" data-portability-action="<?php echo esc_attr( $reviewAction ); ?>">
					<td><strong><?php echo esc_html( $row['name'] ?? '' ); ?></strong><code><?php echo esc_html( $row['identifier'] ?? '' ); ?></code></td>
					<td><?php echo esc_html( $row['type'] ?? '' ); ?></td>
					<td><span class="ran-booster-portability__status ran-booster-portability__status--<?php echo esc_attr( $reviewAction ); ?>"><?php echo esc_html( $portabilityActionLabels[ $reviewAction ][0] ); ?></span><span class="ran-booster-portability__status-detail"><?php echo esc_html( $row['reason'] ?? '' ); ?></span></td>
					<td>
					<?php if ( in_array( $reviewAction, array( 'install', 'adopt' ), true ) ) : ?>
						<label class="ran-booster-portability__apply-choice"><input type="checkbox" data-portability-select value="<?php echo esc_attr( (string) $rowIndex ); ?>" checked> <?php echo esc_html( $portabilityActionLabels[ $reviewAction ][1] ); ?></label>
					<?php else : ?>
						<?php echo esc_html( $portabilityActionLabels[ $reviewAction ][1] ); ?>
					<?php endif; ?>
					</td>
				</tr>
				<?php if ( null !== $credential ) : ?>
					<tr class="ran-booster-portability__reconciliation-row"><td colspan="4">
						<div class="notice notice-warning inline">
							<p><strong><?php esc_html_e( 'Repository access required', 'ran-booster' ); ?></strong></p>
							<p><?php esc_html_e( 'Choose a saved target credential that can access this repository, then review the Transporter Blueprint again.', 'ran-booster' ); ?></p>
							<?php $choices = is_array( $credential['choices'] ?? null ) ? array_slice( $credential['choices'], 0, 50 ) : array(); ?>
							<?php if ( array() !== $choices ) : ?>
								<label for="<?php echo esc_attr( $credentialId ); ?>"><?php esc_html_e( 'Target credential', 'ran-booster' ); ?></label>
								<select id="<?php echo esc_attr( $credentialId ); ?>" data-portability-target-credential data-portability-row="<?php echo esc_attr( (string) $rowIndex ); ?>">
									<option value=""><?php esc_html_e( 'Choose a target credential', 'ran-booster' ); ?></option>
									<?php foreach ( $choices as $choice ) : ?>
										<?php if ( is_array( $choice ) ) : ?>
										<option value="<?php echo esc_attr( $choice['id'] ?? '' ); ?>"<?php selected( (string) ( $credential['selected_id'] ?? '' ), (string) ( $choice['id'] ?? '' ) ); ?>><?php echo esc_html( $choice['label'] ?? '' ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Selecting a credential reviews the Transporter Blueprint again automatically.', 'ran-booster' ); ?></p>
							<?php else : ?>
								<p><?php esc_html_e( 'No saved target credentials are available for this provider.', 'ran-booster' ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $credential['settings_url'] ) ) : ?>
								<p><a class="button button-secondary" href="<?php echo esc_url( $credential['settings_url'] ); ?>"><?php esc_html_e( 'Manage repository credentials', 'ran-booster' ); ?></a></p>
							<?php endif; ?>
						</div>
					</td></tr>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</div>
