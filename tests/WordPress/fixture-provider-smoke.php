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

$container = require __DIR__ . '/core-container-fixture.php';
$registry  = $container->make( RAN\RepositoryProvider\ProviderRegistry::class );
$provider  = $registry->get( 'fixture-provider' );
if ( 0 !== $provider->get_client()->get_request_count() ) {
	throw new RuntimeException( 'Provider registration must not contact the provider client.' );
}

if ( ! $registry->is_sealed()
	|| ! $provider instanceof RANBoosterFixtureProvider\Provider
	|| $provider instanceof RAN\RepositoryProvider\RepositoryBrowser
	|| ! $provider instanceof RAN\RepositoryProvider\WebhookNormalizer
) {
	throw new RuntimeException( 'The external fixture provider contract is not active.' );
}

$package_form     = $container->make( RAN\Admin\ProviderSettingsPresenter::class )->build_package_form( 'fixture-provider' );
$package_provider = array_column( $package_form['providers'], null, 'code' )['fixture-provider'] ?? null;

if ( 'fixture-provider' !== $package_form['default_provider']
	|| ! is_array( $package_provider )
	|| empty( $package_provider['deploy'] )
	|| ! empty( $package_provider['browse'] )
	|| empty( $package_provider['webhooks'] )
) {
	throw new RuntimeException( 'The external fixture package form contract is invalid.' );
}

$descriptor = $provider->resolve_repository(
	new RAN\RepositoryProvider\RepositoryLookupRequest(
		'group/subgroup/package'
	)
);

if ( 'group/subgroup/package' !== $descriptor->locator
	|| 'package' !== $descriptor->package_slug
	|| '' === $descriptor->provider_repository_id
) {
	throw new RuntimeException( 'The external fixture repository identity is invalid.' );
}

$resolved_ref = sha1( "group/subgroup/package\0main" );
$archive      = $provider->prepare_archive(
	new RAN\RepositoryProvider\ArchiveRequest(
		new RAN\RepositoryProvider\RepositoryReference(
			$descriptor->locator,
			$descriptor->provider_repository_id,
			$descriptor->private,
			$descriptor->credential_id
		),
		$resolved_ref,
		'main'
	)
);

try {
	if ( $resolved_ref !== $archive->get_resolved_ref() ) {
		throw new RuntimeException( 'The external fixture archive ref is not immutable.' );
	}
	$archive->verify_current_head();
} finally {
	$archive->cleanup();
}

$diagnostic_request = new RAN\RepositoryProvider\ProviderDiagnosticRequest( null, 'group/subgroup/package' );
$diagnostic_results = $provider->get_provider_diagnostics()->diagnose( $diagnostic_request );

if ( 3 !== count( $diagnostic_results )
	|| 2 !== $diagnostic_request->get_remote_calls()
	|| 2 !== count( $provider->get_client()->get_diagnostic_timeouts() )
) {
	throw new RuntimeException( 'The external fixture diagnostics contract is invalid.' );
}

foreach ( $provider->get_client()->get_diagnostic_timeouts() as $timeout ) {
	if ( $timeout <= 0.0 || $timeout > RAN\RepositoryProvider\ProviderDiagnosticRequest::MAX_SECONDS ) {
		throw new RuntimeException( 'The external fixture diagnostic timeout is invalid.' );
	}
}

$troubleshooting = $container->make( RAN\Troubleshooting\TroubleshootingService::class )->diagnose(
	'fixture-provider',
	null,
	'group/subgroup/package'
);
$result_codes    = array_column( $troubleshooting['results'] ?? array(), 'code' );

if ( in_array( $troubleshooting['partial_reason'] ?? null, array( 'provider_results_invalid', 'provider_unavailable' ), true )
	|| ! in_array( 'fixture-provider.environment.ready', $result_codes, true )
	|| ! in_array( 'fixture-provider.repository.reachable', $result_codes, true )
) {
	throw new RuntimeException( 'The external fixture troubleshooting integration is invalid.' );
}

foreach ( array( RAN\RepositoryProvider\RepositoryBrowser::class, RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser::class, RAN\RepositoryProvider\RepositoryReleaseCandidateListing::class ) as $capability ) {
	try {
		$registry->require_capability( 'fixture-provider', $capability );
		throw new RuntimeException( 'The external fixture exposed an unsupported capability.' );
	// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- This expected exception is the exercised negative path; the surrounding proof continues deliberately.
	} catch ( RAN\RepositoryProvider\UnsupportedProviderCapability ) {
		// Expected: public browsing, authenticated public browsing and webhooks are independently optional.
	}
}

WP_CLI::success( 'External fixture provider registered, resolved, diagnosed and presented.' );
