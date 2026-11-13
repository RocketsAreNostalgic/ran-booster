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
		self::store( $codes );
	}

	/** Store one Check releases result, with the newer release version it may name. */
	public static function enqueue_check_result( string $code, string $version = '' ): void {
		self::store( array( $code ), $version );
	}

	/** @param array<array-key, string> $codes */
	private static function store( array $codes, string $version = '' ): void {
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
			) + ( self::valid_version( $version ) ? array( 'version' => $version ) : array() )
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
			$version    = is_string( $flash['version'] ?? null ) ? $flash['version'] : '';
			$definition = is_string( $code ) ? self::definition( $code, $version ) : null;
			if ( null !== $definition ) {
				$target = isset( $definition[2] ) ? ' data-feedback-target="' . esc_attr( $definition[2] ) . '"' : '';
				$items .= '<div class="ran-booster-feedback__item" data-feedback-key="' . esc_attr( $code ) . '" data-intent="' . esc_attr( $definition[0] ) . '"' . $target . ' role="' . ( 'error' === $definition[0] ? 'alert' : 'status' ) . '">' . esc_html( $definition[1] ) . '</div>';
			}
		}
		if ( '' !== $items ) {
			echo '<div class="ran-booster-feedback" data-ran-booster-feedback>' . $items . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic value above is escaped; the code is a closed vocabulary member.
		}
	}

	/** The whole sentence shared by the Check releases update-available fallback and the notice. */
	public static function release_checked(): string {
		return __( 'Release check complete.', 'ran-booster' );
	}

	/** The id of the inline notice a Check releases pointer leads to. */
	public const CHECK_RESULT_TARGET = 'ran-booster-release-check-result';

	/**
	 * The one copy source for a Check releases result that is not a success:
	 * the snackbar and the inline notice show the summary, and the notice adds the detail.
	 *
	 * @return array{0: 'info'|'error'|'warning', 1: string, 2: string}|null Intent, summary and detail for a closed result code.
	 */
	public static function check_result( string $code, string $version = '' ): ?array {
		return match ( $code ) {
			'refresh_newer_while_off'    => array(
				'info',
				self::valid_version( $version )
					/* translators: %s: release version. */
					? sprintf( __( 'Newer release %s available. Updates are off for this package.', 'ran-booster' ), $version )
					: __( 'Newer release available. Updates are off for this package.', 'ran-booster' ),
				'',
			),
			'refresh_current_while_off'  => array( 'info', __( 'Checked: no newer release. Updates are off for this package.', 'ran-booster' ), '' ),
			'refresh_skipped_disabled'   => array( 'info', __( 'Check not run. Updates are off for this package.', 'ran-booster' ), __( 'Nothing is wrong. Turn updates on in the package settings to check for releases.', 'ran-booster' ) ),
			'refresh_skipped_forced_off' => array( 'info', __( 'Check not run. Site configuration turns updates off.', 'ran-booster' ), __( 'Nothing is wrong. Site configuration blocks updates for every package; change it there to allow checks.', 'ran-booster' ) ),
			'refresh_unchecked'          => array( 'warning', __( 'Releases were not checked. Use Check releases to retry.', 'ran-booster' ), '' ),
			'refresh_rejected'           => array( 'warning', __( 'The newest release cannot be used.', 'ran-booster' ), __( 'It did not pass Booster\'s eligibility checks. Activity records the reason.', 'ran-booster' ) ),
			'refresh_check_failed'       => array( 'error', __( 'The release check failed.', 'ran-booster' ), __( 'Booster could not finish the check. Try again; if it repeats, see Activity.', 'ran-booster' ) ),
			'refresh_failed'             => array( 'error', __( 'The release check could not start. Review this package’s published release settings, then retry.', 'ran-booster' ), '' ),
			default                      => null,
		};
	}

	private static function valid_version( string $version ): bool {
		return 1 === preg_match( '/\A[0-9A-Za-z][0-9A-Za-z.+_-]{0,63}\z/', $version );
	}

	/** @return array{0: 'success'|'info'|'error'|'warning', 1: string, 2?: string}|null Intent, fixed Core copy and optional inline target for a closed code. */
	private static function definition( string $code, string $version = '' ): ?array {
		$check = self::check_result( $code, $version );
		return match ( $code ) {
			'release_check_current' => array( 'success', __( 'Release check complete. No newer eligible release was found.', 'ran-booster' ) ),
			'release_check_update'  => array( 'success', self::release_checked() ),
			default                 => null === $check ? null : array( $check[0], $check[1], self::CHECK_RESULT_TARGET ),
		};
	}
}
