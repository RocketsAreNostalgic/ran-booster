<?php

declare(strict_types=1);

namespace RAN\Tests\Uninstall;

final class UninstallDatabase {

	public string $prefix      = 'wp_';
	public string $base_prefix = 'wp_';
	public string $options     = 'wp_options';
	public string $usermeta    = 'wp_usermeta';

	/** @var list<string> */
	public array $tables = array();

	/** @var array<string, list<int>> */
	public array $user_meta = array();

	public ?string $failure_contains = null;

	public function prepare( string $query, mixed ...$arguments ): string {
		foreach ( $arguments as $argument ) {
			$placeholder = substr( $query, (int) strpos( $query, '%' ), 2 );
			$replacement = '%i' === $placeholder
				? '`' . str_replace( '`', '``', (string) $argument ) . '`'
				: "'" . addslashes( (string) $argument ) . "'";
			$query       = (string) preg_replace( '/%[is]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function query( string $query ): int|false {
		if ( null !== $this->failure_contains && str_contains( $query, $this->failure_contains ) ) {
			return false;
		}
		if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`$/', $query, $matches ) ) {
			$this->tables = array_values( array_diff( $this->tables, array( $matches[1] ) ) );
			return 1;
		}
		if ( 1 === preg_match( "/^DELETE FROM `wp_usermeta` WHERE meta_key = '([^']+)'$/", $query, $matches ) ) {
			unset( $this->user_meta[ stripslashes( $matches[1] ) ] );
			return 1;
		}

		return 0;
	}
}
