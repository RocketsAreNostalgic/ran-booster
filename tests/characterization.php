<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Exercise the WordPress-owned constant without changing its runtime identity.
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
use RAN\WordPress\BranchUpdaterBootstrap;

$ran_booster_checks = 0;
$ran_booster_assert = static function ( bool $ran_booster_condition, string $ran_booster_message ) use ( &$ran_booster_checks ): void {
	++$ran_booster_checks;
	if ( ! $ran_booster_condition ) {
		throw new RuntimeException( $ran_booster_message );
	}
};

$ran_booster_assert( class_exists( RAN\Booster::class ), 'The plugin runtime must autoload.' );
$ran_booster_assert( class_exists( RAN\Deployment\DeploymentCoordinator::class ), 'The deployment coordinator must autoload.' );
$ran_booster_assert( class_exists( RAN\WordPress\CorePackageExecutor::class ), 'The WordPress core adapter must autoload.' );
$ran_booster_assert( class_exists( RAN\PackageOperation::class ), 'The explicit package operation must autoload.' );
$ran_booster_assert( class_exists( RAN\PackageOperationService::class ), 'The package operation service must autoload.' );
$ran_booster_assert( ! class_exists( 'RAN\\Commands\\InstallPlugin' ), 'The inherited command bus must stay removed.' );
$ran_booster_assert( ! class_exists( 'RAN\\Handlers\\InstallPlugin' ), 'The inherited handler bus must stay removed.' );
$ran_booster_assert( ! class_exists( 'RAN\\Actions\\PluginWasInstalled' ), 'The inherited action bus must stay removed.' );
$ran_booster_assert( ! class_exists( 'RAN\\Deployment\\DeploymentIntent' ), 'The legacy execution graph must stay removed.' );
$ran_booster_assert( ! class_exists( 'RAN\\Deployment\\DeploymentHistoryItem' ), 'The legacy history projection must stay removed.' );
$ran_booster_assert( ! class_exists( 'RAN\\Deployment\\RetryableDeploymentFailure' ), 'Automatic retry infrastructure must stay removed.' );
$ran_booster_assert( ! class_exists( 'RAN\\Deployment\\WorkerCliCommand' ), 'The direct worker CLI must stay removed.' );
// @phpstan-ignore identical.alwaysTrue (Pin this public constant as a runtime compatibility contract; changing its declaration must fail characterization.)
$ran_booster_assert( 'ran_booster_run_deployment' === WordPressWorkerWakeup::HOOK, 'One real WP-Cron hook must own execution.' );

BranchUpdaterBootstrap::register();
$ran_booster_assert( class_exists( RAN\UpdaterSupport\V1\RepositoryRelativePath::class ), 'The shared updater-support runtime must autoload after dependency registration.' );

// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- This literal is the fixed deployment host identifier rather than prose.
$ran_booster_request = new DeploymentRequest( 'org/package', 'profile_1', true, 'main', 'package', 'wordpress', DeploymentPolicy::AUTOMATIC, 7 );
$ran_booster_assert( $ran_booster_request->to_json() === DeploymentRequest::from_json( $ran_booster_request->to_json() )->to_json(), 'Deployment requests must round-trip canonically.' );
$ran_booster_assert( ! str_contains( $ran_booster_request->to_json(), 'Authorization' ), 'The durable request must not contain authorization material.' );
$ran_booster_assert( DeploymentPolicy::MANUAL->allows_manual_mutation(), 'Manual policy must allow administrator deployment.' );
$ran_booster_assert( ! DeploymentPolicy::MANUAL->allows_webhook_mutation(), 'Manual policy must reject webhook deployment.' );
$ran_booster_assert( DeploymentPolicy::AUTOMATIC->allows_webhook_mutation(), 'Automatic policy must allow webhook deployment.' );
$ran_booster_assert( ! DeploymentPolicy::DISABLED->allows_manual_mutation(), 'Disabled policy must reject deployment.' );

$ran_booster_browse = RepositoryBrowseRequest::accessible( 'profile_1' );
$ran_booster_assert( 'profile_1' === $ran_booster_browse->get_credential_id(), 'Repository browsing must use one explicitly selected credential.' );
// @phpstan-ignore identical.alwaysTrue (Pin this public constant as a runtime compatibility contract; changing its declaration must fail characterization.)
$ran_booster_assert( 5 === RepositoryBrowseRequest::MAX_REMOTE_CALLS, 'Repository browsing must retain the five-call limit.' );
$ran_booster_assert( ( new RepositoryBrowseResult( array(), RepositoryBrowseResult::LIMIT ) )->is_partial(), 'Bounded repository results must report truncation.' );

$ran_booster_success = DeploymentOutcome::from_code( DeploymentOutcome::CODE_DEPLOYED );
$ran_booster_failed  = DeploymentOutcome::from_code( DeploymentOutcome::CODE_PREFLIGHT_FAILED );
$ran_booster_unsafe  = DeploymentOutcome::from_code( DeploymentOutcome::CODE_INTERRUPTED );
$ran_booster_assert( DeploymentState::SUCCEEDED === $ran_booster_success->get_state(), 'Deployed must be successful.' );
$ran_booster_assert( DeploymentState::FAILED === $ran_booster_failed->get_state(), 'Preflight failure must be terminal failure.' );
$ran_booster_assert( DeploymentState::NEEDS_ATTENTION === $ran_booster_unsafe->get_state(), 'Interrupted mutation must require attention.' );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local source bytes for CLI characterization without WordPress bootstrap.
$ran_booster_source = file_get_contents( dirname( __DIR__ ) . '/ran-booster.php' );
$ran_booster_assert( is_string( $ran_booster_source ) && ! str_contains( $ran_booster_source, 'WorkerCliCommand' ), 'Bootstrap must not expose a second executor.' );
$ran_booster_assert( is_string( $ran_booster_source ) && ! str_contains( $ran_booster_source, 'ActionHandlerProvider' ), 'Bootstrap must not restore the inherited action bus.' );
$ran_booster_assert( is_string( $ran_booster_source ) && str_contains( $ran_booster_source, "RAN_BOOSTER_PROVIDER_API_VERSION', 14" ), 'Provider API 14 must remain explicit.' );
$ran_booster_assert( is_string( $ran_booster_source ) && str_contains( $ran_booster_source, "RAN_BOOSTER_ADDON_API_VERSION', 17" ), 'Add-on API 17 must remain explicit.' );
$ran_booster_assert( is_string( $ran_booster_source ) && ! str_contains( $ran_booster_source, 'RAN_BOOSTER_WEBHOOK_CLEANUP_API_VERSION' ), 'The removed Webhook Cleanup marker must stay absent.' );
$ran_booster_assert( is_string( $ran_booster_source ) && ! str_contains( $ran_booster_source, 'RAN_BOOSTER_LOGGING_API_VERSION' ), 'The removed Logging API marker must stay absent.' );
$ran_booster_updater_registration = is_string( $ran_booster_source ) ? strpos( $ran_booster_source, 'ReleaseUpdaterBootstrap::register' ) : false;
$ran_booster_plugins_loaded       = is_string( $ran_booster_source ) ? strpos( $ran_booster_source, "'plugins_loaded'" ) : false;
$ran_booster_assert( false !== $ran_booster_updater_registration, 'Bootstrap must register the shared release updater.' );
$ran_booster_assert( is_string( $ran_booster_source ) && ! str_contains( $ran_booster_source, 'GitHubReleaseUpdaterBootstrap' ), 'Bootstrap must remove the GitHub-specific updater facade.' );
$ran_booster_assert(
	false !== $ran_booster_plugins_loaded && $ran_booster_updater_registration < $ran_booster_plugins_loaded,
	'The shared release updater must register before plugins_loaded.'
);
$ran_booster_assert(
	! class_exists( 'RAN\\WordPress\\PublicGitHubReleaseUpdater' ),
	'The duplicated Booster-specific updater must stay removed.'
);

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output reports only the integer assertion count; this is not an HTML response.
echo "RAN Booster characterization checks passed: {$ran_booster_checks}\n";
