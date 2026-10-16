<?php

declare(strict_types=1);

namespace RAN\Portability;

/**
 * Advisory local observations; never package identity or acquisition authority.
 *
 * @phpstan-type PackageRequirementsReport array{type:string,identifier:string,basis:string,checks:array<string,string>,dependencies:array<string,string>,recommendation:string,activation_readiness:string}
 * @phpstan-type RequirementsReport array{packages:list<PackageRequirementsReport>,summary:array<string,array{minimum:?string,declared:int,not_declared:int,missing:int}>}
 */
final class BlueprintRequirements {

	/** @return array{requirements:?array<string,mixed>,issues:array<string,string>} */
	public function installed( BlueprintPackage $package ): array {
		$issues = array();
		try {
			$path = 'plugin' === $package->type
				? ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . $package->identifier : '' )
				: get_theme_root( $package->identifier ) . '/' . $package->identifier . '/style.css';
			if ( '' === $path || ! is_readable( $path ) ) {
				return array(
					'requirements' => null,
					'issues'       => array( 'headers' => 'unavailable' ),
				);
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- A failed native header read must remain unavailable, never become undeclared headers.
			set_error_handler(
				static function (): never {
					throw new \RuntimeException( 'Installed headers are unavailable.' );
				}
			);
			try {
				$headers = get_file_data(
					$path,
					array(
						'version'            => 'Version',
						'requires_php'       => 'Requires PHP',
						'requires_wordpress' => 'Requires at least',
						'requires_plugins'   => 'Requires Plugins',
						'parent_theme'       => 'Template',
					)
				);
			} finally {
				restore_error_handler();
			}
		} catch ( \Throwable ) {
			return array(
				'requirements' => null,
				'issues'       => array( 'headers' => 'unavailable' ),
			);
		}
		$observation = array(
			'basis'             => 'installed_headers',
			'observed_identity' => array(),
			'declarations'      => array(),
		);
		$fields      = array( 'version', 'requires_php', 'requires_wordpress', 'plugin' === $package->type ? 'requires_plugins' : 'parent_theme' );
		foreach ( $fields as $field ) {
			if ( ! isset( $headers[ $field ] ) || ! is_string( $headers[ $field ] ) ) {
				$issues[ $field ] = 'unavailable';
				continue;
			}
			$value = trim( $headers[ $field ] );
			if ( 'version' === $field && '' === $value ) {
				continue;
			}
			$value                    = 'requires_plugins' === $field
				? ( '' === $value ? array() : array_values( array_unique( array_map( 'trim', explode( ',', $value ) ) ) ) )
				: ( '' === $value ? null : $value );
			$part                     = 'version' === $field ? 'observed_identity' : 'declarations';
			$trial                    = $observation;
			$trial[ $part ][ $field ] = $value;
			try {
				$record                 = $package->to_array();
				$record['requirements'] = $trial;
				BlueprintPackage::from_array( $record );
				$observation = $trial;
			} catch ( \InvalidArgumentException ) {
				$issues[ $field ] = 'malformed';
			}
		}
		return array(
			'requirements' => $observation,
			'issues'       => $issues,
		);
	}

	/**
	 * @param list<array{type:string,identifier:string}>|null $selected
	 * @return RequirementsReport
	 */
	public function assess( PackageBlueprint $blueprint, ?array $selected = null ): array {
		$selection = null === $selected ? null : array_fill_keys( array_map( static fn( array $item ): string => $item['type'] . ':' . $item['identifier'], $selected ), true );
		$inventory = $this->inventory();
		$summary   = array_fill_keys(
			array( 'requires_php', 'requires_wordpress' ),
			array(
				'minimum'      => null,
				'declared'     => 0,
				'not_declared' => 0,
				'missing'      => 0,
			)
		);
		$reports   = array();
		$wordpress = $GLOBALS['wp_version'] ?? null;
		foreach ( $blueprint->packages as $package ) {
			if ( null !== $selection && ! isset( $selection[ $package->type . ':' . $package->identifier ] ) ) {
				continue;
			}
			$requirements = $package->requirements;
			$declarations = is_array( $requirements['declarations'] ?? null ) ? $requirements['declarations'] : array();
			$checks       = array();
			foreach ( array(
				'requires_php'       => PHP_VERSION,
				'requires_wordpress' => $wordpress,
			) as $field => $current ) {
				$minimum = $declarations[ $field ] ?? null;
				if ( ! array_key_exists( $field, $declarations ) ) {
					++$summary[ $field ]['missing'];
					$checks[ $field ] = 'not_supplied';
				} elseif ( null === $minimum ) {
					++$summary[ $field ]['not_declared'];
					$checks[ $field ] = 'not_declared';
				} elseif ( is_string( $minimum ) ) {
					++$summary[ $field ]['declared'];
					if ( null === $summary[ $field ]['minimum'] || version_compare( $minimum, $summary[ $field ]['minimum'], '>' ) ) {
						$summary[ $field ]['minimum'] = $minimum;
					}
					$checks[ $field ] = is_string( $current ) && '' !== $current ? ( version_compare( $current, $minimum, '>=' ) ? 'passed' : 'failed' ) : 'not_checked';
				}
			}
			$dependencies = array();
			$field        = 'plugin' === $package->type ? 'requires_plugins' : 'parent_theme';
			$declared     = array_key_exists( $field, $declarations );
			$required     = $declarations[ $field ] ?? array();
			$required     = is_string( $required ) ? array( $required ) : $required;
			foreach ( is_array( $required ) ? $required : array() as $slug ) {
				if ( is_string( $slug ) ) {
					$dependencies[ $slug ] = $this->dependency( $package->type, $slug, $inventory, $blueprint, $selection );
				}
			}
			$ready     = $declared && array() === array_diff( array_values( $dependencies ), array( 'active', 'present' ) );
			$basis     = is_string( $requirements['basis'] ?? null ) ? $requirements['basis'] : 'not_supplied';
			$reports[] = array(
				'type'                 => $package->type,
				'identifier'           => $package->identifier,
				'basis'                => $basis,
				'checks'               => $checks,
				'dependencies'         => $dependencies,
				'recommendation'       => in_array( 'failed', $checks, true ) ? ( 'selected_package' === $basis ? 'blocked_recommendation' : 'warning' ) : 'not_verified',
				'activation_readiness' => ! $declared ? 'not_checked' : ( $ready ? 'declared_dependencies_satisfied' : 'declared_dependencies_not_ready' ),
			);
		}
		return array(
			'packages' => $reports,
			'summary'  => $summary,
		);
	}

	/** @return array{plugin:?array<string,string>,theme:?array<string,string>} */
	private function inventory(): array {
		$result = array(
			'plugin' => null,
			'theme'  => null,
		);
		try {
			$result['plugin'] = array();
			foreach ( array_keys( get_plugins() ) as $file ) {
				$result['plugin'][ $file ] = is_plugin_active( $file ) ? 'active' : 'inactive';
			}
		} catch ( \Throwable ) {
			$result['plugin'] = null;
		}
		try {
			$result['theme'] = array_fill_keys( array_keys( wp_get_themes() ), 'present' );
		} catch ( \Throwable ) {
			$result['theme'] = null;
		}
		return $result;
	}

	/**
	 * @param array{plugin:?array<string,string>,theme:?array<string,string>} $inventory
	 * @param array<string,true>|null $selected
	 */
	private function dependency( string $type, string $slug, array $inventory, PackageBlueprint $blueprint, ?array $selected ): string {
		if ( null === $inventory[ $type ] ) {
			return 'unresolved';
		}
		$matches = array();
		foreach ( $inventory[ $type ] as $identifier => $state ) {
			$local_slug = 'plugin' === $type ? dirname( $identifier ) : $identifier;
			if ( $slug === $local_slug ) {
				$matches[] = $state;
			} elseif ( 'plugin' === $type && '.' === $local_slug && pathinfo( $identifier, PATHINFO_FILENAME ) === $slug ) {
				$matches[] = 'unresolved';
			}
		}
		if ( count( $matches ) > 1 ) {
			return 'unresolved';
		}
		if ( 1 === count( $matches ) ) {
			return $matches[0];
		}
		foreach ( $blueprint->packages as $package ) {
			$local_slug = 'plugin' === $type ? dirname( $package->identifier ) : $package->identifier;
			if ( $type === $package->type && $slug === $local_slug ) {
				$matches[] = null === $selected || isset( $selected[ $type . ':' . $package->identifier ] ) ? 'selected' : 'omitted';
			} elseif ( 'plugin' === $type && $type === $package->type && '.' === $local_slug && pathinfo( $package->identifier, PATHINFO_FILENAME ) === $slug ) {
				$matches[] = 'unresolved';
			}
		}
		return count( $matches ) > 1 ? 'unresolved' : ( $matches[0] ?? 'missing' );
	}
}
