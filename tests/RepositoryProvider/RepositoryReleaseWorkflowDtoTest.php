<?php

declare(strict_types=1);

namespace Tests\RepositoryProvider;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;

final class RepositoryReleaseWorkflowDtoTest extends TestCase {
	public function testPreviewAllowsAnEmptyOldTemplateTagOnlyForBootstrap(): void {
		$preview = new RepositoryReleaseWorkflowPreview(
			str_repeat( 'a', 32 ),
			'gh',
			'101',
			'bootstrap',
			'stable',
			'owner/example',
			array(
				'repository'       => 'owner/example',
				'default_branch'   => 'main',
				'base_sha'         => 'base',
				'pack_version'     => '1.0.0',
				'template_digest'  => str_repeat( 'b', 64 ),
				'old_template_tag' => '',
				'new_template_tag' => 'v1.0.0',
			),
			array()
		);

		self::assertSame( '', $preview->summary()['old_template_tag'] );
	}

	public function testDtosRejectHtmlAndUnboundedRecords(): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowStatus(
			'gh',
			'101',
			false,
			false,
			credentialChoices: array(
				array(
					'id'    => 'selected',
					'label' => '<b>Selected</b>',
				),
			)
		);
	}

	public function testStatusRejectsAnExactRecordThatDoesNotOccupyTheRepository(): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryReleaseWorkflowStatus( 'gh', '101', true, false );
	}

	public function testResultRejectsHtmlAndPreviewRejectsUnexpectedRecordKeys(): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowResult( 'workflow_invalid_request', false, message: '<em>Unsafe</em>' );
	}

	/** @dataProvider invalidResultFailureStageProvider */
	public function testResultRejectsFailureStagesOutsideCoreDisplayContract( bool $successful, string $failureStage ): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryReleaseWorkflowResult( 'workflow_invalid_request', $successful, failureStage: $failureStage );
	}

	/** @return array<string, array{bool, string}> */
	public static function invalidResultFailureStageProvider(): array {
		return array(
			'success-stage' => array( true, 'repository_snapshot' ),
			'unknown-stage' => array( false, 'provider_transport' ),
		);
	}

	/** @dataProvider invalidUtf8ProviderText */
	public function testResultRejectsMalformedUtf8ProviderText( string $field ): void {
		$this->expectException( InvalidArgumentException::class );

		new RepositoryReleaseWorkflowResult(
			'workflow_invalid_request',
			false,
			...array( $field => "\xC3\x28" )
		);
	}

	/** @return array<string, array{string}> */
	public static function invalidUtf8ProviderText(): array {
		return array(
			'message'     => array( 'message' ),
			'remediation' => array( 'remediation' ),
		);
	}

	public function testPreviewRejectsUnexpectedChangedPathFields(): void {
		$this->expectException( InvalidArgumentException::class );
		new RepositoryReleaseWorkflowPreview(
			str_repeat( 'a', 32 ),
			'gh',
			'101',
			'bootstrap',
			'stable',
			'owner/example',
			array(
				'repository'       => 'owner/example',
				'default_branch'   => 'main',
				'base_sha'         => 'base',
				'pack_version'     => '1.0.0',
				'template_digest'  => str_repeat( 'b', 64 ),
				'old_template_tag' => '',
				'new_template_tag' => 'v1.0.0',
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
