<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\Support;

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
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use RuntimeException;

final class RepositoryReleaseWorkflowProviderDouble implements RepositoryProvider, RepositoryReleaseWorkflowManagementV3, RepositoryReleaseMetadata, RepositoryReleaseCandidateListing, RepositoryReleaseInspector, RepositoryReleaseAcquirer, RepositoryReleaseNativeTargets {
	/** @var list<array{operation:string,credential_id:?string,channel?:string,key?:string,confirmation?:string}> */
	public array $calls           = array();
	public int $statusReads       = 0;
	public bool $throwOnWorkflow  = false;
	public bool $throwOnOperation = false;

	public function __construct( private readonly string $code = 'fixture', private readonly string $repositoryId = '101', private readonly ?RepositoryReleaseWorkflowPreview $preview = null, private readonly ?RepositoryReleaseWorkflowStatus $status = null, private readonly ?RepositoryReleaseWorkflowResult $workflowResult = null, private readonly bool $adminSurface = true ) {}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), 'Workflow fixture', 'https://fixture.example/', 'Owner', $this->adminSurface ? new ProviderAdminMetadata( array(), array() ) : null ); }
	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics { public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );
				return array();
		} }; }
	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return new RepositoryDescriptor( ProviderCode::parse( $this->code ), $request->locator, 'example', $this->repositoryId, false, 'main', null ); }
	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );
		throw new RuntimeException( 'Archive preparation is outside this fixture.' ); }
	public function expected_update_uri( RepositoryReference $repository ): string {
		return 'https://fixture.example/' . $repository->locator; }
	public function release_details_url( RepositoryReference $repository, string $tag ): string {
		return 'https://fixture.example/' . $repository->locator . '/releases/tag/' . rawurlencode( $tag ); }
	public function list_release_candidates( string $packageType, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList {
		unset( $packageType, $repository, $channel );
		throw new RuntimeException( 'Candidate listing is outside this fixture.' ); }
	public function inspect_release( string $packageType, RepositoryReference $repository, string $providerReleaseId, string $tag, string $channel ): RepositoryReleaseInspection {
		unset( $packageType, $repository, $providerReleaseId, $tag, $channel );
		throw new RuntimeException( 'Release inspection is outside this fixture.' ); }
	public function acquire_release( string $packageType, RepositoryReference $repository, string $providerReleaseId, string $tag, string $expectedFingerprint, string $channel ): RepositoryReleaseArtifact {
		unset( $packageType, $repository, $providerReleaseId, $tag, $expectedFingerprint, $channel );
		throw new RuntimeException( 'Release acquisition is outside this fixture.' ); }
	public function has_registered_native_target( string $packageType, string $installedIdentifier ): bool {
		unset( $packageType, $installedIdentifier );
		return false; }
	public function create_native_target( string $packageType, RepositoryReference $repository, string $metadataFile, string $packageRoot, string $installedIdentifier, string $channel, string $deploymentPolicy ): RepositoryReleaseNativeTarget {
		unset( $packageType, $repository, $metadataFile, $packageRoot, $installedIdentifier, $channel, $deploymentPolicy );
		throw new RuntimeException( 'Native targets are outside this fixture.' ); }
	public function workflow_status( RepositoryReleaseWorkflowTarget $status ): RepositoryReleaseWorkflowStatus {
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
	public function workflow_preview( RepositoryReleaseWorkflowTarget $status, string $key ): ?RepositoryReleaseWorkflowPreview {
		unset( $status );
		$this->throwIfNeeded();
		$this->calls[] = array(
			'operation'     => 'preview',
			'credential_id' => null,
			'key'           => $key,
		);
		return $this->preview; }
	public function workflow_inspect( RepositoryReleaseWorkflowTarget $status, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status, $preflight );
		return $this->result( 'inspect', $credentialId, array( 'channel' => $channel ) ); }
	public function workflow_setup( RepositoryReleaseWorkflowTarget $status, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status, $preflight );
		return $this->result(
			'setup',
			$credentialId,
			array(
				'key'          => $key,
				'confirmation' => $confirmation,
			)
		); }
	public function workflow_outcome( RepositoryReleaseWorkflowTarget $status, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status );
		return $this->result( 'outcome', $credentialId ); }

	/** @param array<string,string> $detail */
	private function result( string $operation, ?string $credentialId, array $detail = array() ): RepositoryReleaseWorkflowResult {
		$this->throwIfNeeded();
		if ( $this->throwOnOperation ) {
			throw new RuntimeException( 'Workflow provider operation failure.' ); }
		$this->calls[] = array(
			'operation'     => $operation,
			'credential_id' => $credentialId,
		) + $detail;
		return $this->workflowResult ?? new RepositoryReleaseWorkflowResult( 'workflow_' . $operation . '_complete', true ); }
	private function throwIfNeeded(): void {
		if ( $this->throwOnWorkflow ) {
			throw new RuntimeException( 'Workflow provider failure.' ); } }
}
