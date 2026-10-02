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
	 * @param non-empty-string $filter_hook
	 */
	public function render_sections(
		string $filter_hook,
		string $documentation_url,
		string $scope,
		?string $provider_code = null
	): void {

		$this->render_prepared_sections( $this->prepare_sections( $filter_hook, $documentation_url, $scope, $provider_code ) );
	}

	/**
	 * Resolve structured add-on documentation sections once, before output.
	 *
	 * @param non-empty-string $filter_hook
	 * @return list<array{id: string, summary: string, content: string, open: bool}>
	 */
	public function prepare_sections(
		string $filter_hook,
		string $documentation_url,
		string $scope,
		?string $provider_code = null
	): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Filter is a validated Core-owned documentation extension point. Public named parameters are retained.
		$sections = apply_filters( $filter_hook, array(), $documentation_url, $scope );
		$prepared = array();

		if ( is_array( $sections ) ) {
			foreach ( $sections as $section ) {

				$normalized = $this->normalize_section( $section, $provider_code );
				if ( null !== $normalized ) {
					$prepared[] = $normalized;
				}
			}
		}

		return $prepared;
	}

	/**
	 * @param list<array{id: string, summary: string, content: string, open: bool}> $sections
	 */
	public function render_prepared_sections( array $sections ): void {
		foreach ( $sections as $section ) {
			?>
			<details id="<?php echo esc_attr( $section['id'] ); ?>" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section<?php echo $section['open'] ? ' open' : ''; ?>>
				<summary><?php echo esc_html( $section['summary'] ); ?></summary>
				<div class="ran-booster-documentation__content"><?php echo wp_kses_post( $section['content'] ); ?></div>
			</details>
			<?php
		}
	}

	/** @return array{id: string, summary: string, content: string, open: bool}|null */
	private function normalize_section( mixed $section, ?string $provider_code ): ?array {
		if ( ! is_array( $section ) ) {
			return null;
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
			return null;
		}

		$buffer_level = ob_get_level();

		try {
			if ( is_callable( $content ) ) {
				ob_start();
				$content();
				$content = (string) ob_get_clean();
			}
		} catch ( Throwable $failure ) {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}

			BoosterLogger::log_exception(
				'documentation section rendering failed',
				$failure,
				array(
					'source'   => 'admin',
					'step'     => 'documentation_section_render',
					'provider' => $provider_code ?? '',
				)
			);
			return array(
				'id'      => $id,
				'summary' => $summary,
				'content' => '<p>' . esc_html__( 'An add-on guide is temporarily unavailable. Check plugin compatibility and the error log.', 'ran-booster' ) . '</p>',
				'open'    => $open,
			);
		}

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return null;
		}

		$content = wp_kses( $content, $this->documentation_content_allowed_html() );

		if ( '' === trim( $content ) ) {
			return null;
		}

		return array(
			'id'      => $id,
			'summary' => $summary,
			'content' => $content,
			'open'    => $open,
		);
	}

	/** @return array<string, array<string, true>> */
	private function documentation_content_allowed_html(): array {
		$allowed_html = wp_kses_allowed_html( 'post' );

		foreach ( $allowed_html as &$attributes ) {
			unset( $attributes['id'] );
		}
		unset( $attributes );

		return $allowed_html;
	}
}
