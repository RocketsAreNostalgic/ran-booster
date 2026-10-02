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
		?RepositorySourceGuard $source_guard = null
	) {
		$source_guard  ??= new RepositorySourceGuard();
		$this->display   = new ReleaseWorkflowDisplay();
		$this->requests  = new ReleaseWorkflowRequestController( $releases, $plugins, $themes, $providers, $source_guard );
		$this->presenter = new ReleaseWorkflowPresenter( $releases, $plugins, $themes, $providers, $this->requests, $source_guard );
	}

	public function register(): void {
		add_filter( 'ran_booster_admin_package_source_choices', array( $this, 'keep_release_settings_discoverable' ), 20, 5 );
		add_action( 'ran_booster_admin_package_release_readiness_actions', array( $this, 'render_package_release_automation_link' ), 20, 2 );
		add_action( 'ran_booster_admin_repository_release_sections', array( $this, 'render_repository_release_sections' ), 20, 2 );
		add_action( 'admin_post_ran_booster_release_workflow', array( $this, 'handle_workflow' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $choices
	 * @return array<string, array<string, mixed>>
	 */
	public function keep_release_settings_discoverable( array $choices, string $mode, string $type, ?object $package, string $page_url ): array {
		return $this->presenter->keep_release_settings_discoverable( $choices, $mode, $type, $package, $page_url );
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, array<string, mixed>> $repositoryProjections
	 * @return array<string, array<string, mixed>>
	 */
	public function enrich_repository_rows( array $rows, string $provider_code, array $repository_projections, string $return_url ): array {
		return $this->presenter->enrich_repository_rows( $rows, $provider_code, $repository_projections, $return_url );
	}

	public function render_package_release_automation_link( object $package, ReleaseTrackingStatus $status ): void {
		$result = $this->requests->requested_result();
		$result = is_array( $result ) && $this->requests->result_matches_current_screen( $result ) ? $result : null;
		echo $this->display->package_automation( $this->presenter->package_projection( $package, $status, $result ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed Core display renders bounded presenter data.
	}

	/** @param array<string,mixed> $row */
	public function render_repository_release_sections( array $row, string $return_url ): void {
		$projection = $this->presenter->repository_section_projection( $row, $return_url, $this->requests->requested_preview_key(), $this->requests->requested_result() );
		if ( null !== $projection ) {
			echo $this->display->repository_section( $projection ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed Core display renders bounded presenter data.
		}
	}

	public function handle_workflow(): never {
		$this->requests->handle_workflow();
	}
}
