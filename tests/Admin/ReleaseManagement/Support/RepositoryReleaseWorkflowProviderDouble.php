<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\Support;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingPreflight;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\Admin\ProviderAdminMetadata;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReference;
use RAN\RepositoryProvider\RepositoryReleaseAcquirer;
use RAN\RepositoryProvider\RepositoryReleaseArtifact;
use RAN\RepositoryProvider\RepositoryReleaseCandidateList;
use RAN\RepositoryProvider\RepositoryReleaseCandidateListing;
use RAN\RepositoryProvider\RepositoryReleaseInspection;
use RAN\RepositoryProvider\RepositoryReleaseInspector;
use RAN\RepositoryProvider\RepositoryReleaseMetadata;
use RAN\RepositoryProvider\RepositoryReleaseNativeTarget;
use RAN\RepositoryProvider\RepositoryReleaseNativeTargets;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagement;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RuntimeException;

final class RepositoryReleaseWorkflowProviderDouble implements RepositoryProvider, RepositoryReleaseWorkflowManagement, RepositoryReleaseMetadata, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseAcquirer, RepositoryReleaseNativeTargets {
	/** @var list<array{operation:string,credential_id:?string,channel?:string,key?:string,confirmation?:string}> */
	public array $calls          = array();
	public int $statusReads      = 0;
	public bool $throwOnWorkflow = false;

	public function __construct( private readonly string $code = 'fixture', private readonly string $repositoryId = '101', private readonly ?RepositoryReleaseWorkflowPreview $preview = null, private readonly ?RepositoryReleaseWorkflowStatus $status = null, private readonly ?RepositoryReleaseWorkflowResult $workflowResult = null, private readonly bool $adminSurface = true ) {}

	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), 'Workflow fixture', 'https://fixture.example/', 'Owner', $this->adminSurface ? new ProviderAdminMetadata( array(), array() ) : null ); }
	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics { public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );
				return array();
		} }; }
	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return new RepositoryDescriptor( ProviderCode::parse( $this->code ), $request->locator, 'example', $this->repositoryId, false, 'main', null ); }
	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );
		throw new RuntimeException( 'Archive preparation is outside this fixture.' ); }
	public function expectedUpdateUri( RepositoryReference $repository ): string {
		return 'https://fixture.example/' . $repository->locator; }
	public function releaseDetailsUrl( RepositoryReference $repository, string $tag ): string {
		return 'https://fixture.example/' . $repository->locator . '/releases/tag/' . rawurlencode( $tag ); }
	public function listReleaseCandidates( string $packageType, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList {
		unset( $packageType, $repository, $channel );
		throw new RuntimeException( 'Candidate listing is outside this fixture.' ); }
	public function inspectRelease( string $packageType, RepositoryReference $repository, string $providerReleaseId, string $tag, string $channel ): RepositoryReleaseInspection {
		unset( $packageType, $repository, $providerReleaseId, $tag, $channel );
		throw new RuntimeException( 'Release inspection is outside this fixture.' ); }
	public function acquireRelease( string $packageType, RepositoryReference $repository, string $providerReleaseId, string $tag, string $expectedFingerprint, string $channel ): RepositoryReleaseArtifact {
		unset( $packageType, $repository, $providerReleaseId, $tag, $expectedFingerprint, $channel );
		throw new RuntimeException( 'Release acquisition is outside this fixture.' ); }
	public function hasRegisteredNativeTarget( string $packageType, string $installedIdentifier ): bool {
		unset( $packageType, $installedIdentifier );
		return false; }
	public function createNativeTarget( string $packageType, RepositoryReference $repository, string $metadataFile, string $packageRoot, string $installedIdentifier, string $channel, string $deploymentPolicy ): RepositoryReleaseNativeTarget {
		unset( $packageType, $repository, $metadataFile, $packageRoot, $installedIdentifier, $channel, $deploymentPolicy );
		throw new RuntimeException( 'Native targets are outside this fixture.' ); }
	public function workflowStatus( ReleaseTrackingStatus $status ): RepositoryReleaseWorkflowStatus {
		++$this->statusReads;
		$this->throwIfNeeded();
		return $this->status ?? new RepositoryReleaseWorkflowStatus(
			$this->code,
			$status->providerRepositoryId(),
			false,
			false,
			credentialChoices: array(
				array(
					'id'    => 'credential_1',
					'label' => 'Fixture credential',
				),
			)
		); }
	public function workflowPreview( ReleaseTrackingStatus $status, string $key ): ?RepositoryReleaseWorkflowPreview {
		unset( $status );
		$this->throwIfNeeded();
		$this->calls[] = array(
			'operation'     => 'preview',
			'credential_id' => null,
			'key'           => $key,
		);
		return $this->preview; }
	public function workflowInspect( ReleaseTrackingStatus $status, string $channel, ReleaseTrackingPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status, $preflight );
		return $this->result( 'inspect', $credentialId, array( 'channel' => $channel ) ); }
	public function workflowSetup( ReleaseTrackingStatus $status, string $key, string $confirmation, ReleaseTrackingPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status, $preflight );
		return $this->result(
			'setup',
			$credentialId,
			array(
				'key'          => $key,
				'confirmation' => $confirmation,
			)
		); }
	public function workflowOutcome( ReleaseTrackingStatus $status, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status );
		return $this->result( 'outcome', $credentialId ); }
	public function workflowInspectUpdate( ReleaseTrackingStatus $status, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status );
		return $this->result( 'update_inspect', $credentialId ); }
	public function workflowSetupUpdate( ReleaseTrackingStatus $status, string $key, string $confirmation, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status );
		return $this->result(
			'update_setup',
			$credentialId,
			array(
				'key'          => $key,
				'confirmation' => $confirmation,
			)
		); }

	/** @param array<string,string> $detail */
	private function result( string $operation, ?string $credentialId, array $detail = array() ): RepositoryReleaseWorkflowResult {
		$this->throwIfNeeded();
		$this->calls[] = array(
			'operation'     => $operation,
			'credential_id' => $credentialId,
		) + $detail;
		return $this->workflowResult ?? new RepositoryReleaseWorkflowResult( 'workflow_' . $operation . '_complete', true ); }
	private function throwIfNeeded(): void {
		if ( $this->throwOnWorkflow ) {
			throw new RuntimeException( 'Workflow provider failure.' ); } }
}
