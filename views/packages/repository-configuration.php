<?php

defined( 'WPINC' ) || die;

$packageRepositoryDescription = isset( $packageRepositoryDescription ) && is_string( $packageRepositoryDescription )
	? $packageRepositoryDescription
	: '';

?>
<section class="ran-booster-settings-section" aria-labelledby="ran-booster-package-configuration-heading">
	<header class="ran-booster-settings-section__header">
		<h3 id="ran-booster-package-configuration-heading" class="ran-booster-section__title"><?php esc_html_e( 'Repository configuration', 'ran-booster' ); ?></h3>
		<p class="ran-booster-section__description"><?php echo esc_html( $packageRepositoryDescription ); ?></p>
	</header>
	<div class="ran-booster-settings-section__body">
		<fieldset <?php disabled( ! $packageMutationAvailable ); ?>>
			<div class="ran-booster-settings-fields">
				<?php require __DIR__ . '/fields/provider.php'; ?>
				<?php require __DIR__ . '/fields/credential.php'; ?>
				<?php require __DIR__ . '/fields/repository.php'; ?>
			</div>
		</fieldset>
	</div>
</section>
