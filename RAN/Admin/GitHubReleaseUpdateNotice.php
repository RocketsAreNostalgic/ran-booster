<?php

declare(strict_types=1);

namespace RAN\Admin;

/**
 * Keeps Core self-update diagnostics out of global administrator notices.
 */
final class GitHubReleaseUpdateNotice {

	private const REPOSITORY = 'RocketsAreNostalgic/ran-booster';

	public static function register( string $package ): void {
		add_filter(
			'ran_wp_github_release_updater_notice',
			static fn ( array $notice, array $context ): ?array => self::filter( $notice, $context, $package ),
			10,
			2
		);
	}

	/**
	 * @param array<string, mixed> $notice
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>|null
	 */
	public static function filter( array $notice, array $context, string $package ): ?array {
		if ( 'plugin' !== ( $context['type'] ?? null )
			|| $package !== ( $context['package'] ?? null )
			|| self::REPOSITORY !== ( $context['repository'] ?? null ) ) {
			return $notice;
		}

		return null;
	}
}
