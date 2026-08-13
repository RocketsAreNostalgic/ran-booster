<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RAN\RepositoryProvider\CredentialExpiryReport;
use RAN\RepositoryProvider\GitReferenceSyntax;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderCredentialStore;
use RAN\RepositoryProvider\RepositoryBrowseMode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RuntimeException;

/**
 * Lists the repositories available to the configured GitHub token.
 *
 * The token is used only in the server-side request and is never included in
 * the returned repository data or in exception messages.
 */
class RepositoryBrowser {

	const API_URL          = 'https://api.github.com/user/repos';
	const PUBLIC_API_BASE  = 'https://api.github.com';
	const API_VERSION      = '2022-11-28';
	const PER_PAGE         = 30;
	const HTTP_DATE_FORMAT = 'D, d M Y H:i:s \G\M\T';

	private ProviderCredentialStore $credentials;

	public function __construct( ProviderCredentialStore $credentials ) {
		$this->credentials = $credentials;
	}

	/**
	 * Resolve one repository from GitHub's canonical repository response.
	 *
	 * An omitted credential deliberately means an anonymous request, even when
	 * the site has a default GitHub credential. A selected credential is looked
	 * up only in GitHub's provider scope and is never included in the URL or an
	 * exception message.
	 */
	public function repository(
		string $fullName,
		?string $credentialId = null,
		float|int $timeout = 15,
		?int $responseSize = null,
		bool $authenticateDefault = false
	): RepositoryDescriptor {
		$fullName = $this->validateRepositoryName( $fullName );
		$headers  = $this->requestHeaders();

		if ( null !== $credentialId || $authenticateDefault ) {
			$credential = $this->credentials->credentialMaterial( $credentialId );
			$token      = is_array( $credential ) && isset( $credential['secret'] ) && is_string( $credential['secret'] )
				? trim( $credential['secret'] )
				: '';

			if ( '' === $token ) {
				throw new RuntimeException( 'The selected GitHub credential is not available.', 400 );
			}

			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$arguments = array(
			'timeout'            => $timeout,
			'redirection'        => 0,
			'reject_unsafe_urls' => true,
			'headers'            => $headers,
		);
		if ( null !== $responseSize ) {
			$arguments['limit_response_size'] = $responseSize;
		}

		$response = wp_remote_get(
			self::PUBLIC_API_BASE . '/repos/' . $this->encodeRepositoryName( $fullName ),
			$arguments
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'GitHub could not be reached. Please try again.', 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the selected credential.', 401 );
		}

		if ( $this->isRateLimitedResponse( $response, $status ) ) {
			throw new RuntimeException( 'GitHub API rate limit has been reached. Try again later.', 429 );
		}

		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied the repository request. Check repository access.', 403 );
		}

		if ( 404 === $status ) {
			throw new RuntimeException( 'GitHub could not find that repository, or the selected credential cannot access it.', 404 );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'GitHub could not resolve that repository. Please try again.', 502 );
		}

		$item       = json_decode( wp_remote_retrieve_body( $response ), true, 512, JSON_BIGINT_AS_STRING );
		$repository = $this->descriptorFromItem( $item, $credentialId );

		if ( null === $repository || 0 !== strcasecmp( $fullName, $repository->locator ) ) {
			throw new RuntimeException( 'GitHub returned an invalid repository response. Please try again.', 502 );
		}

		return $repository;
	}

	/**
	 * Resolve the immutable commit currently at a repository branch head.
	 *
	 * This request is used to reject delayed webhook deliveries before an
	 * archive session (and its authentication hook) is created. Public
	 * repositories remain anonymous unless a credential was explicitly
	 * selected; private repositories may use the provider's default credential.
	 */
	public function branchHead(
		string $fullName,
		string $branch,
		string $expectedRepositoryId,
		?string $credentialId = null,
		bool $private = false
	): string {
		$fullName = $this->validateRepositoryName( $fullName );
		$branch   = $this->validateBranch( $branch );
		$identity = $this->repository( $fullName, $credentialId, 15, 65536, $private );

		if ( ! hash_equals( $expectedRepositoryId, $identity->providerRepositoryId ) ) {
			throw new RuntimeException( 'GitHub returned an invalid repository identity while resolving the branch.', 502 );
		}

		return $this->currentBranchHead( $fullName, $branch, $credentialId, $private );
	}

	/**
	 * Resolve a branch, tag or commit to an immutable repository-bound commit.
	 */
	public function immutableRef(
		string $fullName,
		string $ref,
		string $expectedRepositoryId,
		?string $credentialId = null,
		bool $private = false
	): string {
		$fullName = $this->validateRepositoryName( $fullName );
		$ref      = $this->validateRef( $ref );
		$identity = $this->repository( $fullName, $credentialId, 15, 65536, $private );

		if ( ! hash_equals( $expectedRepositoryId, $identity->providerRepositoryId ) ) {
			throw new RuntimeException( 'GitHub returned an invalid repository identity while resolving the revision.', 502 );
		}

		$headers           = $this->authenticatedRequestHeaders( $credentialId, $private );
		$headers['Accept'] = 'application/vnd.github.sha';
		$response          = wp_remote_get(
			self::PUBLIC_API_BASE
				. '/repos/'
				. $this->encodeRepositoryName( $fullName )
				. '/commits/'
				. rawurlencode( $ref ),
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 128,
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'GitHub could not be reached to resolve the repository revision.', 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $status ) {
			throw new RuntimeException( 'GitHub rejected the repository revision.', 400 );
		}
		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the selected credential while resolving the repository revision.', 401 );
		}
		if ( $this->isRateLimitedResponse( $response, $status ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The retry type stores only a normalized integer delay and fixed message.
			throw new RuntimeException( 'GitHub API rate limit has been reached. Try again later.', 429 );
		}
		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied access while resolving the repository revision.', 403 );
		}
		if ( 404 === $status ) {
			throw new RuntimeException( 'GitHub could not find that repository revision, or the selected credential cannot access it.', 404 );
		}
		if ( 410 === $status ) {
			throw new RuntimeException( 'The requested GitHub repository revision is no longer available.', 410 );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'GitHub could not resolve the repository revision.', 502 );
		}

		$sha = trim( wp_remote_retrieve_body( $response ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/i', $sha ) ) {
			throw new RuntimeException( 'GitHub returned an invalid revision-resolution response.', 502 );
		}

		return strtolower( $sha );
	}

	/**
	 * Re-read a previously identity-bound branch without another repository call.
	 */
	public function currentBranchHead(
		string $fullName,
		string $branch,
		?string $credentialId = null,
		bool $private = false
	): string {
		$fullName = $this->validateRepositoryName( $fullName );
		$branch   = $this->validateBranch( $branch );

		$headers = $this->authenticatedRequestHeaders( $credentialId, $private );

		$response = wp_remote_get(
			self::PUBLIC_API_BASE
				. '/repos/'
				. $this->encodeRepositoryName( $fullName )
				. '/branches/'
				. rawurlencode( $branch ),
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'GitHub could not be reached to resolve the repository branch.', 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $status ) {
			throw new RuntimeException( 'GitHub rejected the repository branch.', 400 );
		}

		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the selected credential while resolving the repository branch.', 401 );
		}

		if ( $this->isRateLimitedResponse( $response, $status ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The retry type stores only a normalized integer delay and fixed message.
			throw new RuntimeException( 'GitHub API rate limit has been reached. Try again later.', 429 );
		}

		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied access while resolving the repository branch.', 403 );
		}

		if ( 404 === $status ) {
			throw new RuntimeException( 'GitHub could not find that repository branch, or the selected credential cannot access it.', 404 );
		}

		if ( 410 === $status ) {
			throw new RuntimeException( 'The requested GitHub repository branch is no longer available.', 410 );
		}

		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'GitHub could not resolve the repository branch.', 502 );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$sha  = is_array( $data ) && is_array( $data['commit'] ?? null )
			? $data['commit']['sha'] ?? null
			: null;

		if ( ! is_array( $data )
			|| ! is_string( $data['name'] ?? null )
			|| ! hash_equals( $branch, $data['name'] )
			|| ! is_string( $sha )
			|| 1 !== preg_match( '/^[0-9a-f]{40}$/i', $sha )
		) {
			throw new RuntimeException( 'GitHub returned an invalid branch-resolution response.', 502 );
		}

		return strtolower( $sha );
	}

	public function validateCredential( string $credentialId, float $timeout = 15.0 ): CredentialValidationResult {
		$credential = $this->credentials->credentialMaterial( $credentialId );
		$token      = is_array( $credential ) && is_string( $credential['secret'] ?? null )
			? trim( $credential['secret'] )
			: '';

		if ( '' === $token ) {
			return CredentialValidationResult::invalid();
		}

		$response = wp_remote_get(
			self::PUBLIC_API_BASE . '/user',
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'reject_unsafe_urls'  => true,
				'headers'             => array_merge(
					$this->requestHeaders(),
					array( 'Authorization' => 'Bearer ' . $token )
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return CredentialValidationResult::unavailable();
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			return CredentialValidationResult::invalid();
		}

		if ( $this->isRateLimitedResponse( $response, $status ) ) {
			return CredentialValidationResult::rateLimited();
		}

		if ( 403 === $status ) {
			return CredentialValidationResult::invalid();
		}

		if ( $status < 200 || $status >= 300 ) {
			return CredentialValidationResult::unavailable();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! is_string( $body['login'] ?? null ) || '' === trim( $body['login'] ) ) {
			return CredentialValidationResult::invalidResponse();
		}

		return CredentialValidationResult::valid( $this->credentialExpiryReport( $response ) );
	}

	/**
	 * Parse GitHub's optional token-expiration header without retaining the raw
	 * response value. Missing or untrustworthy metadata remains explicitly
	 * unknown.
	 *
	 * @param array<string, mixed> $response WordPress HTTP response.
	 */
	private function credentialExpiryReport( array $response ): CredentialExpiryReport {
		$value = wp_remote_retrieve_header( $response, 'GitHub-Authentication-Token-Expiration' );
		if ( ! is_string( $value )
			|| '' === $value
			|| strlen( $value ) > 64
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value )
		) {
			return CredentialExpiryReport::unknown();
		}

		$format = match ( true ) {
			1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\z/D', $value ) => '!Y-m-d H:i:s \U\T\C',
			1 === preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} [+-](?:[01]\d|2[0-3])[0-5]\d\z/D', $value ) => '!Y-m-d H:i:s O',
			default => null,
		};
		if ( null === $format ) {
			return CredentialExpiryReport::unknown();
		}

		$expiry = DateTimeImmutable::createFromFormat( $format, $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $expiry
			|| ( is_array( $errors ) && ( 0 !== $errors['warning_count'] || 0 !== $errors['error_count'] ) )
			|| $expiry->format( substr( $format, 1 ) ) !== $value
		) {
			return CredentialExpiryReport::unknown();
		}

		return CredentialExpiryReport::known(
			$expiry->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' )
		);
	}

	public function browse( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		return RepositoryBrowseMode::PUBLIC_OWNER === $request->getMode()
			? $this->browsePublic( $request )
			: $this->browseAccessible( $request );
	}

	private function browseAccessible( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		$credentialId = (string) $request->getCredentialId();
		$credential   = $this->credentials->credentialMaterial( $credentialId );
		$token        = is_array( $credential ) && is_string( $credential['secret'] ?? null )
			? trim( $credential['secret'] )
			: '';

		if ( '' === $token ) {
			throw new RuntimeException( 'The selected GitHub credential is not available.', 400 );
		}

		$headers                  = $this->requestHeaders();
		$headers['Authorization'] = 'Bearer ' . $token;

		return $this->browsePages(
			self::API_URL . '?affiliation=owner%2Ccollaborator%2Corganization_member',
			$headers,
			$request,
			$credentialId,
			false
		);
	}

	private function browsePublic( RepositoryBrowseRequest $request ): RepositoryBrowseResult {
		$owner = trim( (string) $request->getOwner() );
		if ( ! preg_match( '/\A(?=.{1,39}\z)(?!-)(?!.*--)[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/', $owner ) ) {
			throw new RuntimeException( 'Enter a valid GitHub user or organisation name.', 400 );
		}

		$credentialId = $request->getCredentialId();
		$headers      = $this->requestHeaders();
		if ( null !== $credentialId ) {
			$credential = $this->credentials->credentialMaterial( $credentialId );
			$token      = is_array( $credential ) && is_string( $credential['secret'] ?? null )
				? trim( $credential['secret'] )
				: '';

			if ( '' === $token ) {
				throw new RuntimeException( 'The selected GitHub credential is not available.', 400 );
			}

			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$account        = $this->browseRequest(
			self::PUBLIC_API_BASE . '/users/' . rawurlencode( $owner ),
			$headers,
			$request
		);
		$accountType    = is_string( $account['type'] ?? null ) ? $account['type'] : '';
		$canonicalOwner = is_string( $account['login'] ?? null ) ? $account['login'] : $owner;

		if ( 'Organization' === $accountType ) {
			$endpoint = self::PUBLIC_API_BASE . '/orgs/' . rawurlencode( $canonicalOwner ) . '/repos?type=public';
		} elseif ( 'User' === $accountType ) {
			$endpoint = self::PUBLIC_API_BASE . '/users/' . rawurlencode( $canonicalOwner ) . '/repos?type=owner';
		} else {
			throw new RuntimeException( 'That GitHub account is not a user or organisation.', 400 );
		}

		return $this->browsePages( $endpoint, $headers, $request, null, true );
	}

	/** @param array<string, string> $headers */
	private function browsePages(
		string $endpoint,
		array $headers,
		RepositoryBrowseRequest $request,
		?string $credentialId,
		bool $publicOnly
	): RepositoryBrowseResult {
		$repositories = array();

		for ( $page = 1; ; ++$page ) {
			if ( ! $request->hasCapacity() ) {
				return $this->partialBrowseResult( $repositories, 503 );
			}

			$separator = str_contains( $endpoint, '?' ) ? '&' : '?';
			$url       = $endpoint . $separator . 'per_page=' . self::PER_PAGE . '&page=' . $page . '&sort=full_name';
			try {
				$items = $this->browseRequest( $url, $headers, $request );
			} catch ( RuntimeException | InvalidArgumentException $exception ) {
				if ( array() === $repositories
					|| ( $publicOnly
						&& null !== $request->getCredentialId()
						&& in_array( (int) $exception->getCode(), array( 401, 403, 429 ), true ) )
				) {
					throw $exception;
				}

				return $this->partialBrowseResult( $repositories, (int) $exception->getCode() );
			}
			if ( ! array_is_list( $items ) ) {
				if ( array() === $repositories ) {
					throw new RuntimeException( 'GitHub returned an invalid repository list.', 422 );
				}

				return $this->partialBrowseResult( $repositories, 422 );
			}

			foreach ( $items as $item ) {
				$repository = $this->descriptorFromItem( $item, $credentialId );
				if ( null === $repository || ( $publicOnly && $repository->private ) ) {
					continue;
				}

				$repositories[] = $repository;
				if ( RepositoryBrowseRequest::MAX_RESULTS <= count( $repositories ) ) {
					return $this->partialBrowseResult( $repositories, 206 );
				}
			}

			if ( count( $items ) < self::PER_PAGE ) {
				$this->sortRepositories( $repositories );

				return new RepositoryBrowseResult( $repositories );
			}
		}
	}

	/** @param array<string, string> $headers */
	private function browseRequest( string $url, array $headers, RepositoryBrowseRequest $request ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => $request->claimRemoteCall(),
				'redirection'         => 0,
				'limit_response_size' => $request->getResponseSizeLimit(),
				'reject_unsafe_urls'  => true,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'GitHub could not be reached. Please try again.', 504 );
		}

		$body   = wp_remote_retrieve_body( $response );
		$status = (int) wp_remote_retrieve_response_code( $response );
		$request->acceptResponseBody( $body );

		if ( 401 === $status ) {
			throw new RuntimeException( 'GitHub rejected the selected credential.', 401 );
		}
		if ( $this->isRateLimitedResponse( $response, $status ) ) {
			throw new RuntimeException( 'GitHub API rate limit has been reached. Try again later.', 429 );
		}
		if ( 403 === $status ) {
			throw new RuntimeException( 'GitHub denied repository access. Check credential permissions.', 403 );
		}
		if ( 404 === $status ) {
			throw new RuntimeException( 'GitHub could not find that user or organisation.', 404 );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException( 'GitHub could not list repositories. Please try again.', 502 );
		}

		$data = json_decode( $body, true, 512, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'GitHub returned an invalid repository response.', 422 );
		}

		return $data;
	}

	/** @param list<RepositoryDescriptor> $repositories */
	private function partialBrowseResult( array $repositories, int $status ): RepositoryBrowseResult {
		if ( array() === $repositories ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status is an internal fixed integer; the message is fixed and redacted.
			throw new RuntimeException( 'GitHub repository browsing could not continue safely.', $status );
		}

		$this->sortRepositories( $repositories );

		$reason = match ( $status ) {
			401, 403 => RepositoryBrowseResult::AUTHORIZATION,
			429 => RepositoryBrowseResult::RATE_LIMIT,
			206, 413, 503, 504 => RepositoryBrowseResult::LIMIT,
			default => RepositoryBrowseResult::PROVIDER,
		};

		return new RepositoryBrowseResult( $repositories, $reason );
	}

	private function isRateLimitedResponse( mixed $response, int $status ): bool {
		if ( 429 === $status ) {
			return true;
		}

		if ( 403 !== $status ) {
			return false;
		}

		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		if ( is_string( $remaining ) && '0' === trim( $remaining ) ) {
			return true;
		}

		$retryAfter = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( ! is_string( $retryAfter ) ) {
			return false;
		}

		$retryAfter = trim( $retryAfter );
		if ( '' === $retryAfter ) {
			return false;
		}

		if ( preg_match( '/\A\d+\z/', $retryAfter ) ) {
			return true;
		}

		$retryAt = DateTimeImmutable::createFromFormat(
			'!' . self::HTTP_DATE_FORMAT,
			$retryAfter,
			new DateTimeZone( 'GMT' )
		);

		return false !== $retryAt && $retryAt->format( self::HTTP_DATE_FORMAT ) === $retryAfter;
	}

	/**
	 * @return array<string, string>
	 */
	private function requestHeaders(): array {
		return array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => self::API_VERSION,
			'User-Agent'           => 'RAN-Booster',
		);
	}

	/** @return array<string, string> */
	private function authenticatedRequestHeaders( ?string $credentialId, bool $private ): array {
		$headers = $this->requestHeaders();

		if ( ! $private && null === $credentialId ) {
			return $headers;
		}

		$credential = $this->credentials->credentialMaterial( $credentialId );
		$token      = is_array( $credential ) && is_string( $credential['secret'] ?? null )
			? trim( $credential['secret'] )
			: '';

		if ( '' === $token ) {
			throw new RuntimeException( 'The selected GitHub credential is not available.', 400 );
		}

		$headers['Authorization'] = 'Bearer ' . $token;

		return $headers;
	}

	private function validateRepositoryName( string $fullName ): string {
		$fullName = trim( $fullName );
		$parts    = explode( '/', $fullName );

		if ( 2 !== count( $parts )
			|| ! preg_match( '/\A(?=.{1,39}\z)(?!-)(?!.*--)[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*\z/', $parts[0] )
			|| ! preg_match( '/\A(?=.{1,100}\z)[A-Za-z0-9._-]+\z/', $parts[1] )
			|| '.' === $parts[1]
			|| '..' === $parts[1] ) {
			throw new RuntimeException( 'Enter a valid GitHub repository in owner/repository form.', 400 );
		}

		return $fullName;
	}

	private function validateBranch( string $branch ): string {
		if ( ! GitReferenceSyntax::isValidNamedReference( $branch ) ) {
			throw new RuntimeException( 'Enter a valid GitHub repository branch.', 400 );
		}

		return $branch;
	}

	private function validateRef( string $ref ): string {
		try {
			return $this->validateBranch( $ref );
		} catch ( RuntimeException ) {
			throw new RuntimeException( 'Enter a valid GitHub repository branch, tag or commit.', 400 );
		}
	}

	private function encodeRepositoryName( string $fullName ): string {
		$parts = explode( '/', $fullName );

		return rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] );
	}

	private function descriptorFromItem( mixed $item, ?string $credentialId = null ): ?RepositoryDescriptor {
		if ( ! is_array( $item )
			|| ! isset( $item['id'] )
			|| ( ! is_int( $item['id'] ) && ! is_string( $item['id'] ) )
			|| empty( $item['full_name'] )
			|| ! is_string( $item['full_name'] )
			|| ! array_key_exists( 'private', $item )
			|| ! is_bool( $item['private'] )
			|| empty( $item['default_branch'] )
			|| ! is_string( $item['default_branch'] ) ) {
			return null;
		}

		$providerRepositoryId = trim( (string) $item['id'] );
		if ( '' === $providerRepositoryId ) {
			return null;
		}

		try {
			$fullName = $this->validateRepositoryName( $item['full_name'] );
		} catch ( RuntimeException ) {
			return null;
		}
		$parts = explode( '/', $fullName );

		return new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			$fullName,
			$parts[1],
			$providerRepositoryId,
			$item['private'],
			$item['default_branch'],
			null !== $credentialId && '' !== $credentialId ? $credentialId : null
		);
	}

	/**
	 * @param list<RepositoryDescriptor> $repositories Repositories to sort.
	 */
	private function sortRepositories( array &$repositories ): void {
		usort(
			$repositories,
			static function ( RepositoryDescriptor $left, RepositoryDescriptor $right ): int {
				return strcasecmp( $left->locator, $right->locator );
			}
		);
	}
}
