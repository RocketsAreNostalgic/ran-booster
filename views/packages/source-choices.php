<?php

defined( 'WPINC' ) || die;

$sourceChoiceMode = isset( $packageSourceMode ) && 'create' === $packageSourceMode ? 'create' : 'edit';
if ( ! is_array( $packageSourceChoices ) || array() === $packageSourceChoices ) {
	$pageUrl              = 'create' === $sourceChoiceMode
		? add_query_arg( 'page', $packageView->getCreatePageSlug(), $packageView->getAdminUrl() )
		: add_query_arg(
			array(
				'page'    => $packageView->getPageSlug(),
				'package' => (string) ( $identifierValue ?? '' ),
			),
			$packageView->getAdminUrl()
		);
	$packageSourceChoices = array(
		'branch'        => array(
			'heading'           => __( 'Branch', 'ran-booster' ),
			'description'       => __( 'Deploy a saved repository branch manually or when a signed push webhook arrives.', 'ran-booster' ),
			'meta'              => __( 'Included with Booster', 'ran-booster' ),
			'url'               => add_query_arg( 'source_view', 'branch', $pageUrl ),
			'disabled'          => false,
			'hydrated'          => true,
			'client_hydratable' => false,
		),
		'release_asset' => array(
			'heading'           => __( 'Published releases', 'ran-booster' ),
			'description'       => __( 'Install verified packages from a supported provider\'s published releases.', 'ran-booster' ),
			'meta'              => __( 'Provider capability required', 'ran-booster' ),
			'url'               => '',
			'disabled'          => true,
			'hydrated'          => false,
			'client_hydratable' => false,
		),
	);
}

?>
<legend class="screen-reader-text"><?php esc_html_e( 'Package source', 'ran-booster' ); ?></legend>
<div class="ran-booster-package-source" aria-labelledby="ran-booster-package-source-heading">
	<header class="ran-booster-package-source__header">
		<h3 id="ran-booster-package-source-heading" class="ran-booster-section__title"><?php esc_html_e( 'Package source', 'ran-booster' ); ?></h3>
		<p class="ran-booster-section__description"><?php esc_html_e( 'Choose one authority for package updates. Switching source never installs a package immediately.', 'ran-booster' ); ?></p>
		<p class="ran-booster-package-source__guidance" data-ran-booster-source-repository-guidance <?php echo ! empty( $packageRepositoryReady ) ? 'hidden' : ''; ?>>
			<?php esc_html_e( 'Choose or enter a repository above before configuring its package source.', 'ran-booster' ); ?>
		</p>
	</header>
	<div class="ran-booster-source-choices">
		<?php foreach ( $packageSourceChoices as $sourceKey => $sourceChoice ) { ?>
			<?php
			$isSelected = $sourceKey === $packageSourceView;
			$classes    = 'ran-booster-source-choice' . ( $isSelected ? ' is-selected' : '' ) . ( $sourceChoice['disabled'] ? ' is-disabled' : '' );
			$sourceSlug = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $sourceKey ) );
			$tabId      = 'ran-booster-source-tab-' . $sourceSlug;
			$panelId    = 'ran-booster-source-pane-' . $sourceSlug;
			$sourceUrl  = wp_make_link_relative( $sourceChoice['url'] );
			?>
			<?php if ( 'edit' === $sourceChoiceMode && ! $sourceChoice['disabled'] ) { ?>
				<a id="<?php echo esc_attr( $tabId ); ?>" aria-controls="<?php echo esc_attr( $panelId ); ?>" class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $sourceUrl . '#ran-booster-advanced-source-settings' ); ?>" hx-get="<?php echo esc_url( $sourceUrl ); ?>" hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none" hx-push-url="true" hx-history="false" hx-sync="closest [data-ran-booster-source-controls]:replace" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-package-mutation-error" data-ran-booster-source-choice="<?php echo esc_attr( $sourceKey ); ?>"<?php echo $isSelected ? ' aria-current="true"' : ''; ?>>
			<?php } else { ?>
				<button id="<?php echo esc_attr( $tabId ); ?>" aria-controls="<?php echo esc_attr( $panelId ); ?>" aria-pressed="<?php echo $isSelected ? 'true' : 'false'; ?>" type="button" class="<?php echo esc_attr( $classes ); ?>" data-ran-booster-source-choice="<?php echo esc_attr( $sourceKey ); ?>" data-ran-booster-source-hydratable="<?php echo $sourceChoice['client_hydratable'] ? '1' : '0'; ?>" <?php disabled( $sourceChoice['disabled'] ); ?>>
			<?php } ?>
				<span class="ran-booster-source-choice__radio" aria-hidden="true"></span>
				<span>
					<strong data-ran-booster-source-heading><?php echo esc_html( $sourceChoice['heading'] ); ?></strong>
					<small data-ran-booster-source-description><?php echo esc_html( $sourceChoice['description'] ); ?></small>
					<?php if ( '' !== $sourceChoice['meta'] ) { ?>
						<span class="ran-booster-source-choice__meta" data-ran-booster-source-meta><?php echo esc_html( $sourceChoice['meta'] ); ?></span>
					<?php } ?>
				</span>
			<?php if ( 'edit' === $sourceChoiceMode && ! $sourceChoice['disabled'] ) { ?>
				</a>
			<?php } else { ?>
				</button>
			<?php } ?>
		<?php } ?>
	</div>
</div>
