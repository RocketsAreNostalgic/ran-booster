<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\ManagedReleaseBundle;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\RepositorySnapshot;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\SourceReadyAssessor;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi2Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi2Fixture.php';

final class ManagedReleaseBundleTest extends TestCase {
	public function testBuildsOneRepeatableCompleteApi2BootstrapTree(): void {
		$archive    = TemplatePackApi2Fixture::archive();
		$packResult = TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) );
		self::assertSame( 'ok', $packResult['code'] );
		$snapshot   = $this->snapshot();
		$assessment = $this->assessment( $snapshot );
		$documents  = array();
		foreach ( array_reverse( $snapshot->documentPaths() ) as $path ) {
			$documents[ $path ] = $snapshot->document( $path );
		}
		$reordered = new RepositorySnapshot(
			$snapshot->repositoryId(),
			$snapshot->repository(),
			$snapshot->defaultBranch(),
			$snapshot->sha(),
			array_reverse( $snapshot->entries(), true ),
			$documents
		);
		$first     = ManagedReleaseBundle::bootstrap( $packResult['pack'], $assessment, $snapshot, 'https://github.com/owner/example-plugin/' );
		$second    = ManagedReleaseBundle::bootstrap( $packResult['pack'], $this->assessment( $reordered ), $reordered, 'https://github.com/owner/example-plugin' );

		self::assertSame( 'ok', $first['code'] );
		self::assertSame( $first['bundle']->hash(), $second['bundle']->hash() );
		self::assertSame( $first['bundle']->changedPathHash(), $second['bundle']->changedPathHash() );
		self::assertSame( $first['bundle']->allowlistHash(), $second['bundle']->allowlistHash() );
		self::assertSame( $first['bundle']->files(), $second['bundle']->files() );
		self::assertSame( 'source-ready-wordpress-plugin/2', $first['bundle']->profile() );
		$files = $first['bundle']->files();
		self::assertSame(
			array(
				'.github/workflows/release-please.yml',
				'.prettierignore',
				'.ran-booster-release-profile.json',
				'.release-please-manifest.json',
				'example-plugin.php',
				'release-contents.txt',
				'release-please-config.json',
				'scripts/build-release.sh',
				'scripts/upload-release-assets.sh',
				'scripts/verify-release.sh',
				'version.txt',
			),
			array_keys( $files )
		);
		self::assertSame( hash( 'sha256', implode( "\n", array_keys( $files ) ) . "\n" ), $first['bundle']->changedPathHash() );
		self::assertSame( hash( 'sha256', $files['release-contents.txt']['content'] ), $first['bundle']->allowlistHash() );
		self::assertSame( '100755', $files['scripts/build-release.sh']['mode'] );
		self::assertSame( 'modified', $files['example-plugin.php']['operation'] );
		$receipt = json_decode( $files[ ManagedReleaseBundle::RECEIPT_PATH ]['content'], true, 32, JSON_THROW_ON_ERROR );
		self::assertSame( $receipt, ManagedReleaseBundle::receipt( $files[ ManagedReleaseBundle::RECEIPT_PATH ]['content'] ) );
		self::assertSame( 2, $receipt['consumer_api'] );
		self::assertSame( array_keys( TemplatePackApi2Fixture::identity( $archive ) ), array_slice( array_keys( $receipt['template'] ), 2 ) );
		self::assertSame( TemplatePackApi2Fixture::identity( $archive ), array_slice( $receipt['template'], 2, null, true ) );
		self::assertArrayHasKey( ManagedReleaseBundle::WORKFLOW_PATH, $receipt['managed_files'] );
		self::assertArrayNotHasKey( '.prettierignore', $receipt['managed_files'] );
		self::assertSame( $files[ ManagedReleaseBundle::WORKFLOW_PATH ]['git_sha'], $first['bundle']->expectedPullFiles()[0]['sha'] );
	}

	public function testReceiptRejectsOpenSchemaIdentityInputAndManagedSetDrift(): void {
		$archive                               = TemplatePackApi2Fixture::archive();
		$pack                                  = TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['pack'];
		$bundle                                = ManagedReleaseBundle::bootstrap( $pack, $this->assessment( $this->snapshot() ), $this->snapshot(), 'https://github.com/owner/example-plugin' )['bundle'];
		$valid                                 = json_decode( $bundle->files()[ ManagedReleaseBundle::RECEIPT_PATH ]['content'], true, 32, JSON_THROW_ON_ERROR );
		$cases                                 = array();
		$mutated                               = $valid;
		$mutated['unknown']                    = true;
		$cases[]                               = $mutated;
		$mutated                               = $valid;
		$mutated['schema_version']             = 2;
		$cases[]                               = $mutated;
		$mutated                               = $valid;
		$mutated['profile']['id']              = 'source-ready-wordpress-plugin/1';
		$cases[]                               = $mutated;
		$mutated                               = $valid;
		$mutated['inputs']['unknown']          = 'value';
		$cases[]                               = $mutated;
		$mutated                               = $valid;
		$mutated['template']['release_target'] = str_repeat( 'f', 40 );
		$cases[]                               = $mutated;
		$mutated                               = $valid;
		$mutated['template']['release_draft']  = true;
		$cases[]                               = $mutated;
		$mutated                               = $valid;
		unset( $mutated['managed_files'][ ManagedReleaseBundle::WORKFLOW_PATH ] );
		$cases[]                               = $mutated;
		$mutated                               = $valid;
		$mutated['managed_files']['extra.yml'] = str_repeat( 'a', 64 );
		$cases[]                               = $mutated;
		$mutated                               = $valid;
		$mutated['managed_files']              = array_reverse( $mutated['managed_files'], true );
		$cases[]                               = $mutated;
		foreach ( $cases as $case ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact hostile receipt bytes.
			$bytes = json_encode( $case, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
			self::assertNull( ManagedReleaseBundle::receipt( $bytes ) );
		}
	}

	public function testRefusesNonReadyAssessmentAndOccupiedGeneratedPath(): void {
		$archive = TemplatePackApi2Fixture::archive();
		$pack    = TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['pack'];
		$base    = $this->snapshot();
		self::assertSame( 'invalid_bundle', ManagedReleaseBundle::bootstrap( $pack, $this->assessment( $base ), $base, 'https://github.com/owner/other' )['code'] );
		$entries                = $base->entries();
		$docs                   = array(
			'example-plugin.php' => $base->document( 'example-plugin.php' ),
			'version.txt'        => '1.2.3',
		);
		$entries['version.txt'] = array(
			'type' => 'blob',
			'mode' => '100644',
			'sha'  => sha1( 'version.txt' ),
			'size' => 5,
		);
		$occupied               = new RepositorySnapshot( '101', 'owner/example-plugin', 'main', str_repeat( 'a', 40 ), $entries, $docs );
		$assessment             = $this->assessment( $occupied );

		self::assertSame( 'release_path_conflict', $assessment->code() );
		self::assertSame( 'invalid_bundle', ManagedReleaseBundle::bootstrap( $pack, $assessment, $occupied, 'https://github.com/owner/example-plugin' )['code'] );
	}

	public function testTemplateUpdateUsesFullApi2IdentityAndRefusesDirtyFiles(): void {
		$oldArchive = TemplatePackApi2Fixture::archive();
		$oldPack    = TemplatePack::fromArchive( $oldArchive, TemplatePackApi2Fixture::identity( $oldArchive ) )['pack'];
		$bootstrap  = ManagedReleaseBundle::bootstrap( $oldPack, $this->assessment( $this->snapshot() ), $this->snapshot(), 'https://github.com/owner/example-plugin' )['bundle'];
		$current    = $this->managedSnapshot( $bootstrap );

		$configPath      = 'templates/profiles/wordpress-plugin/release-please-config.json.tmpl';
		$newContent      = str_replace( '"slug"', '"package"', TemplatePackApi2Fixture::templates()[ $configPath ] );
		$manifest        = TemplatePackApi2Fixture::manifest( 2, '1.2.4' );
		$entry           = &$manifest['profiles']['source-ready-wordpress-plugin/2']['entries']['release-please-config'];
		$entry['size']   = strlen( $newContent );
		$entry['sha256'] = hash( 'sha256', $newContent );
		$newArchive      = TemplatePackApi2Fixture::archive( $manifest, array( $configPath => $newContent ) );
		$newPack         = TemplatePack::fromArchive( $newArchive, TemplatePackApi2Fixture::identity( $newArchive, '1.2.4' ) )['pack'];
		$update          = ManagedReleaseBundle::templateUpdate( $oldPack, $newPack, $current );

		self::assertSame( 'ok', $update['code'] );
		self::assertSame( array( ManagedReleaseBundle::RECEIPT_PATH, 'release-please-config.json' ), array_keys( $update['bundle']->files() ) );

		$entries = $current->entries();
		$docs    = array();
		foreach ( $current->documentPaths() as $path ) {
			$docs[ $path ] = $current->document( $path );
		}
		$docs[ ManagedReleaseBundle::WORKFLOW_PATH ]           .= "# owner edit\n";
		$entries[ ManagedReleaseBundle::WORKFLOW_PATH ]['size'] = strlen( $docs[ ManagedReleaseBundle::WORKFLOW_PATH ] );
		$dirty = new RepositorySnapshot( '101', 'owner/example-plugin', 'main', str_repeat( 'b', 40 ), $entries, $docs );
		self::assertSame( 'managed_profile_modified', ManagedReleaseBundle::templateUpdate( $oldPack, $newPack, $dirty )['code'] );

		$docs    = array();
		$entries = $current->entries();
		foreach ( $current->documentPaths() as $path ) {
			$docs[ $path ] = $current->document( $path );
		}
		$receipt                         = json_decode( $docs[ ManagedReleaseBundle::RECEIPT_PATH ], true, 32, JSON_THROW_ON_ERROR );
		$receipt['template']['asset_id'] = '999';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact throwing receipt fixture.
		$docs[ ManagedReleaseBundle::RECEIPT_PATH ]            = json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n";
		$entries[ ManagedReleaseBundle::RECEIPT_PATH ]['size'] = strlen( $docs[ ManagedReleaseBundle::RECEIPT_PATH ] );
		$altered = new RepositorySnapshot( '101', 'owner/example-plugin', 'main', str_repeat( 'c', 40 ), $entries, $docs );
		self::assertSame( 'managed_profile_modified', ManagedReleaseBundle::templateUpdate( $oldPack, $newPack, $altered )['code'] );
	}

	private function assessment( RepositorySnapshot $snapshot ): object {
		return ( new SourceReadyAssessor() )->assess( $snapshot, 'plugin', 'example-plugin', '1.2.3', 'https://github.com/owner/example-plugin' );
	}

	private function snapshot(): RepositorySnapshot {
		$documents = array(
			'example-plugin.php' => "<?php\n/**\n * Plugin Name: Example\n * Version: 1.2.3\n * Update URI: https://github.com/owner/example-plugin\n */\n",
			'assets/app.css'     => 'body{}',
		);
		$entries   = array();
		foreach ( $documents as $path => $document ) {
			$entries[ $path ] = array(
				'type' => 'blob',
				'mode' => '100644',
				'sha'  => sha1( $path ),
				'size' => strlen( $document ),
			);
		}
		return new RepositorySnapshot( '101', 'owner/example-plugin', 'main', str_repeat( 'a', 40 ), $entries, $documents );
	}

	private function managedSnapshot( ManagedReleaseBundle $bundle ): RepositorySnapshot {
		$entries   = array();
		$documents = array();
		foreach ( $bundle->files() as $path => $file ) {
			if ( $file['managed'] ) {
				$entries[ $path ]   = array(
					'type' => 'blob',
					'mode' => $file['mode'],
					'sha'  => $file['git_sha'],
					'size' => strlen( $file['content'] ),
				);
				$documents[ $path ] = $file['content'];
			}
		}
		return new RepositorySnapshot( '101', 'owner/example-plugin', 'main', str_repeat( 'b', 40 ), $entries, $documents );
	}
}
