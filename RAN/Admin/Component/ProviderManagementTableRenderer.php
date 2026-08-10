<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

use Closure;
use InvalidArgumentException;

/**
 * Renders the shared provider credential and webhook-secret list structure.
 *
 * The renderer owns only the bounded table schema and pagination chrome.
 * Callers retain responsibility for each cell's escaped semantic content,
 * actions, forms and nonces.
 */
final class ProviderManagementTableRenderer {

	public const ACCESS  = 'access';
	public const WEBHOOK = 'webhook';

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param Closure(array<string, mixed>, string): void $renderCell
	 * @param array<string,string> $sortUrls
	 * @param array{
	 *     item_count_label: string,
	 *     page_label: string,
	 *     current: int,
	 *     pages: int,
	 *     per_page: int,
	 *     action_url: string,
	 *     hidden_fields: array<string, scalar>,
	 *     previous_url: string,
	 *     next_url: string
	 * } $pagination
	 */
	public function render(
		string $type,
		array $rows,
		string $emptyMessage,
		Closure $renderCell,
		array $sortUrls,
		array $pagination
	): void {
		$columns = $this->columns( $type );
		?>
		<table class="widefat striped ran-booster-data-table ran-booster-credential-table ran-booster-credential-table--<?php echo esc_attr( $type ); ?> ran-booster-provider-management-table">
			<thead>
				<tr>
					<?php foreach ( $columns as $column ) { ?>
						<th scope="col">
							<?php if ( null === $column['sort'] ) { ?>
								<span class="screen-reader-text"><?php echo esc_html( $column['label'] ); ?></span>
							<?php } else { ?>
								<a href="<?php echo esc_url( $sortUrls[ $column['sort'] ] ?? '' ); ?>"><?php echo esc_html( $column['label'] ); ?></a>
							<?php } ?>
						</th>
					<?php } ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $rows ) { ?>
					<tr><td colspan="<?php echo esc_attr( (string) count( $columns ) ); ?>"><?php echo esc_html( $emptyMessage ); ?></td></tr>
				<?php } ?>
				<?php foreach ( $rows as $row ) { ?>
					<tr>
						<?php foreach ( $columns as $column ) { ?>
							<td<?php echo $column['actions'] ? ' class="ran-booster-actions"' : ''; ?> data-label="<?php echo esc_attr( $column['label'] ); ?>"><?php $renderCell( $row, $column['key'] ); ?></td>
						<?php } ?>
					</tr>
				<?php } ?>
			</tbody>
		</table>
		<?php
		$this->renderPagination( $type, $pagination );
	}

	/**
	 * @return list<array{key: string, label: string, sort: ?string, actions: bool}>
	 */
	private function columns( string $type ): array {
		if ( self::ACCESS === $type ) {
			return array(
				array(
					'key'     => 'name',
					'label'   => __( 'Name', 'ran-booster' ),
					'sort'    => 'name',
					'actions' => false,
				),
				array(
					'key'     => 'kind',
					'label'   => __( 'Credential type', 'ran-booster' ),
					'sort'    => 'kind',
					'actions' => false,
				),
				array(
					'key'     => 'scope',
					'label'   => __( 'Scope', 'ran-booster' ),
					'sort'    => 'scope',
					'actions' => false,
				),
				array(
					'key'     => 'usage',
					'label'   => __( 'Used by', 'ran-booster' ),
					'sort'    => 'usage',
					'actions' => false,
				),
				array(
					'key'     => 'health',
					'label'   => __( 'Health', 'ran-booster' ),
					'sort'    => 'health',
					'actions' => false,
				),
				array(
					'key'     => 'actions',
					'label'   => __( 'Actions', 'ran-booster' ),
					'sort'    => null,
					'actions' => true,
				),
			);
		}

		if ( self::WEBHOOK === $type ) {
			return array(
				array(
					'key'     => 'name',
					'label'   => __( 'Name', 'ran-booster' ),
					'sort'    => 'name',
					'actions' => false,
				),
				array(
					'key'     => 'scope',
					'label'   => __( 'Scope', 'ran-booster' ),
					'sort'    => 'scope',
					'actions' => false,
				),
				array(
					'key'     => 'usage',
					'label'   => __( 'Used by', 'ran-booster' ),
					'sort'    => 'usage',
					'actions' => false,
				),
				array(
					'key'     => 'health',
					'label'   => __( 'Health', 'ran-booster' ),
					'sort'    => 'health',
					'actions' => false,
				),
				array(
					'key'     => 'actions',
					'label'   => __( 'Actions', 'ran-booster' ),
					'sort'    => null,
					'actions' => true,
				),
			);
		}

		throw new InvalidArgumentException( 'Provider management tables require a supported type.' );
	}

	/**
	 * @param array{
	 *     item_count_label: string,
	 *     page_label: string,
	 *     current: int,
	 *     pages: int,
	 *     per_page: int,
	 *     action_url: string,
	 *     hidden_fields: array<string, scalar>,
	 *     previous_url: string,
	 *     next_url: string
	 * } $pagination
	 */
	private function renderPagination( string $type, array $pagination ): void {
		$pageSizeId = self::ACCESS === $type
			? 'ran-booster-credential-page-size'
			: 'ran-booster-secret-page-size';
		$current    = max( 1, $pagination['current'] );
		$pages      = max( 1, $pagination['pages'] );
		?>
		<div class="ran-booster-provider-table-navigation">
			<span><?php echo esc_html( $pagination['item_count_label'] ); ?></span>
			<div>
				<label for="<?php echo esc_attr( $pageSizeId ); ?>"><?php esc_html_e( 'Rows', 'ran-booster' ); ?></label>
				<form method="get" action="<?php echo esc_url( $pagination['action_url'] ); ?>" class="ran-booster-inline-form">
					<?php foreach ( $pagination['hidden_fields'] as $name => $value ) { ?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
					<?php } ?>
					<select id="<?php echo esc_attr( $pageSizeId ); ?>" name="per_page" onchange="this.form.submit()">
						<option value="20" <?php selected( 20, $pagination['per_page'] ); ?>>20</option>
						<option value="50" <?php selected( 50, $pagination['per_page'] ); ?>>50</option>
					</select>
				</form>
				<a class="button <?php echo 1 >= $current ? 'disabled' : ''; ?>" href="<?php echo esc_url( $pagination['previous_url'] ); ?>" aria-disabled="<?php echo 1 >= $current ? 'true' : 'false'; ?>" <?php echo 1 >= $current ? 'tabindex="-1"' : ''; ?>>&lsaquo;</a>
				<span><?php echo esc_html( $pagination['page_label'] ); ?></span>
				<a class="button <?php echo $current >= $pages ? 'disabled' : ''; ?>" href="<?php echo esc_url( $pagination['next_url'] ); ?>" aria-disabled="<?php echo $current >= $pages ? 'true' : 'false'; ?>" <?php echo $current >= $pages ? 'tabindex="-1"' : ''; ?>>&rsaquo;</a>
			</div>
		</div>
		<?php
	}
}
