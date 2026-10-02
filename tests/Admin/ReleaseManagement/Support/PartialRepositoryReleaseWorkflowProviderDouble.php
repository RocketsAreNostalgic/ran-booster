<?php

declare(strict_types=1);

namespace Tests\Admin\ReleaseManagement\Support;

use RAN\RepositoryProvider\ArchiveRequest;
use RAN\RepositoryProvider\PreparedArchive;
use RAN\RepositoryProvider\ProviderCode;
use RAN\RepositoryProvider\ProviderDiagnosticRequest;
use RAN\RepositoryProvider\ProviderDiagnostics;
use RAN\RepositoryProvider\ProviderMetadata;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\RepositoryProvider;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV3;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use RuntimeException;

/** Deliberately lacks the five release-consumption capabilities required by Core. */
final class PartialRepositoryReleaseWorkflowProviderDouble implements RepositoryProvider, RepositoryReleaseWorkflowManagementV3 {
	public function get_metadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'partial' ), 'Partial workflow fixture', 'https://partial.example/', 'Owner' ); }
	public function get_provider_diagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics { public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );
				return array();
		} }; }
	public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return new RepositoryDescriptor( ProviderCode::parse( 'partial' ), $request->locator, 'example', '101', false, 'main', null ); }
	public function prepare_archive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );
		throw new RuntimeException( 'Archive preparation is outside this fixture.' ); }
	public function workflow_status( RepositoryReleaseWorkflowTarget $status ): RepositoryReleaseWorkflowStatus {
		return new RepositoryReleaseWorkflowStatus( 'partial', $status->provider_repository_id(), false, false ); }
	public function workflow_preview( RepositoryReleaseWorkflowTarget $status, string $key ): ?RepositoryReleaseWorkflowPreview {
		unset( $status, $key );
		return null; }
	public function workflow_inspect( RepositoryReleaseWorkflowTarget $status, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id ): RepositoryReleaseWorkflowResult {
		unset( $status, $channel, $preflight, $credential_id );
		return new RepositoryReleaseWorkflowResult( 'workflow_partial', false ); }
	public function workflow_setup( RepositoryReleaseWorkflowTarget $status, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credential_id ): RepositoryReleaseWorkflowResult {
		unset( $status, $key, $confirmation, $preflight, $credential_id );
		return new RepositoryReleaseWorkflowResult( 'workflow_partial', false ); }
	public function workflow_outcome( RepositoryReleaseWorkflowTarget $status, ?string $credential_id ): RepositoryReleaseWorkflowResult {
		unset( $status, $credential_id );
		return new RepositoryReleaseWorkflowResult( 'workflow_partial', false ); }
}
