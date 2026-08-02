<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Logging\BoosterLogger;
use Throwable;

/**
 * Renders trusted, structured documentation sections supplied by add-ons.
 */
final class DocumentationHookRenderer {

	/**
	 * Render structured add-on documentation sections.
	 *
	 * @param non-empty-string $filterHook
	 */
	public function renderSections(
		string $filterHook,
		string $documentationUrl,
		string $scope,
		?string $providerCode = null
	): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Filter is a validated Core-owned documentation extension point.
		$sections = apply_filters( $filterHook, array(), $documentationUrl, $scope );

		if ( is_array( $sections ) ) {
			foreach ( $sections as $section ) {
				$this->renderSection( $section, $providerCode );
			}
		}
	}

	private function renderSection( mixed $section, ?string $providerCode ): void {
		if ( ! is_array( $section ) ) {
			return;
		}

		$id      = $section['id'] ?? null;
		$summary = $section['summary'] ?? null;
		$content = $section['content'] ?? null;
		$open    = true === ( $section['open'] ?? false );

		if (
			! is_string( $id )
			|| 1 !== preg_match( '/\A[a-z][a-z0-9_-]{0,127}\z/', $id )
			|| ! is_string( $summary )
			|| '' === trim( $summary )
			|| ( ! is_string( $content ) && ! is_callable( $content ) )
		) {
			return;
		}

		$bufferLevel = ob_get_level();

		try {
			if ( is_callable( $content ) ) {
				ob_start();
				$content();
				$content = (string) ob_get_clean();
			}
		} catch ( Throwable $failure ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}

			BoosterLogger::logException(
				'documentation section rendering failed',
				$failure,
				array(
					'source'   => 'admin',
					'step'     => 'documentation_section_render',
					'provider' => $providerCode ?? '',
				)
			);
			$this->renderUnavailableGuide();

			return;
		}

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return;
		}
		?>
		<details id="<?php echo esc_attr( $id ); ?>" class="ran-booster-documentation__section ran-booster-panel"<?php echo $open ? ' open' : ''; ?>>
			<summary><?php echo esc_html( $summary ); ?></summary>
			<div class="ran-booster-documentation__content"><?php echo wp_kses_post( $content ); ?></div>
		</details>
		<?php
	}

	private function renderUnavailableGuide(): void {
		?>
		<details class="ran-booster-documentation__section ran-booster-panel">
			<summary><?php esc_html_e( 'Add-on guide unavailable', 'ran-booster' ); ?></summary>
			<div class="ran-booster-documentation__content">
				<p><?php esc_html_e( 'An add-on guide is temporarily unavailable. Check plugin compatibility and the error log.', 'ran-booster' ); ?></p>
			</div>
		</details>
		<?php
	}
}
