<?php

declare(strict_types=1);

namespace RAN\Tests\Support;

final class NoticePreferenceLockDatabase {
	public string $usermeta       = 'wp_usermeta';
	public string $last_error     = '';
	public ?\Closure $before_lock = null;
	public bool $locked           = false;
	public function prepare( string $query, string $name ): string {
		return $query . ':' . $name;
	}
	public function get_var( string $query ): string {
		if ( str_contains( $query, 'GET_LOCK' ) && null !== $this->before_lock ) {
			$callback          = $this->before_lock;
			$this->before_lock = null;
			$callback();
		}
		if ( str_contains( $query, 'GET_LOCK' ) ) {
			if ( $this->locked ) {
				return '0';
			}
			$this->locked = true;
		} else {
			$this->locked = false;
		}
		return '1';
	}
}
