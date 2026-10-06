<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\CredentialValidationResult;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\PushEvent;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseMode;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\WebhookEnvelope;
use RAN\RepositoryProvider\WebhookRequest;

final class NormalizedValuesTest extends TestCase {

	private const GITHUB_HEADERS    = array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' );
	private const BITBUCKET_HEADERS = array( 'x-event-key', 'x-request-uuid', 'x-hub-signature' );

	public function test_repository_descriptor_has_the_exact_normalized_edge_shape(): void {
		$repository = new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			'RocketsAreNostalgic/ran-booster',
			'ran-booster',
			'1234',
			true,
			'main',
			'credential-one'
		);

		self::assertSame(
			array(
				'provider'               => 'gh',
				'locator'                => 'RocketsAreNostalgic/ran-booster',
				'package_slug'           => 'ran-booster',
				'provider_repository_id' => '1234',
				'private'                => true,
				'default_branch'         => 'main',
				'credential_id'          => 'credential-one',
			),
			$repository->to_array()
		);
		self::assertSame(
			$repository->to_array(),
			array_intersect_key( $repository->to_array(), array_flip( array_keys( $repository->to_array() ) ) )
		);
	}

	public function test_repository_contracts_keep_opaque_nested_locators_without_rewriting_provider_data(): void {
		$request    = new RepositoryLookupRequest(
			' group/subgroup/package '
		);
		$repository = new RepositoryDescriptor(
			ProviderCode::parse( 'fixture' ),
			' group/subgroup/package ',
			'package',
			'fixture:group/subgroup/package',
			false,
			'main',
			null
		);
		$reference  = RepositoryReference::from_descriptor( $repository );

		self::assertSame( ' group/subgroup/package ', $request->locator );
		self::assertSame( ' group/subgroup/package ', $repository->locator );
		self::assertSame( ' group/subgroup/package ', $reference->locator );
		self::assertSame( 'package', $repository->package_slug );
	}

	public function test_repository_descriptor_preserves_provider_identity_casing(): void {
		$repository = new RepositoryDescriptor(
			ProviderCode::parse( 'gh' ),
			'RocketsAreNostalgic/tnyGmaps',
			'tnyGmaps',
			'565105478',
			false,
			'master',
			null
		);

		self::assertSame( 'RocketsAreNostalgic/tnyGmaps', $repository->locator );
		self::assertSame( 'tnyGmaps', $repository->package_slug );
		self::assertSame( '565105478', $repository->provider_repository_id );
	}

	public function test_repository_contracts_accept_the_exact_locator_and_package_slug_byte_bounds(): void {
		$locator = str_repeat( 'l', 512 );
		$slug    = str_repeat( 's', 191 );

		self::assertSame( $locator, ( new RepositoryLookupRequest( $locator ) )->locator );
		self::assertSame(
			$slug,
			( new RepositoryDescriptor( ProviderCode::parse( 'fixture' ), $locator, $slug, 'fixture-id', false, 'main', null ) )->package_slug
		);
	}

	#[DataProvider( 'invalid_opaque_locators' )]
	public function test_repository_contracts_reject_invalid_opaque_locators( string $locator ): void {
		foreach ( array( 'lookup', 'descriptor', 'reference' ) as $contract ) {
			try {
				match ( $contract ) {
					'lookup' => new RepositoryLookupRequest( $locator ),
					'descriptor' => new RepositoryDescriptor( ProviderCode::parse( 'fixture' ), $locator, 'package', 'fixture-id', false, 'main', null ),
					'reference' => new RepositoryReference( $locator, 'fixture-id', false, null ),
				};
				self::fail( 'Expected an invalid repository locator to be rejected.' );
			} catch ( InvalidArgumentException ) {
				self::assertTrue( true );
			}
		}
	}

	/** @return list<array{string}> */
	public static function invalid_opaque_locators(): array {
		return array(
			array( '' ),
			array( "group/pack\nage" ),
			array( str_repeat( 'l', 513 ) ),
		);
	}

	#[DataProvider( 'invalid_provider_package_slugs' )]
	public function test_repository_descriptor_rejects_invalid_provider_package_slugs( string $slug ): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryDescriptor( ProviderCode::parse( 'fixture' ), 'group/subgroup/package', $slug, 'fixture-id', false, 'main', null );
	}

	/** @return list<array{string}> */
	public static function invalid_provider_package_slugs(): array {
		return array(
			array( '' ),
			array( 'group/package' ),
			array( '../package' ),
			array( str_repeat( 's', 192 ) ),
		);
	}

	public function test_repository_references_are_derived_without_credential_material(): void {
		$repository = new RepositoryDescriptor(
			ProviderCode::parse( 'bb' ),
			'workspace/project',
			'project',
			'{repository-uuid}',
			false,
			'main',
			null
		);
		$reference  = RepositoryReference::from_descriptor( $repository );
		$request    = new ArchiveRequest( $reference, 'd34db33f', 'main' );

		self::assertSame( 'workspace/project', $reference->locator );
		self::assertFalse( $reference->private );
		self::assertSame( 'd34db33f', $request->ref );
		self::assertSame( 'main', $request->expected_branch );
		self::assertSame( $reference, $request->repository );
	}

	public function test_archive_request_leaves_the_expected_branch_optional_and_rejects_ablank_expectation(): void {
		$reference = new RepositoryReference(
			'workspace/project',
			'{repository-uuid}',
			false,
			null
		);

		self::assertNull( ( new ArchiveRequest( $reference, 'd34db33f' ) )->expected_branch );

		$this->expectException( InvalidArgumentException::class );

		new ArchiveRequest( $reference, 'd34db33f', " \t\n" );
	}

	public function test_repository_browse_request_carries_only_scope_and_credential_identity(): void {
		$request = RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic' );

		self::assertSame( RepositoryBrowseMode::PUBLIC_OWNER, $request->get_mode() );
		self::assertSame( 'RocketsAreNostalgic', $request->get_owner() );
		self::assertNull( $request->get_credential_id() );

		$accessible = RepositoryBrowseRequest::accessible( 'credential-one' );

		self::assertSame( RepositoryBrowseMode::ACCESSIBLE, $accessible->get_mode() );
		self::assertNull( $accessible->get_owner() );
		self::assertSame( 'credential-one', $accessible->get_credential_id() );
	}

	public function test_public_repository_browse_request_may_carry_one_profile_identity(): void {
		$request = RepositoryBrowseRequest::public_owner( 'RocketsAreNostalgic', 'public-lookup' );

		self::assertSame( RepositoryBrowseMode::PUBLIC_OWNER, $request->get_mode() );
		self::assertSame( 'RocketsAreNostalgic', $request->get_owner() );
		self::assertSame( 'public-lookup', $request->get_credential_id() );
	}

	public function test_accessible_browse_requires_one_credential_profile(): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryBrowseRequest( RepositoryBrowseMode::ACCESSIBLE );
	}

	public function test_push_event_has_the_exact_normalized_edge_shape(): void {
		$event = new PushEvent(
			ProviderCode::parse( 'bb' ),
			'workspace/project',
			'{repository-uuid}',
			'main',
			'd34db33f',
			'delivery-one'
		);

		self::assertSame(
			array(
				'provider'               => 'bb',
				'repository'             => 'workspace/project',
				'provider_repository_id' => '{repository-uuid}',
				'branch'                 => 'main',
				'commit'                 => 'd34db33f',
				'delivery_id'            => 'delivery-one',
			),
			$event->to_array()
		);
	}

	public function test_webhook_envelope_supports_multiple_push_events(): void {
		$first  = new PushEvent( ProviderCode::parse( 'bb' ), 'workspace/project', 'one', 'main', 'aaa', 'request' );
		$second = new PushEvent( ProviderCode::parse( 'bb' ), 'workspace/project', 'one', 'release', 'bbb', 'request' );
		$result = WebhookEnvelope::events( $first, $second );

		self::assertTrue( $result->has_events() );
		self::assertFalse( $result->is_probe() );
		self::assertFalse( $result->is_ignored() );
		self::assertSame( array( $first, $second ), $result->get_events() );
	}

	public function test_webhook_envelope_normalizes_named_and_mixed_arguments_to_an_ordered_list(): void {
		$first  = new PushEvent( ProviderCode::parse( 'bb' ), 'workspace/project', 'one', 'main', 'aaa', 'request' );
		$second = new PushEvent( ProviderCode::parse( 'bb' ), 'workspace/project', 'one', 'release', 'bbb', 'request' );

		foreach ( array(
			WebhookEnvelope::events( second: $first, first: $second ),
			WebhookEnvelope::events( $first, remaining: $second ),
		) as $envelope ) {
			self::assertSame( array( $first, $second ), $envelope->get_events() );
		}
	}

	public function test_webhook_envelope_distinguishes_probe_and_ignored_requests(): void {
		self::assertTrue( WebhookEnvelope::probe()->is_probe() );
		self::assertSame( array(), WebhookEnvelope::probe()->get_events() );
		self::assertTrue( WebhookEnvelope::ignored()->is_ignored() );
		self::assertSame( array(), WebhookEnvelope::ignored()->get_events() );
	}

	public function test_event_envelope_rejects_an_empty_event_list(): void {
		$this->expectException( InvalidArgumentException::class );

		WebhookEnvelope::events();
	}

	public function test_webhook_request_accepts_native_word_press_header_shape(): void {
		$request = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{"ref":"refs/heads/main"}',
			array(
				'X-GitHub-Event'      => array( 'push' ),
				'X-GitHub-Delivery'   => array( 'delivery-one' ),
				'X-Hub-Signature-256' => array( 'sha256=signature' ),
			),
			self::GITHUB_HEADERS
		);

		self::assertSame( '{"ref":"refs/heads/main"}', $request->get_body() );
		self::assertSame( 'push', $request->get_header( 'x-github-event' ) );
		self::assertSame( 'delivery-one', $request->get_header( 'X-GITHUB-DELIVERY' ) );
		self::assertSame( 'sha256=signature', $request->get_header( 'x-hub-signature-256' ) );
		self::assertSame( array( 'push' ), $request->get_raw_header_values( 'X_GITHUB_EVENT' ) );
	}

	public function test_webhook_request_retains_equivalent_raw_values_and_aliases(): void {
		$request = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array(
				'X-GitHub-Event' => array( ' push ', 'push' ),
				'X_GitHub_Event' => 'push',
			),
			self::GITHUB_HEADERS
		);

		self::assertSame( 'push', $request->get_header( 'x-github-event' ) );
		self::assertSame( array( ' push ', 'push', 'push' ), $request->get_raw_header_values( 'x-github-event' ) );
		self::assertSame( array(), $request->get_raw_header_values( 'authorization' ) );
	}

	public function test_webhook_header_names_are_case_and_separator_insensitive(): void {
		$request = new WebhookRequest(
			ProviderCode::parse( 'bb' ),
			'{}',
			array(
				'X_EVENT_KEY'     => array( 'repo:push' ),
				'x_request_UUID'  => 'request-one',
				'X_HUB_SIGNATURE' => array( 'signature' ),
			),
			self::BITBUCKET_HEADERS
		);

		self::assertSame( 'repo:push', $request->get_header( 'x-event-key' ) );
		self::assertSame( 'request-one', $request->get_header( 'X_REQUEST_UUID' ) );
		self::assertSame( 'signature', $request->get_header( 'x-hub-signature' ) );
	}

	public function test_webhook_request_drops_all_unplanned_and_sensitive_headers(): void {
		$request = new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array(
				'Authorization'   => array( 'Bearer omitted' ),
				'Cookie'          => array( 'session=omitted' ),
				'X-Custom-Secret' => array( 'omitted' ),
				'X-GitHub-Event'  => array( 'push' ),
			),
			self::GITHUB_HEADERS
		);

		self::assertNull( $request->get_header( 'authorization' ) );
		self::assertNull( $request->get_header( 'cookie' ) );
		self::assertNull( $request->get_header( 'x-custom-secret' ) );
		self::assertSame( 'push', $request->get_header( 'x-github-event' ) );
	}

	public function test_webhook_request_rejects_ambiguous_retained_header_values(): void {
		$this->expectException( InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array( 'X-GitHub-Event' => array( 'push', 'ping' ) ),
			self::GITHUB_HEADERS
		);
	}

	public function test_webhook_request_rejects_missing_retained_header_values(): void {
		$this->expectException( InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array( 'X-GitHub-Event' => array() ),
			self::GITHUB_HEADERS
		);
	}

	public function test_webhook_request_rejects_oversized_retained_header_values(): void {
		$this->expectException( InvalidArgumentException::class );

		new WebhookRequest(
			ProviderCode::parse( 'gh' ),
			'{}',
			array( 'X-GitHub-Event' => str_repeat( 'e', 257 ) ),
			self::GITHUB_HEADERS
		);
	}

	public function test_webhook_request_rejects_oversized_aggregate_retained_headers(): void {
		$headers = array();
		$policy  = array();
		foreach ( range( 1, 16 ) as $index ) {
			$name             = 'x-provider-' . $index;
			$headers[ $name ] = str_repeat( 'v', 128 );
			$policy[]         = $name;
		}

		$this->expectException( InvalidArgumentException::class );
		new WebhookRequest( ProviderCode::parse( 'gh' ), '{}', $headers, $policy );
	}

	public function test_valid_credential_validation_result_contains_no_display_message(): void {
		self::assertTrue( CredentialValidationResult::valid()->is_valid() );
		self::assertNull( CredentialValidationResult::valid()->get_display_message() );
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function untrusted_credential_validation_messages(): array {
		return array(
			'canary'    => array(
				'invalid',
				'provider-validation-canary',
				CredentialValidationResult::INVALID,
				'The repository provider rejected this credential.',
			),
			'header'    => array(
				'unavailable',
				'Authorization: Bearer provider-header-canary',
				CredentialValidationResult::UNAVAILABLE,
				'The repository provider could not validate this credential. Try again later.',
			),
			'multiline' => array(
				'rate_limited',
				"Validation failed\r\nSet-Cookie: provider-multiline-canary=1",
				CredentialValidationResult::RATE_LIMITED,
				'The repository provider rate-limited credential validation. Try again later.',
			),
			'oversize'  => array(
				'invalid_response',
				str_repeat( 'provider-oversize-canary-', 256 ),
				CredentialValidationResult::INVALID_RESPONSE,
				'The repository provider returned an invalid credential-validation response.',
			),
		);
	}

	#[DataProvider( 'untrusted_credential_validation_messages' )]
	public function test_credential_validation_result_discards_untrusted_provider_text(
		string $factory,
		string $untrusted_message,
		string $reason,
		string $expected_message
	): void {
		$factory_method = new \ReflectionMethod( CredentialValidationResult::class, $factory );
		$result         = $factory_method->invokeArgs( null, array( $untrusted_message ) );

		self::assertSame( 0, $factory_method->getNumberOfParameters() );
		self::assertInstanceOf( CredentialValidationResult::class, $result );
		self::assertFalse( $result->is_valid() );
		self::assertSame( $reason, $result->reason );
		self::assertSame( $expected_message, $result->get_display_message() );
		self::assertNotSame( '', $result->get_display_message() );
		self::assertLessThanOrEqual( 160, strlen( $expected_message ) );
		self::assertDoesNotMatchRegularExpression( '/[\x00-\x1F\x7F]/', $expected_message );
		self::assertStringNotContainsString( 'canary', $expected_message );
		self::assertStringNotContainsString( $untrusted_message, $expected_message );
	}

	/**
	 * @return list<array{class-string}>
	 */
	public static function credential_free_data_transfer_objects(): array {
		return array(
			array( RepositoryDescriptor::class ),
			array( RepositoryBrowseRequest::class ),
			array( RepositoryReference::class ),
			array( ArchiveRequest::class ),
			array( PushEvent::class ),
		);
	}

	#[DataProvider( 'credential_free_data_transfer_objects' )]
	public function test_data_transfer_objects_do_not_expose_raw_secret_or_token_fields( string $class_name ): void {
		$properties = array_map(
			static fn ( \ReflectionProperty $property ): string => $property->getName(),
			( new ReflectionClass( $class_name ) )->getProperties()
		);

		self::assertDoesNotMatchRegularExpression( '/(?:token|secret)/i', implode( ' ', $properties ) );
	}
}
