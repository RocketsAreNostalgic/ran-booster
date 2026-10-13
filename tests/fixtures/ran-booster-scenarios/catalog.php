<?php

/** Reviewed scenario and navigation definitions; extend these in source, never via request URLs. */

/** @return array<string, array{label: string, initial: string, next: string, expected: string}> */
function ran_booster_scenarios_catalog(): array {
	return array(
		'immutable'         => array(
			'label'    => 'Immutable release',
			'initial'  => 'immutable',
			'next'     => '',
			'expected' => 'A valid immutable 2.0.0 release is available; normal Core eligibility and installation checks still apply.',
		),
		'mutable'           => array(
			'label'    => 'Mutable release',
			'initial'  => 'mutable',
			'next'     => '',
			'expected' => 'Release evidence is mutable; record actual beta.35 behavior. Planned #85 immutability notices may not exist yet.',
		),
		'no-release'        => array(
			'label'    => 'No releases',
			'initial'  => 'no-release',
			'next'     => '',
			'expected' => 'No current release is available. Existing history remains visible without inventing a new offer.',
		),
		'malformed'         => array(
			'label'    => 'Malformed release response',
			'initial'  => 'malformed',
			'next'     => '',
			'expected' => 'Invalid JSON must produce an unavailable/error result, never a successful release.',
		),
		'unavailable'       => array(
			'label'    => 'Provider unavailable (503)',
			'initial'  => 'unavailable',
			'next'     => '',
			'expected' => '503 must report provider unavailability, never deployment success.',
		),
		'denied'            => array(
			'label'    => 'Provider access denied (403)',
			'initial'  => 'denied',
			'next'     => '',
			'expected' => '403 must report access denial, never authenticate with real credentials.',
		),
		'rate-limited'      => array(
			'label'    => 'Provider rate limited (429)',
			'initial'  => 'rate-limited',
			'next'     => '',
			'expected' => '429 must report rate limiting without repeated background retry loops.',
		),
		'becomes-immutable' => array(
			'label'    => 'Mutable → immutable (manual advance)',
			'initial'  => 'mutable',
			'next'     => 'immutable',
			'expected' => 'Stage 0 mutable; advance explicitly, refresh normally, then observe immutable evidence in a later request.',
		),
		'becomes-mutable'   => array(
			'label'    => 'Immutable → mutable (manual advance)',
			'initial'  => 'immutable',
			'next'     => 'mutable',
			'expected' => 'Stage 0 immutable; advance and refresh to verify current mutable evidence replaces stale immutable evidence. Planned #85 notices may not exist yet.',
		),
		'offer-disappears'  => array(
			'label'    => 'Immutable offer → no release (manual advance)',
			'initial'  => 'immutable',
			'next'     => 'no-release',
			'expected' => 'Stage 0 valid offer; advance explicitly, refresh normally, then verify the offer disappears while earlier history remains.',
		),
	);
}

/** @return array<string, string> */
function ran_booster_scenarios_routes(): array {
	return array(
		'Fixture plugin management'  => 'admin.php?page=ran-booster-plugins&package=scenario-plugin%2Fscenario-plugin.php',
		'Fixture theme management'   => 'admin.php?page=ran-booster-themes&package=scenario-theme',
		'Activity history'           => 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity',
		'Published release guidance' => 'admin.php?page=ran-booster&tab=documentation#ran-booster-documentation-published-releases',
		'Booster overview'           => 'admin.php?page=ran-booster&tab=overview',
		'GitHub repositories'        => 'admin.php?page=ran-booster&tab=gh',
		'Add plugin repository'      => 'admin.php?page=ran-booster-plugins-create',
		'Add theme repository'       => 'admin.php?page=ran-booster-themes-create',
		'Booster troubleshooting'    => 'admin.php?page=ran-booster&tab=troubleshooting',
		'Installed plugins'          => 'plugins.php',
		'Installed themes'           => 'themes.php',
		'WordPress updates'          => 'update-core.php',
	);
}
