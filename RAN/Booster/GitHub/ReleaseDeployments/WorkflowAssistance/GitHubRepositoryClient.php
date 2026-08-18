<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use Closure;
use Throwable;
/** Fixed-scope GitHub operations for assisted workflow setup. */
final class GitHubRepositoryClient {
	private const API_ROOT      = 'https://api.github.com';
	private const MAX_BODY      = 262144;
	private const MAX_TREE      = 2000;
	private const MAX_DOCUMENTS = 256;
	private const MAX_CHANGES   = 32;
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

	public function repository( string $repository, string $token = '' ): array {
		if ( ! $this->validRepository( $repository ) ) {
			return $this->error( 'invalid_request' );
		}
		$response      = $this->request( 'GET', '/repos/' . $repository, $token );
		$data          = $response['data'] ?? array();
		$repositoryId  = $this->numericString( $data['id'] ?? null );
		$fullName      = $data['full_name'] ?? null;
		$defaultBranch = $data['default_branch'] ?? null;
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		if ( null === $repositoryId || ! $this->validRepository( $fullName ) || ! $this->validBranch( $defaultBranch ) ) {
			return $this->error( 'invalid_response' );
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact bounded transport shape.
		return $this->ok( array( 'repository_id' => $repositoryId, 'full_name' => $fullName, 'default_branch' => $defaultBranch ) );
	}
	public function branchRef( string $repository, string $branch, string $token = '' ): array {
		if ( ! $this->validRepository( $repository ) || ! $this->validBranch( $branch ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/git/ref/heads/' . rawurlencode( $branch ), $token, null, array( 404 => 'missing' ) );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		$sha = $response['data']['object']['sha'] ?? null;
		$ref = $response['data']['ref'] ?? null;
		return $this->validSha( $sha ) && is_string( $ref ) && hash_equals( 'refs/heads/' . $branch, $ref )
			? $this->ok( array( 'sha' => $sha ) )
			: $this->error( 'invalid_response' );
	}
	public function snapshot(
		string $repository,
		string $repositoryId,
		string $defaultBranch,
		string $sha,
		string $token = ''
	): array {
		if ( ! $this->validRepository( $repository ) || null === $this->numericString( $repositoryId )
			|| ! $this->validBranch( $defaultBranch ) || ! $this->validSha( $sha ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/git/trees/' . $sha . '?recursive=1', $token );
		$data     = $response['data'] ?? array();
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		if ( true === ( $data['truncated'] ?? null ) || ! is_array( $data['tree'] ?? null )
			|| count( $data['tree'] ) > self::MAX_TREE ) {
			return $this->error( 'invalid_response' );
		}
		$entries    = array();
		$candidates = array();
		foreach ( $data['tree'] as $item ) {
			if ( ! is_array( $item ) || ! $this->validPath( $item['path'] ?? null )
				|| isset( $entries[ $item['path'] ] )
				|| ! in_array( $item['type'] ?? null, array( 'blob', 'tree' ), true )
				|| ! in_array( $item['mode'] ?? null, array( '100644', '100755', '040000' ), true )
				|| ! $this->validSha( $item['sha'] ?? null ) ) {
				return $this->error( 'invalid_response' );
			}
			$size = $item['size'] ?? 0;
			if ( ! is_int( $size ) || $size < 0 ) {
				return $this->error( 'invalid_response' );
			}
			$path             = $item['path'];
			$entries[ $path ] = array(
				'type' => $item['type'],
				'mode' => $item['mode'],
				'sha'  => $item['sha'],
				'size' => $size,
			);
			if ( 'blob' === $item['type'] && $this->assessmentDocument( $path ) ) {
				$candidates[ $path ] = $item['sha'];
			}
		}
		if ( count( $candidates ) > self::MAX_DOCUMENTS ) {
			return $this->error( 'invalid_response' );
		}
		$documents = array();
		foreach ( $candidates as $path => $blobSha ) {
			$blob = $this->blob( $repository, $blobSha, $token );
			if ( 'ok' !== $blob['code'] ) {
				return $blob;
			}
			$documents[ $path ] = $blob['content'];
		}

		try {
			$snapshot = new RepositorySnapshot( $repositoryId, $repository, $defaultBranch, $sha, $entries, $documents );
		} catch ( Throwable ) {
			return $this->error( 'invalid_response' );
		}
		return $this->ok( array( 'snapshot' => $snapshot ) );
	}
	public function blob( string $repository, string $sha, string $token = '' ): array {
		if ( ! $this->validRepository( $repository ) || ! $this->validSha( $sha ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/git/blobs/' . $sha, $token );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		$data    = $response['data'];
		$encoded = $data['content'] ?? null;
		if ( 'base64' !== ( $data['encoding'] ?? null ) || ! is_string( $encoded ) ) {
			return $this->error( 'invalid_response' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- GitHub Git Data encoding.
		$content = base64_decode( preg_replace( '/\s+/', '', $encoded ) ?? '', true );
		$size    = $data['size'] ?? null;
		if ( ! is_string( $content ) || ! is_int( $size ) || $size !== strlen( $content )
			|| $size > self::MAX_BODY || str_contains( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
			return $this->error( 'invalid_response' );
		}
		return $this->ok( array( 'content' => $content ) );
	}
	public function gitCommit( string $repository, string $sha, string $token = '' ): array {
		if ( ! $this->validRepository( $repository ) || ! $this->validSha( $sha ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/git/commits/' . $sha, $token );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		$data    = $response['data'];
		$treeSha = $data['tree']['sha'] ?? null;
		$parents = $data['parents'] ?? null;
		if ( ! $this->validSha( $data['sha'] ?? null ) || ! hash_equals( $sha, $data['sha'] )
			|| ! $this->validSha( $treeSha ) || ! is_array( $parents ) || count( $parents ) > 2 ) {
			return $this->error( 'invalid_response' );
		}
		$parentShas = array();
		foreach ( $parents as $parent ) {
			if ( ! is_array( $parent ) || ! $this->validSha( $parent['sha'] ?? null ) ) {
				return $this->error( 'invalid_response' );
			}
			$parentShas[] = $parent['sha'];
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact bounded transport shape.
		return $this->ok( array( 'sha' => $sha, 'tree_sha' => $treeSha, 'parents' => $parentShas ) );
	}
	public function createBlob( string $repository, string $content, string $token ): array {
		if ( '' === $token || ! $this->validRepository( $repository ) || strlen( $content ) > self::MAX_BODY
			|| str_contains( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request(
			'POST',
			'/repos/' . $repository . '/git/blobs',
			$token,
			array(
				'content'  => $content,
				'encoding' => 'utf-8',
			)
		);
		$sha      = $response['data']['sha'] ?? null;
		return 'ok' !== $response['code'] ? $response : ( $this->validSha( $sha ) ? $this->ok( array( 'sha' => $sha ) ) : $this->error( 'invalid_response' ) );
	}
	/** @param list<array{path:string,sha:string,mode:string}> $entries */
	public function createTree( string $repository, string $baseTreeSha, array $entries, string $token ): array {
		if ( '' === $token || ! $this->validRepository( $repository ) || ! $this->validSha( $baseTreeSha )
			|| array() === $entries || count( $entries ) > self::MAX_CHANGES ) {
			return $this->error( 'invalid_request' );
		}
		$tree = array();
		$seen = array();
		foreach ( $entries as $entry ) {
			$path = $entry['path'] ?? null;
			if ( ! $this->validPath( $path ) || isset( $seen[ $path ] ) || ! $this->validSha( $entry['sha'] ?? null )
				|| ! in_array( $entry['mode'] ?? null, array( '100644', '100755' ), true ) ) {
				return $this->error( 'invalid_request' );
			}
			$seen[ $path ] = true;
			$tree[]        = array(
				'path' => $path,
				'mode' => $entry['mode'],
				'type' => 'blob',
				'sha'  => $entry['sha'],
			);
		}
		$response = $this->request(
			'POST',
			'/repos/' . $repository . '/git/trees',
			$token,
			array(
				'base_tree' => $baseTreeSha,
				'tree'      => $tree,
			)
		);
		$sha      = $response['data']['sha'] ?? null;
		return 'ok' !== $response['code'] ? $response : ( $this->validSha( $sha ) ? $this->ok( array( 'sha' => $sha ) ) : $this->error( 'invalid_response' ) );
	}
	public function createCommit( string $repository, string $treeSha, string $parentSha, string $message, string $token ): array {
		if ( '' === $token || ! $this->validRepository( $repository ) || ! $this->validSha( $treeSha ) || ! $this->validSha( $parentSha )
			|| '' === trim( $message ) || strlen( $message ) > 200 ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request(
			'POST',
			'/repos/' . $repository . '/git/commits',
			$token,
			array(
				'message' => $message,
				'tree'    => $treeSha,
				'parents' => array( $parentSha ),
			)
		);
		$sha      = $response['data']['sha'] ?? null;
		return 'ok' !== $response['code'] ? $response : ( $this->validSha( $sha ) ? $this->ok( array( 'sha' => $sha ) ) : $this->error( 'invalid_response' ) );
	}
	public function createRef( string $repository, string $branch, string $defaultBranch, string $sha, string $token ): array {
		if ( '' === $token || ! $this->validTargetBranch( $repository, $branch, $defaultBranch ) || ! $this->validSha( $sha ) ) {
			return $this->error( 'invalid_request' );
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Fixed GitHub request shape.
		$response = $this->request( 'POST', '/repos/' . $repository . '/git/refs', $token, array( 'ref' => 'refs/heads/' . $branch, 'sha' => $sha ), array( 422 => 'conflict' ) );
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		$createdSha = $response['data']['object']['sha'] ?? null;
		$createdRef = $response['data']['ref'] ?? null;
		return $this->validSha( $createdSha ) && hash_equals( $sha, $createdSha ) && is_string( $createdRef ) && hash_equals( 'refs/heads/' . $branch, $createdRef )
			? $this->ok( array( 'sha' => $createdSha ) )
			: $this->error( 'invalid_response' );
	}
	public function pullRequests( string $repository, string $branch, string $token = '' ): array {
		if ( ! $this->validRepository( $repository ) || ! $this->validBranch( $branch ) ) {
			return $this->error( 'invalid_request' );
		}
		$owner = explode( '/', $repository, 2 )[0];
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Fixed GitHub query shape.
		$query    = http_build_query( array( 'state' => 'all', 'head' => $owner . ':' . $branch, 'per_page' => 10 ), '', '&', PHP_QUERY_RFC3986 );
		$response = $this->request( 'GET', '/repos/' . $repository . '/pulls?' . $query, $token );
		$data     = $response['data'] ?? array();
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		if ( ! array_is_list( $data ) || count( $data ) > 10 ) {
			return $this->error( 'invalid_response' );
		}
		$pulls = array();
		foreach ( $data as $pull ) {
			$normalized = $this->normalizePull( $repository, $pull );
			if ( null === $normalized || ! hash_equals( $branch, $normalized['head'] ) ) {
				return $this->error( 'invalid_response' );
			}
			$pulls[] = $normalized;
		}
		return $this->ok( array( 'pulls' => $pulls ) );
	}
	public function pullRequest( string $repository, int $number, string $token = '' ): array {
		if ( ! $this->validRepository( $repository ) || $number < 1 ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/pulls/' . $number, $token, null, array( 404 => 'missing' ) );
		$pull     = $this->normalizePull( $repository, $response['data'] ?? null );
		return 'ok' !== $response['code'] ? $response : ( null === $pull
			? $this->error( 'invalid_response' )
			: $this->ok( array( 'pull' => $pull ) ) );
	}
	public function pullRequestFileSet( string $repository, int $number, string $token = '' ): array {
		if ( ! $this->validRepository( $repository ) || $number < 1 ) {
			return $this->error( 'invalid_request' );
		}
		$response = $this->request( 'GET', '/repos/' . $repository . '/pulls/' . $number . '/files?per_page=100', $token );
		$files    = $response['data'] ?? null;
		if ( 'ok' !== $response['code'] ) {
			return $response;
		}
		if ( ! array_is_list( $files ) || count( $files ) > self::MAX_CHANGES || count( $files ) >= 100 ) {
			return $this->error( 'invalid_response' );
		}
		$normalized = array();
		$seen       = array();
		foreach ( $files as $file ) {
			$path = $file['filename'] ?? null;
			if ( ! is_array( $file ) || ! $this->validPath( $path ) || isset( $seen[ $path ] )
				|| ! in_array( $file['status'] ?? null, array( 'added', 'modified' ), true )
				|| ! $this->validSha( $file['sha'] ?? null ) || isset( $file['previous_filename'] ) ) {
				return $this->error( 'invalid_response' );
			}
			$seen[ $path ] = true;
			$normalized[]  = array(
				'path'   => $path,
				'status' => $file['status'],
				'sha'    => $file['sha'],
			);
		}
		usort( $normalized, static fn ( array $left, array $right ): int => strcmp( $left['path'], $right['path'] ) );
		return $this->ok( array( 'files' => $normalized ) );
	}
	public function createDraftPullRequest( string $repository, string $branch, string $defaultBranch, string $title, string $body, string $token ): array {
		if ( '' === $token || ! $this->validTargetBranch( $repository, $branch, $defaultBranch ) || '' === trim( $title )
			|| strlen( $title ) > 120 || strlen( $body ) > 8000
			|| 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $title . $body ) ) {
			return $this->error( 'invalid_request' );
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Fixed GitHub request shape.
		$response = $this->request( 'POST', '/repos/' . $repository . '/pulls', $token, array( 'title' => $title, 'head' => $branch, 'base' => $defaultBranch, 'body' => $body, 'draft' => true ), array( 422 => 'conflict' ) );
		$pull     = $this->normalizePull( $repository, $response['data'] ?? null );
		return 'ok' !== $response['code'] ? $response : ( null === $pull || ! $pull['draft'] || ! hash_equals( $branch, $pull['head'] ) || ! hash_equals( $defaultBranch, $pull['base'] )
			? $this->error( 'invalid_response' )
			: $this->ok( array( 'pull' => $pull ) ) );
	}
	/** @param array<string,mixed>|null $body @param array<int,string> $special @return array<string,mixed> */
	private function request( string $method, string $path, string $token, ?array $body = null, array $special = array() ): array {
		if ( ! $this->validToken( $token ) || ! str_starts_with( $path, '/repos/' ) ) {
			return $this->error( 'invalid_request' );
		}
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2026-03-10',
			'User-Agent'           => 'RAN-Booster-Release-Deployments',
		);
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$args = array(
			'headers'             => $headers,
			'timeout'             => 12,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_BODY,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			if ( ! is_string( $args['body'] ) ) {
				return $this->error( 'invalid_request' );
			}
		}
		try {
			$response = ( $this->send )( $method, self::API_ROOT . $path, $args );
		} catch ( Throwable ) {
			return $this->error( 'remote_unavailable' );
		}
		if ( ! is_array( $response ) ) {
			return $this->error( 'remote_unavailable' );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		if ( isset( $special[ $status ] ) ) {
			return $this->error( $special[ $status ] );
		}
		if ( in_array( $status, array( 401, 403 ), true ) ) {
			return $this->error( 'unauthorised' );
		}
		if ( 429 === $status ) {
			return $this->error( 'rate_limited' );
		}
		if ( $status < 200 || $status >= 300 || ! is_string( $raw ) || strlen( $raw ) > self::MAX_BODY ) {
			return $this->error( 'remote_unavailable' );
		}
		try {
			$data = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( Throwable ) {
			return $this->error( 'invalid_response' );
		}
		return is_array( $data ) ? $this->ok( array( 'data' => $data ) ) : $this->error( 'invalid_response' );
	}
	private function normalizePull( string $repository, mixed $data ): ?array {
		if ( ! is_array( $data ) || ! is_int( $data['number'] ?? null ) || $data['number'] < 1
			|| ! in_array( $data['state'] ?? null, array( 'open', 'closed' ), true )
			|| ! $this->validSha( $data['head']['sha'] ?? null ) || ! $this->validBranch( $data['head']['ref'] ?? null )
			|| ! $this->validSha( $data['base']['sha'] ?? null ) || ! $this->validBranch( $data['base']['ref'] ?? null )
			|| ! is_string( $data['head']['repo']['full_name'] ?? null ) || 0 !== strcasecmp( $repository, $data['head']['repo']['full_name'] )
			|| ! is_string( $data['base']['repo']['full_name'] ?? null ) || 0 !== strcasecmp( $repository, $data['base']['repo']['full_name'] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact bounded transport shape.
		return array( 'number' => $data['number'], 'state' => $data['state'], 'draft' => true === ( $data['draft'] ?? null ), 'merged' => is_string( $data['merged_at'] ?? null ) && '' !== $data['merged_at'], 'head' => $data['head']['ref'], 'base' => $data['base']['ref'], 'head_sha' => $data['head']['sha'], 'base_sha' => $data['base']['sha'] );
	}
	private function validTargetBranch( string $repository, string $branch, string $defaultBranch ): bool {
		return $this->validRepository( $repository ) && $this->validBranch( $branch )
			&& $this->validBranch( $defaultBranch ) && ! hash_equals( $defaultBranch, $branch );
	}
	private function validRepository( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z/D', $value );
	}
	private function validBranch( mixed $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 191
			&& 1 === preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9_-])?\z/D', $value )
			&& ! str_contains( $value, '..' ) && ! str_contains( $value, '//' ) && ! str_contains( $value, '@{' )
			&& ! str_ends_with( $value, '.lock' );
	}
	private function validPath( mixed $value ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 512
			&& ! str_starts_with( $value, '/' ) && ! str_contains( $value, '\\' ) && ! str_contains( $value, "\0" )
			&& 1 !== preg_match( '#(?:\A|/)\.\.?(/|\z)#', $value ) && 1 === preg_match( '//u', $value );
	}
	private function assessmentDocument( string $path ): bool {
		return ( ! str_contains( $path, '/' ) && ( str_ends_with( strtolower( $path ), '.php' )
			|| in_array( $path, array( 'style.css', 'package.json', 'readme.txt', '.prettierignore', ManagedReleaseBundle::RECEIPT_PATH, 'release-please-config.json' ), true ) ) )
			|| ( str_starts_with( $path, '.github/workflows/' ) && 1 === preg_match( '/\.ya?ml\z/i', $path ) )
			|| ( ( str_starts_with( $path, 'scripts/' ) || str_starts_with( $path, '.github/scripts/' ) || str_starts_with( $path, '.ci/' ) )
				&& str_ends_with( strtolower( $path ), '.sh' ) )
			|| in_array( $path, array( 'composer.json', 'Makefile' ), true )
			|| in_array(
				$path,
				array(
					ManagedReleaseBundle::WORKFLOW_PATH,
					'scripts/build-release.sh',
					'scripts/verify-release.sh',
					'scripts/upload-release-assets.sh',
				),
				true
			)
			|| str_ends_with( $path, 'block.json' ) || str_ends_with( strtolower( $path ), '.pot' );
	}
	private function validSha( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{40}\z/D', $value );
	}
	private function validToken( string $value ): bool {
		return strlen( $value ) <= 255 && 0 === preg_match( '/[\x00-\x20\x7F]/', $value );
	}
	private function numericString( mixed $value ): ?string {
		$value = is_int( $value ) || is_string( $value ) ? (string) $value : '';
		return strlen( $value ) <= 191 && 1 === preg_match( '/\A[1-9][0-9]*\z/D', $value ) ? $value : null;
	}
	/** @param array<string,mixed> $values @return array<string,mixed> */
	private function ok( array $values ): array {
		return array_merge( array( 'code' => 'ok' ), $values );
	}
	private function error( string $code ): array {
		return array( 'code' => $code );
	}
}
