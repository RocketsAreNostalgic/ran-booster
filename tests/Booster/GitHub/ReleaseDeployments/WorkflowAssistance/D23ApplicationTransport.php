<?php

declare(strict_types=1);

namespace Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use Tests\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\Support\TemplatePackApi2Fixture;
use function RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\wp_json_encode;
use function RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance\wp_parse_url;

final class D23ApplicationTransport {
	private const REPOSITORY  = 'owner/example-plugin';
	private const BASE        = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const BASE_TREE   = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	private const HEAD        = 'cccccccccccccccccccccccccccccccccccccccc';
	private const HEAD_TREE   = 'dddddddddddddddddddddddddddddddddddddddd';
	private const UPDATE_HEAD = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
	private const UPDATE_TREE = 'ffffffffffffffffffffffffffffffffffffffff';
	/** @var list<array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @var array<string,int> */
	public array $writeCounts     = array(
		'blob'   => 0,
		'tree'   => 0,
		'commit' => 0,
		'ref'    => 0,
		'pull'   => 0,
	);
	private array $blobs          = array();
	private array $baseEntries    = array();
	private array $headEntries    = array();
	private bool $branchExists    = false;
	private bool $pullExists      = false;
	private string $baseSha       = self::BASE;
	private string $pullState     = 'open';
	private ?string $mergedAt     = null;
	private string $pullBaseSha   = self::BASE;
	private string $pullScenario  = 'none';
	private string $uncertainAt   = '';
	private string $uncertainBlob = '';
	private string $branchHead    = '';
	private string $createdTree   = self::HEAD_TREE;
	/** @var array<int,string> */
	private array $archives    = array();
	private int $latestRelease = TemplatePackApi2Fixture::RELEASE_ID;

	public function __construct( private readonly bool $lostAcknowledgements = false, string $packageType = 'plugin' ) {
		$this->archives[ TemplatePackApi2Fixture::RELEASE_ID ] = TemplatePackApi2Fixture::archive();
		if ( 'theme' === $packageType ) {
			$this->addBase( 'style.css', "/*\nTheme Name: Example Theme\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/example-plugin\n*/\n" );
			$this->addBase( 'templates/index.html', '<!-- wp:paragraph --><p>Theme</p><!-- /wp:paragraph -->' );
		} else {
			$this->addBase( 'example-plugin.php', "<?php\n/**\n * Plugin Name: Example Plugin\n * Version: 1.2.3\n * Update URI: https://github.com/owner/example-plugin\n */\n" );
			$this->addBase( 'src/Runtime.php', "<?php\nnamespace Example;\n" );
		}
	}
	public function mergePull(): void {
		$this->baseSha     = $this->branchHead;
		$this->baseEntries = $this->headEntries;
		$this->pullBaseSha = $this->pullBaseSha;
		$this->pullState   = 'closed';
		$this->mergedAt    = '2026-08-11T12:00:00Z';
	}
	public function offerTemplateUpdate(): void {
		$manifest                  = TemplatePackApi2Fixture::manifest( 2, '1.2.4' );
		$manifest['release']['id'] = 42;
		$this->archives[42]        = TemplatePackApi2Fixture::archive( $manifest );
		$this->latestRelease       = 42;
		$this->branchExists        = false;
		$this->pullExists          = false;
		$this->pullState           = 'open';
		$this->mergedAt            = null;
		$this->pullScenario        = 'none';
		$this->pullBaseSha         = $this->baseSha;
	}
	public function closePull(): void {
		$this->pullState = 'closed';
		$this->mergedAt  = null;
	}
	public function reopenPull(): void {
		$this->pullState = 'open';
		$this->mergedAt  = null;
	}
	public function driftPullBase(): void {
		$this->pullBaseSha = str_repeat( '9', 40 );
	}
	public function mutateDefaultDocument( string $path, string $content ): void {
		$this->addBase( $path, $content );
	}
	public function removeDefaultDocument( string $path ): void {
		unset( $this->baseEntries[ $path ], $this->headEntries[ $path ] );
	}
	/** @param callable(array<string,mixed>):array<string,mixed> $mutate */
	public function mutateReceipt( callable $mutate ): string {
		$entry   = $this->baseEntries['.ran-booster-release-profile.json'];
		$receipt = json_decode( $this->blobs[ $entry['sha'] ], true );
		$receipt = $mutate( is_array( $receipt ) ? $receipt : array() );
		$bytes   = (string) wp_json_encode( $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
		$this->addBase( '.ran-booster-release-profile.json', $bytes );
		return $bytes;
	}
	public function seedPullScenario( string $scenario ): void {
		$this->pullScenario = $scenario;
	}
	public function failWriteAcknowledgement( string $operation ): void {
		$this->uncertainAt = $operation;
	}
	/** @param array<string,mixed> $args */
	public function __invoke( string $method, string $url, array $args ): array {
		$this->requests[] = compact( 'method', 'url', 'args' );
		$path             = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query            = (string) wp_parse_url( $url, PHP_URL_QUERY );
		if ( str_contains( $path, '/ran-booster-release-bootstrap-templates' ) ) {
			return $this->template( $path );
		}
		if ( '/repos/' . self::REPOSITORY === $path ) {
			return $this->json(
				200,
				array(
					'id'             => 101,
					'full_name'      => self::REPOSITORY,
					'default_branch' => 'main',
				)
			);
		}
		if ( str_starts_with( $path, '/repos/' . self::REPOSITORY . '/git/ref/heads/' ) ) {
			$branch = rawurldecode( substr( $path, strrpos( $path, '/' ) + 1 ) );
			if ( 'main' === $branch ) {
				return $this->json(
					200,
					array(
						'ref'    => 'refs/heads/main',
						'object' => array( 'sha' => $this->baseSha ),
					)
				);
			}
			return $this->branchExists ? $this->json(
				200,
				array(
					'ref'    => 'refs/heads/' . $branch,
					'object' => array( 'sha' => $this->branchHead ),
				)
			) : $this->json( 404, array() );
		}
		if ( str_starts_with( $path, '/repos/' . self::REPOSITORY . '/git/commits/' ) && 'GET' === $method ) {
			$sha = basename( $path );
			return $this->json(
				200,
				array(
					'sha'     => $sha,
					'tree'    => array( 'sha' => self::BASE === $sha ? self::BASE_TREE : ( self::HEAD === $sha ? self::HEAD_TREE : self::UPDATE_TREE ) ),
					'parents' => self::BASE === $sha ? array() : array( array( 'sha' => self::HEAD === $sha ? self::BASE : self::HEAD ) ),
				)
			);
		}
		if ( str_contains( $path, '/git/trees/' ) && 'GET' === $method ) {
			$sha     = basename( $path );
			$entries = in_array( $sha, array( self::HEAD, self::UPDATE_HEAD ), true ) ? $this->headEntries : $this->baseEntries;
			return $this->json(
				200,
				array(
					'truncated' => false,
					'tree'      => array_values( $entries ),
				)
			);
		}
		if ( str_contains( $path, '/git/blobs/' ) && 'GET' === $method ) {
			if ( '' !== $this->uncertainBlob && hash_equals( $this->uncertainBlob, basename( $path ) ) ) {
				return $this->json( 500, array() );
			}
			$content = $this->blobs[ basename( $path ) ] ?? '';
			return $this->json(
				200,
				array(
					'encoding' => 'base64',
					'size'     => strlen( $content ),
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub blob fixture encoding.
					'content'  => base64_encode( $content ),
				)
			);
		}
		if ( str_ends_with( $path, '/git/blobs' ) && 'POST' === $method ) {
			++$this->writeCounts['blob'];
			$body                = $this->body( $args );
			$sha                 = sha1( 'blob ' . strlen( $body['content'] ) . "\0" . $body['content'] );
			$this->blobs[ $sha ] = $body['content'];
			if ( 'blob' === $this->uncertainAt && 1 === $this->writeCounts['blob'] ) {
				$this->uncertainBlob = $sha;
				return $this->json( 500, array() );
			}
			return $this->json( 201, array( 'sha' => $sha ) );
		}
		if ( str_ends_with( $path, '/git/trees' ) && 'POST' === $method ) {
			++$this->writeCounts['tree'];
			$this->createdTree = self::HEAD === $this->baseSha ? self::UPDATE_TREE : self::HEAD_TREE;
			$this->headEntries = $this->baseEntries;
			foreach ( $this->body( $args )['tree'] as $entry ) {
				$content                             = $this->blobs[ $entry['sha'] ];
				$this->headEntries[ $entry['path'] ] = array(
					'path' => $entry['path'],
					'type' => 'blob',
					'mode' => $entry['mode'],
					'sha'  => $entry['sha'],
					'size' => strlen( $content ),
				);
			}
			return 'tree' === $this->uncertainAt ? $this->json( 500, array() ) : $this->json( 201, array( 'sha' => $this->createdTree ) );
		}
		if ( str_ends_with( $path, '/git/commits' ) && 'POST' === $method ) {
			++$this->writeCounts['commit'];
			$head = self::HEAD === $this->baseSha ? self::UPDATE_HEAD : self::HEAD;
			return 'commit' === $this->uncertainAt ? $this->json( 500, array() ) : $this->json( 201, array( 'sha' => $head ) );
		}
		if ( str_ends_with( $path, '/git/refs' ) && 'POST' === $method ) {
			++$this->writeCounts['ref'];
			$this->branchExists = true;
			$body               = $this->body( $args );
			$ref                = $body['ref'];
			$this->branchHead   = $body['sha'];
			return $this->lostAcknowledgements ? $this->json( 500, array() ) : $this->json(
				201,
				array(
					'ref'    => $ref,
					'object' => array( 'sha' => $this->branchHead ),
				)
			);
		}
		if ( str_ends_with( $path, '/pulls' ) && 'GET' === $method ) {
			parse_str( $query, $parameters );
			$head  = is_string( $parameters['head'] ?? null ) ? explode( ':', $parameters['head'], 2 )[1] : '';
			$pulls = match ( $this->pullScenario ) {
				'closed' => array( $this->pull( $head, 'closed' ) ),
				'wrong_base' => array( $this->pull( $head, 'open', 'develop' ) ),
				'duplicate' => array( $this->pull( $head ), $this->pull( $head ) ),
				default => $this->pullExists ? array( $this->pull() ) : array(),
			};
			return $this->json( 200, $pulls );
		}
		if ( str_ends_with( $path, '/pulls' ) && 'POST' === $method ) {
			++$this->writeCounts['pull'];
			$this->pullExists = true;
			return $this->lostAcknowledgements ? $this->json( 500, array() ) : $this->json( 201, $this->pull() );
		}
		if ( str_ends_with( $path, '/pulls/17' ) ) {
			return $this->json( 200, $this->pull() );
		}
		if ( str_ends_with( $path, '/pulls/17/files' ) ) {
			$files = array();
			foreach ( $this->headEntries as $pathName => $entry ) {
				if ( ! isset( $this->baseEntries[ $pathName ] ) || $this->baseEntries[ $pathName ]['sha'] !== $entry['sha'] ) {
					$files[] = array(
						'filename' => $pathName,
						'status'   => isset( $this->baseEntries[ $pathName ] ) ? 'modified' : 'added',
						'sha'      => $entry['sha'],
					);
				}
			}
			return $this->json( 200, $files );
		}
		return $this->json( 500, array( 'unexpected' => $method . ' ' . $path . '?' . $query ) );
	}
	private function template( string $path ): array {
		$releaseId = $this->latestRelease;
		if ( 1 === preg_match( '#/releases/([0-9]+)\z#', $path, $match ) ) {
			$releaseId = (int) $match[1];
		}
		$archive = $this->archives[ $releaseId ] ?? '';
		$release = array(
			'id'               => $releaseId,
			'tag_name'         => 42 === $releaseId ? 'v1.2.4' : 'v1.2.3',
			'target_commitish' => TemplatePackApi2Fixture::COMMIT,
			'draft'            => false,
			'prerelease'       => false,
			'immutable'        => true,
			'assets'           => array(
				array(
					'id'           => 42 === $releaseId ? 74 : TemplatePackApi2Fixture::ASSET_ID,
					'name'         => TemplatePackApi2Fixture::ASSET_NAME,
					'size'         => strlen( $archive ),
					'state'        => 'uploaded',
					'content_type' => 'application/zip',
					'digest'       => 'sha256:' . hash( 'sha256', $archive ),
				),
			),
		);
		if ( str_ends_with( $path, '/ran-booster-release-bootstrap-templates' ) ) {
			return $this->json(
				200,
				array(
					'id'        => TemplatePackApi2Fixture::REPOSITORY_ID,
					'full_name' => TemplatePackApi2Fixture::REPOSITORY,
				)
			);
		}
		if ( str_ends_with( $path, '/releases' ) ) {
			$releases = array( $release );
			if ( 42 === $releaseId ) {
				$old                 = $this->latestRelease;
				$this->latestRelease = TemplatePackApi2Fixture::RELEASE_ID;
				$oldRelease          = $this->template( '/releases/' . TemplatePackApi2Fixture::RELEASE_ID );
				$this->latestRelease = $old;
				$oldBody             = json_decode( $oldRelease['body'], true );
				if ( is_array( $oldBody ) ) {
					$releases[] = $oldBody;
				}
			}
			return $this->json( 200, $releases );
		}
		if ( str_contains( $path, '/releases/' ) && ! str_contains( $path, '/assets/' ) ) {
			return $this->json( 200, $release );
		}
		if ( str_contains( $path, '/git/ref/tags/' ) ) {
			return $this->json(
				200,
				array(
					'object' => array(
						'type' => 'commit',
						'sha'  => TemplatePackApi2Fixture::COMMIT,
					),
				)
			);
		}
		if ( str_contains( $path, '/commits/' ) ) {
			return $this->json( 200, array( 'sha' => TemplatePackApi2Fixture::COMMIT ) );
		}
		if ( str_contains( $path, '/releases/assets/' ) ) {
			$assetId = (int) basename( $path );
			$archive = 74 === $assetId ? ( $this->archives[42] ?? '' ) : $this->archives[ TemplatePackApi2Fixture::RELEASE_ID ];
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => $archive,
			);
		}
		return $this->json( 500, array() );
	}
	private function addBase( string $path, string $content ): void {
		$sha                        = sha1( 'blob ' . strlen( $content ) . "\0" . $content );
		$this->blobs[ $sha ]        = $content;
		$this->baseEntries[ $path ] = array(
			'path' => $path,
			'type' => 'blob',
			'mode' => '100644',
			'sha'  => $sha,
			'size' => strlen( $content ),
		);
		$this->headEntries          = $this->baseEntries;
	}
	/** @param array<string,mixed> $args @return array<string,mixed> */
	private function body( array $args ): array {
		$value = json_decode( (string) $args['body'], true );
		return is_array( $value ) ? $value : array();
	}
	private function pull( string $name = '', string $state = '', string $base = 'main' ): array {
		$branch  = array_values( array_filter( $this->requests, static fn ( array $request ): bool => str_ends_with( (string) wp_parse_url( $request['url'], PHP_URL_PATH ), '/git/refs' ) ) );
		$refBody = array() !== $branch ? $this->body( $branch[ array_key_last( $branch ) ]['args'] ) : array( 'ref' => 'refs/heads/ran-booster/release-setup-v2-aaaaaaaaaaaa-unknown' );
		$name    = '' !== $name ? $name : substr( $refBody['ref'], strlen( 'refs/heads/' ) );
		$state   = '' !== $state ? $state : $this->pullState;
		return array(
			'number'    => 17,
			'state'     => $state,
			'draft'     => true,
			'merged_at' => $this->mergedAt,
			'head'      => array(
				'ref'  => $name,
				'sha'  => '' !== $this->branchHead ? $this->branchHead : self::HEAD,
				'repo' => array( 'full_name' => self::REPOSITORY ),
			),
			'base'      => array(
				'ref'  => $base,
				'sha'  => $this->pullBaseSha,
				'repo' => array( 'full_name' => self::REPOSITORY ),
			),
		);
	}
	/** @param array<string,mixed> $body */
	private function json( int $status, array $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}
}
