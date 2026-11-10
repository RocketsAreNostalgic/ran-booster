<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

/**
 * The marker that ends a plain text link opening another site in a new tab.
 *
 * A decorative arrow for sighted users followed by the new-tab hint for
 * assistive technology. Print it as the last child of the anchor so the
 * accessible name reads "Label (opens in a new tab)" with no duplicate hint.
 */
final class ExternalLinkMarker {

	/** The marker markup; the new-tab hint is escaped translated text. */
	public static function markup(): string {
		return '<span aria-hidden="true">↗</span><span class="screen-reader-text">' . esc_html__( ' (opens in a new tab)', 'ran-booster' ) . '</span>';
	}

	/** Print the marker markup. */
	public static function print(): void {
		echo self::markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Constant markup around an escaped translation.
	}
}
