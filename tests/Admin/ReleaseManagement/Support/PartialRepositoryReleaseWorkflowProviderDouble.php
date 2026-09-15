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
use RAN\RepositoryProvider\RepositoryReleaseWorkflowManagementV2;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreflight;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowPreview;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowResult;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowStatus;
use RAN\RepositoryProvider\RepositoryReleaseWorkflowTarget;
use RuntimeException;

/** Deliberately lacks the five release-consumption capabilities required by Core. */
final class PartialRepositoryReleaseWorkflowProviderDouble implements RepositoryProvider, RepositoryReleaseWorkflowManagementV2 {
	public function getMetadata(): ProviderMetadata {
		return new ProviderMetadata( ProviderCode::parse( 'partial' ), 'Partial workflow fixture', 'https://partial.example/', 'Owner' ); }
	public function getProviderDiagnostics(): ProviderDiagnostics {
		return new class() implements ProviderDiagnostics { public function diagnose( ProviderDiagnosticRequest $request ): array {
				unset( $request );
				return array();
		} }; }
	public function resolveRepository( RepositoryLookupRequest $request ): RepositoryDescriptor {
		return new RepositoryDescriptor( ProviderCode::parse( 'partial' ), $request->locator, 'example', '101', false, 'main', null ); }
	public function prepareArchive( ArchiveRequest $request ): PreparedArchive {
		unset( $request );
		throw new RuntimeException( 'Archive preparation is outside this fixture.' ); }
	public function workflowStatus( RepositoryReleaseWorkflowTarget $status ): RepositoryReleaseWorkflowStatus {
		return new RepositoryReleaseWorkflowStatus( 'partial', $status->providerRepositoryId(), false, false ); }
	public function workflowPreview( RepositoryReleaseWorkflowTarget $status, string $key ): ?RepositoryReleaseWorkflowPreview {
		unset( $status, $key );
		return null; }
	public function workflowInspect( RepositoryReleaseWorkflowTarget $status, string $channel, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status, $channel, $preflight, $credentialId );
		return new RepositoryReleaseWorkflowResult( 'workflow_partial', false ); }
	public function workflowSetup( RepositoryReleaseWorkflowTarget $status, string $key, string $confirmation, RepositoryReleaseWorkflowPreflight $preflight, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status, $key, $confirmation, $preflight, $credentialId );
		return new RepositoryReleaseWorkflowResult( 'workflow_partial', false ); }
	public function workflowOutcome( RepositoryReleaseWorkflowTarget $status, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status, $credentialId );
		return new RepositoryReleaseWorkflowResult( 'workflow_partial', false ); }
	public function workflowInspectUpdate( RepositoryReleaseWorkflowTarget $status, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status, $credentialId );
		return new RepositoryReleaseWorkflowResult( 'workflow_partial', false ); }
	public function workflowSetupUpdate( RepositoryReleaseWorkflowTarget $status, string $key, string $confirmation, ?string $credentialId ): RepositoryReleaseWorkflowResult {
		unset( $status, $key, $confirmation, $credentialId );
		return new RepositoryReleaseWorkflowResult( 'workflow_partial', false ); }
}
