<?php

declare(strict_types=1);

namespace RAN; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Core owns the established three-character RAN namespace; WPCS requires four characters.

use InvalidArgumentException;
use RAN\RepositoryProvider\ProviderCode;

/**
 * One immutable, canonical identity for a managed package lifetime.
 *
 * @internal Core-owned lifetime contract, not a provider extension API.
 */
final class AdoptionIdentity {
	public const MAX_LENGTH = 643;

	public static function create( string $provider, string $repository_id ): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 15 ) | 64 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 63 ) | 128 );
		$hex      = bin2hex( $bytes );
		$uuid     = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
		$token    = $provider . ':' . rawurlencode( $repository_id ) . ':' . $uuid;
		self::parse( $token );
		return $token;
	}

	/** @return array{provider:string,repository_id:string,uuid:string} */
	public static function parse( string $token ): array {
		$parts = explode( ':', $token );
		if ( strlen( $token ) > self::MAX_LENGTH || 3 !== count( $parts ) ) {
			throw new InvalidArgumentException( 'The adoption identity is invalid.' );
		}
		[$provider, $encoded, $uuid] = $parts;
		ProviderCode::parse( $provider );
		$id = rawurldecode( $encoded );
		if ( '' === $id || strlen( $id ) > 191 || preg_match( '/[\x00-\x1F\x7F]/', $id )
			|| rawurlencode( $id ) !== $encoded
			|| 1 !== preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $uuid ) ) {
			throw new InvalidArgumentException( 'The adoption identity is invalid.' );
		}
		return array(
			'provider'      => $provider,
			'repository_id' => $id,
			'uuid'          => $uuid,
		);
	}

	public static function matches( string $token, string $provider, string $repository_id ): bool {
		try {
			$identity = self::parse( $token );
			return $identity['provider'] === $provider && $identity['repository_id'] === $repository_id;
		} catch ( InvalidArgumentException ) {
			return false;
		}
	}

	public static function of( Package $package ): ?string {
		return $package instanceof AbstractPackage ? $package->get_adoption_token() : null;
	}
}
