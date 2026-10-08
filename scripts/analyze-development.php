<?php

declare(strict_types=1);

use PHPStan\DependencyInjection\ContainerFactory;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$ran_booster_analysis_status = ( static function (): int {
	$started   = hrtime( true );
	$root      = dirname( __DIR__ );
	$config    = $root . '/phpstan-development.neon';
	$container = ( new ContainerFactory( $root ) )->create( $root . '/.phpunit.cache/test-analysis', array( $config ), array() );
	$files     = $container->getService( 'fileFinderAnalyse' )->findFiles( array( $root . '/scripts', $root . '/tests' ) )->getFiles();
	sort( $files );
	$profiles = array();
	foreach ( $files as $file ) {
		$isolated               = preg_match( '~^' . preg_quote( $root, '~' ) . '/tests/(?:WordPress|fixtures|Integration)/~', $file );
		$profile                = $isolated ? 'phpstan-integration.neon' : 'phpstan-development.neon';
		$profiles[ $profile ][] = $file;
	}
	$arguments = array_slice( $GLOBALS['argv'] ?? array(), 1 );
	if ( array( '--list' ) === $arguments ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emit machine-readable CLI selection evidence without HTML escaping.
		echo json_encode( $profiles, JSON_THROW_ON_ERROR ) . "\n";
		return 0;
	}
	if ( ! in_array( $arguments, array( array(), array( '--list-batch' ) ), true ) || array() === $files ) {
		return 2;
	}
	$parser     = ( new ParserFactory() )->createForNewestSupportedVersion();
	$finder     = new NodeFinder();
	$candidates = array_fill_keys( $profiles['phpstan-development.neon'] ?? array(), true );
	$classes    = array();
	foreach ( $files as $file ) {
		// Parse declarations without loading executable fixture code or trusting an analysis result cache.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read discovered maintained source only for the conservative execution partition.
		$nodes     = $parser->parse( (string) file_get_contents( $file ) ) ?? array();
		$traverser = new NodeTraverser( new NameResolver() );
		$nodes     = $traverser->traverse( $nodes );
		$named     = false;
		foreach ( $finder->find(
			$nodes,
			static fn( Node $node ): bool => $node instanceof Node\Stmt\ClassLike
				|| $node instanceof Node\Stmt\Function_
				|| $node instanceof Node\Stmt\Const_
				|| $node instanceof Node\Expr\Eval_
				|| $node instanceof Node\Expr\FuncCall
		) as $node ) {
			if ( $node instanceof Node\Stmt\ClassLike ) {
				if ( null === $node->name ) {
					continue;
				}
				$named = true;
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PhpParser owns the resolved namespacedName property.
				$name               = strtolower( (string) $node->namespacedName );
				$classes[ $name ][] = $file;
				if ( ! str_starts_with( $name, 'ran\\tests\\' ) ) {
					unset( $candidates[ $file ] );
				}
			} elseif ( $node instanceof Node\Expr\FuncCall ) {
				// Aliases are resolved above. Dynamic calls retain their original isolated world.
				if ( ! $node->name instanceof Node\Name || preg_match( '/(?:^|\\\\)(?:define|class_alias)$/i', $node->name->toString() ) ) {
					unset( $candidates[ $file ] );
				}
			} else {
				unset( $candidates[ $file ] );
			}
		}
		if ( ! $named ) {
			unset( $candidates[ $file ] );
		}
	}
	foreach ( $classes as $declarations ) {
		if ( count( $declarations ) > 1 ) {
			foreach ( $declarations as $file ) {
				unset( $candidates[ $file ] );
			}
		}
	}
	$batch = array_keys( $candidates );
	if ( array( '--list-batch' ) === $arguments ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emit the actual batch for independent coverage and isolation controls.
		echo json_encode( $batch, JSON_THROW_ON_ERROR ) . "\n";
		return 0;
	}
	$workers = getenv( 'PHPSTAN_DEVELOPMENT_PROCESSES' );
	$workers = false === $workers || '' === $workers ? '1' : $workers;
	if ( ! in_array( $workers, array( '1', '2', '4' ), true ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Report invalid CLI configuration to standard error.
		fwrite( STDERR, "PHPSTAN_DEVELOPMENT_PROCESSES must be 1, 2 or 4.\n" );
		return 2;
	}
	$status = 0;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Report numeric CLI coverage and process counts, not HTML.
	printf( "Development analysis: %d files; %d batched; %d isolated; %s processes.\n", count( $files ), count( $batch ), count( $files ) - count( $batch ), $workers );
	foreach ( $profiles as $profile => $selected ) {
		$groups = array_map( static fn( string $file ): array => array( $file ), array_values( array_diff( $selected, $batch ) ) );
		if ( 'phpstan-development.neon' === $profile && array() !== $batch ) {
			array_unshift( $groups, $batch );
		}
		foreach ( array_chunk( $groups, (int) $workers ) as $wave ) {
			$running = array();
			foreach ( $wave as $group ) {
				// Each child retains its own PHP process and file selection. Temporary files avoid pipe deadlocks and interleaved diagnostics.
				$output = tmpfile();
				if ( false === $output ) {
					$status = 2;
					break;
				}
				$config = $root . '/' . $profile;
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Concurrent analyzers retain isolated symbol worlds and never execute fixture source.
				$process = proc_open(
					array_merge( array( PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $config, '--no-progress', '--memory-limit=1G' ), $group ),
					array( STDIN, $output, $output ),
					$pipes,
					$root
				);
				if ( ! is_resource( $process ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the private temporary stream also removes it.
					fclose( $output );
					$status = 2;
					break;
				}
				$running[] = array( $process, $output );
			}
			foreach ( $running as list( $process, $output ) ) {
				if ( 0 !== proc_close( $process ) ) {
					$status = max( 1, $status );
				}
				rewind( $output );
				stream_copy_to_stream( $output, STDOUT );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the completed child output and remove its private temporary file.
				fclose( $output );
			}
			if ( 2 === $status ) {
				return $status;
			}
		}
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Report numeric CLI timing for the complete development stage.
	printf( "Development analysis completed in %.2f seconds.\n", ( hrtime( true ) - $started ) / 1000000000 );
	return $status;
} )();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The closure returns only an integer CLI status; exit emits no response body.
exit( $ran_booster_analysis_status );
