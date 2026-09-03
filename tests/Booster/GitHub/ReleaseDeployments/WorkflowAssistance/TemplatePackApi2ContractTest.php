<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\TemplatePack;
use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi2Fixture;

require_once __DIR__ . '/WorkflowAssistanceTestBootstrap.php';
require_once __DIR__ . '/Support/TemplatePackApi2Fixture.php';

final class TemplatePackApi2ContractTest extends TestCase {

	private const LIVE_SHA = '7518b7c30b23fe95fb6c3c5211607657394ffcf440d258323d55c20b15bb5b14';
	private const API1_SHA = '2c223e14287a1fab28aa91e92d6a454b27e647cfb33f1bb3df965ed995cd89db';

	public function testClosedApi2ContractRendersDeterministicPluginAndThemeBundles(): void {
		$archive = TemplatePackApi2Fixture::archive();
		$result  = TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) );

		self::assertSame( 'ok', $result['code'] );
		$pack = $result['pack'];
		self::assertSame( '1.2.3', $pack->packVersion() );
		self::assertSame(
			array( 'source-ready-wordpress-plugin/2', 'source-ready-wordpress-theme/2' ),
			$pack->profiles()
		);
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/D', $pack->manifestHash() );

		$pluginFirst  = self::renderFixtureProfile( $pack, 'plugin' );
		$pluginSecond = self::renderFixtureProfile( $pack, 'plugin' );
		$themeFirst   = self::renderFixtureProfile( $pack, 'theme' );
		$themeSecond  = self::renderFixtureProfile( $pack, 'theme' );
		self::assertSame( $pluginFirst, $pluginSecond );
		self::assertSame( $themeFirst, $themeSecond );
		self::assertNotSame( $pluginFirst['bundle_sha256'], $themeFirst['bundle_sha256'] );
		self::assertSame( self::targetPaths(), array_keys( $pluginFirst['managed_files'] ) );
		self::assertSame( self::targetPaths(), array_keys( $themeFirst['managed_files'] ) );
	}

	public function testHistoricalApi1IsRefusedWithoutAnAdapter(): void {
		$archive = TemplatePackApi2Fixture::archive( TemplatePackApi2Fixture::manifest( 1 ) );

		self::assertSame(
			'template_pack_incompatible',
			TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code']
		);

		$driftedManifest                  = TemplatePackApi2Fixture::manifest( 1 );
		$driftedManifest['release']['id'] = 999;
		$driftedArchive                   = TemplatePackApi2Fixture::archive( $driftedManifest );
		self::assertSame(
			'template_pack_invalid',
			TemplatePack::fromArchive( $driftedArchive, TemplatePackApi2Fixture::identity( $driftedArchive ) )['code']
		);
	}

	public function testRejectsRemoteIdentityManifestAuthorityDigestAndMemberDrift(): void {
		$archive                  = TemplatePackApi2Fixture::archive();
		$identity                 = TemplatePackApi2Fixture::identity( $archive );
		$identity['asset_sha256'] = str_repeat( '0', 64 );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, $identity )['code'] );

		$identity                   = TemplatePackApi2Fixture::identity( $archive );
		$identity['release_target'] = str_repeat( '1', 40 );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, $identity )['code'] );

		$identity                  = TemplatePackApi2Fixture::identity( $archive );
		$identity['repository_id'] = '987654321';
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, $identity )['code'] );

		$manifest = TemplatePackApi2Fixture::manifest();
		$manifest['profiles']['source-ready-wordpress-plugin/2']['entries']['release-workflow']['target_path'] = '.github/workflows/release.yml';
		$archive = TemplatePackApi2Fixture::archive( $manifest );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$manifest = TemplatePackApi2Fixture::manifest();
		$manifest['profiles']['source-ready-wordpress-theme/2']['entries']['release-workflow']['sha256'] = str_repeat( '0', 64 );
		$archive = TemplatePackApi2Fixture::archive( $manifest );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$archive = TemplatePackApi2Fixture::archive( null, array(), array( 'templates/unlisted.txt' => 'not declared' ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$archive = TemplatePackApi2Fixture::archive( null, array(), array( '../escape.txt' => 'unsafe' ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );
	}

	public function testRejectsExecutableUnknownTokensAndHighCompressionRatio(): void {
		$workflowPath = 'templates/shared/release-please.yml.tmpl';
		$archive      = TemplatePackApi2Fixture::archive( null, array(), array(), $workflowPath, 0100755 );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$content  = TemplatePackApi2Fixture::templates()[ $workflowPath ] . '{{RAN_REMOTE_COMMAND}}';
		$manifest = self::manifestWithWorkflowContent( $content );
		$archive  = TemplatePackApi2Fixture::archive( $manifest, array( $workflowPath => $content ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$content  = str_repeat( 'A', 200000 );
		$manifest = self::manifestWithWorkflowContent( $content );
		$archive  = TemplatePackApi2Fixture::archive( $manifest, array( $workflowPath => $content ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );
	}

	public function testRejectsNulAndInvalidUtf8MembersWithExactMatchingSizeAndDigest(): void {
		$workflowPath = 'templates/shared/release-please.yml.tmpl';
		$workflow     = TemplatePackApi2Fixture::templates()[ $workflowPath ];
		foreach ( array(
			'NUL byte'      => $workflow . "\0",
			'invalid UTF-8' => $workflow . "\xC3\x28",
		) as $case => $content ) {
			$manifest = self::manifestWithWorkflowContent( $content );
			foreach ( array( 'source-ready-wordpress-plugin/2', 'source-ready-wordpress-theme/2' ) as $profile ) {
				$entry = $manifest['profiles'][ $profile ]['entries']['release-workflow'];
				self::assertSame( strlen( $content ), $entry['size'], $case );
				self::assertSame( hash( 'sha256', $content ), $entry['sha256'], $case );
			}
			$archive = TemplatePackApi2Fixture::archive( $manifest, array( $workflowPath => $content ) );
			$result  = TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) );

			self::assertSame( 'template_pack_invalid', $result['code'], $case );
			self::assertArrayNotHasKey( 'pack', $result, $case );
		}
	}

	public function testValidUnicodeMemberPassesWithoutTranscodingOrSanitisation(): void {
		$workflowPath = 'templates/shared/release-please.yml.tmpl';
		$content      = TemplatePackApi2Fixture::templates()[ $workflowPath ] . "label=Déploiement sûr 🚀\n";
		$manifest     = self::manifestWithWorkflowContent( $content );
		$archive      = TemplatePackApi2Fixture::archive( $manifest, array( $workflowPath => $content ) );
		$result       = TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) );

		self::assertSame( 'ok', $result['code'] );
		$rendered = $result['pack']->render(
			'source-ready-wordpress-plugin/2',
			'release-workflow',
			array(
				'DEFAULT_BRANCH' => 'main',
				'PACKAGE_SLUG'   => 'unicode-plugin',
			)
		);
		self::assertSame( 'ok', $rendered['code'] );
		self::assertSame(
			str_replace( array( '{{RAN_DEFAULT_BRANCH}}', '{{RAN_PACKAGE_SLUG}}' ), array( 'main', 'unicode-plugin' ), $content ),
			$rendered['content']
		);
	}

	public function testRejectsDuplicateMissingAndExtraDeclarationsAndEveryResourceCap(): void {
		$manifest = TemplatePackApi2Fixture::manifest();
		$manifest['profiles']['source-ready-wordpress-extra/2'] = $manifest['profiles']['source-ready-wordpress-plugin/2'];
		$archive = TemplatePackApi2Fixture::archive( $manifest );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$manifest = TemplatePackApi2Fixture::manifest();
		$workflow = $manifest['profiles']['source-ready-wordpress-plugin/2']['entries']['release-workflow'];
		$manifest['profiles']['source-ready-wordpress-plugin/2']['entries']['release-please-config']['path']   = $workflow['path'];
		$manifest['profiles']['source-ready-wordpress-plugin/2']['entries']['release-please-config']['size']   = $workflow['size'];
		$manifest['profiles']['source-ready-wordpress-plugin/2']['entries']['release-please-config']['sha256'] = $workflow['sha256'];
		$archive = TemplatePackApi2Fixture::archive( $manifest );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$archive = TemplatePackApi2Fixture::archive(
			null,
			array(),
			array(),
			'',
			0100644,
			array( 'templates/shared/upload-release-assets.sh.tmpl' )
		);
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact deterministic oversized-manifest fixture.
		$manifestBytes = (string) json_encode( TemplatePackApi2Fixture::manifest(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		$archive       = TemplatePackApi2Fixture::archive(
			null,
			array( 'template-pack.json' => $manifestBytes . str_repeat( ' ', 65537 - strlen( $manifestBytes ) ) )
		);
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$content  = self::pseudoRandomAscii( 262145 );
		$manifest = self::manifestWithWorkflowContent( $content );
		$archive  = TemplatePackApi2Fixture::archive(
			$manifest,
			array( 'templates/shared/release-please.yml.tmpl' => $content )
		);
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$extra = array();
		for ( $index = 0; $index < 26; ++$index ) {
			$extra[ 'templates/count-' . $index . '.txt' ] = 'bounded';
		}
		$archive = TemplatePackApi2Fixture::archive( null, array(), $extra );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$extra = array();
		for ( $index = 0; $index < 5; ++$index ) {
			$extra[ 'templates/total-' . $index . '.txt' ] = self::pseudoRandomAscii( 220000 );
		}
		$archive = TemplatePackApi2Fixture::archive( null, array(), $extra );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );

		$archive = TemplatePackApi2Fixture::archive(
			null,
			array(),
			array( 'templates/archive-cap.txt' => self::pseudoRandomAscii( 4000000 ) )
		);
		self::assertGreaterThan( 2097152, strlen( $archive ) );
		self::assertSame( 'template_pack_invalid', TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['code'] );
	}

	public function testRendererRejectsUnsafeOrIncompleteConsumerInputs(): void {
		$archive = TemplatePackApi2Fixture::archive();
		$pack    = TemplatePack::fromArchive( $archive, TemplatePackApi2Fixture::identity( $archive ) )['pack'];

		self::assertSame( 'invalid_render', $pack->render( 'unknown-profile', 'release-workflow', array() )['code'] );
		self::assertSame(
			'invalid_render',
			$pack->render( 'source-ready-wordpress-plugin/2', 'release-workflow', array( 'PACKAGE_SLUG' => 'example' ) )['code']
		);
		self::assertSame(
			'invalid_render',
			$pack->render(
				'source-ready-wordpress-plugin/2',
				'release-workflow',
				array(
					'DEFAULT_BRANCH' => 'main',
					'PACKAGE_SLUG'   => '{{RAN_REMOTE_COMMAND}}',
				)
			)['code']
		);
		self::assertSame(
			'invalid_render',
			$pack->render(
				'source-ready-wordpress-plugin/2',
				'release-please-config',
				array(
					'BASE_SHA'         => TemplatePackApi2Fixture::COMMIT,
					'EXTRA_FILES_JSON' => '[{"type":"command","path":"build.sh"}]',
					'PACKAGE_SLUG'     => 'example-plugin',
				)
			)['code']
		);
	}

	#[Group( 'published-template-pack' )]
	public function testExactPublishedPackWhenExplicitlySupplied(): void {
		$path = getenv( 'RAN_TEMPLATE_PACK_ZIP' );
		if ( ! is_string( $path ) || '' === $path ) {
			self::markTestSkipped( 'Set RAN_TEMPLATE_PACK_ZIP for the separately downloaded immutable API 2 asset.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Optional explicit local immutable fixture.
		$archive = file_get_contents( $path );
		self::assertIsString( $archive );
		$identity = self::liveIdentity();
		$result   = TemplatePack::fromArchive( $archive, $identity );

		self::assertSame( 'ok', $result['code'] );
		$pack = $result['pack'];
		self::assertSame( '0.2.1', $pack->packVersion() );
		self::assertSame( $identity, $pack->identity() );
		self::assertSame( '10336df91984d333eb04af365389b1cb7f649381ebca18d5680b2b55099100e7', $pack->manifestHash() );

		$plugin = self::renderFixtureProfile( $pack, 'plugin' );
		$theme  = self::renderFixtureProfile( $pack, 'theme' );
		self::assertSame( 'd5709aead1246d3374b72bbb9503cbc38a4df0f29ea4cba88cb1ae68ecb13f16', $plugin['bundle_sha256'] );
		self::assertSame( '653f9a5b2100d759fd89f4fc710924caf44afd37ddb17edecaa3967eda340374', $theme['bundle_sha256'] );
		self::assertSame( $plugin, self::renderFixtureProfile( $pack, 'plugin' ) );
		self::assertSame( $theme, self::renderFixtureProfile( $pack, 'theme' ) );
		self::assertNotSame( $plugin['bundle_sha256'], $theme['bundle_sha256'] );
	}

	#[Group( 'published-template-pack' )]
	public function testExactHistoricalPublishedApi1PackIsRefusedWhenExplicitlySupplied(): void {
		$path = getenv( 'RAN_TEMPLATE_PACK_API1_ZIP' );
		if ( ! is_string( $path ) || '' === $path ) {
			self::markTestSkipped( 'Set RAN_TEMPLATE_PACK_API1_ZIP for the immutable historical API 1 asset.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Optional explicit local immutable fixture.
		$archive = file_get_contents( $path );
		self::assertIsString( $archive );
		$commit   = 'd1375ee8e38180b6483605779eb29b545f9cc061';
		$identity = self::publishedIdentity(
			$archive,
			367444791,
			'v0.2.0',
			$commit,
			507386990,
			self::API1_SHA
		);

		self::assertSame( 'template_pack_incompatible', TemplatePack::fromArchive( $archive, $identity )['code'] );
	}

	/** @return array{managed_files:array<string, string>,bundle_sha256:string} */
	public static function renderFixtureProfile( TemplatePack $pack, string $type ): array {
		$profile     = 'source-ready-wordpress-' . $type . '/2';
		$slug        = 'fixture-' . $type;
		$header      = 'plugin' === $type ? 'fixture-plugin.php' : 'style.css';
		$values      = array(
			'release-workflow'             => array(
				'DEFAULT_BRANCH' => 'main',
				'PACKAGE_SLUG'   => $slug,
			),
			'release-please-config'        => array(
				'BASE_SHA'         => TemplatePackApi2Fixture::COMMIT,
				'EXTRA_FILES_JSON' => '[{"type":"generic","path":"' . $header . '"},{"type":"json","path":"package.json","jsonpath":"$.version"}]',
				'PACKAGE_SLUG'     => $slug,
			),
			'build-release-script'         => array(
				'HEADER_PATH'  => $header,
				'PACKAGE_SLUG' => $slug,
				'PACKAGE_TYPE' => $type,
			),
			'verify-release-script'        => array(
				'HEADER_PATH'  => $header,
				'PACKAGE_SLUG' => $slug,
				'PACKAGE_TYPE' => $type,
				'UPDATE_URI'   => 'https://github.com/example/' . $slug,
			),
			'upload-release-assets-script' => array(),
		);
		$targets     = array(
			'release-workflow'             => '.github/workflows/release-please.yml',
			'release-please-config'        => 'release-please-config.json',
			'build-release-script'         => 'scripts/build-release.sh',
			'verify-release-script'        => 'scripts/verify-release.sh',
			'upload-release-assets-script' => 'scripts/upload-release-assets.sh',
		);
		$mappedPaths = array_values( array_unique( array_values( $targets ) ) );
		sort( $mappedPaths, SORT_STRING );
		if ( array_keys( $targets ) !== array_keys( $values ) || self::targetPaths() !== $mappedPaths ) {
			throw new \RuntimeException( 'Fixture logical-ID target map is invalid.' );
		}
		$managed = array();
		foreach ( $values as $logicalId => $placeholders ) {
			$rendered = $pack->render( $profile, $logicalId, $placeholders );
			if ( 'ok' !== $rendered['code'] ) {
				throw new \RuntimeException( 'Fixture render was refused.' );
			}
			$managed[ $targets[ $logicalId ] ] = $rendered['sha256'];
		}
		ksort( $managed, SORT_STRING );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Deterministic test-only digest input.
		$ledger = (string) json_encode( $managed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		return array(
			'managed_files' => $managed,
			'bundle_sha256' => hash( 'sha256', $ledger ),
		);
	}

	/** @return list<string> */
	private static function targetPaths(): array {
		$paths = array(
			'.github/workflows/release-please.yml',
			'release-please-config.json',
			'scripts/build-release.sh',
			'scripts/verify-release.sh',
			'scripts/upload-release-assets.sh',
		);
		sort( $paths, SORT_STRING );

		return $paths;
	}

	/** @return array<string, mixed> */
	private static function manifestWithWorkflowContent( string $content ): array {
		$manifest = TemplatePackApi2Fixture::manifest();
		foreach ( array( 'source-ready-wordpress-plugin/2', 'source-ready-wordpress-theme/2' ) as $profile ) {
			$manifest['profiles'][ $profile ]['entries']['release-workflow']['size']   = strlen( $content );
			$manifest['profiles'][ $profile ]['entries']['release-workflow']['sha256'] = hash( 'sha256', $content );
		}

		return $manifest;
	}

	private static function pseudoRandomAscii( int $length ): string {
		$content = '';
		$chunks  = intdiv( $length + 63, 64 );
		for ( $index = 0; $index < $chunks; ++$index ) {
			$content .= hash( 'sha256', 'ran-template-cap-' . $index );
		}

		return substr( $content, 0, $length );
	}

	/** @return array<string, mixed> */
	private static function liveIdentity(): array {
		$commit  = 'df5508fffe1f3e6770b1e7d5d4af5bf195e676c9';
		$archive = str_repeat( 'x', 13879 );

		return self::publishedIdentity( $archive, 368127015, 'v0.2.1', $commit, 509118185, self::LIVE_SHA, 13879 );
	}

	/** @return array<string, mixed> */
	private static function publishedIdentity(
		string $archive,
		int $releaseId,
		string $tag,
		string $commit,
		int $assetId,
		string $sha256,
		?int $knownSize = null
	): array {
		$size = $knownSize ?? strlen( $archive );

		return array(
			'repository_name'    => TemplatePackApi2Fixture::REPOSITORY,
			'repository_id'      => '1322743261',
			'release_id'         => $releaseId,
			'release_tag'        => $tag,
			'release_commit'     => $commit,
			'release_target'     => $commit,
			'tag_target'         => $commit,
			'release_draft'      => false,
			'release_prerelease' => false,
			'release_immutable'  => true,
			'asset_count'        => 1,
			'asset_id'           => $assetId,
			'asset_name'         => TemplatePackApi2Fixture::ASSET_NAME,
			'asset_state'        => 'uploaded',
			'asset_content_type' => 'application/zip',
			'asset_size'         => $size,
			'asset_digest'       => 'sha256:' . $sha256,
			'asset_sha256'       => $sha256,
		);
	}
}
