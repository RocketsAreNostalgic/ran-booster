<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use Closure;
use Throwable;

/** Fixed-purpose reader for the canonical public template-pack repository. */
final class TemplatePackRepositoryClient {

	private const API_ROOT         = 'https://api.github.com';
	private const REPOSITORY       = 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates';
	private const REPOSITORY_ID    = '1322743261';
	private const RELEASE_WINDOW   = 20;
	private const JSON_BODY_LIMIT  = 262144;
	private const ASSET_BODY_LIMIT = 2097152;
	private const ASSET_NAME       = 'ran-booster-release-bootstrap-templates.zip';

	/** @var Closure(string,string,array<string,mixed>):mixed */
	private Closure $send;

	public function __construct( ?callable $send = null ) {
		$this->send = null === $send
			? static fn ( string $method, string $url, array $args ): mixed => wp_safe_remote_request(
				$url,
				array_merge( $args, array( 'method' => $method ) )
			)
			: Closure::fromCallable( $send );
	}

	/**
	 * Select the highest stable immutable release supporting Consumer API 2.
	 *
	 * @return array{code:string, pack?:TemplatePack, newer_incompatible?:bool}
	 */
	public function discover( string $token = '' ): array {
		$repository = $this->repository( $token );
		if ( 'ok' !== $repository['code'] ) {
			return $repository;
		}
		$list = $this->jsonRequest(
			'/repos/' . self::REPOSITORY . '/releases?per_page=' . self::RELEASE_WINDOW . '&page=1',
			self::JSON_BODY_LIMIT,
			$token
		);
		if ( 'ok' !== $list['code'] ) {
			return $list;
		}
		if ( ! array_is_list( $list['data'] ) || count( $list['data'] ) > self::RELEASE_WINDOW ) {
			return $this->error( 'template_pack_invalid' );
		}

		$candidates = array();
		$versions   = array();
		foreach ( $list['data'] as $release ) {
			$candidate = $this->candidate( $release );
			if ( false === $candidate ) {
				continue;
			}
			if ( null === $candidate || isset( $versions[ $candidate['version'] ] ) ) {
				return $this->error( 'template_pack_invalid' );
			}
			$versions[ $candidate['version'] ] = true;
			$candidates[]                      = $candidate;
		}
		usort( $candidates, static fn ( array $left, array $right ): int => version_compare( $right['version'], $left['version'] ) );

		$newerIncompatible = false;
		foreach ( $candidates as $candidate ) {
			$result = $this->verifiedRelease( $candidate, $token );
			if ( 'template_pack_incompatible' === $result['code'] ) {
				$newerIncompatible = true;
				continue;
			}
			if ( 'ok' !== $result['code'] ) {
				return $result;
			}

			return array(
				'code'               => 'ok',
				'pack'               => $result['pack'],
				'newer_incompatible' => $newerIncompatible,
			);
		}

		return $this->error( $newerIncompatible ? 'template_pack_incompatible' : 'template_pack_unavailable' );
	}

	/**
	 * Re-fetch one preview-pinned release and reject any identity drift.
	 *
	 * @param array<string, mixed> $expectedIdentity
	 * @return array{code:string, pack?:TemplatePack}
	 */
	public function exact( array $expectedIdentity, string $token = '' ): array {
		if ( ! $this->expectedIdentityBelongsHere( $expectedIdentity ) ) {
			return $this->error( 'template_pack_changed' );
		}
		$repository = $this->repository( $token );
		if ( 'ok' !== $repository['code'] ) {
			return $repository;
		}
		$release = $this->jsonRequest(
			'/repos/' . self::REPOSITORY . '/releases/' . $expectedIdentity['release_id'],
			self::JSON_BODY_LIMIT,
			$token
		);
		if ( 'ok' !== $release['code'] ) {
			return $release;
		}
		$candidate = $this->candidate( $release['data'] );
		if ( ! is_array( $candidate ) || ! $this->candidateMatchesExpected( $candidate, $expectedIdentity ) ) {
			return $this->error( 'template_pack_changed' );
		}
		$result = $this->verifiedRelease( $candidate, $token );
		if ( 'ok' !== $result['code'] ) {
			return in_array( $result['code'], array( 'template_pack_incompatible', 'template_pack_unavailable' ), true )
				? $result
				: $this->error( 'template_pack_changed' );
		}

		return $result['pack']->identity() === $expectedIdentity ? $result : $this->error( 'template_pack_changed' );
	}

	/** @return array{code:string} */
	private function repository( string $token ): array {
		$response = $this->jsonRequest( '/repos/' . self::REPOSITORY, 65536, $token );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		$id   = $this->positiveNumericString( $response['data']['id'] ?? null );
		$name = $response['data']['full_name'] ?? null;
		return is_string( $name ) && hash_equals( self::REPOSITORY, $name ) && null !== $id && hash_equals( self::REPOSITORY_ID, $id )
			? $this->ok( array() )
			: $this->error( 'template_pack_changed' );
	}

	/**
	 * @param array<string, mixed> $candidate
	 * @return array{code:string, pack?:TemplatePack}
	 */
	private function verifiedRelease( array $candidate, string $token ): array {
		$release = $this->jsonRequest(
			'/repos/' . self::REPOSITORY . '/releases/' . $candidate['release_id'],
			self::JSON_BODY_LIMIT,
			$token
		);
		if ( 'ok' !== $release['code'] ) {
			return $release;
		}
		$exact = $this->candidate( $release['data'] );
		if ( ! is_array( $exact ) || $exact !== $candidate ) {
			return $this->error( 'template_pack_changed' );
		}
		$tag = $this->jsonRequest(
			'/repos/' . self::REPOSITORY . '/git/ref/tags/' . rawurlencode( $candidate['release_tag'] ),
			32768,
			$token
		);
		if ( 'ok' !== $tag['code'] ) {
			return $tag;
		}
		$tagType   = $tag['data']['object']['type'] ?? null;
		$tagTarget = $tag['data']['object']['sha'] ?? null;
		if ( 'commit' !== $tagType || ! is_string( $tagTarget ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $tagTarget ) ) {
			return $this->error( 'template_pack_invalid' );
		}

		$commit = $this->jsonRequest(
			'/repos/' . self::REPOSITORY . '/commits/' . rawurlencode( $candidate['release_tag'] ),
			32768,
			$token
		);
		if ( 'ok' !== $commit['code'] ) {
			return $commit;
		}
		$commitSha = $commit['data']['sha'] ?? null;
		if ( ! is_string( $commitSha ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $commitSha )
			|| ! hash_equals( $candidate['release_target'], $commitSha ) || ! hash_equals( $tagTarget, $commitSha ) ) {
			return $this->error( 'template_pack_invalid' );
		}
		$asset = $this->binaryRequest(
			'/repos/' . self::REPOSITORY . '/releases/assets/' . $candidate['asset_id'],
			$candidate['asset_size'],
			$token
		);
		if ( 'ok' !== $asset['code'] ) {
			return $asset;
		}
		if ( strlen( $asset['body'] ) !== $candidate['asset_size'] || ! hash_equals( $candidate['asset_sha256'], hash( 'sha256', $asset['body'] ) ) ) {
			return $this->error( 'template_pack_invalid' );
		}
		$identity = array(
			'repository_name'    => self::REPOSITORY,
			'repository_id'      => self::REPOSITORY_ID,
			'release_id'         => $candidate['release_id'],
			'release_tag'        => $candidate['release_tag'],
			'release_commit'     => $commitSha,
			'release_target'     => $candidate['release_target'],
			'tag_target'         => $tagTarget,
			'release_draft'      => false,
			'release_prerelease' => false,
			'release_immutable'  => true,
			'asset_count'        => $candidate['asset_count'],
			'asset_id'           => $candidate['asset_id'],
			'asset_name'         => $candidate['asset_name'],
			'asset_state'        => $candidate['asset_state'],
			'asset_content_type' => $candidate['asset_content_type'],
			'asset_size'         => $candidate['asset_size'],
			'asset_digest'       => $candidate['asset_digest'],
			'asset_sha256'       => $candidate['asset_sha256'],
		);

		return TemplatePack::fromArchive( $asset['body'], $identity );
	}

	/** @return array<string, int|string>|false|null False means intentionally ineligible. */
	private function candidate( mixed $release ): array|false|null {
		if ( ! is_array( $release ) || ! is_bool( $release['immutable'] ?? null ) || ! is_bool( $release['draft'] ?? null )
			|| ! is_bool( $release['prerelease'] ?? null ) ) {
			return null;
		}
		if ( true !== $release['immutable'] || true === $release['draft'] || true === $release['prerelease'] ) {
			return false;
		}
		$tag     = $release['tag_name'] ?? null;
		$version = is_string( $tag ) ? $this->versionFromTag( $tag ) : null;
		if ( is_string( $tag ) && null === $version ) {
			return false;
		}
		if ( null === $version ) {
			return null;
		}
		$releaseId = $this->positiveInt( $release['id'] ?? null );
		$target    = $release['target_commitish'] ?? null;
		$assets    = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$matches   = array_values(
			array_filter(
				$assets,
				fn ( mixed $asset ): bool => is_array( $asset ) && hash_equals( self::ASSET_NAME, (string) ( $asset['name'] ?? '' ) )
			)
		);
		if ( null === $releaseId || null === $version || ! is_string( $target )
			|| 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $target ) || 1 !== count( $assets ) || 1 !== count( $matches ) ) {
			return null;
		}
		$asset       = $matches[0];
		$assetId     = $this->positiveInt( $asset['id'] ?? null );
		$assetSize   = $this->positiveInt( $asset['size'] ?? null );
		$assetState  = $asset['state'] ?? null;
		$contentType = $asset['content_type'] ?? null;
		$digest      = is_string( $asset['digest'] ?? null ) ? $asset['digest'] : '';
		if ( null === $assetId || null === $assetSize || $assetSize > self::ASSET_BODY_LIMIT || 'uploaded' !== $assetState
			|| 'application/zip' !== $contentType || 1 !== preg_match( '/\Asha256:([a-f0-9]{64})\z/D', $digest, $digestMatch ) ) {
			return null;
		}

		return array(
			'release_id'         => $releaseId,
			'release_tag'        => $tag,
			'release_target'     => $target,
			'version'            => $version,
			'asset_count'        => 1,
			'asset_id'           => $assetId,
			'asset_name'         => self::ASSET_NAME,
			'asset_state'        => $assetState,
			'asset_content_type' => $contentType,
			'asset_size'         => $assetSize,
			'asset_digest'       => $digest,
			'asset_sha256'       => $digestMatch[1],
		);
	}

	/** @param array<string, mixed> $expected */
	private function expectedIdentityBelongsHere( array $expected ): bool {
		$keys = array(
			'repository_name',
			'repository_id',
			'release_id',
			'release_tag',
			'release_commit',
			'release_target',
			'tag_target',
			'release_draft',
			'release_prerelease',
			'release_immutable',
			'asset_count',
			'asset_id',
			'asset_name',
			'asset_state',
			'asset_content_type',
			'asset_size',
			'asset_digest',
			'asset_sha256',
		);
		return array_keys( $expected ) === $keys
			&& ( $expected['repository_name'] ?? null ) === self::REPOSITORY
			&& ( $expected['repository_id'] ?? null ) === self::REPOSITORY_ID
			&& is_int( $expected['release_id'] ?? null ) && $expected['release_id'] > 0
			&& is_string( $expected['release_tag'] ?? null ) && null !== $this->versionFromTag( $expected['release_tag'] )
			&& is_string( $expected['release_commit'] ?? null ) && 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $expected['release_commit'] )
			&& hash_equals( $expected['release_commit'], (string) ( $expected['release_target'] ?? '' ) )
			&& hash_equals( $expected['release_commit'], (string) ( $expected['tag_target'] ?? '' ) )
			&& false === ( $expected['release_draft'] ?? null ) && false === ( $expected['release_prerelease'] ?? null )
			&& true === ( $expected['release_immutable'] ?? null ) && 1 === ( $expected['asset_count'] ?? null )
			&& is_int( $expected['asset_id'] ?? null ) && $expected['asset_id'] > 0
			&& ( $expected['asset_name'] ?? null ) === self::ASSET_NAME
			&& 'uploaded' === ( $expected['asset_state'] ?? null ) && 'application/zip' === ( $expected['asset_content_type'] ?? null )
			&& is_int( $expected['asset_size'] ?? null ) && $expected['asset_size'] > 0 && $expected['asset_size'] <= self::ASSET_BODY_LIMIT
			&& is_string( $expected['asset_sha256'] ?? null ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $expected['asset_sha256'] )
			&& 'sha256:' . $expected['asset_sha256'] === ( $expected['asset_digest'] ?? null );
	}

	/** @param array<string, int|string> $candidate @param array<string, mixed> $expected */
	private function candidateMatchesExpected( array $candidate, array $expected ): bool {
		return $candidate['release_id'] === $expected['release_id']
			&& hash_equals( $candidate['release_tag'], $expected['release_tag'] )
			&& hash_equals( $candidate['release_target'], $expected['release_target'] )
			&& $candidate['asset_count'] === $expected['asset_count']
			&& $candidate['asset_id'] === $expected['asset_id']
			&& hash_equals( $candidate['asset_name'], $expected['asset_name'] )
			&& hash_equals( $candidate['asset_state'], $expected['asset_state'] )
			&& hash_equals( $candidate['asset_content_type'], $expected['asset_content_type'] )
			&& $candidate['asset_size'] === $expected['asset_size']
			&& hash_equals( $candidate['asset_digest'], $expected['asset_digest'] )
			&& hash_equals( $candidate['asset_sha256'], $expected['asset_sha256'] );
	}

	/** @return array{code:string, data?:array<string,mixed>|list<mixed>} */
	private function jsonRequest( string $path, int $limit, string $token ): array {
		$response = $this->request( $path, 'application/vnd.github+json', $limit, 0, $token );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		try {
			$data = json_decode( $response['body'], true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( Throwable ) {
			return $this->error( 'template_pack_invalid' );
		}

		return is_array( $data ) ? $this->ok( array( 'data' => $data ) ) : $this->error( 'template_pack_invalid' );
	}

	/** @return array{code:string, body?:string} */
	private function binaryRequest( string $path, int $expectedSize, string $token ): array {
		return $this->request( $path, 'application/octet-stream', min( self::ASSET_BODY_LIMIT, $expectedSize + 1 ), 3, $token );
	}

	/** @return array{code:string, body?:string} */
	private function request( string $path, string $accept, int $limit, int $redirects, string $token ): array {
		if ( ! str_starts_with( $path, '/repos/' ) || $limit < 1 || $limit > self::ASSET_BODY_LIMIT ) {
			return $this->error( 'template_pack_invalid' );
		}
		$args = array(
			'headers'             => array(
				'Accept'               => $accept,
				'X-GitHub-Api-Version' => '2026-03-10',
				'User-Agent'           => 'RAN-Booster-Release-Deployments',
			),
			'timeout'             => 15,
			'redirection'         => $redirects,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => $limit,
		);
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		try {
			$response = ( $this->send )( 'GET', self::API_ROOT . $path, $args );
		} catch ( Throwable ) {
			return $this->error( 'template_pack_unavailable' );
		}
		if ( ! is_array( $response ) ) {
			return $this->error( 'template_pack_unavailable' );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		if ( 404 === $status ) {
			return $this->error( 'template_pack_changed' );
		}
		if ( 429 === $status || 403 === $status || $status < 200 || $status >= 300 || strlen( $body ) > $limit ) {
			return $this->error( 'template_pack_unavailable' );
		}

		return $this->ok( array( 'body' => $body ) );
	}

	private function positiveInt( mixed $value ): ?int {
		$value = is_int( $value ) || is_string( $value ) ? (string) $value : '';
		if ( 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $value ) || strlen( $value ) > 18 ) {
			return null;
		}
		$number = (int) $value;
		return $number > 0 && (string) $number === $value ? $number : null;
	}

	private function positiveNumericString( mixed $value ): ?string {
		$value = is_int( $value ) || is_string( $value ) ? (string) $value : '';
		return strlen( $value ) <= 191 && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value ) ? $value : null;
	}

	private function versionFromTag( string $tag ): ?string {
		$version = str_starts_with( $tag, 'v' ) ? substr( $tag, 1 ) : $tag;
		return 1 === preg_match( '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D', $version ) ? $version : null;
	}

	/** @param array<string, mixed> $values @return array<string, mixed> */
	private function ok( array $values ): array {
		return array_merge( array( 'code' => 'ok' ), $values );
	}

	/** @return array{code:string} */
	private function error( string $code ): array {
		return array( 'code' => $code );
	}
}
