<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/tests/fixtures/wordpress/' );
}

require_once dirname( __DIR__ ) . '/autoload.php';

use RAN\Deployment\DeploymentOutcome;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentState;
use RAN\Deployment\WordPressWorkerWakeup;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\RepositoryBrowseRequest;
use RAN\RepositoryProvider\RepositoryBrowseResult;

$checks = 0;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( class_exists( RAN\Booster::class ), 'The plugin runtime must autoload.' );
$assert( class_exists( RAN\Deployment\DeploymentCoordinator::class ), 'The deployment coordinator must autoload.' );
$assert( class_exists( RAN\WordPress\CorePackageExecutor::class ), 'The WordPress core adapter must autoload.' );
$assert( class_exists( RAN\PackageOperation::class ), 'The explicit package operation must autoload.' );
$assert( class_exists( RAN\PackageOperationService::class ), 'The package operation service must autoload.' );
$assert( ! class_exists( 'RAN\\Commands\\InstallPlugin' ), 'The inherited command bus must stay removed.' );
$assert( ! class_exists( 'RAN\\Handlers\\InstallPlugin' ), 'The inherited handler bus must stay removed.' );
$assert( ! class_exists( 'RAN\\Actions\\PluginWasInstalled' ), 'The inherited action bus must stay removed.' );
$assert( ! class_exists( 'RAN\\Deployment\\DeploymentIntent' ), 'The legacy execution graph must stay removed.' );
$assert( ! class_exists( 'RAN\\Deployment\\DeploymentHistoryItem' ), 'The legacy history projection must stay removed.' );
$assert( ! class_exists( 'RAN\\Deployment\\RetryableDeploymentFailure' ), 'Automatic retry infrastructure must stay removed.' );
$assert( ! class_exists( 'RAN\\Deployment\\WorkerCliCommand' ), 'The direct worker CLI must stay removed.' );
$assert( 'ran_booster_run_deployment' === WordPressWorkerWakeup::HOOK, 'One real WP-Cron hook must own execution.' );

$request = new DeploymentRequest( 'org/package', 'profile_1', true, 'main', 'package', 'wordpress', DeploymentPolicy::AUTOMATIC, 7 );
$assert( $request->toJson() === DeploymentRequest::fromJson( $request->toJson() )->toJson(), 'Deployment requests must round-trip canonically.' );
$assert( ! str_contains( $request->toJson(), 'Authorization' ), 'The durable request must not contain authorization material.' );
$assert( DeploymentPolicy::MANUAL->allowsManualMutation(), 'Manual policy must allow administrator deployment.' );
$assert( ! DeploymentPolicy::MANUAL->allowsWebhookMutation(), 'Manual policy must reject webhook deployment.' );
$assert( DeploymentPolicy::AUTOMATIC->allowsWebhookMutation(), 'Automatic policy must allow webhook deployment.' );
$assert( ! DeploymentPolicy::DISABLED->allowsManualMutation(), 'Disabled policy must reject deployment.' );

$browse = RepositoryBrowseRequest::accessible( 'profile_1' );
$assert( 'profile_1' === $browse->getCredentialId(), 'Repository browsing must use one explicitly selected credential.' );
$assert( 5 === RepositoryBrowseRequest::MAX_REMOTE_CALLS, 'Repository browsing must retain the five-call limit.' );
$assert( ( new RepositoryBrowseResult( array(), RepositoryBrowseResult::LIMIT ) )->isPartial(), 'Bounded repository results must report truncation.' );

$success = DeploymentOutcome::fromCode( DeploymentOutcome::CODE_DEPLOYED );
$failed  = DeploymentOutcome::fromCode( DeploymentOutcome::CODE_PREFLIGHT_FAILED );
$unsafe  = DeploymentOutcome::fromCode( DeploymentOutcome::CODE_INTERRUPTED );
$assert( DeploymentState::SUCCEEDED === $success->getState(), 'Deployed must be successful.' );
$assert( DeploymentState::FAILED === $failed->getState(), 'Preflight failure must be terminal failure.' );
$assert( DeploymentState::NEEDS_ATTENTION === $unsafe->getState(), 'Interrupted mutation must require attention.' );

$source = file_get_contents( dirname( __DIR__ ) . '/ran-booster.php' );
$assert( is_string( $source ) && ! str_contains( $source, 'WorkerCliCommand' ), 'Bootstrap must not expose a second executor.' );
$assert( is_string( $source ) && ! str_contains( $source, 'ActionHandlerProvider' ), 'Bootstrap must not restore the inherited action bus.' );
$assert( is_string( $source ) && str_contains( $source, "RAN_BOOSTER_PROVIDER_API_VERSION', 10" ), 'Provider API 10 must remain explicit.' );
$assert( is_string( $source ) && str_contains( $source, "RAN_BOOSTER_ADDON_API_VERSION', 16" ), 'Add-on API 16 must remain explicit.' );
$assert( is_string( $source ) && ! str_contains( $source, 'RAN_BOOSTER_WEBHOOK_CLEANUP_API_VERSION' ), 'The removed Webhook Cleanup marker must stay absent.' );
$assert( is_string( $source ) && ! str_contains( $source, 'RAN_BOOSTER_LOGGING_API_VERSION' ), 'The removed Logging API marker must stay absent.' );
$updaterRegistration = is_string( $source ) ? strpos( $source, 'ReleaseUpdaterBootstrap::register' ) : false;
$pluginsLoaded       = is_string( $source ) ? strpos( $source, "'plugins_loaded'" ) : false;
$assert( false !== $updaterRegistration, 'Bootstrap must register the shared release updater.' );
$assert( ! str_contains( $source, 'GitHubReleaseUpdaterBootstrap' ), 'Bootstrap must remove the GitHub-specific updater facade.' );
$assert(
	false !== $pluginsLoaded && $updaterRegistration < $pluginsLoaded,
	'The shared release updater must register before plugins_loaded.'
);
$assert(
	! class_exists( 'RAN\\WordPress\\PublicGitHubReleaseUpdater' ),
	'The duplicated Booster-specific updater must stay removed.'
);

echo "RAN Booster characterization checks passed: {$checks}\n";
