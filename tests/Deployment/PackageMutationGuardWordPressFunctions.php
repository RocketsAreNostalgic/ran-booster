<?php

declare(strict_types=1);

namespace RAN\Deployment;

require_once dirname( __DIR__ ) . '/Runtime/RuntimeSupportWordPressFunctions.php';

if ( ! function_exists( __NAMESPACE__ . '\\is_multisite' ) ) {
	function is_multisite(): bool {
		if ( array_key_exists( 'ran_booster_package_mutation_guard_multisite', $GLOBALS ) ) {
			return (bool) $GLOBALS['ran_booster_package_mutation_guard_multisite'];
		}

		return class_exists( DeploymentArchivePreflightWordPressState::class )
			? DeploymentArchivePreflightWordPressState::$multisite
			: false;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_is_file_mod_allowed' ) ) {
	function wp_is_file_mod_allowed( string $context ): bool {
		$GLOBALS['ran_booster_package_mutation_guard_contexts'][] = $context;

		if ( array_key_exists( 'ran_booster_package_mutation_guard_file_mods', $GLOBALS ) ) {
			return (bool) $GLOBALS['ran_booster_package_mutation_guard_file_mods'];
		}

		return class_exists( DeploymentArchivePreflightWordPressState::class )
			? DeploymentArchivePreflightWordPressState::$fileMods
			: true;
	}
}
