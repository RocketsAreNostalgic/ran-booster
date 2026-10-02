<?php

declare(strict_types=1);

namespace Tests\Admin;

require_once dirname( __DIR__ ) . '/Support/PackageViewWordPressFunctions.php';

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\Admin\Component\ProviderManagementTableRenderer;

final class ProviderManagementTableRendererTest extends TestCase {

	public function test_it_renders_the_bounded_access_column_schema_and_delegates_cell_content(): void {
		$rendered_columns = array();
		$html            = $this->render(
			ProviderManagementTableRenderer::ACCESS,
			array( array( 'value' => 'Profile <one>' ) ),
			static function ( array $row, string $column ) use ( &$rendered_columns ): void {
				$rendered_columns[] = $column;
				?>
				<span data-cell="<?php echo esc_attr( $column ); ?>"><?php echo esc_html( $row['value'] ); ?></span>
				<?php
			}
		);

		self::assertSame( array( 'name', 'kind', 'scope', 'usage', 'health', 'actions' ), $rendered_columns );
		self::assertStringContainsString( 'ran-booster-credential-table--access', $html );
		self::assertStringContainsString( '<th scope="col">', $html );
		self::assertStringContainsString( '>Credential type</a>', $html );
		self::assertStringContainsString( 'data-label="Used by"', $html );
		self::assertStringContainsString( 'class="ran-booster-actions" data-label="Actions"', $html );
		self::assertStringContainsString( '<span class="screen-reader-text">Actions</span>', $html );
		self::assertStringContainsString( 'Profile &lt;one&gt;', $html );
		self::assertStringContainsString( 'orderby=name&amp;next=&lt;unsafe&gt;', $html );
		self::assertSame( 2, substr_count( $html, 'aria-disabled="true" tabindex="-1"' ) );
	}

	public function test_it_renders_the_webhook_schema_without_access_only_columns(): void {
		$rendered_columns = array();
		$html            = $this->render(
			ProviderManagementTableRenderer::WEBHOOK,
			array( array( 'value' => 'Secret' ) ),
			static function ( array $row, string $column ) use ( &$rendered_columns ): void {
				unset( $row );
				$rendered_columns[] = $column;
			}
		);

		self::assertSame( array( 'name', 'scope', 'usage', 'health', 'actions' ), $rendered_columns );
		self::assertStringContainsString( 'ran-booster-credential-table--webhook', $html );
		self::assertStringNotContainsString( 'Credential type', $html );
		self::assertSame( 5, substr_count( $html, '<th scope="col">' ) );
	}

	public function test_it_escapes_the_empty_state_and_does_not_invoke_the_row_callback(): void {
		$callback_invoked = false;
		$html            = $this->render(
			ProviderManagementTableRenderer::WEBHOOK,
			array(),
			static function () use ( &$callback_invoked ): void {
				$callback_invoked = true;
			},
			'No <matching> secrets.'
		);

		self::assertFalse( $callback_invoked );
		self::assertStringContainsString( '<td colspan="5">No &lt;matching&gt; secrets.</td>', $html );
	}

	public function test_it_owns_escaped_pagination_chrome_and_preserves_structured_query_fields(): void {
		$html = $this->render(
			ProviderManagementTableRenderer::ACCESS,
			array(),
			static function (): void {},
			'No credentials.',
			array(
				'item_count_label' => '21 <items>',
				'page_label'       => 'Page 2 & 3',
				'current'          => 2,
				'pages'            => 3,
				'per_page'         => 50,
				'action_url'       => 'https://example.test/wp-admin/admin.php?mode=<unsafe>',
				'hidden_fields'    => array(
					'page' => 'ran-booster',
					'tab'  => 'gh"><unsafe',
				),
				'previous_url'     => 'https://example.test/list?paged=1&mode=<unsafe>',
				'next_url'         => 'https://example.test/list?paged=3&mode=<unsafe>',
			)
		);

		self::assertStringContainsString( 'class="ran-booster-provider-table-navigation"', $html );
		self::assertStringContainsString( '21 &lt;items&gt;', $html );
		self::assertStringContainsString( 'Page 2 &amp; 3', $html );
		self::assertStringContainsString( 'id="ran-booster-credential-page-size"', $html );
		self::assertStringContainsString( 'value="50"  selected="selected"', $html );
		self::assertStringContainsString( 'name="tab" value="gh&quot;&gt;&lt;unsafe"', $html );
		self::assertStringContainsString( 'paged=1&amp;mode=&lt;unsafe&gt;', $html );
		self::assertStringContainsString( 'paged=3&amp;mode=&lt;unsafe&gt;', $html );
		self::assertStringNotContainsString( 'aria-disabled="true"', $html );
	}

	public function test_it_rejects_unsupported_table_types_before_rendering(): void {
		$this->expectException( InvalidArgumentException::class );

		( new ProviderManagementTableRenderer() )->render(
			'credentialish',
			array(),
			'Nothing found.',
			static function (): void {},
			array(),
			array(
				'item_count_label' => '0 items',
				'page_label'       => 'Page 1 of 1',
				'current'          => 1,
				'pages'            => 1,
				'per_page'         => 20,
				'action_url'       => 'https://example.test/wp-admin/admin.php',
				'hidden_fields'    => array(),
				'previous_url'     => 'https://example.test/list?paged=1',
				'next_url'         => 'https://example.test/list?paged=1',
			)
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param \Closure(array<string, mixed>, string): void $render_cell
	 * @param array<string, mixed> $pagination_overrides
	 */
	private function render(
		string $type,
		array $rows,
		\Closure $render_cell,
		string $empty_message = 'Nothing found.',
		array $pagination_overrides = array()
	): string {
		$pagination = array_merge(
			array(
				'item_count_label' => '1 item',
				'page_label'       => 'Page 1 of 1',
				'current'          => 1,
				'pages'            => 1,
				'per_page'         => 20,
				'action_url'       => 'https://example.test/wp-admin/admin.php',
				'hidden_fields'    => array(
					'page' => 'ran-booster',
					'tab'  => 'gh',
					'view' => 'credentials',
				),
				'previous_url'     => 'https://example.test/list?paged=1',
				'next_url'         => 'https://example.test/list?paged=1',
			),
			$pagination_overrides
		);

		ob_start();
		( new ProviderManagementTableRenderer() )->render(
			$type,
			$rows,
			$empty_message,
			$render_cell,
			array_fill_keys(
				array( 'name', 'kind', 'scope', 'usage', 'health' ),
				'https://example.test/list?orderby=name&next=<unsafe>'
			),
			$pagination
		);

		return (string) ob_get_clean();
	}
}
