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
<div class="ran-booster-package-source<?php echo 'edit' === $sourceChoiceMode ? ' ran-booster-package-source--navigation' : ''; ?>" aria-labelledby="ran-booster-package-source-heading">
	<header class="ran-booster-package-source__header">
		<h3 id="ran-booster-package-source-heading" class="ran-booster-section__title"><?php esc_html_e( 'Package source', 'ran-booster' ); ?></h3>
		<p class="ran-booster-section__description">
			<?php
			echo esc_html(
				'edit' === $sourceChoiceMode
					? __( 'Review each source\'s settings. Opening a settings view does not change the current source.', 'ran-booster' )
					: __( 'Choose one authority for package updates. Switching source never installs a package immediately.', 'ran-booster' )
			);
			?>
		</p>
		<p class="ran-booster-package-source__guidance" data-ran-booster-source-repository-guidance <?php echo ! empty( $packageRepositoryReady ) ? 'hidden' : ''; ?>>
			<?php esc_html_e( 'Choose or enter a repository above before configuring its package source.', 'ran-booster' ); ?>
		</p>
	</header>
	<div class="ran-booster-source-choices<?php echo 'edit' === $sourceChoiceMode ? ' ran-booster-source-choices--navigation nav-tab-wrapper wp-clearfix' : ''; ?>"<?php echo 'edit' === $sourceChoiceMode ? ' role="navigation" aria-label="' . esc_attr( __( 'Package source settings', 'ran-booster' ) ) . '"' : ''; ?>>
		<?php foreach ( $packageSourceChoices as $sourceKey => $sourceChoice ) { ?>
			<?php
			$isSelected          = $sourceKey === $packageSourceView;
			$isCurrent           = 'edit' === $sourceChoiceMode && isset( $packageCurrentSource ) && $sourceKey === $packageCurrentSource;
			$isNavigationLink    = 'edit' === $sourceChoiceMode && ! $sourceChoice['disabled'] && ! $isSelected;
			$isCurrentView       = 'edit' === $sourceChoiceMode && ! $sourceChoice['disabled'] && $isSelected;
			$classes             = 'ran-booster-source-choice' . ( 'edit' === $sourceChoiceMode ? ' ran-booster-source-choice--navigation nav-tab' : '' ) . ( $isSelected ? ' is-selected' : '' ) . ( 'edit' === $sourceChoiceMode && $isSelected ? ' nav-tab-active' : '' ) . ( $sourceChoice['disabled'] ? ' is-disabled' : '' );
			$sourceHeading       = $sourceChoice['heading'];
			$sourceSlug          = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $sourceKey ) );
			$tabId               = 'ran-booster-source-tab-' . $sourceSlug;
			$panelId             = 'ran-booster-source-pane-' . $sourceSlug;
			$sourceUrl           = wp_make_link_relative( $sourceChoice['url'] );
			$disabledExplanation = $sourceChoice['disabled'] ? (string) $sourceChoice['description'] : '';
			?>
			<?php if ( $isNavigationLink ) { ?>
				<a id="<?php echo esc_attr( $tabId ); ?>" aria-controls="<?php echo esc_attr( $panelId ); ?>" class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $sourceUrl . '#ran-booster-advanced-source-settings' ); ?>" hx-get="<?php echo esc_url( $sourceUrl ); ?>" hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none" hx-push-url="true" hx-history="false" hx-sync="closest [data-ran-booster-source-controls]:replace" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-package-mutation-error" data-ran-booster-source-choice="<?php echo esc_attr( $sourceKey ); ?>">
			<?php } elseif ( $isCurrentView ) { ?>
				<span id="<?php echo esc_attr( $tabId ); ?>" aria-controls="<?php echo esc_attr( $panelId ); ?>" aria-current="page" class="<?php echo esc_attr( $classes ); ?>" data-ran-booster-source-choice="<?php echo esc_attr( $sourceKey ); ?>">
			<?php } else { ?>
				<button id="<?php echo esc_attr( $tabId ); ?>" aria-controls="<?php echo esc_attr( $panelId ); ?>" aria-pressed="<?php echo $isSelected ? 'true' : 'false'; ?>"<?php echo $sourceChoice['disabled'] ? ' aria-disabled="true"' : ''; ?><?php echo '' !== $disabledExplanation ? ' title="' . esc_attr( $disabledExplanation ) . '"' : ''; ?> type="button" class="<?php echo esc_attr( $classes ); ?>" data-ran-booster-source-choice="<?php echo esc_attr( $sourceKey ); ?>" data-ran-booster-source-hydratable="<?php echo $sourceChoice['client_hydratable'] ? '1' : '0'; ?>">
			<?php } ?>
				<?php if ( 'create' === $sourceChoiceMode ) { ?>
					<span class="ran-booster-source-choice__radio" aria-hidden="true"></span>
				<?php } ?>
				<span>
					<strong data-ran-booster-source-heading><?php echo esc_html( $sourceHeading ); ?></strong>
					<?php if ( 'create' === $sourceChoiceMode ) { ?>
						<small data-ran-booster-source-description><?php echo esc_html( $sourceChoice['description'] ); ?></small>
						<?php if ( '' !== $sourceChoice['meta'] ) { ?>
							<span class="ran-booster-source-choice__meta" data-ran-booster-source-meta><?php echo esc_html( $sourceChoice['meta'] ); ?></span>
						<?php } ?>
					<?php } ?>
				</span>
				<?php if ( $isCurrent ) { ?>
					<span class="ran-booster-source-choice__current-source"><?php esc_html_e( 'Active', 'ran-booster' ); ?></span>
				<?php } ?>
			<?php if ( $isNavigationLink ) { ?>
				</a>
			<?php } elseif ( $isCurrentView ) { ?>
				</span>
			<?php } else { ?>
				</button>
			<?php } ?>
		<?php } ?>
	</div>
</div>
