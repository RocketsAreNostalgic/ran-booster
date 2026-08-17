<?php

// Executed by WP-CLI against the installed release ZIP in a disposable site.
// phpcs:disable

if ( ! defined( 'RAN_BOOSTER_PROVIDER_API_VERSION' ) || 10 !== RAN_BOOSTER_PROVIDER_API_VERSION ) {
	throw new RuntimeException( 'The installed runtime does not expose Provider API 10.' );
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
$metadata  = $provider->getMetadata();
$admin     = $metadata->admin;

if ( ! $registry->isSealed()
	|| ! $provider instanceof RAN\Booster\GitHub\GitHubProvider
	|| 'gh' !== $metadata->code->value
	|| 'GitHub' !== $metadata->label
	|| 'https://github.com/' !== $metadata->repositoryUrlBase
	|| 'Owner' !== $metadata->ownerLabel
	|| null === $admin
	|| 'git-host' !== $admin->navigation?->group
	|| 100 !== $admin->navigation?->slot
	|| 'owner/repository' !== $admin->repositoryLocatorHint
	|| array( 'classic', 'fine-grained' ) !== array_map( static fn ( $kind ): string => $kind->code, $admin->credentialKinds )
	|| array( 'owner', 'repository' ) !== array_map( static fn ( $scope ): string => $scope->code, $admin->webhookScopes )
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
		RAN\RepositoryProvider\RepositoryReleaseMetadata::class,
	) as $capability
) {
	if ( $provider !== $registry->requireCapability( 'gh', $capability ) ) {
		throw new RuntimeException( 'The installed GitHub provider capability is not registered: ' . $capability );
	}
}

$credential_policy = $provider->getCredentialPolicy();
$webhook_policy    = $provider->getWebhookPolicy();
if ( 'gh' !== $credential_policy->getProvider()->value
	|| array( 'RAN_BOOSTER_GITHUB_TOKEN' ) !== $credential_policy->getConstantNames()
	|| 'gh' !== $webhook_policy->getProvider()->value
	|| array( 'x-github-event', 'x-github-delivery', 'x-hub-signature-256' ) !== $webhook_policy->getRetainedHeaders()
	|| 'x-hub-signature-256' !== $webhook_policy->getSignatureHeader()
) {
	throw new RuntimeException( 'The installed GitHub provider policies do not match the bundled contract.' );
}

$tabs = ( new RAN\Admin\AdminTabRegistry( $registry ) )->all();
if ( array( 'overview', 'gh', 'portability', 'documentation', 'troubleshooting' ) !== array_map( static fn ( $tab ): string => $tab->getKey(), $tabs )
	|| 'GitHub' !== $tabs[1]->getLabel()
	|| 'provider.php' !== $tabs[1]->getView()
	|| ! $tabs[1]->isProvider()
) {
	throw new RuntimeException( 'The installed GitHub provider navigation does not match the bundled contract.' );
}

$module_root = $plugin_root . '/RAN/Booster/GitHub/';
foreach ( array( $provider, $credential_policy, $webhook_policy ) as $module_object ) {
	$source = ( new ReflectionClass( $module_object ) )->getFileName();
	$source = is_string( $source ) ? realpath( $source ) : false;
	if ( false === $source || ! str_starts_with( $source, $module_root ) ) {
		throw new RuntimeException( 'The installed GitHub provider loaded outside the bundled module tree.' );
	}
}

WP_CLI::success( 'Installed GitHub provider metadata, capabilities, policies and navigation passed without a development Composer autoloader.' );
