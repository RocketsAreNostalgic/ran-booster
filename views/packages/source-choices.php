<?php // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included view bindings are supplied by the caller; declarations and hooks still require prefixes.

/**
 * Inherited from the including package template.
 *
 * @var array<string, array<string, mixed>> $package_source_choices
 * @var string $package_source_view
 * @var \RAN\Admin\PackagePagePresenter $package_view
 */

defined( 'WPINC' ) || die;

$source_choice_mode = isset( $package_source_mode ) && 'create' === $package_source_mode ? 'create' : 'edit';
if ( ! is_array( $package_source_choices ) || array() === $package_source_choices ) {
	$page_url               = 'create' === $source_choice_mode
		? add_query_arg( 'page', $package_view->get_create_page_slug(), $package_view->get_admin_url() )
		: add_query_arg(
			array(
				'page'    => $package_view->get_page_slug(),
				'package' => (string) ( $identifier_value ?? '' ),
			),
			$package_view->get_admin_url()
		);
	$package_source_choices = $package_view->get_source_choice_defaults( $page_url, true );
}

?>
<legend class="screen-reader-text"><?php esc_html_e( 'Update source', 'ran-booster' ); ?></legend>
<div class="ran-booster-package-source<?php echo 'edit' === $source_choice_mode ? ' ran-booster-package-source--navigation' : ''; ?>">
	<div class="ran-booster-package-source__header">
		<h3 id="ran-booster-package-source-heading" class="ran-booster-section__title"><?php esc_html_e( 'Update source', 'ran-booster' ); ?></h3>
		<?php if ( 'edit' !== $source_choice_mode ) { ?>
		<p class="ran-booster-section__description"><?php esc_html_e( 'Selecting it does not install the package.', 'ran-booster' ); ?></p>
		<?php } ?>
		<div class="notice notice-info inline ran-booster-package-source__guidance ran-wp-ui-notice ran-wp-ui-box-sizing is-info" data-ran-booster-ui-notice data-ran-booster-ui-notice-intent="info" data-ran-booster-source-repository-guidance <?php echo ! empty( $package_repository_ready ) ? 'hidden' : ''; ?>><div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content><p><?php esc_html_e( 'Choose a repository first.', 'ran-booster' ); ?></p></div><?php \RAN\Admin\Component\NoticeIcon::print_intent( 'info' ); ?></div>
	</div>
	<div class="ran-booster-source-choices<?php echo 'edit' === $source_choice_mode ? esc_attr( ' ran-booster-source-choices--navigation ' . \RAN\Admin\Component\SectionTabs::STRIP_CLASS ) : ''; ?>"<?php echo 'edit' === $source_choice_mode ? ' role="navigation" aria-label="' . esc_attr( __( 'Update source settings', 'ran-booster' ) ) . '"' : ''; ?>>
		<?php if ( 'edit' === $source_choice_mode ) { ?>
			<ul class="<?php echo esc_attr( \RAN\Admin\Component\SectionTabs::LIST_CLASS ); ?>">
		<?php } ?>
		<?php foreach ( $package_source_choices as $source_key => $source_choice ) { ?>
			<?php
			$is_selected          = $source_key === $package_source_view;
			$is_current           = 'edit' === $source_choice_mode && isset( $package_current_source ) && $source_key === $package_current_source;
			$is_navigation_link   = 'edit' === $source_choice_mode && ! $source_choice['disabled'] && ! $is_selected;
			$is_current_view      = 'edit' === $source_choice_mode && ! $source_choice['disabled'] && $is_selected;
			$classes              = 'ran-booster-source-choice' . ( 'edit' === $source_choice_mode ? ' ran-booster-source-choice--navigation ' . \RAN\Admin\Component\SectionTabs::TAB_CLASS : '' ) . ( $is_selected ? ' is-selected' : '' ) . ( 'edit' === $source_choice_mode && $is_selected ? ' current' : '' ) . ( $source_choice['disabled'] ? ' is-disabled' : '' );
			$source_heading       = $source_choice['heading'];
			$source_slug          = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $source_key ) );
			$tab_id               = 'ran-booster-source-tab-' . $source_slug;
			$source_url           = wp_make_link_relative( add_query_arg( array_map( 'rawurlencode', $package_return_args ?? array() ), $source_choice['url'] ) );
			$disabled_explanation = $source_choice['disabled'] ? (string) $source_choice['description'] : '';
			?>
			<?php if ( 'edit' === $source_choice_mode ) { ?>
				<li>
			<?php } ?>
			<?php if ( $is_navigation_link ) { ?>
				<a id="<?php echo esc_attr( $tab_id ); ?>" class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $source_url . '#ran-booster-advanced-source-settings' ); ?>" hx-get="<?php echo esc_url( $source_url ); ?>" hx-target="#wpbody-content" hx-select="#wpbody-content" hx-swap="outerHTML show:none" hx-push-url="true" hx-history="false" hx-sync="closest [data-ran-booster-source-controls]:replace" data-ran-booster-enhanced-mutation data-ran-booster-error-target="#ran-booster-package-mutation-error" data-ran-booster-source-choice="<?php echo esc_attr( $source_key ); ?>">
			<?php } elseif ( $is_current_view ) { ?>
				<span id="<?php echo esc_attr( $tab_id ); ?>" aria-current="page" tabindex="-1" class="<?php echo esc_attr( $classes ); ?>" data-ran-booster-source-choice="<?php echo esc_attr( $source_key ); ?>">
			<?php } else { ?>
				<button id="<?php echo esc_attr( $tab_id ); ?>" aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>"<?php echo $source_choice['disabled'] ? ' aria-disabled="true"' : ''; ?><?php echo '' !== $disabled_explanation ? ' title="' . esc_attr( $disabled_explanation ) . '"' : ''; ?> type="button" class="<?php echo esc_attr( $classes ); ?>" data-ran-booster-source-choice="<?php echo esc_attr( $source_key ); ?>" data-ran-booster-source-hydratable="<?php echo $source_choice['client_hydratable'] ? '1' : '0'; ?>">
			<?php } ?>
				<?php if ( 'create' === $source_choice_mode ) { ?>
					<span class="ran-booster-source-choice__radio" aria-hidden="true"></span>
				<?php } ?>
				<span class="ran-booster-source-choice__content">
					<strong id="<?php echo esc_attr( $tab_id . '-heading' ); ?>" data-ran-booster-source-heading><?php echo esc_html( $source_heading ); ?></strong>
					<?php if ( 'create' === $source_choice_mode ) { ?>
						<small data-ran-booster-source-description><?php echo esc_html( $source_choice['description'] ); ?></small>
					<?php } ?>
				</span>
				<?php if ( $is_current ) { ?>
					<?php \RAN\Admin\Component\SectionTabs::print_in_use_marker(); ?>
				<?php } ?>
			<?php if ( $is_navigation_link ) { ?>
				</a>
			<?php } elseif ( $is_current_view ) { ?>
				</span>
			<?php } else { ?>
				</button>
			<?php } ?>
			<?php if ( 'edit' === $source_choice_mode ) { ?>
				</li>
			<?php } ?>
		<?php } ?>
		<?php if ( 'edit' === $source_choice_mode ) { ?>
			</ul>
			<p class="ran-booster-provider-task-progress" data-ran-booster-source-progress role="status" aria-live="polite" hidden><span class="spinner is-active" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Loading source settings…', 'ran-booster' ); ?></span></p>
		<?php } ?>
	</div>
</div>
