<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

defined( 'WPINC' ) || die;

// This partial receives display-safe rows from the portability controller.
$portability_action_labels   = array(
	'install'   => array( __( 'Ready to install', 'ran-booster' ), __( 'Install package', 'ran-booster' ) ),
	'adopt'     => array( __( 'Available to adopt', 'ran-booster' ), __( 'Adopt with Booster', 'ran-booster' ) ),
	'managed'   => array( __( 'Already managed', 'ran-booster' ), __( 'No change', 'ran-booster' ) ),
	'protected' => array( __( 'Protected', 'ran-booster' ), __( 'Leave unchanged', 'ran-booster' ) ),
	'blocked'   => array( __( 'Blocked', 'ran-booster' ), __( 'Cannot apply', 'ran-booster' ) ),
);
$portability_review_rows     = is_array( $portability_review_rows ?? null )
	? array_values(
		array_filter(
			$portability_review_rows,
			static fn( mixed $row ): bool => is_array( $row ) && isset( $portability_action_labels[ $row['action'] ?? '' ] )
		)
	)
	: array();
$portability_credential_rows = is_array( $portability_credential_rows ?? null )
	? array_values( array_filter( $portability_credential_rows, 'is_array' ) )
	: array();
$has_actionable_rows         = array() !== array_filter(
	$portability_review_rows,
	static fn( array $row ): bool => in_array( $row['action'], array( 'install', 'adopt' ), true ) || true === ( $row['credential_recovery'] ?? false )
);
$has_credential_decisions    = array() !== array_filter(
	$portability_credential_rows,
	static fn( array $credential ): bool => (bool) ( $credential['decision_required'] ?? true )
);

?>
<?php if ( array() !== $portability_credential_rows ) : ?>
	<section class="ran-booster-portability__credential-review" aria-labelledby="ran-booster-portability-credentials-heading">
		<h5 id="ran-booster-portability-credentials-heading" class="ran-booster-portability__subsection-title ran-booster-portability__credentials-title"><?php esc_html_e( 'Repository credentials', 'ran-booster' ); ?></h5>
		<?php if ( $has_credential_decisions ) : ?>
			<p><?php esc_html_e( 'Choose how this site should access repositories needed by the proposed package changes or credential recovery. Booster verifies each exact repository using only your choice.', 'ran-booster' ); ?></p>
			<p class="description"><strong><?php esc_html_e( 'Before you continue:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Booster checks whether the credential can access each required repository, not what other permissions it has. Importing a credential for an already-managed package does not change that package’s saved credential selection.', 'ran-booster' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'This Blueprint includes repository credentials, but none are needed for package changes on this site.', 'ran-booster' ); ?></p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'Repository access continues to be handled by the active provider. Booster does not authenticate a third-party publisher.', 'ran-booster' ); ?></p>
	<div class="ran-booster-portability__credential-list">
	<?php foreach ( $portability_credential_rows as $credential ) : ?>
		<?php
		$ordinal           = max( 0, (int) ( $credential['ordinal'] ?? 0 ) );
		$credential_action = is_string( $credential['action'] ?? null ) ? $credential['action'] : '';
		$decision_required = (bool) ( $credential['decision_required'] ?? true );
		$packages          = array_values( array_filter( (array) ( $credential['packages'] ?? array() ), 'is_array' ) );
		$package_count     = count( $packages );
		$proposed_count    = max( 0, (int) ( $credential['proposed_count'] ?? 0 ) );
		$recovery_count    = max( 0, (int) ( $credential['recovery_count'] ?? 0 ) );
		$package_label     = sprintf(
			/* translators: %d: number of packages using the credential. */
			_nx( '%d package', '%d packages', $package_count, 'Packages using a credential', 'ran-booster' ),
			$package_count
		);
		$recovery_label = sprintf(
			/* translators: %d: number of already-managed packages eligible for credential-only recovery. */
			_n( '%d managed package', '%d managed packages', $recovery_count, 'ran-booster' ),
			$recovery_count
		);
		$package_summary = 0 < $recovery_count && 0 === $proposed_count
			? sprintf(
				/* translators: 1: total package count label, 2: managed package count label. */
				__( 'Used by %1$s; credential recovery is available for %2$s', 'ran-booster' ),
				$package_label,
				$recovery_label
			)
			: ( $decision_required
			? sprintf(
				/* translators: 1: total package count label, 2: proposed package change count label. */
				__( 'Used by %1$s; %2$s', 'ran-booster' ),
				$package_label,
				sprintf(
					/* translators: %d: number of proposed package changes. */
					_n( '%d package may change', '%d packages may change', $proposed_count, 'ran-booster' ),
					$proposed_count
				)
			)
			: sprintf(
				/* translators: %s: unchanged package count label. */
				__( 'Used by %s; all unchanged', 'ran-booster' ),
				$package_label
			) );
		$target_choices = is_array( $credential['target_choices'] ?? null ) ? array_slice( $credential['target_choices'], 0, 50 ) : array();
		$description_id = 'ran-booster-portability-credential-description-' . $ordinal;
		$target_id      = 'ran-booster-portability-credential-target-' . $ordinal;
		?>
		<fieldset class="ran-booster-portability__credential-row ran-booster-portability__credential-card" data-portability-credential-group data-portability-credential-ordinal="<?php echo esc_attr( (string) $ordinal ); ?>">
			<legend class="screen-reader-text"><?php echo esc_html( (string) ( $credential['provider_label'] ?? '' ) ); ?> — <?php echo esc_html( (string) ( $credential['label'] ?? '' ) ); ?></legend>
			<div class="ran-booster-portability__credential-heading">
				<h6 class="ran-booster-portability__credential-name"><?php echo esc_html( (string) ( $credential['label'] ?? '' ) ); ?></h6>
				<span class="ran-booster-tile ran-booster-portability__credential-provider"><span class="screen-reader-text"><?php esc_html_e( 'Provider:', 'ran-booster' ); ?> </span><span class="ran-booster-tile__value"><?php echo esc_html( (string) ( $credential['provider_label'] ?? '' ) ); ?></span></span>
				<?php if ( '' !== (string) ( $credential['kind'] ?? '' ) ) : ?>
					<span class="ran-booster-tile ran-booster-portability__credential-kind"><span class="screen-reader-text"><?php esc_html_e( 'Credential type:', 'ran-booster' ); ?> </span><span class="ran-booster-tile__value"><?php echo esc_html( (string) ( $credential['kind_label'] ?? $credential['kind'] ) ); ?></span></span>
				<?php endif; ?>
			</div>
			<span id="<?php echo esc_attr( $description_id ); ?>" class="screen-reader-text"><?php echo esc_html( $package_summary ); ?>.</span>
			<details class="ran-booster-portability__credential-packages">
				<summary><?php echo esc_html( $package_summary ); ?></summary>
				<ul>
				<?php foreach ( $packages as $package ) : ?>
					<li><span><?php echo esc_html( (string) ( $package['name'] ?? '' ) ); ?></span> <span class="description"><?php echo esc_html( (string) ( $package['type'] ?? '' ) ); ?></span></li>
				<?php endforeach; ?>
				</ul>
			</details>

			<?php if ( $decision_required ) : ?>
				<div class="ran-booster-portability__credential-actions">
					<div class="ran-booster-portability__credential-action">
						<label>
							<input type="radio" name="credential_decisions[<?php echo esc_attr( (string) $ordinal ); ?>][action]" value="import" data-portability-credential-action data-portability-credential-refresh aria-describedby="<?php echo esc_attr( $description_id ); ?>" hx-trigger="change delay:150ms" hx-include="[data-portability-preview], [data-portability-credential-action]:checked, [data-portability-credential-target]:not(:disabled)" hx-encoding="multipart/form-data" hx-target="#ran-booster-portability-package-review" hx-select="#ran-booster-portability-package-review" hx-swap="outerHTML show:none" hx-sync="[data-portability-preview]:replace" hx-indicator="#ran-booster-portability-review-progress"<?php checked( 'import', $credential_action ); ?>>
							<span><strong><?php esc_html_e( 'Import this credential', 'ran-booster' ); ?></strong><span class="description"><?php esc_html_e( 'Add the protected credential from this Blueprint to this site.', 'ran-booster' ); ?></span></span>
						</label>
					</div>
					<?php if ( 0 < $proposed_count ) : ?>
					<div class="ran-booster-portability__credential-action ran-booster-portability__credential-action--target">
						<label>
							<input type="radio" name="credential_decisions[<?php echo esc_attr( (string) $ordinal ); ?>][action]" value="target" data-portability-credential-action aria-describedby="<?php echo esc_attr( $description_id ); ?>"<?php checked( 'target', $credential_action ); ?><?php disabled( array() === $target_choices ); ?>>
							<span><strong><?php esc_html_e( 'Use a saved credential', 'ran-booster' ); ?></strong><span class="description"><?php esc_html_e( 'Connect these packages to a credential already stored on this site.', 'ran-booster' ); ?></span></span>
						</label>
						<?php if ( array() !== $target_choices ) : ?>
							<div class="ran-booster-portability__credential-target">
								<label for="<?php echo esc_attr( $target_id ); ?>"><?php esc_html_e( 'Saved credential', 'ran-booster' ); ?></label>
								<select id="<?php echo esc_attr( $target_id ); ?>" name="credential_decisions[<?php echo esc_attr( (string) $ordinal ); ?>][target_id]" data-portability-credential-target data-portability-credential-refresh hx-trigger="change delay:150ms" hx-include="[data-portability-preview], [data-portability-credential-action]:checked, [data-portability-credential-target]:not(:disabled)" hx-encoding="multipart/form-data" hx-target="#ran-booster-portability-package-review" hx-select="#ran-booster-portability-package-review" hx-swap="outerHTML show:none" hx-sync="[data-portability-preview]:replace" hx-indicator="#ran-booster-portability-review-progress"<?php disabled( 'target' !== $credential_action ); ?>>
									<option value=""><?php esc_html_e( 'Choose a saved credential', 'ran-booster' ); ?></option>
									<?php foreach ( $target_choices as $choice ) : ?>
										<?php if ( is_array( $choice ) ) : ?>
											<option value="<?php echo esc_attr( (string) ( $choice['id'] ?? '' ) ); ?>"<?php selected( (string) ( $credential['target_id'] ?? '' ), (string) ( $choice['id'] ?? '' ) ); ?>><?php echo esc_html( (string) ( $choice['label'] ?? '' ) ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
							</div>
						<?php else : ?>
							<p class="description ran-booster-portability__credential-unavailable"><?php esc_html_e( 'No saved credentials are available for this provider.', 'ran-booster' ); ?></p>
						<?php endif; ?>
					</div>
					<?php endif; ?>
					<div class="ran-booster-portability__credential-action">
						<label>
							<input type="radio" name="credential_decisions[<?php echo esc_attr( (string) $ordinal ); ?>][action]" value="leave" data-portability-credential-action data-portability-credential-refresh aria-describedby="<?php echo esc_attr( $description_id ); ?>" hx-trigger="change delay:150ms" hx-include="[data-portability-preview], [data-portability-credential-action]:checked, [data-portability-credential-target]:not(:disabled)" hx-encoding="multipart/form-data" hx-target="#ran-booster-portability-package-review" hx-select="#ran-booster-portability-package-review" hx-swap="outerHTML show:none" hx-sync="[data-portability-preview]:replace" hx-indicator="#ran-booster-portability-review-progress"<?php checked( 'leave', $credential_action ); ?>>
							<span><strong><?php esc_html_e( 'Leave unchanged', 'ran-booster' ); ?></strong><span class="description"><?php esc_html_e( 'Do not install, adopt or import this repository credential.', 'ran-booster' ); ?></span></span>
						</label>
					</div>
				</div>
			<?php else : ?>
				<div class="ran-booster-portability__credential-decision-state">
					<strong><?php esc_html_e( 'No action needed', 'ran-booster' ); ?></strong>
					<span class="description"><?php esc_html_e( 'No credential choice is needed because every affected package will remain unchanged.', 'ran-booster' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $credential['settings_url'] ) ) : ?>
				<div class="ran-booster-portability__credential-footer">
					<a href="<?php echo esc_url( $credential['settings_url'] ); ?>"><?php esc_html_e( 'Manage repository credentials', 'ran-booster' ); ?></a>
				</div>
			<?php endif; ?>
		</fieldset>
	<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>
<h5 id="ran-booster-portability-packages-heading" class="ran-booster-portability__subsection-title ran-booster-portability__packages-title"><?php esc_html_e( 'Packages', 'ran-booster' ); ?></h5>
<p class="ran-booster-portability__packages-description"><?php esc_html_e( 'Review the target state of each package and select the eligible changes you want to apply.', 'ran-booster' ); ?></p>
<div id="ran-booster-portability-package-review" class="ran-booster-portability__table-scroll" role="region" aria-labelledby="ran-booster-portability-packages-heading" tabindex="0">
	<table class="widefat striped ran-booster-portability__review-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Packages in this import review', 'ran-booster' ); ?></caption>
		<thead><tr><th scope="col"><?php esc_html_e( 'Package', 'ran-booster' ); ?></th><th scope="col"><?php esc_html_e( 'Type', 'ran-booster' ); ?></th><th scope="col"><?php esc_html_e( 'Target state', 'ran-booster' ); ?></th><th scope="col"><label><input type="checkbox" data-portability-select-all aria-label="<?php esc_attr_e( 'Select all actionable changes', 'ran-booster' ); ?>"<?php checked( $has_actionable_rows ); ?><?php disabled( ! $has_actionable_rows ); ?>> <?php esc_html_e( 'Apply plan', 'ran-booster' ); ?></label></th></tr></thead>
		<tbody>
		<?php if ( array() === $portability_review_rows ) : ?>
			<tr><td colspan="4"><?php esc_html_e( 'Choose a Transporter Blueprint to review its packages.', 'ran-booster' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $portability_review_rows as $row_index => $row ) : ?>
				<?php
				$review_action      = $row['action'];
				$package_name       = '' !== (string) ( $row['name'] ?? '' ) ? (string) $row['name'] : (string) ( $row['identifier'] ?? '' );
				$credential_ordinal = isset( $row['credential_ordinal'] ) ? max( 0, (int) $row['credential_ordinal'] ) : null;
				?>
				<tr data-portability-row="<?php echo esc_attr( (string) $row_index ); ?>" data-portability-action="<?php echo esc_attr( $review_action ); ?>"
				<?php
				if ( true === ( $row['credential_recovery'] ?? false ) ) :
					?>
					data-portability-credential-recovery="true"<?php endif; ?>
				<?php
				if ( null !== $credential_ordinal ) :
					?>
					data-portability-credential-ordinal="<?php echo esc_attr( (string) $credential_ordinal ); ?>"<?php endif; ?> data-portability-package-name="<?php echo esc_attr( $package_name ); ?>" data-portability-package-type="<?php echo esc_attr( (string) ( $row['type'] ?? __( 'Package', 'ran-booster' ) ) ); ?>" data-portability-package-identifier="<?php echo esc_attr( (string) ( $row['identifier'] ?? '' ) ); ?>">
					<td><strong><?php echo esc_html( $row['name'] ?? '' ); ?></strong><code><?php echo esc_html( $row['identifier'] ?? '' ); ?></code></td>
					<td><?php echo esc_html( $row['type'] ?? '' ); ?></td>
					<td><span class="ran-booster-portability__status ran-booster-portability__status--<?php echo esc_attr( $review_action ); ?>"><?php echo esc_html( $portability_action_labels[ $review_action ][0] ); ?></span><span class="ran-booster-portability__status-detail"><?php echo esc_html( $row['reason'] ?? '' ); ?></span></td>
					<td>
					<?php if ( true === ( $row['credential_recovery'] ?? false ) ) : ?>
						<label class="ran-booster-portability__apply-choice"><input type="checkbox" data-portability-select value="<?php echo esc_attr( (string) $row_index ); ?>" checked> <?php esc_html_e( 'Import credential only', 'ran-booster' ); ?></label>
					<?php elseif ( in_array( $review_action, array( 'install', 'adopt' ), true ) ) : ?>
						<label class="ran-booster-portability__apply-choice"><input type="checkbox" data-portability-select value="<?php echo esc_attr( (string) $row_index ); ?>" checked> <?php echo esc_html( $portability_action_labels[ $review_action ][1] ); ?></label>
					<?php else : ?>
						<?php echo esc_html( $portability_action_labels[ $review_action ][1] ); ?>
					<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>
</div>
