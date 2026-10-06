<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

use RAN\Secrets\SiteKeyStore;

/**
 * Test-only option seam shared by SecretsFile fixtures in one PHP process.
 */
final class InMemorySiteKeyStore extends SiteKeyStore {

	/** @var array<string, string> */
	private static array $keys = array();

	public function __construct( private string $identity ) {
	}

	/**
	 * Read shared keys that other fixture instances may change between calls.
	 *
	 * @phpstan-impure
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass -- The fixture implementation of load retains the production method contract; these inputs do not affect this controlled result.
	public function load( bool $repair_autoload = true ): ?string {
		return self::$keys[ $this->identity ] ?? null;
	}

	public function load_or_create(): array {
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

	public function delete_exact( #[\SensitiveParameter] string $key ): bool {
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
