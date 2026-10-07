<?php

declare(strict_types=1);

namespace RAN\Tests\Quality;

use PHPUnit\Framework\TestCase;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\FileList;
use PHP_CodeSniffer\Runner;

final class StandardsCoverageTest extends TestCase {

	public function test_owned_php_cannot_disable_every_standard(): void {
		$paths = $this->tracked_php_files( dirname( __DIR__, 2 ) );
		foreach ( $paths as $path ) {
			$source = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only local owned source; never load or execute the fixture.
			self::assertIsString( $source );
			self::assertFalse( $this->has_blanket_suppression( $source, $path ), $path . ' must identify the specific rule and reason instead of suppressing a whole standard or category.' );
		}
	}

	public function test_canonical_commands_keep_the_inventory_checker_scope(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local command contract; do not execute manifest content.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' );
		self::assertIsString( $source );
		$manifest = json_decode( $source, true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'phpcs --standard=.phpcs.xml --report=summary', $manifest['scripts']['standards'] ?? null, 'Command changes must preserve the real-checker inventory contract; extra paths or ignore arguments cannot silently narrow it.' );
		self::assertSame( 'phpcbf --standard=.phpcs.xml --report=summary', $manifest['scripts']['standards:fix'] ?? null, 'Check and fix must keep the same reviewed selection.' );
	}

	public function test_every_tracked_php_file_is_selected_by_the_real_checker(): void {
		$tracked  = $this->tracked_php_files( dirname( __DIR__, 2 ) );
		$selected = $this->selected_php_files();
		self::assertNotEmpty( $tracked );
		self::assertSame( array(), array_values( array_diff( $tracked, $selected ) ), 'Every tracked PHP file needs standards coverage; a new exclusion requires explicit policy review.' );
	}

	public function test_a_checker_exclusion_exposes_the_omitted_owned_file(): void {
		$tracked  = $this->tracked_php_files( dirname( __DIR__, 2 ) );
		$selected = $this->selected_php_files( array( '--ignore=*/RAN/Theme.php' ) );
		self::assertSame( array( dirname( __DIR__, 2 ) . '/RAN/Theme.php' ), array_values( array_diff( $tracked, $selected ) ) );
	}

	public function test_a_new_tracked_root_reaches_discovery_and_the_blanket_guard(): void {
		$root      = sys_get_temp_dir() . '/ran-standards-' . bin2hex( random_bytes( 8 ) );
		$directory = $root . '/new-tooling';
		$path      = $directory . '/probe.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create an isolated CLI-only Git inventory fixture.
		self::assertTrue( mkdir( $directory, 0700, true ) );
		try {
			$source = "#!/usr/bin/env php\n<?php\n// phpcs:disable\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the isolated inventory fixture; it is never executed.
			self::assertSame( strlen( $source ), file_put_contents( $path, $source ) );
			$this->git_output( $root, array( 'init', '--quiet' ) );
			$this->git_output( $root, array( 'add', '--', 'new-tooling/probe.php' ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A second tracked entrypoint has no extension and must fail the same inventory comparison.
			file_put_contents( $directory . '/new-command', $source );
			$this->git_output( $root, array( 'add', '--', 'new-tooling/new-command' ) );
			$tracked = $this->tracked_php_files( $root );
			self::assertSame( array( $directory . '/new-command', $path ), $tracked );
			self::assertSame( array( $directory . '/new-command' ), array_values( array_diff( $tracked, $this->selected_php_files( array( $directory ) ) ) ), 'The canonical standards coverage comparison must reject an unsupported PHP entrypoint.' );
			self::assertContains( $path, $this->selected_php_files( array( $directory ) ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the inert fixture through the same discovered path used by the guard.
			$discovered = file_get_contents( $tracked[0] );
			self::assertIsString( $discovered );
			self::assertTrue( $this->has_blanket_suppression( $discovered ) );
			foreach ( array( 'template.phtml', 'template.PHTML', 'template.inc', 'template.html', 'template.htm', 'template-command' ) as $name ) {
				$template_path = $directory . '/' . $name;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write inert mixed HTML/PHP beyond the former header bound; never execute it.
				file_put_contents( $template_path, str_repeat( '<p>Template</p>', 100 ) . '<?php function ( {' );
				$this->git_output( $root, array( 'add', '--', 'new-tooling/' . $name ) );
				self::assertContains( $template_path, $this->tracked_php_files( $root ) );
				if ( in_array( $name, array( 'template.phtml', 'template.PHTML', 'template-command' ), true ) ) {
					self::assertNotContains( $template_path, $this->selected_php_files( array( $directory ) ) );
				}
			}
			foreach ( array( 'example.md', 'example.json', 'example.sh' ) as $name ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Documentation/data/shell PHP examples must not become executable PHP merely by containing an opening tag.
				file_put_contents( $directory . '/' . $name, 'Example: <?php echo 1;' );
				$this->git_output( $root, array( 'add', '--', 'new-tooling/' . $name ) );
				self::assertNotContains( $directory . '/' . $name, $this->tracked_php_files( $root ) );
			}
		} finally {
			// This unique fixture contains only files created above and by Git init/add.
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the isolated CLI fixture tree.
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the now-empty fixture root.
			rmdir( $root );
		}
	}

	public function test_narrowed_real_annotations_do_not_cover_new_neighbors(): void {
		$cases = array(
			array( 'RAN/Admin/Component/RepositoryDetailRenderer.php', 'WordPress.Security.EscapeOutput.OutputNotEscaped', "echo \$unescaped_probe;\n" ),
			array( 'RAN/Admin/DeploymentAdminPresenter.php', 'WordPress.Security.NonceVerification.Recommended', "\$query_probe = \$_GET['probe'];\n" ),
			array( 'tests/Uninstall/UninstallWordPressFunctions.php', 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound', "function unrelated_probe() {}\n" ),
			array( 'RAN/Secrets/SecretsFile.php', 'WordPress.WP.AlternativeFunctions.file_system_operations_fopen', "fopen( '/tmp/unused-probe', 'rb' );\n" ),
			array( 'RAN/Deployment/AdmittedBranchHostAdapter.php', 'WordPress.WP.AlternativeFunctions.parse_url_parse_url', "parse_url( 'https://example.invalid/' );\n" ),
		);
		foreach ( $cases as list( $path, $diagnostic, $probe ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Use each real narrowed annotation as the fixture; never execute its source.
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			self::assertIsString( $source );
			self::assertStringContainsString( 'phpcs:ignore ' . $diagnostic, $source );
			$sniff = implode( '.', array_slice( explode( '.', $diagnostic ), 0, 3 ) );
			self::assertNotContains( $diagnostic, $this->inspect( $source, $path, $sniff )['sources'] );
			self::assertContains( $diagnostic, $this->inspect( $source . "\n" . $probe, $path, $sniff )['sources'] );
		}
	}

	public function test_rule_exclusions_only_cover_the_immutable_generated_binding(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only the canonical local XML without resolving external entities.
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/.phpcs.xml' );
		self::assertIsString( $source );
		$xml = new \DOMDocument();
		self::assertTrue( $xml->loadXML( $source, LIBXML_NONET ) );
		$xpath      = new \DOMXPath( $xml );
		$exclusions = $xpath->query( '//rule/exclude-pattern | //rule/exclude' );
		self::assertCount( 1, $exclusions );
		$entry = $exclusions->item( 0 );
		self::assertInstanceOf( \DOMElement::class, $entry );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM exposes the native parentNode and textContent properties.
		$parent = $entry->parentNode;
		self::assertInstanceOf( \DOMElement::class, $parent );
		self::assertSame( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound', $parent->getAttribute( 'ref' ) );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Compare the native DOM textContent of the one independently parity-checked generated exception.
		self::assertSame( '/views/generated/ran-admin-shell\\.php$', $entry->textContent );
		$root = dirname( __DIR__, 2 );
		foreach ( $this->tracked_php_files( $root ) as $path ) {
			self::assertFalse( $this->has_generated_binding_collision( substr( $path, strlen( $root ) + 1 ) ), 'Only the exact parity-checked root binding may receive the generated-variable exception: ' . $path );
		}
	}

	public function test_phpstan_annotations_are_identifier_local_with_reasons(): void {
		foreach ( $this->tracked_php_files( dirname( __DIR__, 2 ) ) as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Parse comments as inert tokens; fixture strings must not become policy directives.
			$source = file_get_contents( $path );
			self::assertIsString( $source );
			foreach ( token_get_all( $source ) as $token ) {
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( preg_match_all( '/@phpstan-ignore[^\r\n]*/i', $token[1], $annotations ) ) {
					foreach ( $annotations[0] as $annotation ) {
						self::assertMatchesRegularExpression( '/^@phpstan-ignore\s+[a-zA-Z][a-zA-Z0-9]*\.[a-zA-Z0-9.]+(?:\s*,\s*[a-zA-Z][a-zA-Z0-9]*\.[a-zA-Z0-9.]+)*\s+\(\S.+\)/', $annotation, $path . ':' . $token[2] );
					}
				}
			}
		}
	}

	/** @return list<string> */
	private function tracked_php_files( string $root ): array {
		$output = $this->git_output( $root, array( 'ls-files', '-z' ) );
		$paths  = array_map( static fn( string $path ): string => $root . '/' . $path, array_filter( explode( "\0", $output ), static fn( string $path ): bool => '' !== $path ) );
		$paths  = array_values(
			array_filter(
				$paths,
				static function ( string $path ): bool {
					$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
					$template  = in_array( $extension, array( '', 'phtml', 'inc', 'html', 'htm' ), true );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect inert tracked PHP/template bytes, including HTML preambles; never execute source.
					$header = file_get_contents( $path, false, null, 0, $template ? null : 256 );
					self::assertIsString( $header );
					return in_array( $extension, array( 'php', 'phtml' ), true ) || 1 === preg_match( $template ? '/<\?(?:php(?:\s|$)|=)/i' : '/\A(?:#![^\r\n]*\R)?\s*<\?(?:php(?:\s|$)|=)/i', $header );
				}
			)
		);
		sort( $paths );
		return $paths;
	}

	/** @param list<string> $arguments */
	private function git_output( string $root, array $arguments ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Read tracked CLI inventory or prepare its isolated negative fixture without invoking a shell.
		$process = proc_open(
			array( 'git', '-C', $root, ...$arguments ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the child input pipe; Git reads no input.
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the child output pipe.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the child error pipe.
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), (string) $error );
		self::assertIsString( $output );
		return $output;
	}

	/**
	 * @param list<string> $arguments
	 * @return list<string>
	 */
	private function selected_php_files( array $arguments = array() ): array {
		$root = dirname( __DIR__, 2 );
		require_once $root . '/vendor/squizlabs/php_codesniffer/autoload.php';
		$runner         = new Runner();
		$runner->config = new Config( array( '--standard=' . $root . '/.phpcs.xml', ...$arguments ) );
		$runner->init();
		$paths = array();
		foreach ( new FileList( $runner->config, $runner->ruleset ) as $path => $file ) {
			$paths[] = $path;
		}
		sort( $paths );
		return $paths;
	}

	public function test_blanket_guard_distinguishes_annotations_from_fixture_strings(): void {
		foreach ( array( '// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda', '// phpcs:ignore WordPress.WP.AlternativeFunctions -- Whole sniff.', '// phpcs:disable WordPress.PHP.YodaConditions.NotYoda -- Still future-wide.', '// @CODINGSTANDARDSCHANGESETTING WordPress.NamingConventions.PrefixAllGlobals prefixes rogue', '// PHPCS:DISABLE', '// phpcs:ignorefile', '// phpcs:ignore WordPress -- Too broad.', '// phpcs:disable WordPress.Security -- Too broad.', '// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda, WordPress -- Mixed broad selector.', '// phpcs:disable', '// phpcs:disable -- fixture', '// phpcs:ignore', '// phpcs:ignoreFile -- fixture', "/**\n * @codingStandardsIgnoreStart\n */", '/** @codingStandardsIgnoreFile */', '/** @codingStandardsIgnoreLine */' ) as $annotation ) {
			self::assertTrue( $this->has_blanket_suppression( "<?php\n" . $annotation . "\n" ) );
		}
		self::assertFalse( $this->has_blanket_suppression( "<?php\n// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Required evaluation order.\n" ) );
		self::assertFalse( $this->has_blanket_suppression( '<?php $fixture = "// phpcs:disable";' ) );
		self::assertFalse( $this->has_blanket_suppression( '<?php $fixture = "/* @codingStandardsIgnoreFile */";' ) );
	}

	public function test_a_template_binding_cannot_authorize_another_directive_in_the_same_comment(): void {
		$source = "<?php\n/* phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Caller binding.\nphpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Unrelated future-wide escape.\n*/\necho \$_GET['x'];";
		self::assertNotContains( 'WordPress.Security.EscapeOutput.OutputNotEscaped', $this->inspect( $source, 'views/probe.php', 'WordPress.Security.EscapeOutput' )['sources'] );
		self::assertTrue( $this->has_blanket_suppression( $source, 'views/probe.php' ) );
		self::assertFalse( $this->has_blanket_suppression( "<?php\n// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Caller binding.\n", 'views/probe.php' ) );
	}

	public function test_legacy_and_block_suppressions_cannot_hide_checker_findings(): void {
		$probe  = 'function ran_booster_probe( $unused, $value ) { return $value === 1; }';
		$result = $this->inspect( "<?php\n" . $probe, 'RAN/NewQualityProbe.php' );
		self::assertContains( 'WordPress.PHP.YodaConditions.NotYoda', $result['sources'] );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed', $result['sources'] );
		foreach ( array( '@codingStandardsIgnoreStart', '@codingStandardsIgnoreFile', '@codingStandardsIgnoreLine', 'phpcs:disable', 'PHPCS:DISABLE', 'phpcs:ignorefile', 'phpcs:ignoreFileSuffix', 'phpcs:ignoreFile', 'phpcs:ignore' ) as $directive ) {
			foreach ( array( '// ' . $directive, '/* ' . $directive . ' */' ) as $annotation ) {
				$source = "<?php\n" . $annotation . "\n" . $probe;
				$result = $this->inspect( $source, 'RAN/NewQualityProbe.php' );
				self::assertSame( array(), $result['sources'], $annotation . ' must demonstrate a real checker suppression.' );
				self::assertTrue( $this->has_blanket_suppression( $source ), $annotation . ' must still fail the independent blanket guard.' );
			}
		}
	}

	public function test_broad_selectors_really_hide_diagnostics_but_fail_the_guard(): void {
		foreach ( array( 'phpcs:ignore WordPress', 'PHPCS:DISABLE WordPress.PHP', 'phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed, WordPress' ) as $directive ) {
			$source = "<?php\n// " . $directive . " -- Broad fixture.\nfunction ran_booster_probe( \$value ) { return \$value === 1; }";
			$result = $this->inspect( $source, 'views/nested/new-probe.php' );
			self::assertNotContains( 'WordPress.PHP.YodaConditions.NotYoda', $result['sources'] );
			self::assertTrue( $this->has_blanket_suppression( $source ) );
		}
	}

	public function test_inline_properties_cannot_redefine_the_approved_prefix_policy(): void {
		$sniff  = 'WordPress.NamingConventions.PrefixAllGlobals';
		$source = "<?php\nfunction rogue_function() {}\n";
		self::assertContains( $sniff . '.NonPrefixedFunctionFound', $this->inspect( $source, 'RAN/NewQualityProbe.php', $sniff )['sources'] );
		foreach ( array( 'phpcs:set', 'PHPCS:SET', '@codingStandardsChangeSetting' ) as $directive ) {
			$source = "<?php\n// " . $directive . ' ' . $sniff . " prefixes rogue\nfunction rogue_function() {}\n";
			self::assertSame( array(), $this->inspect( $source, 'RAN/NewQualityProbe.php', $sniff )['sources'], 'The property annotation must demonstrate a real checker bypass.' );
			self::assertTrue( $this->has_blanket_suppression( $source ), 'Source annotations cannot replace the approved ruleset property.' );
		}
	}

	private function has_blanket_suppression( string $source, string $path = '' ): bool {
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true )
				&& 1 === preg_match( '/@codingStandards(?:Ignore(?:Start|File|Line)|ChangeSetting)|phpcs:(?:set\b|ignoreFile|(?:disable|ignore)(?:\s*(?:--[^\r\n]*)?\s*(?:\*\/)?\s*$))/im', $token[1] ) ) {
				return true;
			}
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true )
				&& preg_match_all( '/phpcs:(disable|ignore)\b([^\r\n]*)/i', $token[1], $matches ) ) {
				foreach ( $matches[2] as $index => $directive ) {
					if ( ! preg_match( '/--\s+\S/', $directive ) ) {
						return true;
					}
					if ( 'disable' === strtolower( $matches[1][ $index ] ) ) {
						$template_binding = preg_match( '~(?:^|/)views/(?!generated/).*\.php$~', str_replace( '\\', '/', $path ) )
							&& preg_match( '/^\s*WordPress\.NamingConventions\.PrefixAllGlobals\.NonPrefixedVariableFound\s+--\s+\S/i', $directive );
						if ( ! $template_binding ) {
							return true;
						}
					}
					$selectors = explode( ',', explode( '--', $directive, 2 )[0] );
					foreach ( $selectors as $selector ) {
						if ( count( explode( '.', trim( $selector, " \t*/" ) ) ) < 4 ) {
							return true;
						}
					}
				}
			}
		}
		return false;
	}

	public function test_new_owned_paths_cannot_escape_condition_and_parameter_checks(): void {
		$source = '<?php function ran_booster_probe( $unused, $value ) { return $value === 1; }';
		foreach ( array( 'RAN/NewQualityProbe.php', 'views/new-quality-probe.php', 'scripts/new-quality-probe.php', 'tests/Quality/NewQualityProbe.php', 'new-quality-probe.php' ) as $path ) {
			$result = $this->inspect( $source, $path );
			self::assertNotSame( 0, $result['exit'], $path );
			self::assertContains( 'WordPress.PHP.YodaConditions.NotYoda', $result['sources'], $path );
			self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed', $result['sources'], $path );
		}
	}

	public function test_local_ruleset_cannot_disable_diagnostics_with_severity_overrides(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the owned quality configuration without executing it.
		$xml = file_get_contents( dirname( __DIR__, 2 ) . '/.phpcs.xml' );
		self::assertIsString( $xml );
		self::assertFalse( $this->has_disabled_severity( $xml ), 'Local severity overrides must meet the default minimum diagnostic severity of five.' );
	}

	public function test_local_ruleset_arguments_preserve_canonical_diagnostics(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only the owned checker configuration.
		$xml = file_get_contents( dirname( __DIR__, 2 ) . '/.phpcs.xml' );
		self::assertIsString( $xml );
		self::assertFalse( $this->has_unreviewed_arguments( $xml ), 'Local checker arguments must preserve the reviewed canonical invocation.' );
		$source     = '<?php function ran_booster_probe( $camelCase ) { return $camelCase; }';
		$diagnostic = 'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase';
		self::assertContains( $diagnostic, $this->inspect( $source, 'RAN/NewQualityProbe.php', '' )['sources'] );
		foreach ( array(
			'sniffs'  => 'Generic.PHP.Syntax',
			'exclude' => 'WordPress.NamingConventions.ValidVariableName',
		) as $name => $value ) {
			$fixture_xml = str_replace( '</ruleset>', '<arg name="' . $name . '" value="' . $value . '"/></ruleset>', $xml );
			self::assertTrue( $this->has_unreviewed_arguments( $fixture_xml ) );
			$path = sys_get_temp_dir() . '/ran-arguments-' . bin2hex( random_bytes( 12 ) ) . '.xml';
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the unique disposable ruleset fixture.
				self::assertSame( strlen( $fixture_xml ), file_put_contents( $path, $fixture_xml ) );
				self::assertNotContains( $diagnostic, $this->inspect( $source, 'RAN/NewQualityProbe.php', '', $path )['sources'], 'The rejected argument must hide an actual diagnostic without a probe sniff override.' );
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this unique disposable ruleset fixture.
				unlink( $path );
			}
		}
	}

	public function test_owned_method_rule_removal_cannot_hide_inherited_declarations(): void {
		$diagnostic = 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase';
		$source     = "<?php\nnamespace RAN;\nclass Probe extends \\stdClass {\n// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Required foreign spelling in this boundary fixture.\npublic function acceptedLegacy(): void {}\npublic function camelCase(): void {}\n}\n";
		foreach ( array( 'RAN/NewQualityProbe.php', 'tests/Quality/NewQualityProbe.php' ) as $path ) {
			$result = $this->inspect( $source, $path, '' );
			self::assertSame( 1, count( array_filter( $result['sources'], static fn( string $actual ): bool => $diagnostic === $actual ) ), 'The canonical profile must reject the neighboring owned method without overriding sniff selection.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Mutate only a disposable copy of the canonical ruleset.
		$xml = file_get_contents( dirname( __DIR__, 2 ) . '/.phpcs.xml' );
		self::assertIsString( $xml );
		$fixture_xml = str_replace( '<rule ref="RANOwnedMethods"/>', '', $xml );
		self::assertNotSame( $xml, $fixture_xml, 'The negative control must remove the actual owned-method rule.' );
		$path = sys_get_temp_dir() . '/ran-owned-methods-' . bin2hex( random_bytes( 12 ) ) . '.xml';
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only this unique private ruleset mutation; the canonical profile stays unchanged.
			self::assertSame( strlen( $fixture_xml ), file_put_contents( $path, $fixture_xml ) );
			self::assertNotContains( $diagnostic, $this->inspect( $source, 'RAN/NewQualityProbe.php', '', $path )['sources'], 'Deleting the rule must demonstrate a real inherited-method bypass that the canonical probe prevents.' );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this unique private ruleset mutation.
			unlink( $path );
		}
	}

	public function test_local_xml_selectors_and_exit_configuration_cannot_weaken_checks(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the owned canonical profile as inert XML.
		$xml = file_get_contents( dirname( __DIR__, 2 ) . '/.phpcs.xml' );
		self::assertIsString( $xml );
		self::assertFalse( $this->has_unreviewed_xml_selection( $xml ) );
		$source     = '<?php json_encode( array() );';
		$diagnostic = 'WordPress.WP.AlternativeFunctions.json_encode_json_encode';
		self::assertContains( $diagnostic, $this->inspect( $source, 'RAN/FutureSelection.php', '' )['sources'] );
		$mutations = array(
			str_replace( '</ruleset>', '<rule ref="' . $diagnostic . '"><include-pattern>*/existing-only.php</include-pattern></rule></ruleset>', $xml ),
			str_replace( '<rule ref="RANWordPressPlugin"/>', '<rule ref="RANWordPressPlugin" phpcbf-only="true"/>', $xml ),
			str_replace( '<rule ref="RANWordPressPlugin"/>', '<rule ref="RANWordPressPlugin" phpcs-only="false"/>', $xml ),
			str_replace( '</ruleset>', '<config name="ignore_errors_on_exit" value="1"/><config name="ignore_warnings_on_exit" value="1"/></ruleset>', $xml ),
		);
		foreach ( $mutations as $index => $mutation ) {
			self::assertTrue( $this->has_unreviewed_xml_selection( $mutation ) );
			$path = sys_get_temp_dir() . '/ran-selector-' . bin2hex( random_bytes( 12 ) ) . '.xml';
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write the unique inert profile mutation for the real checker.
				self::assertSame( strlen( $mutation ), file_put_contents( $path, $mutation ) );
				$result = $this->inspect( $source, 'RAN/FutureSelection.php', '', $path );
				if ( 3 === $index ) {
					self::assertContains( $diagnostic, $result['sources'] );
					self::assertSame( 0, $result['exit'], 'The rejected config hides diagnostic failure in the exit status.' );
				} else {
					self::assertNotContains( $diagnostic, $result['sources'], 'The rejected selector must hide the actual native JSON diagnostic.' );
				}
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this unique private profile mutation.
				unlink( $path );
			}
		}
	}

	public function test_root_exclusions_need_review_before_future_files_exist(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect only the canonical profile before creating inert XML mutations.
		$xml = file_get_contents( dirname( __DIR__, 2 ) . '/.phpcs.xml' );
		self::assertIsString( $xml );
		self::assertFalse( $this->has_unreviewed_xml_selection( $xml ) );
		foreach ( array(
			str_replace( '</ruleset>', '<exclude-pattern>*/not-yet-created/*</exclude-pattern></ruleset>', $xml ),
			str_replace( '<exclude-pattern>/vendor/</exclude-pattern>', '', $xml ),
			str_replace( '<exclude-pattern>/vendor/</exclude-pattern>', '<exclude-pattern>/vendor/</exclude-pattern><exclude-pattern>/vendor/</exclude-pattern>', $xml ),
			str_replace( '<exclude-pattern>/vendor/', '<exclude-pattern type="relative">/vendor/', $xml ),
			str_replace( '<exclude-pattern>/vendor/', '<exclude-pattern>*/vendor/', $xml ),
			str_replace( '<exclude-pattern>/views/generated/', '<exclude-pattern type="relative">/views/generated/', $xml ),
		) as $mutation ) {
			self::assertNotSame( $xml, $mutation );
			self::assertTrue( $this->has_unreviewed_xml_selection( $mutation ) );
		}
	}

	private function has_unreviewed_xml_selection( string $source ): bool {
		$xml = new \DOMDocument();
		self::assertTrue( $xml->loadXML( $source, LIBXML_NONET ) );
		$xpath = new \DOMXPath( $xml );
		if ( 0 !== $xpath->query( '//rule/include-pattern | //*[@phpcs-only or @phpcbf-only]' )->length ) {
			return true;
		}
		foreach ( $xpath->query( '//exclude-pattern' ) as $exclusion ) {
			self::assertInstanceOf( \DOMElement::class, $exclusion );
			if ( $exclusion->hasAttributes() ) {
				return true;
			}
		}
		$root_exclusions = array();
		foreach ( $xpath->query( '/ruleset/exclude-pattern' ) as $exclusion ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM exposes textContent for inspecting the existing exclusion patterns.
			$root_exclusions[] = $exclusion->textContent;
		}
		sort( $root_exclusions );
		if ( array( '/.phpcs-cache', '/.phpunit.cache/', '/.plugin-check/', '/coverage/', '/node_modules/', '/ran-booster-workbench/', '/vendor/' ) !== $root_exclusions ) {
			return true;
		}
		$configs = array();
		foreach ( $xpath->query( '//config' ) as $config ) {
			self::assertInstanceOf( \DOMElement::class, $config );
			$configs[] = $config->getAttribute( 'name' ) . ':' . $config->getAttribute( 'value' );
		}
		sort( $configs );
		return array( 'minimum_wp_version:7.0', 'testVersion:8.2-' ) !== $configs;
	}

	private function has_generated_binding_collision( string $path ): bool {
		return 'views/generated/ran-admin-shell.php' !== $path && 1 === preg_match( '~(?:^|/)views/generated/ran-admin-shell\\.php$~i', $path );
	}

	public function test_generated_binding_suffix_collision_requires_explicit_review(): void {
		$diagnostic = 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound';
		foreach ( array( 'views/generated/ran-admin-shell.php', 'new-root/views/generated/ran-admin-shell.php', 'views/generated/RAN-ADMIN-SHELL.php' ) as $path ) {
			self::assertNotContains( $diagnostic, $this->inspect( '<?php $unrelated = 1;', $path, '' )['sources'], 'Locked PHPCS rule patterns match absolute suffixes and ignore relative mode.' );
			self::assertSame( 'views/generated/ran-admin-shell.php' !== $path, $this->has_generated_binding_collision( $path ) );
		}
		self::assertContains( $diagnostic, $this->inspect( '<?php $unrelated = 1;', 'views/generated/ran-admin-shell.php-extra.php', '' )['sources'] );
	}

	private function has_unreviewed_arguments( string $xml ): bool {
		$ruleset = new \DOMDocument();
		self::assertTrue( $ruleset->loadXML( $xml, LIBXML_NONET ) );
		$arguments = array();
		foreach ( ( new \DOMXPath( $ruleset ) )->query( '//arg' ) as $argument ) {
			self::assertInstanceOf( \DOMElement::class, $argument );
			$arguments[] = $argument->getAttribute( 'name' ) . ':' . $argument->getAttribute( 'value' );
		}
		sort( $arguments );
		return array( ':sp', 'basepath:.', 'colors:', 'extensions:php', 'parallel:4' ) !== $arguments;
	}

	public function test_variable_naming_diagnostics_remain_enforced_across_owned_paths(): void {
		$source = '<?php function ran_booster_probe( $camelCase ) { return $camelCase; }';
		foreach ( array( 'RAN/NewQualityProbe.php', 'views/new-quality-probe.php', 'scripts/new-quality-probe.php', 'tests/Quality/NewQualityProbe.php', 'new-quality-probe.php' ) as $path ) {
			$result = $this->inspect( $source, $path, 'WordPress.NamingConventions.ValidVariableName' );
			self::assertSame( array_fill( 0, 2, 'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase' ), $result['sources'], $path );
		}
	}

	public function test_below_threshold_severity_hides_real_diagnostics_but_fails_the_guard(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Copy only the owned ruleset into a disposable negative control.
		$xml = file_get_contents( dirname( __DIR__, 2 ) . '/.phpcs.xml' );
		self::assertIsString( $xml );
		foreach ( array( 0, 1, 4 ) as $level ) {
			$fixture_xml = str_replace( '</ruleset>', '<rule ref="WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase"><severity>' . $level . '</severity></rule></ruleset>', $xml );
			self::assertTrue( $this->has_disabled_severity( $fixture_xml ) );
			$path = sys_get_temp_dir() . '/ran-severity-' . bin2hex( random_bytes( 12 ) ) . '.xml';
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write only the uniquely allocated private ruleset fixture.
				self::assertSame( strlen( $fixture_xml ), file_put_contents( $path, $fixture_xml ) );
				$result = $this->inspect( '<?php function ran_booster_probe( $camelCase ) { return $camelCase; }', 'RAN/NewQualityProbe.php', 'WordPress.NamingConventions.ValidVariableName', $path );
				self::assertSame( array(), $result['sources'], 'The rejected configuration must demonstrate a real checker bypass.' );
				self::assertSame( 0, $result['exit'] );
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this uniquely allocated disposable ruleset.
				unlink( $path );
			}
		}
		foreach ( array( '00', '-1', 'invalid' ) as $severity ) {
			self::assertTrue( $this->has_disabled_severity( '<ruleset><rule ref="WordPress"><severity>' . $severity . '</severity></rule></ruleset>' ) );
		}
		self::assertFalse( $this->has_disabled_severity( '<ruleset><rule ref="WordPress"><severity>5</severity></rule></ruleset>' ) );
	}

	private function has_disabled_severity( string $xml ): bool {
		$ruleset = new \DOMDocument();
		self::assertTrue( $ruleset->loadXML( $xml, LIBXML_NONET ) );
		foreach ( ( new \DOMXPath( $ruleset ) )->query( '//rule/severity' ) as $severity ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode exposes the native textContent property.
			if ( ! preg_match( '/^[1-9][0-9]*$/D', trim( $severity->textContent ) ) || 5 > (int) $severity->textContent ) {
				return true;
			}
		}
		return false;
	}

	public function test_an_inherited_class_does_not_hide_an_unused_private_parameter(): void {
		$result = $this->inspect( '<?php class Probe extends ParentProbe { private function owned_helper( $unused ) { return true; } }', 'RAN/NewQualityProbe.php' );
		self::assertNotSame( 0, $result['exit'] );
		self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass', $result['sources'] );
	}

	public function test_new_owned_paths_check_reserved_parameter_names(): void {
		$result = $this->inspect( '<?php function ran_booster_probe( $default ) { return $default; }', 'RAN/NewQualityProbe.php' );
		self::assertNotSame( 0, $result['exit'] );
		self::assertContains( 'Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound', $result['sources'] );
	}

	public function test_owned_factories_and_dtos_enforce_reserved_parameter_names(): void {
		$sniff = 'Universal.NamingConventions.NoReservedKeywordParameterNames';
		$cases = array(
			array( 'RAN/Theme.php', '$wp_theme', '$object', 'objectFound' ),
			array( 'RAN/Plugin.php', '$plugin_data', '$array', 'arrayFound' ),
			array( 'RAN/ManagedRepository.php', '$is_private', '$private', 'privateFound' ),
			array( 'RAN/RepositoryProvider/RepositoryDescriptor.php', '$is_private', '$private', 'privateFound' ),
			array( 'RAN/RepositoryProvider/RepositoryReference.php', '$is_private', '$private', 'privateFound' ),
		);
		foreach ( $cases as list( $path, $parameter, $reserved, $code ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect the real owned signature without executing its source.
			$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
			self::assertIsString( $source );
			self::assertStringContainsString( $parameter, $source );
			$accepted = $this->inspect( $source, $path, $sniff );
			self::assertSame( 0, $accepted['exit'], $path );
			self::assertSame( array(), $accepted['sources'], $path );
			$regressed = $this->inspect( str_replace( $parameter, $reserved, $source ), $path, $sniff );
			self::assertNotSame( 0, $regressed['exit'], $path );
			self::assertSame( array( $sniff . '.' . $code ), $regressed['sources'], $path );
		}
	}

	public function test_a_signature_exception_does_not_hide_the_next_declaration(): void {
		$source = "<?php\n// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required external callback signature.\nfunction ran_booster_callback( \$unused ) { return true; }\nfunction ran_booster_helper( \$unused ) { return true; }\n";
		$result = $this->inspect( $source, 'RAN/NewQualityProbe.php' );
		self::assertNotSame( 0, $result['exit'] );
		self::assertSame( array( 'Generic.CodeAnalysis.UnusedFunctionParameter.Found' ), $result['sources'] );
		self::assertSame( array( 4 ), $result['lines'] );
	}

	public function test_a_compliant_new_file_passes_the_same_rules(): void {
		$result = $this->inspect( '<?php function ran_booster_probe( $value ) { return 1 === $value; }', 'RAN/NewQualityProbe.php' );
		self::assertSame( 0, $result['exit'] );
		self::assertSame( array(), $result['sources'] );
	}

	public function test_view_locals_do_not_exempt_new_declarations_or_hooks(): void {
		$sniff  = 'WordPress.NamingConventions.PrefixAllGlobals';
		$source = '<?php $view_binding = true; function unprefixed_helper() {} class UnprefixedType {} const UNPREFIXED_VALUE = 1; do_action( "unprefixed_hook" );';
		foreach ( array( 'views/new-probe.php', 'views/nested/new-probe.php', 'RAN/views/new-probe.php' ) as $path ) {
			$result = $this->inspect( $source, $path, $sniff );
			foreach ( array( 'NonPrefixedFunctionFound', 'NonPrefixedClassFound', 'NonPrefixedConstantFound', 'NonPrefixedHooknameFound' ) as $code ) {
				self::assertContains( $sniff . '.' . $code, $result['sources'], $path );
			}
			self::assertContains( $sniff . '.NonPrefixedVariableFound', $result['sources'] );
		}
		$result = $this->inspect( '<?php $view_binding = true;', 'RAN/NewProbe.php', $sniff );
		self::assertContains( $sniff . '.NonPrefixedVariableFound', $result['sources'] );
	}

	public function test_only_the_immutable_admin_shell_can_match_its_path_exception(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( $this->tracked_php_files( $root ) as $path ) {
			self::assertFalse( $this->is_generated_exception_collision( substr( $path, strlen( $root ) + 1 ) ), $path );
		}
		self::assertTrue( $this->is_generated_exception_collision( 'RAN/views/generated/ran-admin-shell.php' ) );
		self::assertFalse( $this->is_generated_exception_collision( 'views/generated/ran-admin-shell.php' ) );
		$result = $this->inspect( '<?php $binding = true;', 'RAN/views/generated/ran-admin-shell.php', 'WordPress.NamingConventions.PrefixAllGlobals' );
		self::assertSame( array(), $result['sources'], 'The collision guard must detect this real rule-pattern exemption.' );
	}

	private function is_generated_exception_collision( string $path ): bool {
		return 'views/generated/ran-admin-shell.php' !== $path && 1 === preg_match( '~/views/generated/ran-admin-shell\.php$~i', '/' . $path );
	}

	public function test_view_binding_annotation_only_exempts_its_exact_diagnostic(): void {
		$source = "<?php\n// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Caller-owned include bindings.\n\$binding = true;\nfunction unprefixed_helper() {}";
		$result = $this->inspect( $source, 'views/new-probe.php', 'WordPress.NamingConventions.PrefixAllGlobals' );
		self::assertSame( array( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound' ), $result['sources'] );
	}

	public function test_test_locals_do_not_exempt_new_declarations_or_hooks(): void {
		$sniff  = 'WordPress.NamingConventions.PrefixAllGlobals';
		$source = '<?php $fixture_state = true; function unprefixed_helper() {} class UnprefixedType {} const UNPREFIXED_VALUE = 1; do_action( "unprefixed_hook" );';
		foreach ( array( 'tests/new-probe.php', 'tests/fixtures/nested/new-probe.php', 'RAN/tests/new-probe.php' ) as $path ) {
			$result = $this->inspect( $source, $path, $sniff );
			foreach ( array( 'NonPrefixedFunctionFound', 'NonPrefixedClassFound', 'NonPrefixedConstantFound', 'NonPrefixedHooknameFound' ) as $code ) {
				self::assertContains( $sniff . '.' . $code, $result['sources'], $path );
			}
			self::assertContains( $sniff . '.NonPrefixedVariableFound', $result['sources'] );
		}
	}

	public function test_wordpress_double_exception_does_not_hide_the_next_owned_helper(): void {
		$source = "<?php\n// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress-owned fixture identity.\nfunction wp_example() {}\nfunction owned_helper() {}\n";
		$result = $this->inspect( $source, 'tests/fixtures/new-double.php', 'WordPress.NamingConventions.PrefixAllGlobals' );
		self::assertSame( array( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound' ), $result['sources'] );
		self::assertSame( array( 4 ), $result['lines'] );
	}

	public function test_namespace_exceptions_do_not_disable_unrelated_namespaces(): void {
		$sniff = 'WordPress.NamingConventions.PrefixAllGlobals';
		foreach ( array( 'RAN/NewProbe.php', 'tests/fixtures/NewProbe.php' ) as $path ) {
			$result = $this->inspect( '<?php namespace Unrelated;', $path, $sniff );
			self::assertContains( $sniff . '.NonPrefixedNamespaceFound', $result['sources'] );
			$result = $this->inspect( '<?php namespace RAN\\Quality;', $path, $sniff );
			self::assertSame( array(), $result['sources'] );
		}
		foreach ( array( 'RAN' ) as $namespace ) {
			$source = "<?php\nnamespace " . $namespace . "; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Established namespace identity.\nnamespace Unrelated;\n";
			$result = $this->inspect( $source, 'tests/fixtures/NewProbe.php', $sniff );
			self::assertSame( array( $sniff . '.NonPrefixedNamespaceFound' ), $result['sources'] );
			self::assertSame( array( 3 ), $result['lines'] );
		}
	}

	/** @return array{exit: int, sources: list<string>, lines: list<int>} */
	private function inspect( string $source, string $path, string $sniffs = 'WordPress.PHP.YodaConditions,Generic.CodeAnalysis.UnusedFunctionParameter,Universal.NamingConventions.NoReservedKeywordParameterNames', ?string $standard = null ): array {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the locked checker in isolation; these are CLI-only enforcement controls.
		$process = proc_open(
			array(
				PHP_BINARY,
				$root . '/vendor/bin/phpcs',
				'--standard=' . ( $standard ?? $root . '/.phpcs.xml' ),
				...( '' === $sniffs ? array() : array( '--sniffs=' . $sniffs ) ),
				'--report=json',
				'--no-colors',
				'--parallel=1',
				'--stdin-path=' . $root . '/' . $path,
				'-q',
				'-',
			),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$root
		);
		self::assertIsResource( $process );
		fwrite( $pipes[0], $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Feed only an in-memory fixture to the locked CLI checker.
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the local checker input pipe.
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the local checker output pipe.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the local checker error pipe.
		$exit = proc_close( $process );
		self::assertSame( '', $error );
		$report = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertArrayHasKey( 'files', $report );
		$sources = array();
		$lines   = array();
		foreach ( $report['files'] as $file ) {
			foreach ( $file['messages'] as $message ) {
				$sources[] = $message['source'];
				$lines[]   = $message['line'];
			}
		}
		return array(
			'exit'    => $exit,
			'sources' => $sources,
			'lines'   => $lines,
		);
	}
}
