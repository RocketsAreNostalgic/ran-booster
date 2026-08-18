<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use Throwable;

/** Fixed API 2 source-ready rules for repository-root plugins and themes. */
final class SourceReadyAssessor {
	private const GENERATED_PATHS = array(
		'.github/workflows/release-please.yml',
		'.ran-booster-release-profile.json',
		'.release-please-manifest.json',
		'release-please-config.json',
		'version.txt',
		'release-contents.txt',
		'scripts/build-release.sh',
		'scripts/verify-release.sh',
		'scripts/upload-release-assets.sh',
	);

	private const RELEASE_ACTIONS = array(
		'actions/create-release',
		'actions/upload-release-asset',
		'changesets/action',
		'cycjimmy/semantic-release-action',
		'googleapis/release-please-action',
		'goreleaser/goreleaser-action',
		'marvinpinto/action-automatic-releases',
		'ncipollo/release-action',
		'release-drafter/release-drafter',
		'softprops/action-gh-release',
		'svenstaro/upload-release-action',
	);

	private const DEVELOPMENT_ROOTS = array(
		'.agents',
		'.codex',
		'.github',
		'.wordpress-org',
		'coverage',
		'dist',
		'docs',
		'node_modules',
		'scripts',
		'tests',
	);

	private const DEVELOPMENT_FILES = array(
		'.editorconfig',
		'.gitattributes',
		'.gitignore',
		'.phpcs.xml.dist',
		'.prettierignore',
		'.prettierrc',
		'.prettierrc.json',
		'.stylelintignore',
		'.stylelintrc',
		'.stylelintrc.json',
		'AGENTS.md',
		'CHANGELOG.md',
		'CONTRIBUTING.md',
		'LICENSE',
		'LICENSE.md',
		'Makefile',
		'README.md',
		'RELEASE.md',
		'SECURITY.md',
		'composer.json',
		'composer.lock',
		'package-lock.json',
		'package.json',
		'phpunit.xml',
		'phpunit.xml.dist',
		'pnpm-lock.yaml',
		'yarn.lock',
	);

	private const PLUGIN_RUNTIME_ROOTS = array(
		'assets',
		'blocks',
		'build',
		'fonts',
		'inc',
		'includes',
		'languages',
		'src',
		'templates',
		'vendor',
		'views',
	);

	private const THEME_RUNTIME_ROOTS = array(
		'assets',
		'blocks',
		'build',
		'fonts',
		'inc',
		'includes',
		'languages',
		'parts',
		'patterns',
		'src',
		'styles',
		'templates',
		'vendor',
	);

	public function assess(
		RepositorySnapshot $snapshot,
		string $type,
		string $packageSlug,
		string $installedVersion,
		string $expectedUpdateUri
	): SourceReadyAssessment {
		$expectedUpdateUri = rtrim( $expectedUpdateUri, '/' );
		if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
			|| 1 !== preg_match( '/\A[a-z0-9](?:[a-z0-9-]{0,198}[a-z0-9])?\z/D', $packageSlug )
			|| 1 !== preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $installedVersion )
			|| ! hash_equals( 'https://github.com/' . $snapshot->repository(), $expectedUpdateUri ) ) {
			return SourceReadyAssessment::refused( 'repository_unsupported' );
		}

		foreach ( self::GENERATED_PATHS as $path ) {
			if ( $snapshot->has( $path ) ) {
				return SourceReadyAssessment::refused( 'release_path_conflict' );
			}
		}
		if ( $this->hasExistingReleaseAutomation( $snapshot ) ) {
			return SourceReadyAssessment::refused( 'release_automation_conflict' );
		}

		$header = $this->header( $snapshot, $type, $installedVersion, $expectedUpdateUri );
		if ( is_string( $header ) ) {
			return SourceReadyAssessment::refused( $header );
		}

		$versionSources = $this->versionSources( $snapshot, $installedVersion, $header['path'] );
		if ( is_string( $versionSources ) ) {
			return SourceReadyAssessment::refused( $versionSources );
		}

		$prettier = $this->prettierIgnore( $snapshot );
		if ( null === $prettier ) {
			return SourceReadyAssessment::refused( 'prettier_contract_custom' );
		}

		$releaseFiles = $this->releaseFiles( $snapshot, $type, $header['path'] );
		if ( null === $releaseFiles ) {
			return SourceReadyAssessment::refused( 'runtime_paths_unknown' );
		}

		$modified = array( $header['path'] => $header['content'] );
		foreach ( $versionSources['modified'] as $path => $content ) {
			$modified[ $path ] = $content;
		}
		if ( null !== $prettier['content'] ) {
			$modified['.prettierignore'] = $prettier['content'];
		}

		$extraFiles   = array_merge(
			array(
				array(
					'type' => 'generic',
					'path' => $header['path'],
				),
			),
			$versionSources['extra']
		);
		$releaseFiles = array_values( array_unique( array_merge( $releaseFiles, array_keys( $versionSources['runtime'] ) ) ) );
		sort( $releaseFiles, SORT_STRING );

		return SourceReadyAssessment::ready(
			'source-ready-wordpress-' . $type . '/2',
			$packageSlug,
			$header['path'],
			$installedVersion,
			$releaseFiles,
			$modified,
			$extraFiles
		);
	}

	/** @return array{path:string,content:string}|string */
	private function header( RepositorySnapshot $snapshot, string $type, string $version, string $updateUri ): array|string {
		$candidates = array();
		if ( 'theme' === $type ) {
			$candidates = array( 'style.css' );
		} else {
			foreach ( $snapshot->documentPaths() as $path ) {
				if ( ! str_contains( $path, '/' ) && str_ends_with( strtolower( $path ), '.php' ) ) {
					$document = $snapshot->document( $path );
					if ( is_string( $document ) && 1 === preg_match( '/^[ \t]*\*[ \t]*Plugin Name:[ \t]*\S/im', $document ) ) {
						$candidates[] = $path;
					}
				}
			}
		}

		if ( 1 !== count( $candidates ) ) {
			return 'package_ambiguous';
		}
		$path     = $candidates[0];
		$document = $snapshot->document( $path );
		if ( ! is_string( $document ) ) {
			return 'repository_unsupported';
		}

		$label = 'theme' === $type ? 'Theme Name' : 'Plugin Name';
		if ( 1 !== preg_match( '/^[ \t]*(?:\*[ \t]*)?' . preg_quote( $label, '/' ) . ':[ \t]*\S/im', $document ) ) {
			return 'package_ambiguous';
		}
		if ( 1 !== preg_match( '/^[ \t]*(?:\*[ \t]*)?Update URI:[ \t]*(\S+)[ \t]*$/im', $document, $uriMatch )
			|| ! hash_equals( $updateUri, rtrim( $uriMatch[1], '/' ) ) ) {
			return 'repository_unsupported';
		}
		$annotated = $this->annotateVersionLine( $document, 'Version', $version );
		return null === $annotated ? 'version_mismatch' : array(
			'path'    => $path,
			'content' => $annotated,
		);
	}

	/**
	 * @return array{modified:array<string,string>,extra:list<array<string,mixed>>,runtime:array<string,true>}|string
	 */
	private function versionSources( RepositorySnapshot $snapshot, string $version, string $headerPath ): array|string {
		$modified             = array();
		$extra                = array();
		$runtime              = array();
		$header               = $snapshot->document( $headerPath ) ?? '';
		$withoutHeaderVersion = preg_replace( '/^[ \t]*(?:\*[ \t]*)?Version:[^\r\n]*\R?/mi', '', $header, 1 ) ?? $header;
		if ( 1 === preg_match( '/define\s*\(\s*[\'\"][^\'\"]*VERSION[^\'\"]*[\'\"]\s*,\s*[\'\"]' . preg_quote( $version, '/' ) . '[\'\"]/i', $withoutHeaderVersion ) ) {
			return 'version_contract_custom';
		}

		$package = $snapshot->document( 'package.json' );
		if ( is_string( $package ) ) {
			try {
				$data = json_decode( $package, true, 32, JSON_THROW_ON_ERROR );
			} catch ( Throwable ) {
				return 'version_contract_custom';
			}
			if ( ! is_array( $data ) || ! is_string( $data['version'] ?? null ) || ! hash_equals( $version, $data['version'] ) ) {
				return 'version_contract_custom';
			}
			$extra[] = array(
				'type'     => 'json',
				'path'     => 'package.json',
				'jsonpath' => '$.version',
			);
		}

		$readme = $snapshot->document( 'readme.txt' );
		if ( is_string( $readme ) ) {
			$annotated = $this->annotateVersionLine( $readme, 'Stable tag', $version );
			if ( null === $annotated ) {
				return 'version_contract_custom';
			}
			$modified['readme.txt'] = $annotated;
			$runtime['readme.txt']  = true;
			$extra[]                = array(
				'type' => 'generic',
				'path' => 'readme.txt',
			);
		}

		foreach ( $snapshot->documentPaths() as $path ) {
			if ( str_ends_with( $path, 'block.json' ) ) {
				$document = $snapshot->document( $path );
				try {
					$data = is_string( $document ) ? json_decode( $document, true, 32, JSON_THROW_ON_ERROR ) : null;
				} catch ( Throwable ) {
					return 'version_contract_custom';
				}
				if ( ! is_array( $data ) || ! is_string( $data['version'] ?? null ) || ! hash_equals( $version, $data['version'] ) ) {
					return 'version_contract_custom';
				}
				$extra[]          = array(
					'type'     => 'json',
					'path'     => $path,
					'jsonpath' => '$.version',
				);
				$runtime[ $path ] = true;
			}
			if ( str_ends_with( strtolower( $path ), '.pot' ) ) {
				return 'version_contract_custom';
			}
		}

		return array(
			'modified' => $modified,
			'extra'    => $extra,
			'runtime'  => $runtime,
		);
	}

	/** @return array{content:?string}|null */
	private function prettierIgnore( RepositorySnapshot $snapshot ): ?array {
		$package = $snapshot->document( 'package.json' );
		if ( is_string( $package ) && preg_match_all( '/--ignore-path(?:=|[ \t]+)([^ \t\r\n\"\']+)/', $package, $matches ) ) {
			foreach ( $matches[1] as $path ) {
				if ( ! in_array( $path, array( '.prettierignore', './.prettierignore' ), true ) ) {
					return null;
				}
			}
		}

		$current = $snapshot->document( '.prettierignore' );
		if ( null === $current ) {
			return array( 'content' => "# RAN Booster release bootstrap: Release Please owns this generated file.\n/CHANGELOG.md\n" );
		}
		if ( 1 === preg_match( '/^[ \t]*![\/]?CHANGELOG\.md[ \t]*$/mi', $current ) ) {
			return null;
		}
		if ( 1 === preg_match( '/^[ \t]*\/?CHANGELOG\.md[ \t]*$/mi', $current ) ) {
			return array( 'content' => null );
		}

		$newline = str_contains( $current, "\r\n" ) ? "\r\n" : "\n";
		$prefix  = '' === $current || str_ends_with( $current, "\n" ) ? '' : $newline;
		return array(
			'content' => $current . $prefix
				. '# RAN Booster release bootstrap: Release Please owns this generated file.' . $newline
				. '/CHANGELOG.md' . $newline,
		);
	}

	private function hasExistingReleaseAutomation( RepositorySnapshot $snapshot ): bool {
		foreach ( array_keys( $snapshot->entries() ) as $path ) {
			if ( ! in_array( $path, self::GENERATED_PATHS, true )
				&& in_array( basename( $path ), array( '.release-please-manifest.json', 'release-please-config.json' ), true ) ) {
				return true;
			}
		}

		foreach ( $snapshot->documentPaths() as $path ) {
			$workflow = str_starts_with( $path, '.github/workflows/' ) && 1 === preg_match( '/\.ya?ml\z/i', $path );
			$script   = ( str_starts_with( $path, 'scripts/' ) || str_starts_with( $path, '.github/scripts/' ) || str_starts_with( $path, '.ci/' ) )
				&& str_ends_with( strtolower( $path ), '.sh' );
			$config   = in_array( $path, array( 'package.json', 'composer.json', 'Makefile' ), true );
			if ( ! $workflow && ! $script && ! $config ) {
				continue;
			}

			$content = $snapshot->document( $path ) ?? '';
			if ( $workflow ) {
				foreach ( self::RELEASE_ACTIONS as $action ) {
					if ( 1 === preg_match( '/^[ \t-]*uses[ \t]*:[ \t]*[\'\"]?' . preg_quote( $action, '/' ) . '@[^\r\n\'\"]+/mi', $content ) ) {
						return true;
					}
				}
				if ( 1 === preg_match( '/^[ \t-]*uses[ \t]*:[^\r\n]*(?:release|publish)[^\r\n]*\.ya?ml@[^\r\n]+/mi', $content )
					&& ( 1 === preg_match( '/^[ \t]*contents[ \t]*:[ \t]*[\'\"]?write\b/mi', $content )
						|| 1 === preg_match( '/^[ \t]*permissions[ \t]*:[ \t]*[\'\"]?write-all\b/mi', $content )
						|| 1 === preg_match( '/^[ \t]*permissions[ \t]*:[ \t]*\{[^}\r\n]*\bcontents[ \t]*:[ \t]*[\'\"]?write\b[^}\r\n]*\}/mi', $content ) ) ) {
					return true;
				}
			}

			$commands = $content;
			if ( in_array( $path, array( 'package.json', 'composer.json' ), true ) ) {
				try {
					$data = json_decode( $content, true, 32, JSON_THROW_ON_ERROR );
				} catch ( Throwable ) {
					$data = array();
				}
				if ( 'package.json' === $path && is_array( $data ) ) {
					foreach ( array( 'dependencies', 'devDependencies', 'optionalDependencies' ) as $group ) {
						foreach ( array_keys( is_array( $data[ $group ] ?? null ) ? $data[ $group ] : array() ) as $dependency ) {
							if ( in_array( $dependency, array( 'release-it', 'release-please', 'semantic-release' ), true )
								|| str_starts_with( $dependency, '@semantic-release/' ) ) {
								return true;
							}
						}
					}
				}
				$scriptValues = array();
				if ( is_array( $data['scripts'] ?? null ) ) {
					array_walk_recursive(
						$data['scripts'],
						static function ( mixed $value ) use ( &$scriptValues ): void {
							if ( is_string( $value ) ) {
								$scriptValues[] = $value;
							}
						}
					);
				}
				$commands = implode( "\n", $scriptValues );
			}

			if ( $this->containsReleaseCommand( $commands ) || $this->mutatesReleaseApi( $commands ) ) {
				return true;
			}
		}
		return false;
	}

	private function containsReleaseCommand( string $content ): bool {
		$prefix = '(?:\A|[\r\n;&|(){}])[ \t]*(?:-[ \t]*)?(?:run:[ \t]*)?@?(?:(?:if|then|do|command|cross-env|env|exec|sudo)[ \t]+|![ \t]*)?(?:[A-Za-z_][A-Za-z0-9_]*=[^ \t\r\n]+[ \t]+)*';
		return 1 === preg_match( '/' . $prefix . 'gh[ \t]+release[ \t]+(?:create|upload|edit|delete)(?:[ \t\r\n]|\\\\|\z)/i', $content )
			|| 1 === preg_match( '/' . $prefix . '(?:(?:npx|yarn|pnpm[ \t]+exec|npm[ \t]+exec)[ \t]+)?(?:release-please|semantic-release|release-it)(?:[ \t\r\n]|\\\\|\z)/i', $content )
			|| 1 === preg_match( '/' . $prefix . 'goreleaser[ \t]+release(?:[ \t\r\n]|\\\\|\z)/i', $content );
	}

	private function mutatesReleaseApi( string $content ): bool {
		$content  = preg_replace( '/\\\\\r?\n/', ' ', $content ) ?? $content;
		$commands = preg_split( '/(?:\r?\n|&&|\|\||;)/', $content );
		if ( ! is_array( $commands ) ) {
			return false;
		}
		foreach ( $commands as $command ) {
			$command      = trim( $command );
			$lowerCommand = strtolower( $command );
			if ( str_starts_with( $command, '#' ) || ! str_contains( $lowerCommand, '/releases' )
				|| 1 !== preg_match( '/\A(?:-[ \t]*)?(?:run:[ \t]*)?@?(?:(?:command|env|exec)[ \t]+)?(?:[A-Za-z_][A-Za-z0-9_]*=[^ \t]+[ \t]+)*(?:gh[ \t]+api|curl)(?:[ \t]|\z)/i', $command ) ) {
				continue;
			}
			if ( 1 === preg_match( '/(?:(?:--method|--request)(?:=|[ \t])*|-X(?:=|[ \t])*)(?i:post|patch|delete)(?:[ \t]|\\\\|\z)/', $command )
				|| ( str_contains( $lowerCommand, 'gh api' ) && ( 1 === preg_match( '/(?:--field|--raw-field|--input)(?:=|[ \t])/', $command )
					|| 1 === preg_match( '/(?:\A|[ \t])-[fF](?:[^ \t]*=|[ \t])/', $command ) ) )
				|| ( str_contains( $lowerCommand, 'curl ' ) && ( 1 === preg_match( '/(?:--data(?:-ascii|-binary|-raw|-urlencode)?|--form(?:-string)?|--json)(?:=|[ \t])/', $command )
					|| 1 === preg_match( '/(?:\A|[ \t])(?:-d(?:[^ \t]|[ \t])|-F(?:[^ \t]*=|[ \t]))/', $command ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return list<string>|null */
	private function releaseFiles( RepositorySnapshot $snapshot, string $type, string $headerPath ): ?array {
		$runtimeRoots = 'plugin' === $type ? self::PLUGIN_RUNTIME_ROOTS : self::THEME_RUNTIME_ROOTS;
		$files        = array();
		foreach ( $snapshot->entries() as $path => $entry ) {
			if ( 'blob' !== $entry['type'] ) {
				continue;
			}
			$parts = explode( '/', $path, 2 );
			$root  = $parts[0];
			if ( in_array( $root, self::DEVELOPMENT_ROOTS, true ) || in_array( $path, self::DEVELOPMENT_FILES, true )
				|| str_starts_with( $root, '.' ) ) {
				continue;
			}
			$runtime = in_array( $root, $runtimeRoots, true )
				|| ( ! str_contains( $path, '/' ) && $this->runtimeRootFile( $path, $type, $headerPath ) );
			if ( ! $runtime || '100644' !== $entry['mode'] ) {
				return null;
			}
			$files[] = $path;
		}
		return in_array( $headerPath, $files, true ) ? $files : null;
	}

	private function runtimeRootFile( string $path, string $type, string $headerPath ): bool {
		if ( hash_equals( $headerPath, $path ) || 'readme.txt' === $path ) {
			return true;
		}
		if ( 'plugin' === $type ) {
			return str_ends_with( strtolower( $path ), '.php' );
		}
		return in_array( $path, array( 'functions.php', 'style.css', 'theme.json', 'screenshot.png' ), true );
	}

	private function annotateVersionLine( string $document, string $label, string $expectedVersion ): ?string {
		$pattern = '/^[ \t]*(?:\*[ \t]*)?' . preg_quote( $label, '/' ) . ':[ \t]*([^\s]+)[ \t]*$/mi';
		if ( 1 !== preg_match_all( $pattern, $document, $matches, PREG_OFFSET_CAPTURE )
			|| ! hash_equals( $expectedVersion, $matches[1][0][0] ) ) {
			return null;
		}
		$line   = $matches[0][0][0];
		$offset = $matches[0][0][1];
		$start  = preg_match_all( '/^[ \t]*(?:\*[ \t]*)?x-release-please-start-version[ \t]*$/mi', $document );
		$end    = preg_match_all( '/^[ \t]*(?:\*[ \t]*)?x-release-please-end[ \t]*$/mi', $document );
		if ( 0 !== $start || 0 !== $end ) {
			$ownedPattern = '/^[ \t]*(?:\*[ \t]*)?x-release-please-start-version[ \t]*\R'
				. preg_quote( $line, '/' ) . '\R^[ \t]*(?:\*[ \t]*)?x-release-please-end[ \t]*$/mi';
			return 1 === $start && 1 === $end && 1 === preg_match( $ownedPattern, $document ) ? $document : null;
		}

		$prefix = '';
		preg_match( '/\A([ \t]*(?:\*[ \t]*)?)/', $line, $prefixMatch );
		$prefix  = $prefixMatch[1] ?? '';
		$newline = str_contains( $document, "\r\n" ) ? "\r\n" : "\n";
		$block   = $prefix . 'x-release-please-start-version' . $newline
			. $line . $newline . $prefix . 'x-release-please-end';
		return substr_replace( $document, $block, $offset, strlen( $line ) );
	}
}
