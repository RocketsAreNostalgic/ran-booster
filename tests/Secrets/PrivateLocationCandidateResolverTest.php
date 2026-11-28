<?php

declare(strict_types=1);

namespace RAN\Tests\Secrets;

// Test fixtures deliberately exercise native filesystem semantics.

use PHPUnit\Framework\TestCase;
use RAN\Secrets\PrivateLocationCandidateResolver;

final class PrivateLocationCandidateResolverTest extends TestCase {

	private string $root;
	private string $temporary_boundary;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function setUp(): void {
		$suffix                   = bin2hex( random_bytes( 6 ) );
		$this->root               = sys_get_temp_dir() . '/ran-booster-location-' . $suffix;
		$this->temporary_boundary = sys_get_temp_dir() . '/ran-booster-temporary-boundary-' . $suffix;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		mkdir( $this->root . '/account/site/public/wp-content/plugins/ran-booster', 0700, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		mkdir( $this->root . '/account/site/.git', 0700 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		mkdir( $this->temporary_boundary, 0700 );
		$this->root = (string) realpath( $this->root );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this exact lifecycle override name.
	protected function tearDown(): void {
		$this->remove( $this->root );
		$this->remove( $this->temporary_boundary );
	}

	public function test_suggests_randomized_128_bit_site_specific_path_outside_repository_and_web_roots(): void {
		$resolver   = new PrivateLocationCandidateResolver( $this->temporary_boundary );
		$candidate  = $resolver->resolve(
			$this->root . '/account/site/public',
			$this->root . '/account/site/public/wp-content',
			$this->root . '/account/site/public/wp-content/plugins/ran-booster',
			$this->root . '/account/site/public'
		);
		$second_run = $resolver->resolve(
			$this->root . '/account/site/public',
			$this->root . '/account/site/public/wp-content',
			$this->root . '/account/site/public/wp-content/plugins/ran-booster',
			$this->root . '/account/site/public'
		);

		self::assertMatchesRegularExpression(
			'#^' . preg_quote( $this->root . '/account', '#' ) . '/\.ran-booster/[a-f0-9]{32}/ran-booster-store\.json$#D',
			(string) $candidate
		);
		self::assertMatchesRegularExpression(
			'#^' . preg_quote( $this->root . '/account', '#' ) . '/\.ran-booster/[a-f0-9]{32}/ran-booster-store\.json$#D',
			(string) $second_run
		);
		self::assertNotSame( $candidate, $second_run );
		self::assertTrue(
			$resolver->validate_configured(
				(string) $candidate,
				$this->root . '/account/site/public',
				$this->root . '/account/site/public/wp-content',
				$this->root . '/account/site/public/wp-content/plugins/ran-booster',
				$this->root . '/account/site/public'
			)
		);
		// Existing configured legacy 16-hex-char paths remain valid.
		self::assertTrue(
			$resolver->validate_configured(
				$this->root . '/account/.ran-booster/' . $this->fingerprint() . '/ran-booster-store.json',
				$this->root . '/account/site/public',
				$this->root . '/account/site/public/wp-content',
				$this->root . '/account/site/public/wp-content/plugins/ran-booster',
				$this->root . '/account/site/public'
			)
		);
		self::assertDirectoryDoesNotExist( $this->root . '/account/.ran-booster' );
	}

	public function test_hosting_names_do_not_disqualify_operator_paths_and_do_not_authorize_automatic_paths(): void {
		$resolver = new PrivateLocationCandidateResolver( $this->temporary_boundary );
		$account  = $this->root . '/var/www/vhosts/example';
		$legacy   = $account . '/.ran-booster/0123456789abcdef';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- A pre-existing operator-owned 16-character storage directory.
		self::assertTrue( mkdir( $legacy, 0700, true ) );
		foreach ( array( 'httpdocs', 'Public_HTML', 'web', 'www/team/httpdocs' ) as $name ) {
			$site_root = $account . '/' . $name;
			$actual    = 'www/team/httpdocs' === $name ? $account . '/www' : $site_root;
			$wordpress = $site_root . '/nested/blog';
			$content   = $wordpress . '/wp-content';
			$plugin    = $content . '/plugins/ran-booster';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create disposable nested WordPress layouts.
			self::assertTrue( mkdir( $plugin, 0700, true ) );

			foreach ( array( $actual, null, $wordpress ) as $document_root ) {
				self::assertTrue( $resolver->validate_configured( $legacy . '/ran-booster-store.json', $wordpress, $content, $plugin, $document_root, true ) );
				self::assertFalse( $resolver->validate_configured( $legacy . '/ran-booster-store.json', $wordpress, $content, $plugin, $document_root ) );
				self::assertFalse( $resolver->validate_configured( $wordpress . '/ran-booster-store.json', $wordpress, $content, $plugin, $document_root, true ) );
				self::assertNull( $resolver->resolve( $wordpress, $content, $plugin, $document_root ) );
			}

			$outer_sidecar = dirname( $wordpress ) . '/ran-booster-store.json';
			self::assertFalse( $resolver->validate_configured( $outer_sidecar, $wordpress, $content, $plugin, $actual, true ) );
		}
	}

	public function test_automatic_storage_requires_an_observed_root_without_a_wider_conflict(): void {
		$resolver  = new PrivateLocationCandidateResolver( $this->temporary_boundary );
		$public    = $this->root . '/account/httpdocs';
		$wordpress = $public . '/nested/blog';
		$content   = $wordpress . '/wp-content';
		$plugin    = $content . '/plugins/ran-booster';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create a disposable site with a reported canonical root.
		self::assertTrue( mkdir( $plugin, 0700, true ) );

		$candidate = $resolver->resolve( $wordpress, $content, $plugin, $public );
		self::assertIsString( $candidate );
		self::assertStringStartsWith( dirname( $public ) . '/.ran-booster/', $candidate );
		self::assertTrue( $resolver->validate_configured( $candidate, $wordpress, $content, $plugin, $public ) );
		self::assertFalse( $resolver->validate_configured( $candidate, $wordpress, $content, $plugin ) );
		self::assertNull( $resolver->resolve( $wordpress, $content, $plugin ) );
		self::assertNull( $resolver->resolve( $wordpress, $content, $plugin, $wordpress ) );
		self::assertNull( $resolver->resolve( $wordpress, $content, $plugin, $content ) );
	}

	public function test_rejects_the_old_basename_as_a_configured_path_before_creating_directories(): void {
		$resolver  = new PrivateLocationCandidateResolver( $this->temporary_boundary );
		$candidate = $this->root . '/account/.ran-booster/' . $this->fingerprint() . '/secrets.json';

		self::assertFalse(
			$resolver->validate_configured(
				$candidate,
				$this->root . '/account/site/public',
				$this->root . '/account/site/public/wp-content',
				$this->root . '/account/site/public/wp-content/plugins/ran-booster',
				$this->root . '/account/site/public'
			)
		);
		self::assertDirectoryDoesNotExist( $this->root . '/account/.ran-booster' );
	}

	public function test_never_suggests_apath_that_fails_the_configured_ancestor_policy(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->root . '/account', 0770 ) );
		$resolver  = new PrivateLocationCandidateResolver( $this->temporary_boundary );
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
		self::assertCount( 1, $discarded );
		self::assertMatchesRegularExpression(
			'#^' . preg_quote( $this->root . '/account', '#' ) . '/\.ran-booster/[a-f0-9]{32}$#D',
			$discarded[0]['directory']
		);
		self::assertSame( 'broad_private_path_permissions', $discarded[0]['code'] );
		self::assertSame( 'A private path component is writable by its group or by other users.', $discarded[0]['reason'] );
		self::assertSame( $this->root . '/account', $discarded[0]['component'] );
	}

	public function test_rejects_agroup_writable_ancestor_owned_by_php_even_when_owner_write_is_disabled(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->root, 0570 ) );
		$resolver  = new PrivateLocationCandidateResolver( $this->temporary_boundary );
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
			chmod( $this->root, 0700 );
		}
	}

	public function test_rejects_aworld_writable_host_ancestor(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $this->root, 0777 ) );
		$resolver  = new PrivateLocationCandidateResolver( $this->temporary_boundary );
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
			chmod( $this->root, 0700 );
		}
	}

	public function test_bazaar_working_copy_ancestor_is_a_boundary(): void {
		$resolver  = new PrivateLocationCandidateResolver( $this->temporary_boundary );
		$wordpress = $this->root . '/account/site/public';
		$content   = $wordpress . '/wp-content';
		$plugin    = $content . '/plugins/ran-booster';
		$private   = $this->root . '/account/private';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $private, 0700 ) );
		self::assertTrue( $resolver->validate_configured( $private . '/ran-booster-store.json', $wordpress, $content, $plugin, $wordpress ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $this->root . '/account/.bzr', 0700 ) );
		self::assertFalse( $resolver->validate_configured( $private . '/ran-booster-store.json', $wordpress, $content, $plugin, $wordpress ) );
	}

	public function test_rejects_unrelated_or_symlinked_boundaries(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		mkdir( $this->root . '/other', 0700 );
		$resolver = new PrivateLocationCandidateResolver( $this->temporary_boundary );
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

	public function test_configured_path_must_remain_outside_unsafe_roots_and_have_no_symlinked_components(): void {
		$resolver  = new PrivateLocationCandidateResolver( $this->temporary_boundary );
		$wordpress = $this->root . '/account/site/public';
		$content   = $wordpress . '/wp-content';
		$plugin    = $content . '/plugins/ran-booster';
		$private   = $this->root . '/account/private';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the disposable native directory topology used by the filesystem/security fixture.
		self::assertTrue( mkdir( $private, 0700 ) );

		self::assertTrue(
			$resolver->validate_configured(
				$private . '/ran-booster-store.json',
				$wordpress,
				$content,
				$plugin,
				$wordpress
			)
		);
		self::assertFalse(
			$resolver->validate_configured(
				$content . '/ran-booster-store.json',
				$wordpress,
				$content,
				$plugin,
				$wordpress
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $private, 0770 ) );
		self::assertFalse(
			$resolver->validate_configured(
				$private . '/ran-booster-store.json',
				$wordpress,
				$content,
				$plugin,
				$wordpress
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set native POSIX permission bits required by the private-path security assertion.
		self::assertTrue( chmod( $private, 0700 ) );

		self::assertTrue( symlink( $private, $this->root . '/account/private-link' ) );
		self::assertFalse(
			$resolver->validate_configured(
				$this->root . '/account/private-link/ran-booster-store.json',
				$wordpress,
				$content,
				$plugin,
				$wordpress
			)
		);
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only disposable native filesystem entries owned by this fixture.
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only disposable native filesystem entries owned by this fixture.
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
