<?php

// Executed by WP-CLI against the installed release ZIP in a disposable site.

if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) || 14 !== RAN_BOOSTER_PROVIDER_API_VERSION ) {
	throw new RuntimeException( 'The installed runtime does not expose Provider API 14.' );
}

if ( 3 !== RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3::RELEASE_WORKFLOW_API_VERSION ) { // @phpstan-ignore notIdentical.alwaysFalse (Installed proof must verify the separately loaded release workflow API marker.)
	throw new RuntimeException( 'The installed runtime does not expose release workflow API 3.' );
}

$ran_booster_plugin_root = realpath( WP_PLUGIN_DIR . '/ran-booster' );
if ( false === $ran_booster_plugin_root || is_file( $ran_booster_plugin_root . '/vendor/autoload.php' ) ) {
	throw new RuntimeException( 'The installed runtime must use the bundled Core autoloader without a Composer autoloader.' );
}

$ran_booster_development_autoloader = realpath( dirname( __DIR__, 2 ) . '/vendor/autoload.php' );
if ( false !== $ran_booster_development_autoloader ) {
	foreach ( get_included_files() as $ran_booster_included_file ) {
		if ( realpath( $ran_booster_included_file ) === $ran_booster_development_autoloader ) {
			throw new RuntimeException( 'The installed runtime readback loaded the development Composer autoloader.' );
		}
	}
}

$ran_booster_container = require __DIR__ . '/core-container-fixture.php';
$ran_booster_registry  = $ran_booster_container->make( RAN\RepositoryProvider\ProviderRegistry::class );
$ran_booster_provider  = $ran_booster_registry->get( 'gh' );
$ran_booster_metadata  = $ran_booster_provider->get_metadata();
$ran_booster_admin     = $ran_booster_metadata->admin;

if ( ! $ran_booster_registry->is_sealed()
	|| ! $ran_booster_provider instanceof RAN\BoosterGitHubProvider\V1\GitHubProvider
	|| 'gh' !== $ran_booster_metadata->code->value
	|| 'GitHub' !== $ran_booster_metadata->label
	|| 'https://github.com/' !== $ran_booster_metadata->repository_url_base
	|| 'Owner' !== $ran_booster_metadata->owner_label
	|| null === $ran_booster_admin
	|| 'git-host' !== $ran_booster_admin->navigation?->group
	|| 100 !== $ran_booster_admin->navigation->slot
	|| 'owner/repository' !== $ran_booster_admin->repository_locator_hint
	|| array( 'classic', 'fine-grained' ) !== array_map( static fn ( $kind ): string => $kind->code, $ran_booster_admin->credential_kinds )
	|| array( 'owner', 'repository' ) !== array_map( static fn ( $scope ): string => $scope->code, $ran_booster_admin->webhook_scopes )
) {
	throw new RuntimeException( 'The installed GitHub provider metadata does not match the bundled contract.' );
}

foreach (
	array(
		RAN\RepositoryProvider\CredentialValidator::class,
		RAN\RepositoryProvider\RepositoryBrowser::class,
		RAN\RepositoryProvider\CredentialedPublicRepositoryBrowser::class,
		RAN\RepositoryProvider\ProviderCredentialPolicySupplier::class,
		RAN\RepositoryProvider\WebhookNormalizer::class,
		RAN\RepositoryProvider\RepositoryWebhookSettingsLink::class,
		RAN\RepositoryProvider\RepositoryWebhookFitness::class,
		RAN\RepositoryProvider\RepositoryWebhookManagement::class,
		RAN\RepositoryProvider\RepositoryReleaseAcquirer::class,
		RAN\RepositoryProvider\RepositoryReleaseCandidateListing::class,
		RAN\RepositoryProvider\RepositoryReleaseInspector::class,
		RAN\RepositoryProvider\RepositoryReleaseMetadata::class,
		RAN\RepositoryProvider\RepositoryReleaseNativeTargets::class,
		RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3::class,
	) as $ran_booster_capability
) {
	if ( $ran_booster_provider !== $ran_booster_registry->require_capability( 'gh', $ran_booster_capability ) ) {
		// Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( 'The installed GitHub provider capability is not registered: ' . $ran_booster_capability );
	}
}

$ran_booster_credential_policy = $ran_booster_provider->get_credential_policy();
$ran_booster_webhook_policy    = $ran_booster_provider->get_webhook_policy();
if ( 'gh' !== $ran_booster_credential_policy->get_provider()->value
	|| array( 'RAN_BOOSTER_GITHUB_TOKEN' ) !== $ran_booster_credential_policy->get_constant_names()
	|| 'gh' !== $ran_booster_webhook_policy->get_provider()->value
	|| array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' ) !== $ran_booster_webhook_policy->get_retained_headers()
	|| 'x-hub-signature-256' !== $ran_booster_webhook_policy->get_signature_header()
) {
	throw new RuntimeException( 'The installed GitHub provider policies do not match the bundled contract.' );
}

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$tabs = ( new RAN\Admin\AdminTabRegistry( $ran_booster_registry ) )->all();
if ( array( 'overview', 'gh', 'portability', 'documentation', 'troubleshooting' ) !== array_map( static fn ( $tab ): string => $tab->get_key(), $tabs )
	|| 'GitHub' !== $tabs[1]->get_label()
	|| 'provider.php' !== $tabs[1]->get_view()
	|| ! $tabs[1]->is_provider()
) {
	throw new RuntimeException( 'The installed GitHub provider navigation does not match the bundled contract.' );
}

$ran_booster_module_root = $ran_booster_plugin_root . '/vendor/ran/booster-github-provider/src/';
if ( is_dir( $ran_booster_plugin_root . '/RAN/Booster/GitHub' )
	|| ! is_file( $ran_booster_plugin_root . '/vendor/ran/booster-github-provider/LICENSE' )
) {
	throw new RuntimeException( 'The installed GitHub provider package boundary is invalid.' );
}
foreach ( array( $ran_booster_provider, $ran_booster_credential_policy, $ran_booster_webhook_policy ) as $ran_booster_module_object ) {
	$ran_booster_source = ( new ReflectionClass( $ran_booster_module_object ) )->getFileName();
	$ran_booster_source = is_string( $ran_booster_source ) ? realpath( $ran_booster_source ) : false;
	if ( false === $ran_booster_source || ! str_starts_with( $ran_booster_source, $ran_booster_module_root ) ) {
		throw new RuntimeException( 'The installed GitHub provider loaded outside the bundled provider package tree.' );
	}
}

WP_CLI::success( 'Installed GitHub provider metadata, capabilities, policies and navigation passed without a development Composer autoloader.' ); // @phpstan-ignore class.notFound (External WP-CLI contract supplied by the installed eval-file process, outside the Composer-locked WordPress dependencies.)
