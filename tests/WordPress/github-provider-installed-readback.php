<?php

// Executed by WP-CLI against the installed release ZIP in a disposable site.

if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) || 14 !== RAN_BOOSTER_PROVIDER_API_VERSION ) {
	throw new RuntimeException( 'The installed runtime does not expose Provider API 14.' );
}

if ( 3 !== RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3::RELEASE_WORKFLOW_API_VERSION ) {
	throw new RuntimeException( 'The installed runtime does not expose release workflow API 3.' );
}

$plugin_root = realpath( WP_PLUGIN_DIR . '/ran-booster' );
if ( false === $plugin_root || is_file( $plugin_root . '/vendor/autoload.php' ) ) {
	throw new RuntimeException( 'The installed runtime must use the bundled Core autoloader without a Composer autoloader.' );
}

$development_autoloader = realpath( dirname( __DIR__, 2 ) . '/vendor/autoload.php' );
if ( false !== $development_autoloader ) {
	foreach ( get_included_files() as $included_file ) {
		if ( realpath( $included_file ) === $development_autoloader ) {
			throw new RuntimeException( 'The installed runtime readback loaded the development Composer autoloader.' );
		}
	}
}

$container = require __DIR__ . '/core-container-fixture.php';
$registry  = $container->make( RAN\RepositoryProvider\ProviderRegistry::class );
$provider  = $registry->get( 'gh' );
$metadata  = $provider->get_metadata();
$admin     = $metadata->admin;

if ( ! $registry->is_sealed()
	|| ! $provider instanceof RAN\BoosterGitHubProvider\V1\GitHubProvider
	|| 'gh' !== $metadata->code->value
	|| 'GitHub' !== $metadata->label
	|| 'https://github.com/' !== $metadata->repository_url_base
	|| 'Owner' !== $metadata->owner_label
	|| null === $admin
	|| 'git-host' !== $admin->navigation?->group
	|| 100 !== $admin->navigation?->slot
	|| 'owner/repository' !== $admin->repository_locator_hint
	|| array( 'classic', 'fine-grained' ) !== array_map( static fn ( $kind ): string => $kind->code, $admin->credential_kinds )
	|| array( 'owner', 'repository' ) !== array_map( static fn ( $scope ): string => $scope->code, $admin->webhook_scopes )
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
	) as $capability
) {
	if ( $provider !== $registry->require_capability( 'gh', $capability ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Diagnostic exception is consumed by the disposable CLI proof, not rendered as HTML.
		throw new RuntimeException( 'The installed GitHub provider capability is not registered: ' . $capability );
	}
}

$credential_policy = $provider->get_credential_policy();
$webhook_policy    = $provider->get_webhook_policy();
if ( 'gh' !== $credential_policy->get_provider()->value
	|| array( 'RAN_BOOSTER_GITHUB_TOKEN' ) !== $credential_policy->get_constant_names()
	|| 'gh' !== $webhook_policy->get_provider()->value
	|| array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' ) !== $webhook_policy->get_retained_headers()
	|| 'x-hub-signature-256' !== $webhook_policy->get_signature_header()
) {
	throw new RuntimeException( 'The installed GitHub provider policies do not match the bundled contract.' );
}

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated fixture deliberately controls this WordPress global to exercise the real runtime boundary.
$tabs = ( new RAN\Admin\AdminTabRegistry( $registry ) )->all();
if ( array( 'overview', 'gh', 'portability', 'documentation', 'troubleshooting' ) !== array_map( static fn ( $tab ): string => $tab->get_key(), $tabs )
	|| 'GitHub' !== $tabs[1]->get_label()
	|| 'provider.php' !== $tabs[1]->get_view()
	|| ! $tabs[1]->is_provider()
) {
	throw new RuntimeException( 'The installed GitHub provider navigation does not match the bundled contract.' );
}

$module_root = $plugin_root . '/vendor/ran/booster-github-provider/src/';
if ( is_dir( $plugin_root . '/RAN/Booster/GitHub' )
	|| ! is_file( $plugin_root . '/vendor/ran/booster-github-provider/LICENSE' )
) {
	throw new RuntimeException( 'The installed GitHub provider package boundary is invalid.' );
}
foreach ( array( $provider, $credential_policy, $webhook_policy ) as $module_object ) {
	$source = ( new ReflectionClass( $module_object ) )->getFileName();
	$source = is_string( $source ) ? realpath( $source ) : false;
	if ( false === $source || ! str_starts_with( $source, $module_root ) ) {
		throw new RuntimeException( 'The installed GitHub provider loaded outside the bundled provider package tree.' );
	}
}

WP_CLI::success( 'Installed GitHub provider metadata, capabilities, policies and navigation passed without a development Composer autoloader.' );
