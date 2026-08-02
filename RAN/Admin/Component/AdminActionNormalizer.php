<?php

declare(strict_types=1);

namespace RAN\Admin\Component;

use LogicException;

/**
 * Validates structured link and POST actions before Core renders them.
 */
final class AdminActionNormalizer {

	/**
	 * @param mixed $actions
	 * @return array<string, array<string, mixed>>
	 */
	public function normalize( mixed $actions ): array {
		if ( ! is_array( $actions ) ) {
			throw new LogicException( 'Administration actions must be a keyed array.' );
		}

		$normalized = array();
		foreach ( $actions as $key => $action ) {
			if ( ! is_string( $key )
				|| 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$/', $key )
				|| ! is_array( $action ) ) {
				throw new LogicException( 'Administration actions require bounded namespaced keys.' );
			}
			if ( isset( $action['key'] ) && $key !== $action['key'] ) {
				throw new LogicException( 'Administration action keys must match their map identity.' );
			}
			if ( ( isset( $action['disabled'] ) && ! is_bool( $action['disabled'] ) )
				|| ( isset( $action['external'] ) && ! is_bool( $action['external'] ) ) ) {
				throw new LogicException( 'Administration action flags must be booleans.' );
			}

			$label        = $this->boundedString( $action['label'] ?? null, 96, false );
			$type         = $action['type'] ?? null;
			$url          = $this->boundedString( $action['url'] ?? '', 2048, true );
			$describedBy  = $this->boundedString( $action['described_by'] ?? '', 255, true );
			$screenReader = $this->boundedString( $action['screen_reader'] ?? '', 255, true );
			$busyLabel    = $this->boundedString( $action['busy_label'] ?? '', 96, true );
			$confirm      = $this->boundedString( $action['confirm_message'] ?? '', 255, true );
			$disabled     = true === ( $action['disabled'] ?? false );
			$external     = true === ( $action['external'] ?? false );

			if ( ! in_array( $type, array( 'link', 'post' ), true ) ) {
				throw new LogicException( 'Administration action types must be link or post.' );
			}
			if ( '' !== $describedBy && 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]*(?: [A-Za-z][A-Za-z0-9_-]*)*$/', $describedBy ) ) {
				throw new LogicException( 'Administration action relationships are invalid.' );
			}

			$hidden = array();
			if ( 'post' === $type ) {
				if ( $external || $url !== admin_url( 'admin-post.php' ) ) {
					throw new LogicException( 'POST actions must target the canonical WordPress handler.' );
				}
				$hidden = $this->hidden( $action['hidden'] ?? null );
			} elseif ( isset( $action['hidden'] ) && array() !== $action['hidden'] ) {
				throw new LogicException( 'Link actions must not contain hidden fields.' );
			}
			if ( 'link' === $type && ! $disabled ) {
				$this->assertLinkUrl( $url );
			}

			$normalized[ $key ] = array(
				'key'           => $key,
				'label'         => $label,
				'type'          => $type,
				'url'           => $url,
				'hidden'        => $hidden,
				'disabled'      => $disabled,
				'external'      => $external,
				'described_by'  => $describedBy,
				'screen_reader' => $screenReader,
				'busy_label'    => $busyLabel,
				'confirm'       => $confirm,
			);
		}

		return $normalized;
	}

	private function assertLinkUrl( string $url ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Validation must happen before rendering.
		$parts = parse_url( $url );
		if ( ! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] ) ) {
			throw new LogicException( 'Enabled link actions require a safe absolute URL.' );
		}
	}

	/**
	 * @return array<string, string>
	 */
	private function hidden( mixed $fields ): array {
		if ( ! is_array( $fields ) || count( $fields ) > 20 ) {
			throw new LogicException( 'POST actions require bounded hidden fields.' );
		}

		$normalized = array();
		foreach ( $fields as $name => $value ) {
			if ( ! is_string( $name )
				|| 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name )
				|| ! is_scalar( $value ) ) {
				throw new LogicException( 'POST hidden fields must be named scalar values.' );
			}
			$normalized[ $name ] = $this->boundedString( (string) $value, 512, true );
		}

		if ( ! isset( $normalized['action'], $normalized['_wpnonce'] )
			|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,95}$/', $normalized['action'] )
			|| '' === $normalized['_wpnonce'] ) {
			throw new LogicException( 'POST actions require a bounded action name and nonce.' );
		}

		return $normalized;
	}

	private function boundedString( mixed $value, int $maximum, bool $allowEmpty ): string {
		if ( ! is_string( $value )
			|| ( ! $allowEmpty && '' === trim( $value ) )
			|| strlen( $value ) > $maximum
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new LogicException( 'Administration actions contain an invalid display value.' );
		}

		return $value;
	}
}
