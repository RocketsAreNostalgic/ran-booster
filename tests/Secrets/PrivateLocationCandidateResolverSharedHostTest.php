<?php

declare(strict_types=1);

namespace Tests\Secrets;

// Test-only namespaced functions model metadata that cannot be created without root.
require_once __DIR__ . '/fixtures/shared-host-functions.php';

// Test fixtures deliberately exercise native filesystem semantics.
// phpcs:disable WordPress.WP.AlternativeFunctions

use PHPUnit\Framework\TestCase;
use RAN\Secrets\PrivateLocationCandidateResolver;

final class PrivateLocationCandidateResolverSharedHostTest extends TestCase {

	private string $root;
	private string $temporaryBoundary;

	protected function setUp(): void {
		$suffix                  = bin2hex( random_bytes( 6 ) );
		$this->root              = sys_get_temp_dir() . '/ran-booster-shared-host-' . $suffix;
		$this->temporaryBoundary = sys_get_temp_dir() . '/ran-booster-shared-host-temp-' . $suffix;
		self::assertTrue( mkdir( $this->root . '/account/site/public/wp-content/plugins/ran-booster', 0700, true ) );
		self::assertTrue( mkdir( $this->root . '/account/site/.git', 0700 ) );
		self::assertTrue( mkdir( $this->root . '/account/private/ran-booster', 0700, true ) );
		self::assertTrue( mkdir( $this->temporaryBoundary, 0700 ) );
		$this->root = (string) realpath( $this->root );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['ran_booster_shared_host_stat'] );
		unset( $GLOBALS['ran_booster_shared_host_identity'] );
		chmod( $this->root, 0700 );
		$this->remove( $this->root );
		$this->remove( $this->temporaryBoundary );
	}

	public function testAcceptsAHostManagedGroupWritableAncestorOutsideThePhpIdentity(): void {
		self::assertTrue( chmod( $this->root, 0770 ) );
		$groups = posix_getgroups();
		self::assertIsArray( $groups );
		$GLOBALS['ran_booster_shared_host_stat'] = array(
			'path' => $this->root,
			'uid'  => posix_geteuid() + 10000,
			'gid'  => max( array_merge( $groups, array( posix_getegid() ) ) ) + 10000,
		);

		$resolver  = new PrivateLocationCandidateResolver( $this->temporaryBoundary );
		$wordpress = $this->root . '/account/site/public';
		$content   = $wordpress . '/wp-content';
		$plugin    = $content . '/plugins/ran-booster';
		$discarded = array();
		$candidate = $resolver->resolve( $wordpress, $content, $plugin, $wordpress, $discarded );

		self::assertIsString( $candidate );
		self::assertStringStartsWith( $this->root . '/account/.ran-booster/', $candidate );
		self::assertSame( array(), $discarded );
		self::assertTrue( $resolver->validateConfigured( $candidate, $wordpress, $content, $plugin, $wordpress ) );
		self::assertTrue(
			$resolver->validateConfigured(
				$this->root . '/account/private/ran-booster/secrets.json',
				$wordpress,
				$content,
				$plugin,
				$wordpress
			)
		);
	}

	public function testRejectsAHostAncestorInThePhpEffectiveGroup(): void {
		self::assertTrue( chmod( $this->root, 0770 ) );
		$effectiveGroup                              = posix_getegid();
		$GLOBALS['ran_booster_shared_host_stat']     = array(
			'path' => $this->root,
			'uid'  => posix_geteuid() + 10000,
			'gid'  => $effectiveGroup,
		);
		$GLOBALS['ran_booster_shared_host_identity'] = array(
			'uid'    => posix_geteuid(),
			'gid'    => $effectiveGroup,
			'groups' => array(),
		);

		$discarded = array();
		self::assertNull( $this->resolve( $discarded ) );
		self::assertSame( 'php_accessible_group_writable_ancestor', $discarded[0]['code'] ?? null );
	}

	public function testFailsClosedWhenSupplementaryGroupsCannotBeRead(): void {
		self::assertTrue( chmod( $this->root, 0770 ) );
		$GLOBALS['ran_booster_shared_host_stat']     = array(
			'path' => $this->root,
			'uid'  => posix_geteuid() + 10000,
			'gid'  => posix_getegid() + 10000,
		);
		$GLOBALS['ran_booster_shared_host_identity'] = array(
			'uid'    => posix_geteuid(),
			'gid'    => posix_getegid(),
			'groups' => false,
		);

		$discarded = array();
		self::assertNull( $this->resolve( $discarded ) );
		self::assertSame( 'php_accessible_group_writable_ancestor', $discarded[0]['code'] ?? null );
	}

		/** @param list<array{directory:string,code:string,reason:string,component:string|null}> $discarded */
	private function resolve( array &$discarded ): ?string {
		$wordpress = $this->root . '/account/site/public';

		return ( new PrivateLocationCandidateResolver( $this->temporaryBoundary ) )->resolve(
			$wordpress,
			$wordpress . '/wp-content',
			$wordpress . '/wp-content/plugins/ran-booster',
			$wordpress,
			$discarded
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
}
