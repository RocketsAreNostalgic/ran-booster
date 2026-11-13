<?php

declare(strict_types=1);

namespace RAN\Admin\Feedback;

/**
 * Per-user one-shot feedback flash for the next Booster screen render.
 *
 * The flash holds closed codes only. Display copy is resolved from the code at
 * render time, so no text, URL or data from any caller can enter storage or
 * markup. Unknown codes are dropped.
 *
 * @internal
 */
final class FeedbackFlash {

	public const USER_META_KEY = '_ran_booster_feedback_flash';

	private const TTL       = 60;
	private const MAX_ITEMS = 3;

	/** Store the codes for this user's next Booster screen. A later call replaces an unread flash. */
	public static function enqueue( string ...$codes ): void {
		$user_id = get_current_user_id();
		$codes   = array_slice(
			array_values( array_unique( array_filter( $codes, static fn ( string $code ): bool => null !== self::definition( $code ) ) ) ),
			0,
			self::MAX_ITEMS
		);
		if ( $user_id < 1 || array() === $codes ) {
			return;
		}

		update_user_meta(
			$user_id,
			self::USER_META_KEY,
			array(
				'site'  => get_current_blog_id(),
				'at'    => time(),
				'codes' => $codes,
			)
		);
	}

	/** Print the static fallback for this user's flash once, then delete it. */
	public static function render(): void {
		$user_id = get_current_user_id();
		$flash   = $user_id < 1 || ! current_user_can( 'manage_options' ) ? null : get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $flash ) ) {
			return;
		}

		$at = $flash['at'] ?? null;
		if ( ! is_int( $at ) || time() - $at > self::TTL || ! is_array( $flash['codes'] ?? null ) ) {
			delete_user_meta( $user_id, self::USER_META_KEY );
			return;
		}
		// Another site's screen leaves the flash for its own site, and it expires.
		if ( ( $flash['site'] ?? null ) !== get_current_blog_id() ) {
			return;
		}
		// Deleting the exact value is the claim: the tab that loses the race prints nothing.
		if ( ! delete_user_meta( $user_id, self::USER_META_KEY, $flash ) ) {
			return;
		}

		$items = '';
		foreach ( $flash['codes'] as $code ) {
			$definition = is_string( $code ) ? self::definition( $code ) : null;
			if ( null !== $definition ) {
				$items .= '<div class="ran-booster-feedback__item" data-feedback-key="' . esc_attr( $code ) . '" data-intent="' . esc_attr( $definition[0] ) . '" role="' . ( 'error' === $definition[0] ? 'alert' : 'status' ) . '">' . esc_html( $definition[1] ) . '</div>';
			}
		}
		if ( '' !== $items ) {
			echo '<div class="ran-booster-feedback" data-ran-booster-feedback>' . $items . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic value above is escaped; the code is a closed vocabulary member.
		}
	}

	/** The opening clause shared by the Check releases snackbar and notice. */
	public static function release_checked(): string {
		return __( 'Release check complete.', 'ran-booster' );
	}

	/** The outcome clause shared by the Check releases snackbar and the inline release status. */
	public static function no_newer_release(): string {
		return __( 'No newer eligible release was found.', 'ran-booster' );
	}

	/** @return array{0: 'success'|'error'|'warning', 1: string}|null Intent and fixed Core copy for a closed code. */
	private static function definition( string $code ): ?array {
		return match ( $code ) {
			'release_check_current' => array( 'success', self::release_checked() . ' ' . self::no_newer_release() ),
			'release_check_update'  => array( 'success', self::release_checked() ),
			default                 => null,
		};
	}
}
