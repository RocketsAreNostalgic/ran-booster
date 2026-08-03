<?php
/**
 * Seed release-managed active and inactive theme rows on a disposable site.
 */

declare(strict_types=1);

use RAN\Deployment\DeploymentPolicy;
use RAN\ManagedRepository;
use RAN\PackageSource;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\ManagedReleaseConfiguration;

$ran_booster_theme_repository = new ThemeRepository();
$ran_booster_theme_fixtures   = array(
	'ran-booster-managed-active'   => '123456701',
	'ran-booster-managed-inactive' => '123456702',
);

foreach ( $ran_booster_theme_fixtures as $ran_booster_stylesheet => $ran_booster_repository_id ) {
	$ran_booster_theme = $ran_booster_theme_repository->installedThemeFromStylesheet( $ran_booster_stylesheet );
	$ran_booster_theme->setRepository(
		new ManagedRepository(
			'gh',
			'RocketsAreNostalgic/' . $ran_booster_stylesheet,
			$ran_booster_repository_id,
			'main'
		)
	);
	$ran_booster_theme->setSource( PackageSource::RELEASE_ASSET, 1 );
	$ran_booster_theme->setDeploymentPolicy( DeploymentPolicy::MANUAL );
	$ran_booster_result = $ran_booster_theme_repository->adoptRelease(
		$ran_booster_theme,
		new ManagedReleaseConfiguration( $ran_booster_stylesheet, 'style.css' ),
		get_current_user_id()
	);
	if ( ! $ran_booster_result->isSuccessful() ) {
		throw new RuntimeException( 'Could not seed a managed theme fixture.' );
	}
}

WP_CLI::success( 'Seeded active and inactive release-managed themes.' );
