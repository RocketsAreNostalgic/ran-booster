<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * Shared underline tab strip used by the repository page and the Edit source switcher.
 *
 * Both strips render the same shared section navigation track and the same
 * green "in use" dot, so the two screens cannot drift apart.
 */
final class SectionTabs {

	/** The tab strip wrapper; carries the bottom rule and the loading indicator slot. */
	public const STRIP_CLASS = 'ran-booster-provider-task-tabs';

	/** The list that carries the shared section navigation track. */
	public const LIST_CLASS = 'filter-links ran-section-navigation ran-section-navigation--thin';

	/** One tab: link, current-view span or disabled button. */
	public const TAB_CLASS = 'ran-wp-ui-box-sizing ran-wp-ui-reset-a ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-link is-unstyled ran-booster-provider-task-tab ran-section-navigation__link';

	/** The "in use" dot with its hidden label and title; print it as the last child of a tab. */
	public static function in_use_marker(): string {
		$label = __( 'In use', 'ran-booster' );

		return '<span class="ran-booster-provider-task-tab__source-indicator" title="' . esc_attr( $label ) . '" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $label ) . '</span>';
	}

	/** Print the "in use" marker. */
	public static function print_in_use_marker(): void {
		echo self::in_use_marker(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Constant markup around escaped translations.
	}
}
