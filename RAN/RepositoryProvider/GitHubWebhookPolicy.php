<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RuntimeException;

final readonly class GitHubWebhookPolicy implements ProviderWebhookPolicy {

	public function getProvider(): ProviderCode {
		return ProviderCode::parse( 'gh' );
	}

	public function getRetainedHeaders(): array {
		return array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' );
	}

	public function getSignatureHeader(): string {
		return 'x-hub-signature-256';
	}

	public function normalizeWebhook( array $metadata, mixed $secret ): array {
		$label       = $this->requiredString( $metadata['label'] ?? null, 'Webhook secret label' );
		$scope       = $this->requiredString( $metadata['scope'] ?? null, 'Webhook secret scope' );
		$target      = isset( $metadata['target'] ) && is_string( $metadata['target'] )
			? trim( $metadata['target'], " \t\n\r\0\x0B/" )
			: '';
		$secret      = $this->requiredSecret( $secret );
		$authorityId = isset( $metadata['authority_id'] ) && is_string( $metadata['authority_id'] )
			? trim( $metadata['authority_id'] )
			: '';

		if ( ! in_array( $scope, array( 'owner', 'repository' ), true ) ) {
			throw new RuntimeException( 'Webhook secret scope is not supported by this provider.' );
		}

		if ( 'owner' === $scope && ! $this->isOwner( $target ) ) {
			throw new RuntimeException( 'Owner-scoped webhook secrets require a valid provider owner.' );
		} elseif ( 'repository' === $scope && ! $this->isRepository( $target ) ) {
			throw new RuntimeException( 'Repository-scoped webhook secrets require an owner/repository target.' );
		}
		if ( 'owner' === $scope ) {
			$authorityId = '';
		} elseif ( '' === $authorityId || strlen( $authorityId ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $authorityId ) ) {
			throw new RuntimeException( 'Repository-scoped webhook secrets require a stable repository identity.' );
		}

		return array(
			'label'        => $label,
			'scope'        => $scope,
			'target'       => $target,
			'authority_id' => $authorityId,
			'secret'       => $secret,
		);
	}

	public function getConstantNames(): array {
		return array();
	}

	public function webhookFromConstants( array $constants ): ?array {
		return null;
	}

	public function authorizeWebhook(
		SignedWebhookVerification $verification,
		string $repositoryAuthorityId,
		string $repository
	): bool {
		if ( '' === $repositoryAuthorityId || ! $verification->getProvider()->equals( $this->getProvider() ) ) {
			return false;
		}

		$repository = strtolower( trim( $repository, '/' ) );
		$owner      = explode( '/', $repository, 2 )[0];
		foreach ( $verification->getProfiles() as $profile ) {
			$scope  = strtolower( trim( $profile['scope'] ) );
			$target = strtolower( trim( $profile['target'], " \t\n\r\0\x0B/" ) );
			if ( ( 'owner' === $scope && '' !== $target && $target === $owner )
				|| ( 'repository' === $scope
					&& '' !== $profile['authority_id']
					&& hash_equals( $profile['authority_id'], $repositoryAuthorityId ) )
			) {
				return true;
			}
		}

		return false;
	}

	public function repositoryTargetMatches( string $target, string $repositoryLocator ): bool {
		return 0 === strcasecmp( trim( $target, '/' ), trim( $repositoryLocator, '/' ) );
	}

	private function assertSecret( string $secret ): void {
		if ( strlen( $secret ) < 32 || strlen( $secret ) > 512 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $secret ) ) {
			throw new RuntimeException( 'Webhook secrets must contain 32 to 512 bytes without control characters.' );
		}
	}

	private function requiredSecret( mixed $secret ): string {
		if ( ! is_string( $secret ) ) {
			throw new RuntimeException( 'Webhook secret must be a string.' );
		}

		$this->assertSecret( $secret );

		return $secret;
	}

	private function requiredString( mixed $value, string $name ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Provider policy errors are mapped at the admin boundary.
			throw new RuntimeException( $name . ' must be a non-empty string.' );
		}

		return trim( $value );
	}

	private function isOwner( string $owner ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/', $owner );
	}

	private function isRepository( string $repository ): bool {
		if ( 1 !== substr_count( $repository, '/' ) ) {
			return false;
		}

		list($owner, $name) = explode( '/', $repository, 2 );

		return $this->isOwner( $owner ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,100}$/', $name );
	}
}
