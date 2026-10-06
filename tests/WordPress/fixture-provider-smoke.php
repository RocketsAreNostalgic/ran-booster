<?php

// Executed by WP-CLI inside a disposable WordPress installation.

if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) || 14 !== RAN_BOOSTER_PROVIDER_API_VERSION ) {
	throw new RuntimeException( 'Provider API 14 is unavailable.' );
}

if ( defined( 'RAN_BOOSTER_LOGGING_API_VERSION' ) ) {
	throw new RuntimeException( 'The removed Logging API marker is available.' );
}

if ( function_exists( 'ran_booster' )
	|| array_key_exists( 'ran_booster_instance', $GLOBALS )
	|| method_exists( RAN\Booster::class, 'getInstance' )
	|| method_exists( RAN\Booster::class, 'setInstance' )
	|| method_exists( RAN\Booster::class, 'make' )
	|| method_exists( RAN\Booster::class, 'bind' )
) {
	throw new RuntimeException( 'The removed global Core container acquisition path is available.' );
}

$ran_booster_container = require __DIR__ . '/core-container-fixture.php';
$ran_booster_registry  = $ran_booster_container->make( RAN\RepositoryProvider\ProviderRegistry::class );
$ran_booster_provider  = $ran_booster_registry->get( 'fixture-provider' );
if ( 0 !== $ran_booster_provider->get_client()->get_request_count() ) {
	throw new RuntimeException( 'Provider registration must not contact the provider client.' );
}

if ( ! $ran_booster_registry->is_sealed()
	|| ! $ran_booster_provider instanceof RAN_Booster_FixtureProvider\Provider
	// @phpstan-ignore instanceof.alwaysFalse (Installed fixture acceptance verifies the actual loaded provider lacks the optional browser facet.)
	|| $ran_booster_provider instanceof RAN\RepositoryProvider\RepositoryBrowser
	// @phpstan-ignore instanceof.alwaysTrue (Installed fixture acceptance verifies the actual loaded provider supplies the webhook facet.)
	|| ! $ran_booster_provider instanceof RAN\RepositoryProvider\WebhookNormalizer
) {
	throw new RuntimeException( 'The external fixture provider contract is not active.' );
}

$ran_booster_package_form     = $ran_booster_container->make( RAN\Admin\ProviderSettingsPresenter::class )->build_package_form( 'fixture-provider' );
$ran_booster_package_provider = array_column( $ran_booster_package_form['providers'], null, 'code' )['fixture-provider'] ?? null;

if ( 'fixture-provider' !== $ran_booster_package_form['default_provider']
	|| ! is_array( $ran_booster_package_provider )
	|| empty( $ran_booster_package_provider['deploy'] )
	|| ! empty( $ran_booster_package_provider['browse'] )
	|| empty( $ran_booster_package_provider['webhooks'] )
) {
	throw new RuntimeException( 'The external fixture package form contract is invalid.' );
}

$ran_booster_descriptor = $ran_booster_provider->resolve_repository(
	new RAN\RepositoryProvider\RepositoryLookupRequest(
		'group/subgroup/package'
	)
);

if ( 'group/subgroup/package' !== $ran_booster_descriptor->locator
	|| 'package' !== $ran_booster_descriptor->package_slug
	|| '' === $ran_booster_descriptor->provider_repository_id
) {
	throw new RuntimeException( 'The external fixture repository identity is invalid.' );
}

$ran_booster_resolved_ref = sha1( "group/subgroup/package\0main" );
$ran_booster_archive      = $ran_booster_provider->prepare_archive(
	new RAN\RepositoryProvider\ArchiveRequest(
		new RAN\RepositoryProvider\RepositoryReference(
			$ran_booster_descriptor->locator,
			$ran_booster_descriptor->provider_repository_id,
			$ran_booster_descriptor->private,
			$ran_booster_descriptor->credential_id
		),
		$ran_booster_resolved_ref,
		'main'
	)
);

try {
	if ( $ran_booster_resolved_ref !== $ran_booster_archive->get_resolved_ref() ) {
		throw new RuntimeException( 'The external fixture archive ref is not immutable.' );
	}
	$ran_booster_archive->verify_current_head();
} finally {
	$ran_booster_archive->cleanup();
}

$ran_booster_diagnostic_request = new RAN\RepositoryProvider\ProviderDiagnosticRequest( null, 'group/subgroup/package' );
$ran_booster_diagnostic_results = $ran_booster_provider->get_provider_diagnostics()->diagnose( $ran_booster_diagnostic_request );

if ( 3 !== count( $ran_booster_diagnostic_results )
	|| 2 !== $ran_booster_diagnostic_request->get_remote_calls()
	|| 2 !== count( $ran_booster_provider->get_client()->get_diagnostic_timeouts() )
) {
	throw new RuntimeException( 'The external fixture diagnostics contract is invalid.' );
}

foreach ( $ran_booster_provider->get_client()->get_diagnostic_timeouts() as $ran_booster_timeout ) {
	if ( $ran_booster_timeout <= 0.0 || $ran_booster_timeout > RAN\RepositoryProvider\ProviderDiagnosticRequest::MAX_SECONDS ) {
		throw new RuntimeException( 'The external fixture diagnostic timeout is invalid.' );
	}
}

$ran_booster_troubleshooting = $ran_booster_container->make( RAN\Troubleshooting\TroubleshootingService::class )->diagnose(
	'fixture-provider',
	null,
	'group/subgroup/package'
);
$ran_booster_result_codes    = array_column( $ran_booster_troubleshooting['results'] ?? array(), 'code' );

if ( in_array( $ran_booster_troubleshooting['partial_reason'] ?? null, array( 'provider_results_invalid', 'provider_unavailable' ), true )
	|| ! in_array( 'fixture-provider.environment.ready', $ran_booster_result_codes, true )
	|| ! in_array( 'fixture-provider.repository.reachable', $ran_booster_result_codes, true )
) {
	throw new RuntimeException( 'The external fixture troubleshooting integration is invalid.' );
}

foreach ( array( RAN\RepositoryProvider\RepositoryBrowser::class, RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser::class, RAN\RepositoryProvider\RepositoryReleaseCandidateListing::class ) as $ran_booster_capability ) {
	try {
		$ran_booster_registry->require_capability( 'fixture-provider', $ran_booster_capability );
		throw new RuntimeException( 'The external fixture exposed an unsupported capability.' );
	// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This expected exception is the exercised negative path; the surrounding proof continues deliberately.
	} catch ( RAN\RepositoryProvider\UnsupportedProviderCapability ) {
		// Expected: public browsing, authenticated public browsing and webhooks are independently optional.
	}
}

WP_CLI::success( 'External fixture provider registered, resolved, diagnosed and presented.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
