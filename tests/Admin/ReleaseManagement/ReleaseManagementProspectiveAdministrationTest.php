<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement;

require_once __DIR__ . '/Support/ReleaseManagementWordPressFunctions.php';
require_once __DIR__ . '/Support/ReleaseManagementFixtures.php';

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\AddOn\ReleaseTracking\ProspectiveReleaseResult;
use RAN\Admin\ReleaseManagement\ProspectiveReleaseOperations;
use RuntimeException;
use Tests\Admin\ReleaseManagement\Support\ProspectiveReleaseFacadeDouble;
use Tests\Admin\ReleaseManagement\Support\ReleaseManagementFixture;
use Tests\Admin\ReleaseManagement\Support\UnreadSecretCanary;

final class ReleaseManagementProspectiveAdministrationTest extends TestCase {
	#[Before]
	public function reset_word_press(): void {
		ReleaseManagementFixture::reset_word_press();
	}

	public function test_legacy_candidate_execute_rejects_before_facade_or_reader_work(): void {
		$prospective  = new ProspectiveReleaseFacadeDouble();
		$reader_calls = 0;
		$operations   = new ProspectiveReleaseOperations(
			$prospective,
			static function ( string $type, array $repository, string $channel ) use ( &$reader_calls ): ProspectiveReleaseResult {
				unset( $type, $repository, $channel );
				++$reader_calls;

				return ProspectiveReleaseResult::failure( 'operation_failed' );
			}
		);

		$outcome = $operations->execute(
			'list_candidates',
			'plugin',
			array(
				'provider'   => 'acme',
				'repository' => 'workspace/example',
			),
			'',
			'',
			'',
			'stable',
			''
		);

		self::assertSame( 'invalid_request', $outcome['code'] );
		self::assertFalse( $outcome['successful'] );
		self::assertSame( array(), $prospective->calls );
		self::assertSame( 0, $reader_calls );
	}

	public function test_list_inspect_and_fingerprint_bound_install_forward_exact_neutral_evidence(): void {
		$fingerprint                             = 'v2:' . str_repeat( 'b', 64 );
		$prospective                             = new ProspectiveReleaseFacadeDouble();
		$prospective->results['list_candidates'] = ProspectiveReleaseResult::success(
			'release_candidates_available',
			array(
				'channel'    => 'prerelease',
				'candidates' => array(
					array(
						'release_id'           => 'release:opaque/042',
						'tag'                  => 'v1.2.3-rc.1',
						'version'              => '1.2.3-rc.1',
						'prerelease'           => true,
						'published_at'         => '2026-07-28T09:00:00Z',
						'expected_asset_names' => array( 'package-1.2.3-rc.1.zip' ),
						'untrusted'            => 'drop-me',
					),
				),
			)
		);
		$prospective->results['inspect']         = ProspectiveReleaseResult::success(
			'release_ready',
			array(
				'release_id'   => 'release:opaque/042',
				'tag'          => 'v1.2.3-rc.1',
				'version'      => '1.2.3-rc.1',
				'commit'       => str_repeat( 'a', 40 ),
				'details_url'  => 'https://releases.acme.test/packages/example/v1.2.3-rc.1',
				'package_root' => 'example',
				'main_file'    => 'example.php',
				'fingerprint'  => $fingerprint,
			)
		);
		$prospective->results['install']         = ProspectiveReleaseResult::success(
			'installed',
			array(
				'identifier' => 'example/example.php',
				'version'    => '1.2.3-rc.1',
			)
		);
		$controls                                = ReleaseManagementFixture::controls( prospective: $prospective );
		$request                                 = $this->request( 'list_candidates', 'plugin', 'acme', 'prerelease' );

		$list = $controls->process_prospective_request( 'list_candidates', $request );
		self::assertTrue( $list['successful'] );
		self::assertArrayNotHasKey( 'untrusted', $list['data']['candidates'][0] );

		$request['release_id']  = 'release:opaque/042';
		$request['release_tag'] = 'v1.2.3-rc.1';
		$request['_wpnonce']    = $this->nonce( 'inspect', 'plugin' );
		$inspect                = $controls->process_prospective_request( 'inspect', $request );
		self::assertTrue( $inspect['successful'] );
		self::assertSame( $fingerprint, $inspect['data']['fingerprint'] );
		self::assertSame( 'https://releases.acme.test/packages/example/v1.2.3-rc.1', $inspect['data']['details_url'] );

		$request['ran_booster_release_install_nonce'] = $this->nonce( 'install', 'plugin' );
		$request['release_fingerprint']               = $fingerprint;
		$install                                      = $controls->process_prospective_request( 'install', $request );
		self::assertTrue( $install['successful'] );
		self::assertSame( 'example/example.php', $install['identifier'] );

		self::assertSame( 'list_candidates', $prospective->calls[0][0] );
		self::assertSame(
			array(
				'provider'      => 'acme',
				'repository'    => 'workspace/example',
				'credential_id' => 'profile_1',
			),
			$prospective->calls[0][2]
		);
		self::assertSame( array( 'inspect', 'plugin', $prospective->calls[0][2], 'release:opaque/042', 'v1.2.3-rc.1', 'prerelease', $this->nonce( 'inspect', 'plugin' ) ), $prospective->calls[1] );
		self::assertSame( array( 'install', 'plugin', $prospective->calls[0][2], 'release:opaque/042', 'v1.2.3-rc.1', $fingerprint, 'prerelease', $this->nonce( 'install', 'plugin' ) ), $prospective->calls[2] );
	}

	public function test_prospective_pane_carries_type_and_install_script_suppresses_branch_dispatcher(): void {
		$controls = ReleaseManagementFixture::controls();
		ob_start();
		$controls->render_advanced_source_section(
			'create',
			'plugin',
			'release_asset',
			null,
			'https://example.test/wp-admin/admin.php?page=ran-booster-plugins-create'
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="expected_type" value="plugin"', $html );
		self::assertStringContainsString( 'data-ran-booster-release-switch-branch hidden>Use branch</button>', $html );
		self::assertStringNotContainsString( 'Use Branch tracking instead', $html );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Direct local interaction conformance read.
		$script = file_get_contents( dirname( __DIR__, 3 ) . '/assets/ran-booster-release-management.js' );
		self::assertIsString( $script );
		self::assertStringContainsString( "form.elements.namedItem('ran_booster[action]')", $script );
		self::assertStringContainsString( 'branchAction.disabled = true', $script );
		self::assertStringContainsString( '%s Refresh releases before installing.', $script );
		self::assertStringNotContainsString( '`${outcomeMessage} Refresh releases before installing.`', $script );
	}

	public function test_invalid_authority_does_not_traverse_credential_bearing_repository_fields(): void {
		$prospective                               = new ProspectiveReleaseFacadeDouble();
		$controls                                  = ReleaseManagementFixture::controls( prospective: $prospective );
		$request                                   = $this->request( 'list_candidates' );
		$request['_wpnonce']                       = 'invalid';
		$request['ran_booster']['credential_id']   = new UnreadSecretCanary();
		$request['ran_booster']['repository']      = new UnreadSecretCanary();
		$request['ran_booster']['private_payload'] = new UnreadSecretCanary();

		$outcome = $controls->process_prospective_request( 'list_candidates', $request );

		self::assertFalse( $outcome['successful'] );
		self::assertSame( 'invalid_request', $outcome['code'] );
		self::assertSame( array(), $prospective->calls );
		self::assertStringNotContainsString(
			'secret',
			(string) \RAN\Admin\ReleaseManagement\wp_json_encode( $outcome )
		);
	}

	public function test_denied_capability_and_unavailable_nonce_authority_stop_before_provider_work(): void {
		foreach ( array( 'denied', 'empty', 'throw' ) as $mode ) {
			$prospective = new ProspectiveReleaseFacadeDouble();
			if ( 'denied' === $mode ) {
				$GLOBALS['ran_booster_release_management_test_denied_capabilities'] = array( 'install_plugins' );
			} else {
				$prospective->nonce_failure = $mode;
			}
			$controls = ReleaseManagementFixture::controls( prospective: $prospective );

			$outcome = $controls->process_prospective_request( 'list_candidates', $this->request( 'list_candidates' ) );

			self::assertFalse( $outcome['successful'], $mode );
			self::assertSame( 'denied' === $mode ? 'forbidden' : 'service_unavailable', $outcome['code'], $mode );
			self::assertSame( array(), $prospective->calls, $mode );
			unset( $GLOBALS['ran_booster_release_management_test_denied_capabilities'] );
		}
	}

	public function test_invalid_fingerprint_and_channel_stop_before_provider_work(): void {
		$prospective = new ProspectiveReleaseFacadeDouble();
		$controls    = ReleaseManagementFixture::controls( prospective: $prospective );
		$request     = array_merge(
			$this->request( 'install' ),
			array(
				'release_id'                        => '42',
				'release_tag'                       => 'v1.2.3',
				'release_fingerprint'               => 'invalid',
				'ran_booster_release_install_nonce' => $this->nonce( 'install', 'plugin' ),
			)
		);

		$fingerprint_outcome            = $controls->process_prospective_request( 'install', $request );
		$request['release_fingerprint'] = 'v2:' . str_repeat( 'a', 64 );
		$request['release_channel']     = 'nightly';
		$channel_outcome                = $controls->process_prospective_request( 'install', $request );

		self::assertSame( 'invalid_request', $fingerprint_outcome['code'] );
		self::assertSame( 'invalid_request', $channel_outcome['code'] );
		self::assertSame( array(), $prospective->calls );
	}

	#[DataProvider( 'invalid_opaque_release_ids' )]
	public function test_invalid_opaque_release_ids_stop_before_facade_work( mixed $release_id ): void {
		$prospective = new ProspectiveReleaseFacadeDouble();
		$controls    = ReleaseManagementFixture::controls( prospective: $prospective );
		$request     = array_merge(
			$this->request( 'inspect' ),
			array(
				'release_id'  => $release_id,
				'release_tag' => 'v1.2.3',
			)
		);

		$outcome = $controls->process_prospective_request( 'inspect', $request );

		self::assertSame( 'invalid_request', $outcome['code'] );
		self::assertSame( array(), $prospective->calls );
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalid_opaque_release_ids(): iterable {
		yield 'empty' => array( '' );
		yield 'control byte' => array( "release\n42" );
		yield 'too long' => array( str_repeat( 'r', 192 ) );
		yield 'array' => array( array( 'release:42' ) );
		yield 'object' => array( (object) array( 'release:42' ) );
	}

	public function test_complete_projection_excludes_partial_provider_and_unsupported_outcome_stays_bounded(): void {
		$prospective                             = new ProspectiveReleaseFacadeDouble();
		$prospective->supported_providers        = array( 'gh', 'acme' );
		$prospective->results['list_candidates'] = ProspectiveReleaseResult::failure( 'unsupported_provider' );
		$controls                                = ReleaseManagementFixture::controls( prospective: $prospective );
		$_GET['page']                            = 'ran-booster-plugins-create'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen fixture.

		$controls->enqueue_prospective_assets();
		$projection = $GLOBALS['ran_booster_release_management_test_localized']['ran-booster-release-management']['ranBoosterReleaseManagement'] ?? null;
		self::assertIsArray( $projection );
		self::assertSame( array( 'gh', 'acme' ), $projection['supportedProviders'] );
		self::assertNotContains( 'partial', $projection['supportedProviders'] );

		$outcome = $controls->process_prospective_request(
			'list_candidates',
			$this->request( 'list_candidates', 'plugin', 'partial' )
		);
		self::assertFalse( $outcome['successful'] );
		self::assertSame( 'unsupported_provider', $outcome['code'] );
		self::assertSame( array(), $outcome['data'] );
	}

	public function test_list_reader_callable_runs_once_and_rejects_thrown_or_unknown_results(): void {
		$prospective = new ProspectiveReleaseFacadeDouble();
		$calls       = 0;
		$controls    = ReleaseManagementFixture::controls(
			prospective: $prospective,
			read_candidates: static function ( string $type, array $repository, string $channel ) use ( &$calls ): ProspectiveReleaseResult {
				++$calls;
				self::assertSame( 'plugin', $type );
				self::assertSame( 'gh', $repository['provider'] );
				self::assertSame( 'stable', $channel );

				return ProspectiveReleaseResult::failure( 'unexpected_reader_code' );
			}
		);

		$outcome = $controls->process_prospective_request( 'list_candidates', $this->request( 'list_candidates' ) );

		self::assertSame( 1, $calls );
		self::assertSame( 'operation_failed', $outcome['code'] );
		self::assertSame( array(), $prospective->calls );

		$controls = ReleaseManagementFixture::controls(
			read_candidates: static function ( string $type, array $repository, string $channel ): ProspectiveReleaseResult {
				unset( $type, $repository, $channel );
				throw new RuntimeException( 'reader-failure' );
			}
		);
		$outcome  = $controls->process_prospective_request( 'list_candidates', $this->request( 'list_candidates' ) );

		self::assertSame( 'unable_to_check', $outcome['code'] );
		self::assertFalse( $outcome['successful'] );
	}

	#[DataProvider( 'callable_candidate_results' )]
	public function test_callable_candidate_contract_closes_invalid_results(
		ProspectiveReleaseResult $result,
		string $expected_code
	): void {
		$calls    = 0;
		$controls = ReleaseManagementFixture::controls(
			read_candidates: static function ( string $type, array $repository, string $channel ) use ( &$calls, $result ): ProspectiveReleaseResult {
				++$calls;
				self::assertSame( 'plugin', $type );
				self::assertSame( 'gh', $repository['provider'] );
				self::assertSame( 'stable', $channel );

				return $result;
			}
		);

		$outcome = $controls->process_prospective_request( 'list_candidates', $this->request( 'list_candidates' ) );

		self::assertSame( 1, $calls );
		self::assertFalse( $outcome['successful'] );
		self::assertSame( $expected_code, $outcome['code'] );
		self::assertSame( array(), $outcome['data'] );
	}

	/** @return iterable<string, array{ProspectiveReleaseResult,string}> */
	public static function callable_candidate_results(): iterable {
		yield 'runtime unsupported' => array(
			ProspectiveReleaseResult::failure( 'runtime_unsupported' ),
			'runtime_unsupported',
		);
		yield 'successful channel mismatch' => array(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'channel'    => 'prerelease',
					'candidates' => array( self::candidate() ),
				)
			),
			'operation_failed',
		);
		yield 'more than eight candidates' => array(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'channel'    => 'stable',
					'candidates' => array_fill( 0, 9, self::candidate() ),
				)
			),
			'operation_failed',
		);
		yield 'more than eight assets' => array(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'channel'    => 'stable',
					'candidates' => array( self::candidate( expected_asset_names: array_fill( 0, 9, 'package.zip' ) ) ),
				)
			),
			'operation_failed',
		);
		yield 'invalid release identifier' => array(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'channel'    => 'stable',
					'candidates' => array( self::candidate( release_id: '' ) ),
				)
			),
			'operation_failed',
		);
		yield 'oversized version' => array(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'channel'    => 'stable',
					'candidates' => array( self::candidate( version: str_repeat( 'v', 65 ) ) ),
				)
			),
			'operation_failed',
		);
		yield 'oversized asset name' => array(
			ProspectiveReleaseResult::success(
				'release_candidates_available',
				array(
					'channel'    => 'stable',
					'candidates' => array( self::candidate( expected_asset_names: array( str_repeat( 'a', 192 ) ) ) ),
				)
			),
			'operation_failed',
		);
	}

	#[DataProvider( 'install_types' )]
	public function test_fingerprint_bound_install_has_plugin_theme_parity( string $type, string $identifier ): void {
		$fingerprint                     = 'v2:' . str_repeat( 'c', 64 );
		$prospective                     = new ProspectiveReleaseFacadeDouble();
		$prospective->results['install'] = ProspectiveReleaseResult::success(
			'installed',
			array(
				'identifier' => $identifier,
				'version'    => '2.0.0',
			)
		);
		$controls                        = ReleaseManagementFixture::controls( prospective: $prospective );
		$request                         = array_merge(
			$this->request( 'install', $type ),
			array(
				'release_id'                        => '84',
				'release_tag'                       => 'v2.0.0',
				'release_fingerprint'               => $fingerprint,
				'ran_booster_release_install_nonce' => $this->nonce( 'install', $type ),
			)
		);

		$outcome = $controls->process_prospective_request( 'install', $request );

		self::assertTrue( $outcome['successful'] );
		self::assertSame( $identifier, $outcome['identifier'] );
		self::assertSame( array( 'install', $type, $request['ran_booster'], '84', 'v2.0.0', $fingerprint, 'stable', $this->nonce( 'install', $type ) ), $prospective->calls[0] );
	}

	/** @return iterable<string, array{string,string}> */
	public static function install_types(): iterable {
		yield 'plugin' => array( 'plugin', 'example/example.php' );
		yield 'theme' => array( 'theme', 'example-theme' );
	}

	public function test_ajax_handler_emits_only_the_bounded_production_envelope(): void {
		$prospective                             = new ProspectiveReleaseFacadeDouble();
		$prospective->results['list_candidates'] = ProspectiveReleaseResult::success(
			'release_candidates_available',
			array(
				'candidates' => array(
					array(
						'release_id'           => '42',
						'tag'                  => 'v1.2.3',
						'version'              => '1.2.3',
						'prerelease'           => false,
						'published_at'         => '2026-07-28T09:00:00Z',
						'expected_asset_names' => array( 'package.zip' ),
					),
				),
			)
		);
		$controls                                = ReleaseManagementFixture::controls( prospective: $prospective );
		$_POST                                   = $this->request( 'list_candidates' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Production callback fixture.

		try {
			$controls->handle_prospective_list_candidates();
			self::fail( 'AJAX transport must terminate.' );
		} catch ( RuntimeException $error ) {
			self::assertSame( 'json-response', $error->getMessage() );
		}
		$response = $GLOBALS['ran_booster_release_management_test_json']['response'] ?? null;
		self::assertIsArray( $response );
		self::assertSame( array( 'successful', 'code', 'data' ), array_keys( $response ) );
		self::assertTrue( $response['successful'] );
	}

	/** @return array<string, mixed> */
	private function request(
		string $operation,
		string $type = 'plugin',
		string $provider = 'gh',
		string $channel = 'stable'
	): array {
		return array(
			'expected_type'   => $type,
			'_wpnonce'        => $this->nonce( $operation, $type ),
			'release_channel' => $channel,
			'ran_booster'     => array(
				'provider'      => $provider,
				'repository'    => 'workspace/example',
				'credential_id' => 'profile_1',
			),
		);
	}

	private function nonce( string $operation, string $type ): string {
		return 'nonce-for-prospective-release-' . $operation . '-' . $type;
	}

	/** @return array{release_id:string,tag:string,version:string,prerelease:bool,published_at:string,expected_asset_names:list<string>} */
	private static function candidate(
		string $release_id = '42',
		string $version = '1.2.3',
		array $expected_asset_names = array( 'package.zip' )
	): array {
		return array(
			'release_id'           => $release_id,
			'tag'                  => 'v1.2.3',
			'version'              => $version,
			'prerelease'           => false,
			'published_at'         => '2026-07-28T09:00:00Z',
			'expected_asset_names' => $expected_asset_names,
		);
	}
}
