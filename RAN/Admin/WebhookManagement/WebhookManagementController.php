<?php

declare( strict_types = 1 );

namespace RAN\Admin\WebhookManagement;

use RAN\UpdaterSupport\V1\FailureText;
use RAN\Admin\Interaction\AdminInteractionFacade;
use RAN\Admin\Interaction\AdminInteractionOutcome;
use RAN\Admin\Interaction\AdminInteractionRequest;
use RAN\Admin\ManagedPackageWebhookAuthorityResolver;
use RAN\Admin\WebhookManagement\Display\WebhookDisplayModel;
use RAN\Admin\WebhookManagement\Operation\WebhookOperationCoordinator;
use RAN\Admin\WebhookManagement\Operation\WebhookValidationWrapper;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryWebhookFitness;
use RAN\RepositoryProvider\RepositoryWebhookManagement;
use RAN\RepositoryProvider\WebhookNormalizer;

/** @internal Owns the registered request boundary and response transport. */
final class WebhookManagementController {
	public const ADMIN_POST_ACTION         = 'ran_booster_repository_webhook_management_operation';
	public const CREATE_REPOSITORY_SECRET  = 'create_repository_secret';
	public const VALIDATE_TEST_NONCE_FIELD = '_wpnonce_test';
	public const DELIVERY_STATUS_ACTION    = 'ran_booster_repository_webhook_delivery_status';

	private const NONCE_ACTION_PREFIX = 'ran_booster_repository_webhook_';

	/** @var \Closure(): bool */
	private \Closure $can_manage;

	/** @var \Closure(string, string): bool */
	private \Closure $verify_nonce;

	/** @var \Closure(string): string */
	private \Closure $create_nonce;

	private ?AdminInteractionFacade $admin_interaction = null;

	private readonly WebhookValidationWrapper $validation;

	public function __construct(
		private readonly WebhookOperationCoordinator $operations,
		private readonly WebhookDisplayModel $display,
		private readonly ProviderRegistry $providers,
		private readonly ManagedPackageWebhookAuthorityResolver $package_authorities,
		?callable $can_manage = null,
		?callable $verify_nonce = null,
		?callable $create_nonce = null
	) {
		$this->validation   = new WebhookValidationWrapper( $operations );
		$this->can_manage   = null === $can_manage
			? static fn (): bool => current_user_can( 'manage_options' )
			: \Closure::fromCallable( $can_manage );
		$this->verify_nonce = null === $verify_nonce
			? static fn ( string $nonce, string $action ): bool => 1 === wp_verify_nonce( $nonce, $action )
			: \Closure::fromCallable( $verify_nonce );
		$this->create_nonce = null === $create_nonce
			? static fn ( string $action ): string => wp_create_nonce( $action )
			: \Closure::fromCallable( $create_nonce );
	}

	public function use_admin_interaction_facade( AdminInteractionFacade $admin_interaction ): void {
		$this->admin_interaction = $admin_interaction;
	}

	/**
	 * Handle one Core-owned admin-post request and return its safe redirect.
	 *
	 * @param array<string, mixed> $request
	 */
	public function handle_admin_post( #[\SensitiveParameter] array $request, string $nonce ): string {
		$operation     = $this->string_value( $request, 'repository_webhook_management_operation' );
		$provider_code = $this->string_value( $request, 'provider_code' );
		$repository_id = $this->string_value( $request, 'repository_id' );
		$credential_id = $this->string_value( $request, 'booster_credential_id' );
		$profile_id    = $this->string_value( $request, 'webhook_profile_id' );
		$metadata      = $this->provider_metadata( $provider_code );
		$result        = array(
			'code'        => 'invalid_request',
			'recovery'    => null,
			'remediation' => null,
			'successful'  => false,
			'inline_safe' => true,
		);

		$inline_safe = true;
		$successful  = false;
		// Null unless a ping ran, or a Validate skipped it ('skipped'): then the ping operation's own code.
		$ping = null;

		if ( ! ( $this->can_manage )() ) {
			$result['code'] = 'forbidden';
		} elseif ( 'setup' === $operation && '' === $profile_id ) {
			$result['code'] = 'invalid_request';
		} elseif ( in_array( $operation, array( 'validate', 'reconfigure' ), true ) ) {
			// Validate checks the settings and Repair reconfigures the hook, then each asks for a ping. Both operation
			// nonces are verified up front so a bad second nonce never leaves a half-run chain.
			$first = 'validate' === $operation ? 'check' : 'reconfigure';
			if ( $metadata instanceof ProviderMetadata
				&& ( $this->verify_nonce )( $nonce, $this->nonce_action( $first, $provider_code, $repository_id ) )
				&& ( $this->verify_nonce )( $this->string_value( $request, self::VALIDATE_TEST_NONCE_FIELD ), $this->nonce_action( 'test', $provider_code, $repository_id ) ) ) {
				$validation  = $this->validation->then_ping( $first, $provider_code, $repository_id, '' === $credential_id ? null : $credential_id, $nonce, $this->string_value( $request, self::VALIDATE_TEST_NONCE_FIELD ) );
				$result      = $validation['operation'];
				$inline_safe = $result['inline_safe'] && ( null === $validation['ping'] || $validation['ping']['inline_safe'] );
				// A failed Repair keeps its own result, while Validate's Ping line names the skipped ping.
				$ping = $validation['ping']['code'] ?? ( 'validate' === $operation ? 'skipped' : null );
				// Validate succeeds only when its ping does too; a Repair has succeeded once the hook is repaired.
				$successful           = $result['successful'] && ( 'reconfigure' === $operation || ( null !== $validation['ping'] && $validation['ping']['successful'] ) );
				$result['recovery'] ??= $validation['ping']['recovery'] ?? null;
			}
		} elseif ( $metadata instanceof ProviderMetadata
			&& in_array( $operation, array( 'setup', 'check', 'remove', 'test', 'forget' ), true )
			&& ( $this->verify_nonce )( $nonce, $this->nonce_action( $operation, $provider_code, $repository_id ) ) ) {
			$result = $this->operations->execute( $operation, $provider_code, $repository_id, '' === $credential_id ? null : $credential_id, 'setup' === $operation ? ( self::CREATE_REPOSITORY_SECRET === $profile_id ? null : $profile_id ) : null, $nonce );
		}
		if ( null === $ping ) {
			$inline_safe = $result['inline_safe'];
			$successful  = $result['successful'];
		}
		$result_code = $result['code'];

		$safe_repository_id  = strlen( $repository_id ) <= 191 && 0 === preg_match( '/[\x00-\x1F\x7F]/', $repository_id ) ? $repository_id : '';
		$return_url          = $this->safe_return_url( $this->string_value( $request, 'return_url' ), $provider_code, $safe_repository_id );
		$interaction_request = $this->interaction_request( $return_url );
		if ( null !== $this->admin_interaction
			&& null !== $interaction_request
			&& $metadata instanceof ProviderMetadata
			&& true === $inline_safe ) {
			$notice  = null === $ping
				? $this->display->notice( $result_code, $result['recovery'], $result['remediation'] )
				: $this->display->validation_notice( $result_code, $ping, $result['recovery'], $result['remediation'] );
			$outcome = true === $successful
				? AdminInteractionOutcome::success( $interaction_request, $notice )
				: AdminInteractionOutcome::validation_failure( $interaction_request, $notice );
			$this->admin_interaction->respond( $outcome );
		}

		// A successful result has its own fixed sentence, so provider guidance rides only on the redirect of one that is not.
		$remediation = false === $result['inline_safe'] && true !== $successful ? $this->safe_remediation( $result['remediation'] ) : null;
		$hook_id     = null !== $result['recovery'] ? $result['recovery']['hook_id'] : null;
		$profile_id  = null !== $result['recovery'] ? $result['recovery']['profile_id'] : null;
		$args        = array( 'webhook_management_result' => $result_code );
		if ( null !== $ping ) {
			$args['webhook_management_ping'] = $ping;
		}
		if ( null !== $hook_id && null !== $profile_id ) {
			$args['recovery_hook']    = $hook_id;
			$args['recovery_profile'] = $profile_id;
		}
		if ( null !== $remediation ) {
			$args['webhook_management_remediation'] = $remediation;
		}
		$args['webhook_management_provider']       = $provider_code;
		$args['webhook_management_repository']     = $safe_repository_id;
		$args['_ran_booster_webhook_result_nonce'] = ( $this->create_nonce )(
			self::result_nonce_action( $provider_code, $safe_repository_id, $result_code, $ping, $hook_id, $profile_id, $remediation )
		);

		return add_query_arg( array_map( 'rawurlencode', $args ), $return_url );
	}

	/**
	 * Whether a signed delivery has verified the stored record, for a result listening after its ping; null when refused.
	 * It reads Booster's record and the site's signing secrets only, never the Git provider.
	 *
	 * @param array<string, mixed> $query
	 */
	public function delivery_status( array $query ): ?bool {
		$provider_code = $this->string_value( $query, 'provider_code' );
		$repository_id = $this->string_value( $query, 'repository_id' );
		if ( ! ( $this->can_manage )()
			|| ! $this->provider_metadata( $provider_code ) instanceof ProviderMetadata
			|| ! ( $this->verify_nonce )( $this->string_value( $query, '_wpnonce' ), $this->nonce_action( 'delivery', $provider_code, $repository_id ) ) ) {
			return null;
		}

		return $this->display->delivery_confirmed( $provider_code, $repository_id );
	}

	/** @return list<ProviderMetadata> */
	public function provider_metadata_list(): array {
		$metadata = array();
		foreach ( $this->providers->ordered_metadata() as $candidate ) {
			$capable = $this->capable_provider_metadata( $candidate->code->value );
			if ( $capable instanceof ProviderMetadata ) {
				$metadata[] = $capable;
			}
		}

		return $metadata;
	}

	public function provider_metadata( string $provider_code ): ?ProviderMetadata {
		return $this->capable_provider_metadata( $provider_code );
	}

	/** @return array{result:?string,ping:?string,recovery:array{hook_id:string,profile_id:string}|null,remediation:?string} */
	public function panel_context( ?string $expected_provider_code = null, ?string $expected_repository_id = null ): array {
		$empty = array(
			'result'      => null,
			'ping'        => null,
			'recovery'    => null,
			'remediation' => null,
		);
		$get   = $GLOBALS['_GET'] ?? null;
		$query = is_array( $get ) ? wp_unslash( $get ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below with _ran_booster_webhook_result_nonce.
		$code  = $this->string_value( $query, 'webhook_management_result' );
		if ( '' === $code ) {
			return $empty;
		}

		$provider_code = $this->string_value( $query, 'webhook_management_provider' );
		$repository_id = $this->string_value( $query, 'webhook_management_repository' );
		if ( '' === $provider_code || '' === $repository_id ) {
			$provider_code = $this->string_value( $query, 'tab' );
			$repository_id = $this->string_value( $query, 'repository' );
		}
		if ( ( null !== $expected_provider_code && ! hash_equals( $expected_provider_code, $provider_code ) )
			|| ( null !== $expected_repository_id && ! hash_equals( $expected_repository_id, $repository_id ) ) ) {
			return $empty;
		}

		$safe_reference = static fn ( mixed $value ): ?string => is_string( $value )
			&& 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/', $value )
			? $value
			: null;
		$raw_hook       = $query['recovery_hook'] ?? null;
		$raw_profile    = $query['recovery_profile'] ?? null;
		$hook_id        = null === $raw_hook ? null : $safe_reference( $raw_hook );
		$profile_id     = null === $raw_profile ? null : $safe_reference( $raw_profile );
		if ( ( null !== $raw_hook && null === $hook_id )
			|| ( null !== $raw_profile && null === $profile_id )
			|| ( null === $hook_id ) !== ( null === $profile_id ) ) {
			return $empty;
		}

		$raw_remediation = $query['webhook_management_remediation'] ?? null;
		$remediation     = null === $raw_remediation ? null : $this->safe_remediation( $raw_remediation );
		if ( null !== $raw_remediation && null === $remediation ) {
			return $empty;
		}

		$ping         = $this->string_value( $query, 'webhook_management_ping' );
		$signed_ping  = '' === $ping ? null : $ping;
		$result_nonce = $this->string_value( $query, '_ran_booster_webhook_result_nonce' );
		if ( '' === $result_nonce
			|| ! ( $this->verify_nonce )(
				$result_nonce,
				self::result_nonce_action( $provider_code, $repository_id, $code, $signed_ping, $hook_id, $profile_id, $remediation )
			) ) {
			return $empty;
		}

		return array(
			'result'      => $this->safe_code( $code, 'request_completed' ),
			'ping'        => null === $signed_ping ? null : $this->safe_code( $signed_ping, 'operation_failed' ),
			'recovery'    => null !== $hook_id && null !== $profile_id ? array(
				'hook_id'    => $hook_id,
				'profile_id' => $profile_id,
			) : null,
			'remediation' => $remediation,
		);
	}

	public static function result_nonce_action(
		string $provider_code,
		string $repository_id,
		string $code,
		?string $ping = null,
		?string $hook_id = null,
		?string $profile_id = null,
		?string $remediation = null
	): string {
		$fields = array(
			$provider_code,
			$repository_id,
			$code,
			null === $ping ? '' : $ping,
			null === $hook_id ? '' : $hook_id,
			null === $profile_id ? '' : $profile_id,
			null === $remediation ? '' : $remediation,
		);

		return 'ran_booster_repository_webhook_result_' . hash( 'sha256', implode( "\0", $fields ) );
	}

	private function safe_remediation( mixed $remediation ): ?string {
		return is_string( $remediation )
			&& FailureText::display( $remediation, 255 ) === $remediation
			? $remediation
			: null;
	}

	private function interaction_request( string $return_url ): ?AdminInteractionRequest {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The URL has already been reconstructed by safeReturnUrl().
		$query = parse_url( $return_url, PHP_URL_QUERY );
		parse_str( is_string( $query ) ? $query : '', $arguments );
		if ( 'ran-booster' !== ( $arguments['page'] ?? '' ) ) {
			return null;
		}

		return AdminInteractionRequest::provider_repositories(
			'repository-webhook-management:manage-webhook',
			$return_url,
			'repository-webhook-management-error'
		);
	}

	private function safe_return_url( string $candidate, string $provider_code, string $repository_id ): string {
		$fallback = WebhookManagementAdminUrl::for_path( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider_code ) ) . '&panel=repositories'
			. ( '' === $repository_id ? '' : '&repository=' . rawurlencode( $repository_id ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Reconstructs an allowlisted same-admin route; the candidate is never returned directly.
		$parts = parse_url( $candidate );
		if ( ! is_array( $parts ) ) {
			return $fallback;
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$page    = is_string( $query['page'] ?? null ) ? $query['page'] : '';
		$package = is_string( $query['package'] ?? null ) ? $query['package'] : '';
		$tab     = is_string( $query['tab'] ?? null ) ? $query['tab'] : '';
		$panel   = is_string( $query['panel'] ?? null ) ? $query['panel'] : '';
		$target  = is_string( $query['repository'] ?? null ) ? $query['repository'] : '';
		$view    = is_string( $query['repository_view'] ?? null ) ? $query['repository_view'] : '';
		if ( 'ran-booster' === $page
			&& hash_equals( $provider_code, $tab )
			&& 'repositories' === $panel
			&& '' !== $repository_id
			&& hash_equals( $repository_id, $target ) ) {
			return WebhookManagementAdminUrl::for_path( 'admin.php?page=ran-booster&tab=' . rawurlencode( $provider_code ) . '&panel=repositories&repository=' . rawurlencode( $repository_id ) . ( in_array( $view, array( 'status', 'branch', 'releases' ), true ) ? '&repository_view=' . rawurlencode( $view ) : '' ) );
		}
		if ( ! in_array( $page, array( 'ran-booster-plugins', 'ran-booster-themes' ), true )
			|| '' === $package || strlen( $package ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $package )
			|| ! $this->package_return_matches_operation( $page, $package, $provider_code, $repository_id ) ) {
			return $fallback;
		}

		return WebhookManagementAdminUrl::for_path( 'admin.php' ) . '?page=' . $page . '&package=' . rawurlencode( $package ) . '&source_view=branch&ran_booster_open_advanced=1';
	}

	/** Prove that a package-settings return URL belongs to this signed repository operation. */
	private function package_return_matches_operation( string $page, string $package, string $provider_code, string $repository_id ): bool {
		$type      = 'ran-booster-plugins' === $page ? 'plugin' : 'theme';
		$authority = $this->package_authorities->for_package( $type, $package );

		return null !== $authority
			&& hash_equals( $provider_code, $authority['provider_code'] )
			&& hash_equals( $repository_id, $authority['repository_id'] );
	}

	private function capable_provider_metadata( string $provider_code ): ?ProviderMetadata {
		try {
			$fitness    = $this->providers->require_capability( $provider_code, RepositoryWebhookFitness::class );
			$management = $this->providers->require_capability( $provider_code, RepositoryWebhookManagement::class );
			$normalizer = $this->providers->require_capability( $provider_code, WebhookNormalizer::class );
			$metadata   = $this->providers->metadata()[ $provider_code ] ?? null;
		} catch ( \Throwable ) {
			return null;
		}

		return $fitness === $management && $management === $normalizer && $metadata instanceof ProviderMetadata
			&& hash_equals( $provider_code, $metadata->code->value )
			? $metadata
			: null;
	}

	private function nonce_action( string $operation, string $provider_code, string $repository_id ): string {
		return self::NONCE_ACTION_PREFIX . implode( '_', array( $operation, $provider_code, $repository_id ) );
	}

	private function safe_code( mixed $code, string $fallback ): string {
		return is_string( $code ) && 1 === preg_match( '/^[a-z0-9][a-z0-9._-]{0,95}$/', $code ) ? $code : $fallback;
	}

	/** @param array<string, mixed> $request */
	private function string_value( array $request, string $key ): string {
		return is_string( $request[ $key ] ?? null ) ? trim( $request[ $key ] ) : '';
	}
}
