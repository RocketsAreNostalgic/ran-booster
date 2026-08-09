<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Test fixtures deliberately exercise native filesystem semantics.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\TestCase;
use RAN\Secrets\PrivateLocationCandidateResolver;

final class PrivateLocationCandidateResolverTest extends TestCase {

	private string $root;
	private string $temporaryBoundary;

	protected function setUp(): void {
		$suffix                  = bin2hex( random_bytes( 6 ) );
		$this->root              = sys_get_temp_dir() . '/ran-booster-location-' . $suffix;
		$this->temporaryBoundary = sys_get_temp_dir() . '/ran-booster-temporary-boundary-' . $suffix;
		mkdir( $this->root . '/account/site/public/wp-content/plugins/ran-booster', 0700, true );
		mkdir( $this->root . '/account/site/.git', 0700 );
		mkdir( $this->temporaryBoundary, 0700 );
		$this->root = (string) realpath( $this->root );
	}

	protected function tearDown(): void {
		$this->remove( $this->root );
		$this->remove( $this->temporaryBoundary );
	}

	public function testSuggestsStableSiteSpecificPathOutsideRepositoryAndWebRoots(): void {
		$resolver  = new PrivateLocationCandidateResolver( $this->temporaryBoundary );
		$candidate = $resolver->resolve(
			$this->root . '/account/site/public',
			$this->root . '/account/site/public/wp-content',
			$this->root . '/account/site/public/wp-content/plugins/ran-booster',
			$this->root . '/account/site/public'
		);

		self::assertMatchesRegularExpression(
			'#^' . preg_quote( $this->root . '/account', '#' ) . '/\.ran-booster/[a-f0-9]{16}/secrets\.json$#D',
			(string) $candidate
		);
		self::assertSame(
			$candidate,
			$resolver->resolve(
				$this->root . '/account/site/public',
				$this->root . '/account/site/public/wp-content',
				$this->root . '/account/site/public/wp-content/plugins/ran-booster',
				$this->root . '/account/site/public'
			)
		);
		self::assertTrue(
			$resolver->validateConfigured(
				(string) $candidate,
				$this->root . '/account/site/public',
				$this->root . '/account/site/public/wp-content',
				$this->root . '/account/site/public/wp-content/plugins/ran-booster',
				$this->root . '/account/site/public'
			)
		);
		self::assertDirectoryDoesNotExist( $this->root . '/account/.ran-booster' );
	}

	public function testNeverSuggestsAPathThatFailsTheConfiguredAncestorPolicy(): void {
		self::assertTrue( chmod( $this->root . '/account', 0770 ) );
		$resolver  = new PrivateLocationCandidateResolver( $this->temporaryBoundary );
		$discarded = array();

		self::assertNull(
			$resolver->resolve(
				$this->root . '/account/site/public',
				$this->root . '/account/site/public/wp-content',
				$this->root . '/account/site/public/wp-content/plugins/ran-booster',
				$this->root . '/account/site/public',
				$discarded
			)
		);
		self::assertSame(
			array(
				array(
					'directory' => $this->root . '/account/.ran-booster/' . $this->fingerprint(),
					'code'      => 'broad_private_path_permissions',
					'reason'    => 'A private path component is writable by its group or by other users.',
					'component' => $this->root . '/account',
				),
			),
			$discarded
		);
	}

	public function testRejectsAGroupWritableAncestorOwnedByPhpEvenWhenOwnerWriteIsDisabled(): void {
		self::assertTrue( chmod( $this->root, 0570 ) );
		$resolver  = new PrivateLocationCandidateResolver( $this->temporaryBoundary );
		$discarded = array();

		try {
			self::assertNull(
				$resolver->resolve(
					$this->root . '/account/site/public',
					$this->root . '/account/site/public/wp-content',
					$this->root . '/account/site/public/wp-content/plugins/ran-booster',
					$this->root . '/account/site/public',
					$discarded
				)
			);
			self::assertSame( 'php_accessible_group_writable_ancestor', $discarded[0]['code'] ?? null );
			self::assertSame( $this->root, $discarded[0]['component'] ?? null );
		} finally {
			chmod( $this->root, 0700 );
		}
	}

	public function testRejectsAWorldWritableHostAncestor(): void {
		self::assertTrue( chmod( $this->root, 0777 ) );
		$resolver  = new PrivateLocationCandidateResolver( $this->temporaryBoundary );
		$discarded = array();

		try {
			self::assertNull(
				$resolver->resolve(
					$this->root . '/account/site/public',
					$this->root . '/account/site/public/wp-content',
					$this->root . '/account/site/public/wp-content/plugins/ran-booster',
					$this->root . '/account/site/public',
					$discarded
				)
			);
			self::assertSame( 'world_writable_host_ancestor', $discarded[0]['code'] ?? null );
			self::assertSame( $this->root, $discarded[0]['component'] ?? null );
		} finally {
			chmod( $this->root, 0700 );
		}
	}

	public function testRejectsUnrelatedOrSymlinkedBoundaries(): void {
		mkdir( $this->root . '/other', 0700 );
		$resolver = new PrivateLocationCandidateResolver( $this->temporaryBoundary );
		self::assertNull(
			$resolver->resolve(
				$this->root . '/account/site/public',
				$this->root . '/other',
				$this->root . '/account/site/public/wp-content/plugins/ran-booster'
			)
		);

		symlink( $this->root . '/account/site/public', $this->root . '/public-link' );
		self::assertNull(
			$resolver->resolve(
				$this->root . '/public-link',
				$this->root . '/account/site/public/wp-content',
				$this->root . '/account/site/public/wp-content/plugins/ran-booster'
			)
		);
	}

	public function testConfiguredPathMustRemainOutsideUnsafeRootsAndHaveNoSymlinkedComponents(): void {
		$resolver  = new PrivateLocationCandidateResolver( $this->temporaryBoundary );
		$wordpress = $this->root . '/account/site/public';
		$content   = $wordpress . '/wp-content';
		$plugin    = $content . '/plugins/ran-booster';
		$private   = $this->root . '/account/private';
		self::assertTrue( mkdir( $private, 0700 ) );

		self::assertTrue(
			$resolver->validateConfigured(
				$private . '/secrets.json',
				$wordpress,
				$content,
				$plugin,
				$wordpress
			)
		);
		self::assertFalse(
			$resolver->validateConfigured(
				$content . '/secrets.json',
				$wordpress,
				$content,
				$plugin,
				$wordpress
			)
		);
		self::assertTrue( chmod( $private, 0770 ) );
		self::assertFalse(
			$resolver->validateConfigured(
				$private . '/secrets.json',
				$wordpress,
				$content,
				$plugin,
				$wordpress
			)
		);
		self::assertTrue( chmod( $private, 0700 ) );

		self::assertTrue( symlink( $private, $this->root . '/account/private-link' ) );
		self::assertFalse(
			$resolver->validateConfigured(
				$this->root . '/account/private-link/secrets.json',
				$wordpress,
				$content,
				$plugin,
				$wordpress
			)
		);
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$entries = scandir( $path );
		foreach ( false === $entries ? array() : $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove( $path . '/' . $entry );
			}
		}
		rmdir( $path );
	}

	private function fingerprint(): string {
		return substr(
			hash(
				'sha256',
				implode(
					"\0",
					array(
						$this->root . '/account/site/public',
						$this->root . '/account/site/public/wp-content',
						$this->root . '/account/site/public/wp-content/plugins/ran-booster',
					)
				)
			),
			0,
			16
		);
	}
}
