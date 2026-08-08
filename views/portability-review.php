<?php

defined( 'WPINC' ) || die;

// This partial receives display-safe rows from the portability controller.
$portabilityActionLabels   = array(
	'install'   => array( __( 'Ready to install', 'ran-booster' ), __( 'Install package', 'ran-booster' ) ),
	'adopt'     => array( __( 'Available to adopt', 'ran-booster' ), __( 'Adopt with Booster', 'ran-booster' ) ),
	'managed'   => array( __( 'Already managed', 'ran-booster' ), __( 'No change', 'ran-booster' ) ),
	'protected' => array( __( 'Protected', 'ran-booster' ), __( 'Leave unchanged', 'ran-booster' ) ),
	'blocked'   => array( __( 'Blocked', 'ran-booster' ), __( 'Cannot apply', 'ran-booster' ) ),
);
$portabilityReviewRows     = is_array( $portabilityReviewRows ?? null )
	? array_values(
		array_filter(
			$portabilityReviewRows,
			static fn( mixed $row ): bool => is_array( $row ) && isset( $portabilityActionLabels[ $row['action'] ?? '' ] )
		)
	)
	: array();
$portabilityCredentialRows = is_array( $portabilityCredentialRows ?? null )
	? array_values( array_filter( $portabilityCredentialRows, 'is_array' ) )
	: array();
$hasActionableRows         = array() !== array_filter(
	$portabilityReviewRows,
	static fn( array $row ): bool => in_array( $row['action'], array( 'install', 'adopt' ), true )
);

?>
<?php if ( array() !== $portabilityCredentialRows ) : ?>
<section class="ran-booster-portability__credential-review" aria-labelledby="ran-booster-portability-credentials-heading">
	<h5 id="ran-booster-portability-credentials-heading"><?php esc_html_e( 'Repository credentials', 'ran-booster' ); ?></h5>
	<p><?php esc_html_e( 'Choose how this site should access each repository. Booster verifies the exact repository using only your choice and does not authenticate a third-party publisher. Credential permissions have not been assessed. The active provider remains the trust authority. Deployment remains Disabled.', 'ran-booster' ); ?></p>
	<div class="ran-booster-portability__credential-list">
	<?php foreach ( $portabilityCredentialRows as $credential ) : ?>
		<?php
		$ordinal          = max( 0, (int) ( $credential['ordinal'] ?? 0 ) );
		$credentialAction = is_string( $credential['action'] ?? null ) ? $credential['action'] : '';
		$targetChoices    = is_array( $credential['target_choices'] ?? null ) ? array_slice( $credential['target_choices'], 0, 50 ) : array();
		$descriptionId    = 'ran-booster-portability-credential-description-' . $ordinal;
		$targetId         = 'ran-booster-portability-credential-target-' . $ordinal;
		?>
		<fieldset class="ran-booster-portability__credential-row" data-portability-credential-group data-portability-credential-ordinal="<?php echo esc_attr( (string) $ordinal ); ?>">
			<legend><strong><?php echo esc_html( (string) ( $credential['provider_label'] ?? '' ) ); ?> — <?php echo esc_html( (string) ( $credential['label'] ?? '' ) ); ?></strong> <span class="description"><?php echo esc_html( (string) ( $credential['kind'] ?? '' ) ); ?></span></legend>
			<p id="<?php echo esc_attr( $descriptionId ); ?>" class="description">
				<?php esc_html_e( 'Used by:', 'ran-booster' ); ?>
				<?php
				foreach ( (array) ( $credential['packages'] ?? array() ) as $packageIndex => $package ) :
					?>
					<?php echo 0 < $packageIndex ? esc_html__( ', ', 'ran-booster' ) : ''; ?><span><?php echo esc_html( (string) ( $package['name'] ?? '' ) ); ?> (<?php echo esc_html( (string) ( $package['type'] ?? '' ) ); ?>)</span><?php endforeach; ?>.
			</p>
			<label><input type="radio" name="credential_decisions[<?php echo esc_attr( (string) $ordinal ); ?>][action]" value="import" data-portability-credential-action aria-describedby="<?php echo esc_attr( $descriptionId ); ?>"<?php checked( 'import', $credentialAction ); ?>> <?php esc_html_e( 'Import this credential on this site', 'ran-booster' ); ?></label>
			<label><input type="radio" name="credential_decisions[<?php echo esc_attr( (string) $ordinal ); ?>][action]" value="target" data-portability-credential-action aria-describedby="<?php echo esc_attr( $descriptionId ); ?>"<?php checked( 'target', $credentialAction ); ?><?php disabled( array() === $targetChoices ); ?>> <?php esc_html_e( 'Use a saved credential on this site', 'ran-booster' ); ?></label>
			<label for="<?php echo esc_attr( $targetId ); ?>" class="screen-reader-text"><?php esc_html_e( 'Saved credential', 'ran-booster' ); ?></label>
			<select id="<?php echo esc_attr( $targetId ); ?>" name="credential_decisions[<?php echo esc_attr( (string) $ordinal ); ?>][target_id]" data-portability-credential-target<?php disabled( 'target' !== $credentialAction ); ?>>
				<option value=""><?php esc_html_e( 'Choose a saved credential', 'ran-booster' ); ?></option>
				<?php foreach ( $targetChoices as $choice ) : ?>
					<?php
					if ( is_array( $choice ) ) :
						?>
						<option value="<?php echo esc_attr( (string) ( $choice['id'] ?? '' ) ); ?>"<?php selected( (string) ( $credential['target_id'] ?? '' ), (string) ( $choice['id'] ?? '' ) ); ?>><?php echo esc_html( (string) ( $choice['label'] ?? '' ) ); ?></option><?php endif; ?>
				<?php endforeach; ?>
			</select>
			<label><input type="radio" name="credential_decisions[<?php echo esc_attr( (string) $ordinal ); ?>][action]" value="leave" data-portability-credential-action aria-describedby="<?php echo esc_attr( $descriptionId ); ?>"<?php checked( 'leave', $credentialAction ); ?>> <?php esc_html_e( 'Leave affected packages unchanged', 'ran-booster' ); ?></label>
			<?php
			if ( array() === $targetChoices ) :
				?>
				<p class="description"><?php esc_html_e( 'No saved credentials are available for this provider.', 'ran-booster' ); ?></p><?php endif; ?>
			<?php
			if ( ! empty( $credential['settings_url'] ) ) :
				?>
				<p><a href="<?php echo esc_url( $credential['settings_url'] ); ?>"><?php esc_html_e( 'Manage repository credentials', 'ran-booster' ); ?></a></p><?php endif; ?>
		</fieldset>
	<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>
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
				$reviewAction      = $row['action'];
				$packageName       = '' !== (string) ( $row['name'] ?? '' ) ? (string) $row['name'] : (string) ( $row['identifier'] ?? '' );
				$credentialOrdinal = isset( $row['credential_ordinal'] ) ? max( 0, (int) $row['credential_ordinal'] ) : null;
				?>
				<tr data-portability-row="<?php echo esc_attr( (string) $rowIndex ); ?>" data-portability-action="<?php echo esc_attr( $reviewAction ); ?>"
				<?php
				if ( null !== $credentialOrdinal ) :
					?>
					data-portability-credential-ordinal="<?php echo esc_attr( (string) $credentialOrdinal ); ?>"<?php endif; ?> data-portability-package-name="<?php echo esc_attr( $packageName ); ?>" data-portability-package-type="<?php echo esc_attr( (string) ( $row['type'] ?? __( 'Package', 'ran-booster' ) ) ); ?>" data-portability-package-identifier="<?php echo esc_attr( (string) ( $row['identifier'] ?? '' ) ); ?>">
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
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</div>
