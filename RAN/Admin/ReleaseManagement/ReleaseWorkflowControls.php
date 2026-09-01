<?php

declare(strict_types=1);

namespace RAN\Admin\ReleaseManagement;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\Storage\PluginRepository;
use RAN\Storage\RepositorySourceGuard;
use RAN\Storage\ThemeRepository;

/** @internal WordPress adapter for provider-neutral release workflows. */
final class ReleaseWorkflowControls {
	private readonly ReleaseWorkflowDisplay $display;
	private readonly ReleaseWorkflowRequestController $requests;
	private readonly ReleaseWorkflowPresenter $presenter;

	public function __construct(
		ReleaseTrackingFacade $releases,
		PluginRepository $plugins,
		ThemeRepository $themes,
		ProviderRegistry $providers,
		?RepositorySourceGuard $sourceGuard = null
	) {
		$sourceGuard   ??= new RepositorySourceGuard();
		$this->display   = new ReleaseWorkflowDisplay();
		$this->requests  = new ReleaseWorkflowRequestController( $releases, $plugins, $themes, $providers, $sourceGuard );
		$this->presenter = new ReleaseWorkflowPresenter( $releases, $plugins, $themes, $providers, $this->requests, $sourceGuard );
	}

	public function register(): void {
		add_filter( 'ran_booster_admin_package_source_choices', array( $this, 'keepReleaseSettingsDiscoverable' ), 20, 5 );
		add_action( 'ran_booster_admin_package_release_readiness_actions', array( $this, 'renderPackageReleaseAutomationLink' ), 20, 2 );
		add_action( 'ran_booster_admin_repository_release_sections', array( $this, 'renderRepositoryReleaseSections' ), 20, 2 );
		add_action( 'admin_post_ran_booster_release_workflow', array( $this, 'handleWorkflow' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $choices
	 * @return array<string, array<string, mixed>>
	 */
	public function keepReleaseSettingsDiscoverable( array $choices, string $mode, string $type, ?object $package, string $pageUrl ): array {
		return $this->presenter->keepReleaseSettingsDiscoverable( $choices, $mode, $type, $package, $pageUrl );
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $repositoryProjections
	 * @return array<string, array<string, mixed>>
	 */
	public function enrichRepositoryRows( array $rows, string $providerCode, array $repositoryProjections, string $returnUrl ): array {
		return $this->presenter->enrichRepositoryRows( $rows, $providerCode, $repositoryProjections, $returnUrl );
	}

	public function renderPackageReleaseAutomationLink( object $package, ReleaseTrackingStatus $status ): void {
		$result = $this->requests->requestedResult();
		$result = is_array( $result ) && $this->requests->resultMatchesCurrentScreen( $result ) ? $result : null;
		echo $this->display->packageAutomation( $this->presenter->packageProjection( $package, $status, $result ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed Core display renders bounded presenter data.
	}

	/** @param array<string,mixed> $row */
	public function renderRepositoryReleaseSections( array $row, string $returnUrl ): void {
		$projection = $this->presenter->repositorySectionProjection( $row, $returnUrl, $this->requests->requestedPreviewKey(), $this->requests->requestedResult() );
		if ( null !== $projection ) {
			echo $this->display->repositorySection( $projection ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed Core display renders bounded presenter data.
		}
	}

	public function handleWorkflow(): never {
		$this->requests->handleWorkflow();
	}
}
