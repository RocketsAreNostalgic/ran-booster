<?php

declare(strict_types=1);

namespace Tests\Secrets;

use RAN\Secrets\SiteKeyStore;

/**
 * Test-only option seam shared by SecretsFile fixtures in one PHP process.
 */
final class InMemorySiteKeyStore extends SiteKeyStore {

	/** @var array<string, string> */
	private static array $keys = array();

	public function __construct( private string $identity ) {
	}

	public function load( bool $repairAutoload = true ): ?string {
		return self::$keys[ $this->identity ] ?? null;
	}

	public function loadOrCreate(): array {
		$key = $this->load();
		if ( null !== $key ) {
			return array(
				'key'     => $key,
				'created' => false,
			);
		}

		$key                           = random_bytes( 32 );
		self::$keys[ $this->identity ] = $key;

		return array(
			'key'     => $key,
			'created' => true,
		);
	}

	public function deleteExact( #[\SensitiveParameter] string $key ): bool {
		$stored = $this->load();
		if ( null === $stored || ! hash_equals( $stored, $key ) ) {
			return false;
		}

		unset( self::$keys[ $this->identity ] );

		return true;
	}

	public static function reset( ?string $identity = null ): void {
		if ( null === $identity ) {
			self::$keys = array();

			return;
		}

		unset( self::$keys[ $identity ] );
	}
}
