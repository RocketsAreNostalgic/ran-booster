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
 *
 * Each record is its own row group: the summary row and, when the caller
 * reports one, a full-width result row. Striping and dividers follow the
 * groups, so a hidden result row never shifts them.
 */
final class ProviderManagementTableRenderer {

	public const ACCESS  = 'access';
	public const WEBHOOK = 'webhook';

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param Closure(array<string, mixed>, string): void $render_cell
	 * @param array<string,string> $sort_urls
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
	 * @param (Closure(array<string, mixed>): ?bool)|null $result_visibility
	 *        Whether a record has a result row: null prints none, false
	 *        prints it hidden and true prints it visible. Its content comes
	 *        from $render_cell with the 'result' key.
	 */
	public function render(
		string $type,
		array $rows,
		string $empty_message,
		Closure $render_cell,
		array $sort_urls,
		array $pagination,
		?Closure $result_visibility = null
	): void {
		$columns = $this->columns( $type );
		?>
		<div class="ran-booster-data-table-wrap">
		<table class="ran-booster-data-table ran-booster-provider-management-table ran-booster-provider-management-table--<?php echo esc_attr( $type ); ?>">
			<caption class="screen-reader-text"><?php echo esc_html( self::ACCESS === $type ? __( 'Credentials', 'ran-booster' ) : __( 'Webhook secrets', 'ran-booster' ) ); ?></caption>
			<thead>
				<tr>
					<?php foreach ( $columns as $column ) { ?>
						<th scope="col">
							<?php if ( null === $column['sort'] ) { ?>
								<span class="screen-reader-text"><?php echo esc_html( $column['label'] ); ?></span>
							<?php } else { ?>
								<a href="<?php echo esc_url( $sort_urls[ $column['sort'] ] ?? '' ); ?>"><?php echo esc_html( $column['label'] ); ?></a>
							<?php } ?>
						</th>
					<?php } ?>
				</tr>
			</thead>
			<?php if ( array() === $rows ) { ?>
				<tbody>
					<tr><td colspan="<?php echo esc_attr( (string) count( $columns ) ); ?>"><?php echo esc_html( $empty_message ); ?></td></tr>
				</tbody>
			<?php } ?>
			<?php foreach ( $rows as $row ) { ?>
				<?php $result_visible = null === $result_visibility ? null : $result_visibility( $row ); ?>
				<tbody>
					<tr>
						<?php foreach ( $columns as $column ) { ?>
							<?php if ( $column['actions'] ) { ?>
								<td class="ran-booster-provider-management-table__actions" data-label="<?php echo esc_attr( $column['label'] ); ?>"><div class="ran-booster-actions"><?php $render_cell( $row, $column['key'] ); ?></div></td>
							<?php } else { ?>
								<td data-label="<?php echo esc_attr( $column['label'] ); ?>"><?php $render_cell( $row, $column['key'] ); ?></td>
							<?php } ?>
						<?php } ?>
					</tr>
					<?php if ( null !== $result_visible ) { ?>
						<tr class="ran-booster-provider-management-table__result"<?php echo $result_visible ? '' : ' hidden'; ?>><td colspan="<?php echo esc_attr( (string) count( $columns ) ); ?>" class="ran-booster-provider-management-table__result-cell"><?php $render_cell( $row, 'result' ); ?></td></tr>
					<?php } ?>
				</tbody>
			<?php } ?>
		</table>
		</div>
		<?php
		$this->render_pagination( $type, $pagination );
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
					'label'   => __( 'Type', 'ran-booster' ),
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
	private function render_pagination( string $type, array $pagination ): void {
		$page_size_id = self::ACCESS === $type
			? 'ran-booster-credential-page-size'
			: 'ran-booster-secret-page-size';
		$current      = max( 1, $pagination['current'] );
		$pages        = max( 1, $pagination['pages'] );
		?>
		<div class="ran-booster-provider-table-navigation">
			<span><?php echo esc_html( $pagination['item_count_label'] ); ?></span>
			<div>
				<label for="<?php echo esc_attr( $page_size_id ); ?>"><?php esc_html_e( 'Rows', 'ran-booster' ); ?></label>
				<form method="get" action="<?php echo esc_url( $pagination['action_url'] ); ?>" class="ran-booster-inline-form">
					<?php foreach ( $pagination['hidden_fields'] as $name => $value ) { ?>
						<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
					<?php } ?>
					<select id="<?php echo esc_attr( $page_size_id ); ?>" name="per_page" data-ran-booster-auto-submit>
						<option value="20" <?php selected( 20, $pagination['per_page'] ); ?>>20</option>
						<option value="50" <?php selected( 50, $pagination['per_page'] ); ?>>50</option>
					</select>
					<button type="submit" class="<?php echo esc_attr( ButtonClasses::secondary( true ) . ' screen-reader-text' ); ?>"><?php esc_html_e( 'Apply', 'ran-booster' ); ?></button>
				</form>
				<a class="<?php echo esc_attr( ButtonClasses::secondary( true, true ) . ( 1 >= $current ? ' disabled' : '' ) ); ?>" href="<?php echo esc_url( $pagination['previous_url'] ); ?>" aria-disabled="<?php echo 1 >= $current ? 'true' : 'false'; ?>" <?php echo 1 >= $current ? 'tabindex="-1"' : ''; ?>>&lsaquo;</a>
				<span><?php echo esc_html( $pagination['page_label'] ); ?></span>
				<a class="<?php echo esc_attr( ButtonClasses::secondary( true, true ) . ( $current >= $pages ? ' disabled' : '' ) ); ?>" href="<?php echo esc_url( $pagination['next_url'] ); ?>" aria-disabled="<?php echo $current >= $pages ? 'true' : 'false'; ?>" <?php echo $current >= $pages ? 'tabindex="-1"' : ''; ?>>&rsaquo;</a>
			</div>
		</div>
		<?php
	}
}
