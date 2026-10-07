<?php

declare(strict_types=1);

namespace RAN\Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;

final class RepositoryReleaseWorkflowDtoTest extends TestCase {
	public function test_preview_uses_only_initial_pack_identity(): void {
		$preview = new RepositoryReleaseWorkflowPreview(
			str_repeat( 'a', 32 ),
			'gh',
			'101',
			'bootstrap',
			'stable',
			'owner/example',
			array(
				'repository'      => 'owner/example',
				'default_branch'  => 'main',
				'base_sha'        => 'base',
				'pack_version'    => '1.0.0',
				'template_digest' => str_repeat( 'b', 64 ),
			),
			array()
		);

		self::assertSame( '1.0.0', $preview->summary()['pack_version'] );
	}

	#[DataProvider( 'retired_preview_modes' )]
	public function test_preview_rejects_retired_update_and_prerelease_modes( string $kind, string $channel ): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowPreview(
			str_repeat( 'a', 32 ),
			'gh',
			'101',
			$kind,
			$channel,
			'owner/example',
			array(
				'repository'      => 'owner/example',
				'default_branch'  => 'main',
				'base_sha'        => 'base',
				'pack_version'    => '1.0.0',
				'template_digest' => str_repeat( 'b', 64 ),
			),
			array()
		);
	}

	/** @return array<string, array{string, string}> */
	public static function retired_preview_modes(): array {
		return array(
			'template-update' => array( 'template_update', '' ),
			'prerelease'      => array( 'bootstrap', 'prerelease' ),
		);
	}

	public function test_status_rejects_retired_update_record(): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowStatus( 'gh', '101', true, true, record_operation: 'template_update' );
	}

	public function test_dtos_reject_html_and_unbounded_records(): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowStatus(
			'gh',
			'101',
			false,
			false,
			credential_choices: array(
				array(
					'id'    => 'selected',
					'label' => '<b>Selected</b>',
				),
			)
		);
	}

	public function test_status_rejects_an_exact_record_that_does_not_occupy_the_repository(): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryReleaseWorkflowStatus( 'gh', '101', true, false );
	}

	public function test_result_rejects_html_and_preview_rejects_unexpected_record_keys(): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowResult( 'workflow_invalid_request', false, message: '<em>Unsafe</em>' );
	}

	#[DataProvider( 'invalid_result_failure_stage_provider' )]
	public function test_result_rejects_failure_stages_outside_core_display_contract( bool $successful, string $failure_stage ): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryReleaseWorkflowResult( 'workflow_invalid_request', $successful, failure_stage: $failure_stage );
	}

	/** @return array<string, array{bool, string}> */
	public static function invalid_result_failure_stage_provider(): array {
		return array(
			'success-stage' => array( true, 'repository_snapshot' ),
			'unknown-stage' => array( false, 'provider_transport' ),
		);
	}

	#[DataProvider( 'invalid_utf8_provider_text' )]
	public function test_result_rejects_malformed_utf8_provider_text( string $field ): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryReleaseWorkflowResult(
			'workflow_invalid_request',
			false,
			...array( $field => "\xC3\x28" )
		);
	}

	/** @return array<string, array{string}> */
	public static function invalid_utf8_provider_text(): array {
		return array(
			'message'     => array( 'message' ),
			'remediation' => array( 'remediation' ),
		);
	}

	#[DataProvider( 'whitespace_only_result_copy' )]
	public function test_result_rejects_whitespace_only_optional_provider_copy( string $field ): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryReleaseWorkflowResult(
			'workflow_invalid_request',
			false,
			...array( $field => " \t " )
		);
	}

	/** @return array<string, array{string}> */
	public static function whitespace_only_result_copy(): array {
		return array(
			'message'     => array( 'message' ),
			'remediation' => array( 'remediation' ),
		);
	}

	public function test_status_rejects_whitespace_only_optional_write_guidance(): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryReleaseWorkflowStatus( 'gh', '101', false, false, write_guidance: " \t " );
	}

	public function test_preview_rejects_unexpected_changed_path_fields(): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowPreview(
			str_repeat( 'a', 32 ),
			'gh',
			'101',
			'bootstrap',
			'stable',
			'owner/example',
			array(
				'repository'      => 'owner/example',
				'default_branch'  => 'main',
				'base_sha'        => 'base',
				'pack_version'    => '1.0.0',
				'template_digest' => str_repeat( 'b', 64 ),
			),
			array(
				array(
					'path'      => 'release.yml',
					'operation' => 'added',
					'digest'    => str_repeat( 'c', 64 ),
					'raw'       => 'forbidden',
				),
			)
		);
	}
}
