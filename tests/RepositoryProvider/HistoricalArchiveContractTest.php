<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\PackageOperation;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryReference;

require_once __DIR__ . '/../Support/PackageOperationWordPressFunctions.php';

final class HistoricalArchiveContractTest extends TestCase {
	public function test_selected_commit_round_trips_without_changing_tracking_or_snapshot_branch(): void {
		foreach ( array( 40, 64 ) as $length ) {
			$commit   = str_repeat( 'a', $length );
			$request  = new DeploymentRequest( 'owner/repo', null, false, 'main', 'repo', null, DeploymentPolicy::MANUAL, 1, selected_commit: $commit );
			$restored = DeploymentRequest::from_json( $request->to_json() );
			self::assertSame( $commit, $restored->selected_commit );
			self::assertSame( 'main', $restored->configured_branch );
			$archive = new ArchiveRequest( new RepositoryReference( 'owner/repo', 'repo-id', false, null ), $commit, selected_commit: $commit );
			self::assertSame( $commit, $archive->ref );
			self::assertNull( $archive->expected_branch );
			$operation = PackageOperation::from_input(
				'update-plugin',
				array(
					'file'            => 'repo/main.php',
					'repository'      => 'owner/repo',
					'expected_branch' => 'main',
					'selected_commit' => $commit,
				)
			);
			self::assertSame( $commit, $operation->selected_commit );
			$snapshot = $operation->expected_package;
			if ( ! array_key_exists( 'branch', $snapshot ) ) {
				self::fail( 'Historical updates must retain a tracking branch snapshot.' );
			}
			self::assertSame( 'main', $snapshot['branch'] );
		}
	}

	/** @return iterable<array{string}> */
	public static function malformed_commits(): iterable {
		foreach ( array( '', 'main', str_repeat( 'a', 39 ), str_repeat( 'a', 41 ), str_repeat( 'A', 40 ), str_repeat( 'g', 40 ), str_repeat( 'a', 40 ) . "\n" ) as $commit ) {
			yield array( $commit );
		}
	}

	#[DataProvider( 'malformed_commits' )]
	public function test_malformed_commit_refuses_at_every_neutral_input( string $commit ): void {
		foreach ( array(
			static fn () => new DeploymentRequest( 'owner/repo', null, false, 'main', 'repo', null, DeploymentPolicy::MANUAL, 1, selected_commit: $commit ),
			static fn () => new ArchiveRequest( new RepositoryReference( 'owner/repo', 'repo-id', false, null ), $commit, selected_commit: $commit ),
			static fn () => PackageOperation::from_input(
				'update-plugin',
				array(
					'file'            => 'repo/main.php',
					'repository'      => 'owner/repo',
					'selected_commit' => $commit,
				)
			),
		) as $operation ) {
			try {
				$operation();
				self::fail( 'Malformed historical input must refuse.' );
			} catch ( InvalidArgumentException $failure ) {
				self::assertStringContainsString( 'historical', strtolower( $failure->getMessage() ) );
			}
		}
	}

	public function test_historical_archive_cannot_carry_a_current_head_constraint_or_different_ref(): void {
		$commit = str_repeat( 'a', 40 );
		foreach ( array( array( $commit, 'main' ), array( str_repeat( 'b', 40 ), null ) ) as list( $ref, $branch ) ) {
			try {
				new ArchiveRequest( new RepositoryReference( 'owner/repo', 'repo-id', false, null ), $ref, $branch, selected_commit: $commit );
				self::fail( 'Conflicting historical archive constraints must refuse.' );
			} catch ( InvalidArgumentException $failure ) {
				self::assertStringContainsString( 'historical', strtolower( $failure->getMessage() ) );
			}
		}
	}

	public function test_archive_capability_defaults_to_one_and_only_recognizes_one_or_two(): void {
		self::assertSame( 1, ( new ProviderMetadata( ProviderCode::parse( 'bb' ), 'Bitbucket', 'https://bitbucket.org/', 'Owner' ) )->archive_api_version );
		self::assertSame( 2, ( new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner', archive_api_version: 2 ) )->archive_api_version );
		$this->expectException( InvalidArgumentException::class );
		new ProviderMetadata( ProviderCode::parse( 'gh' ), 'GitHub', 'https://github.com/', 'Owner', archive_api_version: 3 );
	}
}
