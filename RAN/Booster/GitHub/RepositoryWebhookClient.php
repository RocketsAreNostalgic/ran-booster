<?php
declare(strict_types=1);
namespace RAN\Booster\GitHub;

use RAN\RepositoryProvider\RepositoryWebhookFitnessResult;
use RAN\RepositoryProvider\RepositoryWebhookOperationResult;
use RuntimeException;
/** Fixed-origin GitHub client for repository-webhook-management/3. */
final class RepositoryWebhookClient {
	private const ORIGIN          = 'https://api.github.com';
	private const CALL_TIMEOUT    = 8.0;
	private const TOTAL_TIMEOUT   = 25.0;
	private const LIST_PAGE_BYTES = 262144;
	private const READ_BYTES      = 65536;
	public function assessSetup( string $repositoryId, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repositoryId, $repository, $token, 'setup' );
	}
	public function assessCheck( string $repositoryId, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repositoryId, $repository, $token, 'check' );
	}
	public function assessReconfigure( string $repositoryId, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repositoryId, $repository, $token, 'reconfigure' );
	}
	public function assessRemove( string $repositoryId, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repositoryId, $repository, $token, 'remove' );
	}
	public function assessTest( string $repositoryId, string $repository, #[\SensitiveParameter] string $token ): RepositoryWebhookFitnessResult {
		return $this->assess( $repositoryId, $repository, $token, 'test' );
	}
	public function setup( string $repository, string $callbackUrl, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $secret ): RepositoryWebhookOperationResult {
		$deadline = microtime( true ) + self::TOTAL_TIMEOUT;
		$matches  = array();
		for ( $page = 1; $page <= 3; ++$page ) {
			$response = $this->request( 'GET', $this->hooksPath( $repository ) . '?per_page=100&page=' . $page, $token, null, self::LIST_PAGE_BYTES, $deadline );
			if ( 200 !== $response['status'] ) {
				return $this->uncertain( 'hook_inventory_unavailable' );
			}
			$hooks = $this->decodeList( $response['body'] );
			if ( null === $hooks ) {
				return $this->uncertain( 'hook_inventory_invalid' );
			}
			foreach ( $hooks as $hook ) {
				$configuration = $this->configuration( $hook, $callbackUrl );
				if ( 'matched' === $configuration['endpoint'] ) {
					$matches[] = array( $hook, $configuration );
				}
			}
			if ( count( $hooks ) < 100 ) {
				break;
			}
			if ( 3 === $page ) {
				return $this->uncertain( 'hook_inventory_incomplete' );
			}
		}
		if ( 1 < count( $matches ) ) {
			return $this->uncertain( 'matching_hooks_ambiguous' );
		}
		if ( 1 === count( $matches ) ) {
			return $this->result( 'ambiguous', 'existing_hook_requires_reconfigure', null, $matches[0][1], 'unknown', 'An existing endpoint cannot prove the stored signing secret; inspect it and use explicit reconfiguration.' );
		}
		$created = $this->request( 'POST', $this->hooksPath( $repository ), $token, $this->payload( $callbackUrl, $secret ), self::READ_BYTES, $deadline );
		if ( 201 !== $created['status'] ) {
			return $this->mutationFailure( $created['status'], 'setup_failed' );
		}
		$createdHook = $this->decodeHook( $created['body'] );
		$hookId      = $this->hookId( $createdHook );
		if ( null === $hookId ) {
			return $this->uncertain( 'setup_response_invalid' );
		}
		$readback = $this->request( 'GET', $this->hookPath( $repository, $hookId ), $token, null, self::READ_BYTES, $deadline );
		$hook     = 200 === $readback['status'] ? $this->decodeHook( $readback['body'] ) : null;
		if ( null !== $hook && hash_equals( $hookId, (string) $this->hookId( $hook ) ) ) {
			return $this->configuredResult( $hook, $this->configuration( $hook, $callbackUrl ) );
		}
		$deleted = $this->request( 'DELETE', $this->hookPath( $repository, $hookId ), $token, null, 0, $deadline );
		$absent  = $this->request( 'GET', $this->hookPath( $repository, $hookId ), $token, null, self::READ_BYTES, $deadline );
		if ( 204 === $deleted['status'] && 404 === $absent['status'] ) {
			return $this->result( 'failed', 'setup_compensated', $hookId, $this->unknownConfiguration(), 'absent', 'The unusable remote hook was removed; setup may be tried again.' );
		}
		return $this->result( 'partial', 'setup_compensation_incomplete', $hookId, $this->unknownConfiguration(), 'unknown', 'Inspect the identified remote hook before retrying.' );
	}
	public function check( string $repository, string $hookId, string $callbackUrl, #[\SensitiveParameter] string $token ): RepositoryWebhookOperationResult {
		$response = $this->request( 'GET', $this->hookPath( $repository, $hookId ), $token, null, self::READ_BYTES, microtime( true ) + self::TOTAL_TIMEOUT );
		if ( 404 === $response['status'] ) {
			return $this->result( 'succeeded', 'hook_absent', $hookId, $this->unknownConfiguration(), 'absent', 'Set up a replacement only after reviewing the retained local record.' );
		}
		$hook = 200 === $response['status'] ? $this->decodeHook( $response['body'] ) : null;
		if ( null === $hook || ! hash_equals( $hookId, (string) $this->hookId( $hook ) ) ) {
			return $this->uncertain( 'hook_readback_unavailable', $hookId );
		}
		$configuration = $this->configuration( $hook, $callbackUrl );
		if ( in_array( 'unknown', $configuration, true ) ) {
			return $this->uncertain( 'hook_readback_invalid', $hookId );
		}
		return $this->result(
			'succeeded',
			in_array( 'mismatched', $configuration, true ) ? 'configuration_drift' : 'configuration_confirmed',
			$hookId,
			$configuration,
			'unknown',
			in_array( 'mismatched', $configuration, true ) ? 'Reconfigure the identified hook before relying on it.' : 'A correctly signed inbound delivery is still required for verification.'
		);
	}
	public function test( string $repository, string $hookId, string $callbackUrl, #[\SensitiveParameter] string $token ): RepositoryWebhookOperationResult {
		$deadline = microtime( true ) + self::TOTAL_TIMEOUT;
		$hookRead = $this->request( 'GET', $this->hookPath( $repository, $hookId ), $token, null, self::READ_BYTES, $deadline );
		$hook     = 200 === $hookRead['status'] ? $this->decodeHook( $hookRead['body'] ) : null;
		if ( null === $hook || ! hash_equals( $hookId, (string) $this->hookId( $hook ) ) ) {
			return 404 === $hookRead['status']
				? $this->result( 'failed', 'hook_absent', $hookId, $this->unknownConfiguration(), 'absent', 'The recorded hook no longer exists.' )
				: $this->uncertain( 'hook_readback_unavailable', $hookId );
		}
		$configuration = $this->configuration( $hook, $callbackUrl );
		if ( in_array( 'unknown', $configuration, true ) ) {
			return $this->uncertain( 'hook_readback_invalid', $hookId );
		}
		if ( in_array( 'mismatched', $configuration, true ) ) {
			return $this->result( 'failed', 'hook_ownership_mismatch', $hookId, $configuration, 'unknown', 'The recorded hook does not match this site. Reconfigure it before testing.' );
		}
		$baselineResponse = $this->request( 'GET', $this->deliveriesPath( $repository, $hookId ), $token, null, self::LIST_PAGE_BYTES, $deadline );
		$baseline         = 200 === $baselineResponse['status'] ? $this->deliveryIds( $baselineResponse['body'] ) : null;
		if ( null === $baseline ) {
			return $this->uncertain( 'delivery_inventory_unavailable', $hookId );
		}
		$ping = $this->request( 'POST', $this->hookPath( $repository, $hookId ) . '/pings', $token, array(), 0, $deadline );
		if ( 204 !== $ping['status'] ) {
			return $this->mutationFailure( $ping['status'], 'ping_request_failed', $hookId );
		}
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			if ( 0 < $attempt ) {
				usleep( 250000 );
			}
			$response = $this->request( 'GET', $this->deliveriesPath( $repository, $hookId ), $token, null, self::LIST_PAGE_BYTES, $deadline );
			$delivery = 200 === $response['status'] ? $this->newPingDelivery( $response['body'], $baseline ) : null;
			if ( false === $delivery ) {
				return $this->uncertain( 'delivery_inventory_invalid', $hookId );
			}
			if ( is_array( $delivery ) && null !== $delivery['status_code'] ) {
				return $this->result( 200 <= $delivery['status_code'] && 300 > $delivery['status_code'] ? 'succeeded' : 'failed', 200 <= $delivery['status_code'] && 300 > $delivery['status_code'] ? 'ping_verified' : 'ping_delivery_failed', $hookId, $configuration, 200 <= $delivery['status_code'] && 300 > $delivery['status_code'] ? 'verified' : 'unverified', 200 <= $delivery['status_code'] && 300 > $delivery['status_code'] ? 'GitHub recorded a successful ping delivery for the exact hook.' : 'GitHub recorded a failed ping delivery for the exact hook; inspect provider delivery details.' );
			}
		}

		return $this->result( 'succeeded', 'ping_requested', $hookId, $configuration, 'unknown', 'GitHub accepted the ping request, but no new ping delivery was observed before the bounded check ended.' );
	}
	public function reconfigure( string $repository, string $hookId, string $callbackUrl, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $secret ): RepositoryWebhookOperationResult {
		$deadline   = microtime( true ) + self::TOTAL_TIMEOUT;
		$before     = $this->request( 'GET', $this->hookPath( $repository, $hookId ), $token, null, self::READ_BYTES, $deadline );
		$beforeHook = 200 === $before['status'] ? $this->decodeHook( $before['body'] ) : null;
		if ( null === $beforeHook || ! hash_equals( $hookId, (string) $this->hookId( $beforeHook ) ) ) {
			return 404 === $before['status']
				? $this->result( 'failed', 'hook_absent', $hookId, $this->unknownConfiguration(), 'absent', 'The recorded hook no longer exists.' )
				: $this->uncertain( 'preconfiguration_read_unavailable', $hookId );
		}
		if ( 'matched' !== $this->configuration( $beforeHook, $callbackUrl )['endpoint'] ) {
			return 'unknown' === $this->configuration( $beforeHook, $callbackUrl )['endpoint'] ? $this->uncertain( 'hook_ownership_unavailable', $hookId ) : $this->result( 'failed', 'hook_ownership_mismatch', $hookId, $this->configuration( $beforeHook, $callbackUrl ), 'unknown', 'Inspect the remote hook; its callback does not match this site.' );
		}
		$updated = $this->request( 'PATCH', $this->hookPath( $repository, $hookId ), $token, $this->payload( $callbackUrl, $secret ), self::READ_BYTES, $deadline );
		if ( 200 !== $updated['status'] ) {
			return $this->mutationFailure( $updated['status'], 'reconfigure_failed', $hookId );
		}
		$readback = $this->request( 'GET', $this->hookPath( $repository, $hookId ), $token, null, self::READ_BYTES, $deadline );
		$hook     = 200 === $readback['status'] ? $this->decodeHook( $readback['body'] ) : null;
		if ( null === $hook || ! hash_equals( $hookId, (string) $this->hookId( $hook ) ) ) {
			return $this->uncertain( 'reconfigure_readback_unavailable', $hookId );
		}
		return $this->configuredResult( $hook, $this->configuration( $hook, $callbackUrl ) );
	}
	public function remove( string $repository, string $hookId, string $callbackUrl, #[\SensitiveParameter] string $token ): RepositoryWebhookOperationResult {
		$deadline = microtime( true ) + self::TOTAL_TIMEOUT;
		$before   = $this->request( 'GET', $this->hookPath( $repository, $hookId ), $token, null, self::READ_BYTES, $deadline );
		if ( 404 === $before['status'] ) {
			return $this->result( 'succeeded', 'absence_confirmed', $hookId, $this->unknownConfiguration(), 'absent', 'The remote hook is absent.' );
		}
		$hook = 200 === $before['status'] ? $this->decodeHook( $before['body'] ) : null;
		if ( null === $hook || ! hash_equals( $hookId, (string) $this->hookId( $hook ) ) ) {
			return $this->uncertain( 'predelete_read_unavailable', $hookId );
		}
		if ( 'matched' !== $this->configuration( $hook, $callbackUrl )['endpoint'] ) {
			return 'unknown' === $this->configuration( $hook, $callbackUrl )['endpoint'] ? $this->uncertain( 'hook_ownership_unavailable', $hookId ) : $this->result( 'failed', 'hook_ownership_mismatch', $hookId, $this->configuration( $hook, $callbackUrl ), 'unknown', 'Inspect the remote hook; its callback does not match this site.' );
		}
		$deleted = $this->request( 'DELETE', $this->hookPath( $repository, $hookId ), $token, null, 0, $deadline );
		if ( 204 !== $deleted['status'] ) {
			return $this->mutationFailure( $deleted['status'], 'remove_failed', $hookId );
		}
		$absent = $this->request( 'GET', $this->hookPath( $repository, $hookId ), $token, null, self::READ_BYTES, $deadline );
		return 404 === $absent['status']
			? $this->result( 'succeeded', 'absence_confirmed', $hookId, $this->unknownConfiguration(), 'absent', 'The remote hook is absent.' )
			: $this->uncertain( 'remove_readback_unavailable', $hookId );
	}
	private function assess( string $repositoryId, string $repository, string $token, string $action ): RepositoryWebhookFitnessResult {
		$response = $this->request( 'GET', $this->repositoryPath( $repository ), $token, null, self::READ_BYTES, microtime( true ) + self::TOTAL_TIMEOUT );
		$now      = $this->now();
		if ( 200 !== $response['status'] ) {
			return new RepositoryWebhookFitnessResult( 'supported', 'unknown', 'unknown', 'assessment_unavailable', $action . '_assessment_unavailable', $now, 'Confirm repository access and provider policy, then assess again.' );
		}
		$data = json_decode( $response['body'], true, 32, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) || ! isset( $data['id'] ) || ! hash_equals( $repositoryId, trim( (string) $data['id'] ) ) ) {
			return new RepositoryWebhookFitnessResult( 'supported', 'insufficient', 'unknown', 'observed', 'repository_identity_mismatch', $now, 'Select the credential for the exact managed repository.' );
		}
		$scopes = wp_remote_retrieve_header( $response['response'], 'x-oauth-scopes' );
		if ( is_string( $scopes ) && '' !== trim( $scopes ) ) {
			$grants = array_map( 'trim', explode( ',', strtolower( $scopes ) ) );
			if ( ! in_array( 'admin:repo_hook', $grants, true ) ) {
				return new RepositoryWebhookFitnessResult( 'supported', 'insufficient', 'unknown', 'observed', 'classic_scope_insufficient', $now, 'Use a credential with repository webhook management permission.' );
			}
			return new RepositoryWebhookFitnessResult( 'supported', 'suitable', 'overscoped', 'observed', 'classic_scope_broad', $now, 'Prefer a fine-grained token restricted to the selected repository.' );
		}
		return new RepositoryWebhookFitnessResult( 'supported', 'unknown', 'unknown', 'unknown_by_design', 'fine_grained_authority_unknown', $now, 'Confirm the selected repository and Webhooks write permission before continuing.' );
	}
	/** @return array{status:int,body:string,response:mixed} */
	private function request( string $method, string $path, string $token, ?array $body, int $limit, float $deadline ): array {
		$remaining = $deadline - microtime( true );
		if ( $remaining <= 0 ) {
			return array(
				'status'   => 599,
				'body'     => '',
				'response' => array(),
			);
		}
		$arguments = array(
			'method'              => $method,
			'timeout'             => min( self::CALL_TIMEOUT, $remaining ),
			'redirection'         => 0,
			'limit_response_size' => $limit,
			'reject_unsafe_urls'  => true,
			'headers'             => array(
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Bearer ' . $token,
				'X-GitHub-Api-Version' => RepositoryBrowser::API_VERSION,
				'User-Agent'           => 'RAN-Booster',
			),
		);
		if ( null !== $body ) {
			$arguments['headers']['Content-Type'] = 'application/json';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixed bounded provider payload; JSON exceptions fail closed.
			$arguments['body'] = json_encode( $body, JSON_THROW_ON_ERROR );
		}
		$response = wp_remote_request( self::ORIGIN . $path, $arguments );
		if ( is_wp_error( $response ) ) {
			return array(
				'status'   => 599,
				'body'     => '',
				'response' => $response,
			);
		}
		return array(
			'status'   => (int) wp_remote_retrieve_response_code( $response ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
			'response' => $response,
		);
	}
	private function hooksPath( string $repository ): string {
		return $this->repositoryPath( $repository ) . '/hooks';
	}
	private function hookPath( string $repository, string $hookId ): string {
		if ( 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $hookId ) ) {
			throw new RuntimeException( 'The GitHub hook identity is invalid.', 400 );
		}
		return $this->hooksPath( $repository ) . '/' . rawurlencode( $hookId );
	}
	private function deliveriesPath( string $repository, string $hookId ): string {
		return $this->hookPath( $repository, $hookId ) . '/deliveries?per_page=100';
	}
	private function repositoryPath( string $repository ): string {
		$parts = explode( '/', trim( $repository ) );
		if ( 2 !== count( $parts ) || 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,100}\z/D', $parts[0] ) || 1 !== preg_match( '/\A[A-Za-z0-9_.-]{1,100}\z/D', $parts[1] ) ) {
			throw new RuntimeException( 'The GitHub repository identity is invalid.', 400 );
		}
		return '/repos/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] );
	}
	/** @return array<string,mixed>|null */
	private function decodeHook( string $body ): ?array {
		$data = json_decode( $body, true, 32, JSON_BIGINT_AS_STRING );
		return is_array( $data ) && null !== $this->hookId( $data ) ? $data : null;
	}
	/** @return list<array<string,mixed>>|null */
	private function decodeList( string $body ): ?array {
		$data = json_decode( $body, true, 32, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			return null;
		}
		foreach ( $data as $hook ) {
			if ( ! is_array( $hook ) || null === $this->hookId( $hook ) ) {
				return null;
			}
		}
		return $data;
	}
	/** @return array<string,true>|null */
	private function deliveryIds( string $body ): ?array {
		$data = json_decode( $body, true, 32, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			return null;
		}
		$ids = array();
		foreach ( $data as $delivery ) {
			if ( ! is_array( $delivery ) || null === $this->deliveryId( $delivery ) ) {
				return null;
			}
			$ids[ $this->deliveryId( $delivery ) ] = true;
		}

		return $ids;
	}
	/** @param array<string,true> $baseline @return array{status_code:?int}|false|null */
	private function newPingDelivery( string $body, array $baseline ): array|false|null {
		$data = json_decode( $body, true, 32, JSON_BIGINT_AS_STRING );
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			return false;
		}
		foreach ( $data as $delivery ) {
			$id = is_array( $delivery ) ? $this->deliveryId( $delivery ) : null;
			if ( ! is_array( $delivery ) || null === $id || ! is_string( $delivery['event'] ?? null ) ) {
				return false;
			}
			if ( ! isset( $baseline[ $id ] ) && 'ping' === $delivery['event'] ) {
				$statusCode = $delivery['status_code'] ?? null;
				if ( null !== $statusCode && ! is_int( $statusCode ) ) {
					return false;
				}

				return array( 'status_code' => $statusCode );
			}
		}

		return null;
	}
	/** @param array<string,mixed> $delivery */
	private function deliveryId( array $delivery ): ?string {
		$id = $delivery['id'] ?? null;
		$id = is_int( $id ) || is_string( $id ) ? trim( (string) $id ) : '';

		return 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/D', $id ) ? $id : null;
	}
	/** @param array<string,mixed>|null $hook */
	private function hookId( ?array $hook ): ?string {
		$id = $hook['id'] ?? null;
		$id = is_int( $id ) || is_string( $id ) ? trim( (string) $id ) : '';
		return 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/D', $id ) ? $id : null;
	}
	/** @param array<string,mixed> $hook @return array{endpoint:string,events:string,content_type:string,active:string} */
	private function configuration( array $hook, string $callbackUrl ): array {
		$config = is_array( $hook['config'] ?? null ) ? $hook['config'] : array();
		$events = is_array( $hook['events'] ?? null ) ? $hook['events'] : null;
		return array(
			'endpoint'     => is_string( $config['url'] ?? null ) ? ( hash_equals( $callbackUrl, $config['url'] ) ? 'matched' : 'mismatched' ) : 'unknown',
			'events'       => is_array( $events ) ? ( array( 'push' ) === $events ? 'matched' : 'mismatched' ) : 'unknown',
			'content_type' => is_string( $config['content_type'] ?? null ) ? ( 'json' === $config['content_type'] ? 'matched' : 'mismatched' ) : 'unknown',
			'active'       => is_bool( $hook['active'] ?? null ) ? ( $hook['active'] ? 'matched' : 'mismatched' ) : 'unknown',
		);
	}
	/** @return array<string,mixed> */
	private function payload( string $callbackUrl, string $secret ): array {
		return array(
			'name'   => 'web',
			'active' => true,
			'events' => array( 'push' ),
			'config' => array(
				'url'          => $callbackUrl,
				'content_type' => 'json',
				'insecure_ssl' => '0',
				'secret'       => $secret,
			),
		);
	}
	/** @param array<string,mixed> $hook @param array{endpoint:string,events:string,content_type:string,active:string} $configuration */
	private function configuredResult( array $hook, array $configuration ): RepositoryWebhookOperationResult {
		$hookId = $this->hookId( $hook );
		if ( null === $hookId || in_array( 'unknown', $configuration, true ) ) {
			return $this->uncertain( 'configuration_readback_invalid', $hookId );
		}
		if ( in_array( 'mismatched', $configuration, true ) ) {
			return $this->result( 'partial', 'configuration_readback_mismatch', $hookId, $configuration, 'unknown', 'Inspect the identified remote hook before retrying.' );
		}
		return $this->result( 'succeeded', 'configured_pending_delivery', $hookId, $configuration, 'configured_pending_delivery', 'Send a correctly signed push delivery before treating the hook as verified.' );
	}
	private function mutationFailure( int $status, string $code, ?string $hookId = null ): RepositoryWebhookOperationResult {
		return in_array( $status, array( 400, 401, 403, 404, 422 ), true )
			? $this->result( 'failed', $code, $hookId, $this->unknownConfiguration(), 'unknown', 'Review repository access and the fixed operation inputs.' )
			: $this->uncertain( $code . '_ambiguous', $hookId );
	}
	private function uncertain( string $code, ?string $hookId = null ): RepositoryWebhookOperationResult {
		return $this->result( 'ambiguous', $code, $hookId, $this->unknownConfiguration(), 'unknown', 'Inspect the provider state before retrying; automatic retry is disabled.' );
	}
	/** @param array{endpoint:string,events:string,content_type:string,active:string} $configuration */
	private function result( string $state, string $code, ?string $hookId, array $configuration, string $delivery, string $remediation ): RepositoryWebhookOperationResult {
		return new RepositoryWebhookOperationResult( $state, $code, $this->now(), $hookId, $configuration, $delivery, $remediation );
	}
	/** @return array{endpoint:string,events:string,content_type:string,active:string} */
	private function unknownConfiguration(): array {
		return array(
			'endpoint'     => 'unknown',
			'events'       => 'unknown',
			'content_type' => 'unknown',
			'active'       => 'unknown',
		);
	}
	private function now(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}
}
