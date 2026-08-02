<?php

declare(strict_types=1);

namespace RAN\Admin;

use RAN\Admin\Component\AdminActionNormalizer;
use RAN\Logging\BoosterLogger;
use Throwable;

/**
 * Applies provider repository composition hooks inside Core-owned boundaries.
 */
final class ProviderRepositoryCompositionRenderer {
	/**
	 * @return array{
	 *   action_key: string,
	 *   action_label: string,
	 *   inactive_heading: string,
	 *   inactive_description: string,
	 *   active_heading: string,
	 *   active_description: string
	 * }|null
	 */
	public function assistancePresentation( mixed $presentation ): ?array {
		if ( ! is_array( $presentation ) ) {
			return null;
		}

		$fields = array( 'action_key', 'action_label', 'inactive_heading', 'inactive_description', 'active_heading', 'active_description' );
		foreach ( $fields as $field ) {
			if ( ! isset( $presentation[ $field ] )
				|| ! is_string( $presentation[ $field ] )
				|| '' === trim( $presentation[ $field ] ) ) {
				return null;
			}
		}
		if ( 'core:assisted-hooks' !== $presentation['action_key'] ) {
			return null;
		}

		return array_intersect_key( $presentation, array_flip( $fields ) );
	}

	/**
	 * @param array<string, string> $presentation
	 * @param list<string>          $describedBy
	 * @return array<string, array<string, mixed>>
	 */
	public function dormantAssistanceAction(
		array $presentation,
		string $repository,
		array $describedBy
	): array {
		$actionKey = $presentation['action_key'];

		return array(
			$actionKey => array(
				'key'           => $actionKey,
				'label'         => $presentation['action_label'],
				'type'          => 'link',
				'url'           => '',
				'hidden'        => array(),
				'disabled'      => true,
				'external'      => false,
				'described_by'  => implode( ' ', array_filter( $describedBy ) ),
				'screen_reader' => $repository,
			),
		);
	}

	public function assistanceActive( string $providerCode ): bool {
		try {
			return true === \apply_filters(
				'ran_booster_admin_provider_repository_assistance_active',
				false,
				$providerCode
			);
		} catch ( Throwable $failure ) {
			BoosterLogger::logException(
				'provider repository assistance state unavailable',
				$failure,
				array(
					'source'   => 'admin',
					'step'     => 'provider_repository_assistance_state',
					'provider' => $providerCode,
				)
			);
		}

		return false;
	}

	/** @param array<string, string> $presentation */
	public function renderAssistanceNote(
		array $presentation,
		string $providerCode,
		string $descriptionId
	): void {
		$active = $this->assistanceActive( $providerCode );
		?>
		<div id="<?php echo \esc_attr( $descriptionId ); ?>" class="ran-booster-provider-assistance-note">
			<strong><?php echo \esc_html( $active ? $presentation['active_heading'] : $presentation['inactive_heading'] ); ?></strong>
			<?php echo \esc_html( $active ? $presentation['active_description'] : $presentation['inactive_description'] ); ?>
		</div>
		<?php
	}

	/**
	 * @param array<string, array<string, mixed>> $baseRows
	 * @param array<string, array<string, mixed>> $projections
	 * @return array<string, array<string, mixed>>
	 */
	public function rows(
		array $baseRows,
		string $providerCode,
		array $projections,
		string $returnUrl
	): array {
		try {
			$rows = ( new ProviderRepositoryRowsNormalizer() )->normalize(
				$baseRows,
				\apply_filters(
					'ran_booster_admin_provider_repository_rows',
					$baseRows,
					$providerCode,
					$projections,
					$returnUrl
				),
				$providerCode
			);

			return $this->lockUnprojectedAssistanceActions( $rows, $baseRows, $projections );
		} catch ( Throwable $failure ) {
			BoosterLogger::logException(
				'provider repository filter unavailable',
				$failure,
				array(
					'source'   => 'admin',
					'step'     => 'provider_repository_filter',
					'provider' => $providerCode,
				)
			);
		}

		$rows       = array();
		$normalizer = new AdminActionNormalizer();
		foreach ( $baseRows as $key => $row ) {
			$row['actions'] = $normalizer->normalize( $row['actions'] ?? array() );
			$rows[ $key ]   = $row;
		}

		return $rows;
	}

	/**
	 * Add-ons may activate the reserved action only for rows Core explicitly
	 * projected as webhook-eligible candidates.
	 *
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $baseRows
	 * @param array<string, array<string, mixed>> $projections
	 * @return array<string, array<string, mixed>>
	 */
	private function lockUnprojectedAssistanceActions( array $rows, array $baseRows, array $projections ): array {
		$normalizer = new AdminActionNormalizer();
		foreach ( $baseRows as $rowKey => $baseRow ) {
			if ( isset( $projections[ $rowKey ] )
				|| ! isset( $rows[ $rowKey ]['actions']['core:assisted-hooks'] )
				|| ! isset( $baseRow['actions']['core:assisted-hooks'] ) ) {
				continue;
			}

			$action = $normalizer->normalize(
				array(
					'core:assisted-hooks' => $baseRow['actions']['core:assisted-hooks'],
				)
			);
			$rows[ $rowKey ]['actions']['core:assisted-hooks'] = $action['core:assisted-hooks'];
		}

		return $rows;
	}

	public function renderPanel(
		string $providerCode,
		string $selectedRepositoryId,
		string $returnUrl
	): void {
		$bufferLevel = ob_get_level();
		ob_start();
		try {
			\do_action(
				'ran_booster_admin_provider_repository_panel',
				$providerCode,
				$selectedRepositoryId,
				$returnUrl
			);
			echo (string) ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted add-on output is isolated to this bounded section.
		} catch ( Throwable $failure ) {
			while ( ob_get_level() > $bufferLevel ) {
				ob_end_clean();
			}
			BoosterLogger::logException(
				'provider repository panel unavailable',
				$failure,
				array(
					'source'   => 'admin',
					'step'     => 'provider_repository_panel',
					'provider' => $providerCode,
				)
			);
			?>
			<div class="notice notice-error inline"><p><?php \esc_html_e( 'The repository add-on panel is temporarily unavailable. Check plugin compatibility and the error log.', 'ran-booster' ); ?></p></div>
			<?php
		}
	}
}
