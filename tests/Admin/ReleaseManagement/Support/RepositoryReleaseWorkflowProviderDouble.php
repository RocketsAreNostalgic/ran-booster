<?php

declare(strict_types=1);

namespace RAN\Tests\Admin\ReleaseManagement\Support;

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
	public array $calls             = array();
	public int $status_reads        = 0;
	public bool $throw_on_workflow  = false;
	public bool $throw_on_operation = false;

	public function __construct( private readonly string $code = 'fixture', private readonly string $repository_id = '101', private readonly ?RepositoryReleaseWorkflowPreview $preview = null, private readonly ?RepositoryReleaseWorkflowStatus $status = null, private readonly ?RepositoryReleaseWorkflowResult $workflow_result = null, private readonly bool $admin_surface = true ) {}

	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( $this->code ), 'Workflow fixture', 'https://fixture.example/', 'Owner', $this->admin_surface ? new ProviderAdminMetadata( array(), array() ) : null ); }
	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics { public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );
				return array();
		} }; }
	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return new RepositoryDescriptor( ProviderCode::parse( $this->code ), $request->locator, 'example', $this->repository_id, false, 'main', null ); }
	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );
		throw new RuntimeException( 'Archive preparation is outside this fixture.' ); }
	public function expected_update_uri( RepositoryReference $repository ): string {
		return 'https://fixture.example/' . $repository->locator; }
	public function release_details_url( RepositoryReference $repository, string $tag ): string {
		return 'https://fixture.example/' . $repository->locator . '/releases/tag/' . rawurlencode( $tag ); }
	public function list_release_candidates( string $package_type, RepositoryReference $repository, string $channel ): RepositoryReleaseCandidateList {
		unset( $package_type, $repository, $channel );
		throw new RuntimeException( 'Candidate listing is outside this fixture.' ); }
	public function inspect_release( string $package_type, RepositoryReference $repository, string $provider_release_id, string $tag, string $channel ): RepositoryReleaseInspection {
		unset( $package_type, $repository, $provider_release_id, $tag, $channel );
		throw new RuntimeException( 'Release inspection is outside this fixture.' ); }
	public function acquire_release( string $package_type, RepositoryReference $repository, string $provider_release_id, string $tag, string $expected_fingerprint, string $channel ): RepositoryReleaseArtifact {
		unset( $package_type, $repository, $provider_release_id, $tag, $expected_fingerprint, $channel );
		throw new RuntimeException( 'Release acquisition is outside this fixture.' ); }
	public function has_registered_native_target( string $package_type, string $installed_identifier ): bool {
		unset( $package_type, $installed_identifier );
		return false; }
	public function create_native_target( string $package_type, RepositoryReference $repository, string $metadata_file, string $package_root, string $installed_identifier, string $channel, string $deployment_policy ): RepositoryReleaseNativeTarget {
		unset( $package_type, $repository, $metadata_file, $package_root, $installed_identifier, $channel, $deployment_policy );
		throw new RuntimeException( 'Native targets are outside this fixture.' ); }
	public function workflow_status( RepositoryReleaseWorkflowTarget $status ): RepositoryReleaseWorkflowStatus {
		++$this->status_reads;
		$this->throw_if_needed();
		return $this->status ?? new RepositoryReleaseWorkflowStatus(
			$this->code,
			$status->provider_repository_id(),
			false,
			false,
			credential_choices: array(
				array(
					'id'    => 'credential_1',
					'label' => 'Fixture credential',
				),
			)
		); }
	public function workflow_preview( RepositoryReleaseWorkflowTarget $status, string $key ): ?RepositoryReleaseWorkflowPreview {
		unset( $status );
		$this->throw_if_needed();
		$this->calls[] = array(
			'operation'     => 'preview',
			'credential_id' => null,
			'key'           => $key,
		);
		return $this->preview; }
	public function workflow_inspect( RepositoryReleaseWorkflowTarget $status, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id ): RepositoryReleaseWorkflowResult {
		unset( $status, $preflight );
		return $this->result( 'inspect', $credential_id, array( 'channel' => $channel ) ); }
	public function workflow_setup( RepositoryReleaseWorkflowTarget $status, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id ): RepositoryReleaseWorkflowResult {
		unset( $status, $preflight );
		return $this->result(
			'setup',
			$credential_id,
			array(
				'key'          => $key,
				'confirmation' => $confirmation,
			)
		); }
	public function workflow_outcome( RepositoryReleaseWorkflowTarget $status, ?string $credential_id ): RepositoryReleaseWorkflowResult {
		unset( $status );
		return $this->result( 'outcome', $credential_id ); }

	/** @param array<string,string> $detail */
	private function result( string $operation, ?string $credential_id, array $detail = array() ): RepositoryReleaseWorkflowResult {
		$this->throw_if_needed();
		if ( $this->throw_on_operation ) {
			throw new RuntimeException( 'Workflow provider operation failure.' ); }
		$this->calls[] = array(
			'operation'     => $operation,
			'credential_id' => $credential_id,
		) + $detail;
		return $this->workflow_result ?? new RepositoryReleaseWorkflowResult( 'workflow_' . $operation . '_complete', true ); }
	private function throw_if_needed(): void {
		if ( $this->throw_on_workflow ) {
			throw new RuntimeException( 'Workflow provider failure.' ); } }
}
