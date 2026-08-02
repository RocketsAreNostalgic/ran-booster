<?php

declare(strict_types=1);

namespace RAN\Admin;

use LogicException;
use RAN\Admin\Component\AdminActionNormalizer;

/**
 * Protects Core repository rows while accepting bounded add-on enrichment.
 */
final class ProviderRepositoryRowsNormalizer {

	/**
	 * @param array<string, array<string, mixed>> $baseRows
	 * @param mixed                               $presented
	 * @return array<string, array<string, mixed>>
	 */
	public function normalize( array $baseRows, mixed $presented, string $providerCode ): array {
		if ( ! is_array( $presented ) ) {
			throw new LogicException( 'Provider repository rows must be a keyed array.' );
		}

		$normalizer = new AdminActionNormalizer();
		$rows       = array();
		foreach ( $baseRows as $key => $baseRow ) {
			if ( ! isset( $presented[ $key ] ) || ! is_array( $presented[ $key ] ) ) {
				throw new LogicException( 'Provider filters must preserve every Core repository row.' );
			}

			$row = $presented[ $key ];
			foreach ( array_keys( $row ) as $field ) {
				if ( ! array_key_exists( $field, $baseRow ) && ! in_array( $field, array( 'details', 'actions' ), true ) ) {
					throw new LogicException( 'Provider filters may enrich Core rows only with details and actions.' );
				}
			}
			foreach ( $baseRow as $field => $value ) {
				if ( in_array( $field, array( 'details', 'actions' ), true ) ) {
					continue;
				}
				if ( ! array_key_exists( $field, $row ) || $row[ $field ] !== $value ) {
					throw new LogicException( 'Provider filters must not rewrite Core repository fields.' );
				}
			}

			$baseDetails = is_array( $baseRow['details'] ?? null ) ? array_values( $baseRow['details'] ) : array();
			$details     = is_array( $row['details'] ?? null ) ? array_values( $row['details'] ) : array();
			if ( array_slice( $details, 0, count( $baseDetails ) ) !== $baseDetails ) {
				throw new LogicException( 'Provider filters may append but not replace Core details.' );
			}
			$this->assertDetails( $details );

			$baseActions = is_array( $baseRow['actions'] ?? null ) ? $baseRow['actions'] : array();
			$actions     = is_array( $row['actions'] ?? null ) ? $row['actions'] : array();
			foreach ( $baseActions as $actionKey => $baseAction ) {
				if ( ! isset( $actions[ $actionKey ] ) || ! is_array( $actions[ $actionKey ] ) ) {
					throw new LogicException( 'Provider filters must preserve every Core action.' );
				}
				if ( 'core:assisted-hooks' !== $actionKey ) {
					if ( $actions[ $actionKey ] !== $baseAction ) {
						throw new LogicException( 'Provider filters must not rewrite Core actions.' );
					}
					continue;
				}
				foreach ( $baseAction as $field => $value ) {
					if ( in_array( $field, array( 'url', 'disabled', 'described_by' ), true ) ) {
						continue;
					}
					if ( ! array_key_exists( $field, $actions[ $actionKey ] ) || $actions[ $actionKey ][ $field ] !== $value ) {
						throw new LogicException( 'Assisted Hooks may change only its reserved action state.' );
					}
				}
			}
			$normalizedRow            = $baseRow;
			$normalizedRow['details'] = $details;
			$normalizedRow['actions'] = $normalizer->normalize( $actions );
			$rows[ $key ]             = $normalizedRow;
		}

		foreach ( $presented as $key => $row ) {
			if ( isset( $baseRows[ $key ] ) ) {
				continue;
			}
			if ( ! is_string( $key )
				|| 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z0-9:-]{1,127}$/', $key )
				|| ! is_array( $row )
				|| true !== ( $row['historical'] ?? false )
				|| $providerCode !== ( $row['provider_code'] ?? null ) ) {
				throw new LogicException( 'Provider filters may append only namespaced historical rows.' );
			}
			$rowActions = is_array( $row['actions'] ?? null ) ? $row['actions'] : array();
			if ( isset( $rowActions['core:assisted-hooks'] ) ) {
				throw new LogicException( 'Historical rows must not claim Core actions.' );
			}
			$row['actions'] = $normalizer->normalize( $rowActions );
			$rows[ $key ]   = $this->normalizeHistoricalRow( $key, $row, $providerCode );
		}

		return $rows;
	}

	/** @param list<mixed> $details */
	private function assertDetails( array $details ): void {
		if ( count( $details ) > 20 ) {
			throw new LogicException( 'Repository details must be bounded.' );
		}

		foreach ( $details as $detail ) {
			if ( ! is_array( $detail ) ) {
				throw new LogicException( 'Repository details must be display maps.' );
			}
			$this->boundedString( $detail['label'] ?? null, 96, false );
			$this->boundedString( $detail['value'] ?? null, 255, true );
			$tone = $this->boundedString( $detail['tone'] ?? '', 16, true );
			if ( '' !== $tone && ! in_array( $tone, $this->tones(), true ) ) {
				throw new LogicException( 'Repository detail tones are invalid.' );
			}
			$this->boundedString( $detail['datetime'] ?? '', 64, true );
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalizeHistoricalRow( string $key, array $row, string $providerCode ): array {
		$repositoryId = $this->boundedString( $row['repository_id'] ?? null, 191, false );
		$details      = is_array( $row['details'] ?? null ) ? array_values( $row['details'] ) : array();
		$this->assertDetails( $details );

		return array(
			'key'                => $key,
			'provider_code'      => $providerCode,
			'provider_label'     => $this->boundedString( $row['provider_label'] ?? null, 96, false ),
			'repository_id'      => $repositoryId,
			'repository'         => $this->boundedString( $row['repository'] ?? null, 255, false ),
			'repository_url'     => $this->safeUrl( $row['repository_url'] ?? '' ),
			'historical'         => true,
			'types'              => $this->badges( $row['types'] ?? array() ),
			'package_message'    => $this->boundedString( $row['package_message'] ?? '', 255, true ),
			'package_references' => $this->strings( $row['package_references'] ?? array(), 20, 255 ),
			'policies'           => $this->badges( $row['policies'] ?? array() ),
			'statuses'           => $this->badges( $row['statuses'] ?? array(), true ),
			'status_links'       => $this->links( $row['status_links'] ?? array() ),
			'status_message'     => $this->boundedString( $row['status_message'] ?? '', 255, true ),
			'action_message'     => $this->boundedString( $row['action_message'] ?? '', 255, true ),
			'actions'            => $row['actions'],
			'details'            => $details,
		);
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function badges( mixed $badges, bool $allowRelationships = false ): array {
		if ( ! is_array( $badges ) || count( $badges ) > 20 ) {
			throw new LogicException( 'Repository badges must be bounded.' );
		}

		$normalized = array();
		foreach ( $badges as $badge ) {
			if ( ! is_array( $badge ) ) {
				throw new LogicException( 'Repository badges must be display maps.' );
			}
			$tone = $this->boundedString( $badge['tone'] ?? 'neutral', 16, false );
			if ( ! in_array( $tone, $this->tones(), true ) ) {
				throw new LogicException( 'Repository badge tones are invalid.' );
			}
			$item = array(
				'label' => $this->boundedString( $badge['label'] ?? null, 96, false ),
				'tone'  => $tone,
			);
			if ( $allowRelationships ) {
				$item['id']           = $this->relationship( $badge['id'] ?? '' );
				$item['described_by'] = $this->relationship( $badge['described_by'] ?? '', true );
			}
			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function links( mixed $links ): array {
		if ( ! is_array( $links ) || count( $links ) > 10 ) {
			throw new LogicException( 'Repository status links must be bounded.' );
		}

		$normalized = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				throw new LogicException( 'Repository status links must be display maps.' );
			}
			$normalized[] = array(
				'label'  => $this->boundedString( $link['label'] ?? null, 96, false ),
				'url'    => $this->safeUrl( $link['url'] ?? null, false ),
				'modal'  => $this->boundedString( $link['modal'] ?? '', 64, true ),
				'scope'  => $this->boundedString( $link['scope'] ?? '', 64, true ),
				'target' => $this->boundedString( $link['target'] ?? '', 255, true ),
			);
		}

		return $normalized;
	}

	/** @return list<string> */
	private function strings( mixed $values, int $maximumItems, int $maximumLength ): array {
		if ( ! is_array( $values ) || count( $values ) > $maximumItems ) {
			throw new LogicException( 'Repository string lists must be bounded.' );
		}

		return array_map(
			fn ( mixed $value ): string => $this->boundedString( $value, $maximumLength, false ),
			array_values( $values )
		);
	}

	private function safeUrl( mixed $value, bool $allowEmpty = true ): string {
		$url = $this->boundedString( $value, 2048, $allowEmpty );
		if ( '' === $url ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Validation must happen before rendering.
		$parts = parse_url( $url );
		if ( ! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] ) ) {
			throw new LogicException( 'Repository URLs must be safe and absolute.' );
		}

		return $url;
	}

	private function relationship( mixed $value, bool $multiple = false ): string {
		$relationship = $this->boundedString( $value, 255, true );
		$pattern      = $multiple
			? '/^[A-Za-z][A-Za-z0-9_-]*(?: [A-Za-z][A-Za-z0-9_-]*)*$/'
			: '/^[A-Za-z][A-Za-z0-9_-]*$/';
		if ( '' !== $relationship && 1 !== preg_match( $pattern, $relationship ) ) {
			throw new LogicException( 'Repository relationship identifiers are invalid.' );
		}

		return $relationship;
	}

	private function boundedString( mixed $value, int $maximum, bool $allowEmpty ): string {
		if ( ! is_string( $value )
			|| ( ! $allowEmpty && '' === trim( $value ) )
			|| strlen( $value ) > $maximum
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new LogicException( 'Repository display values must be bounded strings.' );
		}

		return $value;
	}

	/** @return list<string> */
	private function tones(): array {
		return array( 'neutral', 'ok', 'pending', 'warning', 'error' );
	}
}
