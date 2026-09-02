<?php

declare(strict_types=1);

namespace RAN\Admin;

use InvalidArgumentException;
use LogicException;
use RAN\Admin\Component\AdminActionNormalizer;
use RAN\Admin\Component\AdminPackageSourceChoiceNormalizer;
use RAN\Logging\BoosterLogger;
use RAN\Package;
use RAN\PackageSource;
use Throwable;

/**
 * @internal Core plugin/theme page projection and shared-view vocabulary owner.
 */
final class PackagePagePresenter {

	private function __construct(
		private readonly string $type,
		private readonly string $singularLabel,
		private readonly string $pluralLabel,
		private readonly string $identifierField,
		private readonly string $pageSlug
	) {
	}

	public static function plugin(): self {
		return new self(
			'plugin',
			_x( 'Plugin', 'Managed package type singular label', 'ran-booster' ),
			_x( 'Plugins', 'Managed package type plural label', 'ran-booster' ),
			'file',
			'ran-booster-plugins'
		);
	}

	public static function theme(): self {
		return new self(
			'theme',
			_x( 'Theme', 'Managed package type singular label', 'ran-booster' ),
			_x( 'Themes', 'Managed package type plural label', 'ran-booster' ),
			'stylesheet',
			'ran-booster-themes'
		);
	}

	public function getType(): string {
		return $this->type;
	}

	public function getSingularLabel(): string {
		return $this->singularLabel;
	}

	public function getPluralLabel(): string {
		return $this->pluralLabel;
	}

	public function getIdentifierField(): string {
		return $this->identifierField;
	}

	public function getPageSlug(): string {
		return $this->pageSlug;
	}

	public function getCreatePageSlug(): string {
		return $this->pageSlug . '-create';
	}

	public function getAdminUrl(): string {
		return is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	}

	public function getAction( string $operation ): string {
		if ( ! in_array( $operation, array( 'install', 'edit', 'update', 'unlink', 'unlink-delete', 'bulk' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported package action.' );
		}

		return $operation . '-' . $this->type;
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, mixed>
	 */
	public function index(
		array $packages,
		array $packageProviders,
		array $packageListState,
		DeploymentAdminPresenter $deployments
	): array {
		$packageProviderOptions = $this->providerFilterOptions( $packages, $packageProviders );
		if ( '' !== $packageListState['provider']
			&& ! in_array( $packageListState['provider'], array_column( $packageProviderOptions, 'code' ), true )
		) {
			$packageListState['provider'] = '';
		}
		$filteredPackages = $this->filterPackages( $packages, $packageListState, $packageProviderOptions );

		return array(
			'packages'                => $filteredPackages,
			'packageListTotal'        => count( $packages ),
			'packageListState'        => $packageListState,
			'packageProviderOptions'  => $packageProviderOptions,
			'packageView'             => $this,
			'packageProviders'        => $packageProviders,
			'packageActivity'         => $deployments->packageActivity( $filteredPackages, $this->type ),
			'packageExtensionRows'    => $this->extensionRows( $filteredPackages ),
			'packageExtensionActions' => $this->extensionActions( $filteredPackages ),
		);
	}

	/** @return array<string, mixed> */
	public function edit(
		Package $package,
		array $packageProviderSettings,
		?array $packageBranchReadiness,
		string $requestedSourceView,
		bool $openAdvanced = false
	): array {
		return array(
			'packageProviderSettings' => $packageProviderSettings,
			'packageBranchReadiness'  => $packageBranchReadiness,
			'package'                 => $package,
			'packageView'             => $this,
			'packageExtensionPanels'  => $this->extensionPanels( $package ),
			'packageSource'           => $this->sourceComposition( 'edit', $requestedSourceView, $package, $openAdvanced ),
		);
	}

	/** @return array<string, mixed> */
	public function create(
		array $packageProviderSettings,
		bool $explicitProvider,
		bool $openRepositoryPicker,
		string $requestedSourceView,
		?string $managedPackageIdentifier = null,
		bool $openAdvanced = false
	): array {
		return array(
			'packageProviderSettings'  => $packageProviderSettings,
			'packageView'              => $this,
			'explicitProvider'         => $explicitProvider,
			'openRepositoryPicker'     => $openRepositoryPicker,
			'packageSource'            => $this->sourceComposition( 'create', $requestedSourceView, null, $openAdvanced ),
			'managedPackageIdentifier' => $managedPackageIdentifier,
		);
	}

	/** @return array<string, mixed> */
	public function unavailableCreate( array $packageProviderSettings, bool $explicitProvider ): array {
		return array(
			'packageProviderSettings'  => $packageProviderSettings,
			'packageView'              => $this,
			'explicitProvider'         => $explicitProvider,
			'openRepositoryPicker'     => false,
			'packageMutationAvailable' => false,
		);
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @param list<array<string, mixed>> $packageProviders
	 * @return list<array{code: string, label: string}>
	 */
	private function providerFilterOptions( array $packages, array $packageProviders ): array {
		$providerLabels = array();
		foreach ( $packageProviders as $provider ) {
			if ( is_string( $provider['code'] ?? null ) && is_string( $provider['label'] ?? null ) ) {
				$providerLabels[ $provider['code'] ] = $provider['label'];
			}
		}

		$options = array();
		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package ) {
				continue;
			}
			$code = (string) ( $package->getProviderCode() ?? '' );
			if ( '' !== $code && ! isset( $options[ $code ] ) ) {
				$options[ $code ] = array(
					'code'  => $code,
					'label' => $providerLabels[ $code ] ?? $code,
				);
			}
		}

		uasort( $options, static fn ( array $left, array $right ): int => strnatcasecmp( $left['label'], $right['label'] ) );

		return array_values( $options );
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @param array{search: string, provider: string, source: string, policy: string} $state
	 * @param list<array{code: string, label: string}> $providerOptions
	 * @return list<Package>
	 */
	private function filterPackages( array $packages, array $state, array $providerOptions ): array {
		$providerLabels = array_column( $providerOptions, 'label', 'code' );
		$search         = strtolower( $state['search'] );

		return array_values(
			array_filter(
				$packages,
				static function ( mixed $package ) use ( $state, $providerLabels, $search ): bool {
					if ( ! $package instanceof Package ) {
						return false;
					}

					$provider = (string) ( $package->getProviderCode() ?? '' );
					if ( '' !== $state['provider'] && $provider !== $state['provider'] ) {
						return false;
					}
					if ( '' !== $state['source'] && $package->getSource()->value !== $state['source'] ) {
						return false;
					}
					if ( '' !== $state['policy'] && $package->getDeploymentPolicy()->value !== $state['policy'] ) {
						return false;
					}
					if ( '' === $search ) {
						return true;
					}

					return str_contains(
						strtolower(
							implode(
								"\n",
								array(
									$package->getDisplayName(),
									(string) $package->getIdentifier(),
									(string) $package->getRepository(),
									$provider,
									$providerLabels[ $provider ] ?? '',
									(string) $package->getBranch(),
								)
							)
						),
						$search
					);
				}
			)
		);
	}

	/**
	 * @return array{
	 *   choices: array<string, array<string, mixed>>,
	 *   advanced_sections: list<string>,
	 *   advanced_summary: string,
	 *   advanced_summary_projection: array{
	 *     heading: string,
	 *     badges: list<array{label: string}>,
	 *     status: string
	 *   },
	 *   selected: string,
	 *   current: string,
	 *   advanced_open: bool,
	 *   unavailable: bool
	 * }
	 */
	private function sourceComposition( string $mode, string $requested, ?Package $package = null, bool $openAdvanced = false ): array {
		$projection = null === $package ? null : $this->projection( $package );
		$pageUrl    = null === $projection
			? add_query_arg( 'page', $this->getCreatePageSlug(), $this->getAdminUrl() )
			: $projection->settingsUrl();
		$base       = array(
			'branch'        => array(
				'key'               => 'branch',
				'heading'           => __( 'Branch', 'ran-booster' ),
				'description'       => __( 'Deploy the saved branch manually or on a signed push.', 'ran-booster' ),
				'meta'              => __( 'Included with Booster', 'ran-booster' ),
				'url'               => add_query_arg( 'source_view', 'branch', $pageUrl ),
				'disabled'          => false,
				'hydrated'          => true,
				'client_hydratable' => false,
			),
			'release_asset' => array(
				'key'               => 'release_asset',
				'heading'           => __( 'Releases', 'ran-booster' ),
				'description'       => __( 'Install published releases through WordPress.', 'ran-booster' ),
				'meta'              => __( 'Included with Booster', 'ran-booster' ),
				'url'               => '',
				'disabled'          => true,
				'hydrated'          => false,
				'client_hydratable' => false,
			),
		);

		try {
			$choices = ( new AdminPackageSourceChoiceNormalizer() )->normalize(
				apply_filters(
					'ran_booster_admin_package_source_choices',
					$base,
					$mode,
					$this->type,
					$projection,
					$pageUrl
				)
			);
		} catch ( Throwable $failure ) {
			$choices = ( new AdminPackageSourceChoiceNormalizer() )->normalize( $base );
			$this->logFailure( 'package source choices unavailable', 'package_source_choices', $failure );
		}

		$current  = null === $package ? PackageSource::BRANCH->value : $package->getSource()->value;
		$selected = null === $package ? PackageSource::BRANCH->value : $current;
		if ( null !== $package
			&& isset( $choices[ $requested ] )
			&& ! $choices[ $requested ]['disabled']
			&& ( PackageSource::BRANCH->value === $current || $choices[ $current ]['hydrated'] ) ) {
			$selected = $requested;
		}

		return array(
			'choices'                     => $choices,
			'advanced_sections'           => $this->advancedSourceSections( $mode, $selected, $projection, $pageUrl ),
			'advanced_summary'            => $this->advancedSourceSummary( $mode, $selected, $choices, $projection, $package ),
			'advanced_summary_projection' => $this->advancedSourceSummaryProjection( $mode, $selected, $package, $projection ),
			'selected'                    => $selected,
			'current'                     => $current,
			'advanced_open'               => $openAdvanced,
			'unavailable'                 => PackageSource::BRANCH->value !== $current
				&& ( ! isset( $choices[ $current ] ) || ! $choices[ $current ]['hydrated'] ),
		);
	}

	/** @return list<string> */
	private function advancedSourceSections(
		string $mode,
		string $selected,
		?AdminPackageProjection $projection,
		string $pageUrl
	): array {
		$bufferLevel = ob_get_level();
		ob_start();
		try {
			do_action(
				'ran_booster_admin_package_advanced_source_sections',
				$mode,
				$this->type,
				$selected,
				$projection,
				$pageUrl
			);
			$content = (string) ob_get_clean();

			return '' === trim( $content ) ? array() : array( $content );
		} catch ( Throwable $failure ) {
			$this->cleanBuffer( $bufferLevel );
			$this->logFailure( 'advanced package source section unavailable', 'advanced_package_source_section', $failure );
		}

		return array();
	}

	/** @param array<string, array<string, mixed>> $choices */
	private function advancedSourceSummary(
		string $mode,
		string $selected,
		array $choices,
		?AdminPackageProjection $projection,
		?Package $package
	): string {
		$sourceLabel = is_string( $choices[ $selected ]['heading'] ?? null )
			? $choices[ $selected ]['heading']
			: __( 'Update source', 'ran-booster' );
		$summary     = PackageSource::BRANCH->value === $selected
			? sprintf(
				/* translators: 1: source label, 2: branch. */
				__( '%1$s · %2$s', 'ran-booster' ),
				$sourceLabel,
				null !== $package && '' !== (string) $package->getBranch()
					? (string) $package->getBranch()
					: __( 'provider default', 'ran-booster' )
			)
			: $sourceLabel;

		try {
			$filtered = apply_filters(
				'ran_booster_admin_package_advanced_source_summary',
				$summary,
				$mode,
				$this->type,
				$selected,
				$projection
			);
			if ( is_string( $filtered ) ) {
				$filtered = trim( wp_strip_all_tags( $filtered, true ) );
				if ( '' !== $filtered && strlen( $filtered ) <= 180 ) {
					return $filtered;
				}
			}
		} catch ( Throwable $failure ) {
			$this->logFailure( 'advanced package source summary unavailable', 'advanced_package_source_summary', $failure );
		}

		return $summary;
	}

	/**
	 * @return array{heading:string,badges:list<array{label:string}>,status:string}
	 */
	private function advancedSourceSummaryProjection(
		string $mode,
		string $selected,
		?Package $package,
		?AdminPackageProjection $projection
	): array {
		$source  = 'edit' === $mode && null !== $package
			? $package->getSource()->value
			: $selected;
		$heading = PackageSource::BRANCH->value === $source
			? __( 'Branch', 'ran-booster' )
			: __( 'Releases', 'ran-booster' );
		$badges  = array();
		$status  = '';
		if ( 'edit' === $mode && null !== $package ) {
			if ( PackageSource::BRANCH->value === $source && null !== $projection ) {
				$subdirectory = trim( $projection->subdirectory() );
				if ( '' !== $subdirectory ) {
					$badges[] = array(
						'label' => $subdirectory,
					);
				}
				$status = __( 'Active', 'ran-booster' );
			} elseif ( PackageSource::RELEASE_ASSET->value === $source ) {
				$badges[] = array(
					'label' => __( 'Stable', 'ran-booster' ),
				);
				$status   = __( 'Active', 'ran-booster' );
			}
		} elseif ( 'create' === $mode && PackageSource::RELEASE_ASSET->value === $selected ) {
			$badges[] = array(
				'label' => __( 'Stable', 'ran-booster' ),
			);
		}

		try {
			$baseline = apply_filters(
				'ran_booster_admin_package_advanced_source_summary_projection',
				array(
					'heading' => $heading,
					'badges'  => $badges,
					'status'  => $status,
				),
				$mode,
				$this->type,
				$selected,
				$projection
			);
		} catch ( Throwable $failure ) {
			$this->logFailure( 'advanced package source summary projection unavailable', 'advanced_package_source_summary_projection', $failure );

			return array(
				'heading' => $heading,
				'badges'  => $badges,
				'status'  => $status,
			);
		}

		if ( is_array( $baseline ) && isset( $baseline['heading'] ) && is_string( $baseline['heading'] ) ) {
			$validatedHeading = trim( wp_strip_all_tags( $baseline['heading'], true ) );
			if ( '' === $validatedHeading || strlen( $validatedHeading ) > 80 ) {
				return array(
					'heading' => $heading,
					'badges'  => $badges,
					'status'  => $status,
				);
			}
			if ( is_array( $baseline['badges'] ) ) {
				$validated = array();
				foreach ( $baseline['badges'] as $badge ) {
					$label = is_array( $badge ) && is_string( $badge['label'] ?? null )
						? trim( wp_strip_all_tags( $badge['label'], true ) )
						: '';
					if ( '' !== $label && strlen( $label ) <= 255 && count( $validated ) < 3 ) {
						$validated[] = array(
							'label' => $label,
						);
					}
				}
				$badges = $validated;
			} else {
				$badges = array();
			}
			$validatedStatus = is_string( $baseline['status'] ?? null )
				? trim( wp_strip_all_tags( $baseline['status'], true ) )
				: '';
			return array(
				'heading' => $validatedHeading,
				'badges'  => $badges,
				'status'  => strlen( $validatedStatus ) <= 40 ? $validatedStatus : '',
			);
		}

		return array(
			'heading' => $heading,
			'badges'  => $badges,
			'status'  => $status,
		);
	}

	/** @return list<string> */
	private function extensionPanels( Package $package ): array {
		$projection  = $this->projection( $package );
		$bufferLevel = ob_get_level();
		ob_start();
		try {
			do_action( 'ran_booster_admin_package_settings_sections', $projection, $projection->settingsUrl() );
			$content = (string) ob_get_clean();

			return '' === trim( $content ) ? array() : array( $content );
		} catch ( Throwable $failure ) {
			$this->cleanBuffer( $bufferLevel );
			$this->logFailure( 'package settings action unavailable', 'package_settings_action', $failure );
		}

		return array();
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, array{badges: list<array{label: string, tone: string}>, status: string}>
	 */
	private function extensionRows( array $packages ): array {
		if ( array() === $packages ) {
			return array();
		}

		$projections = array();
		$baseRows    = array();
		foreach ( $packages as $package ) {
			if ( $package instanceof Package ) {
				$projection                               = $this->projection( $package );
				$projections[ $projection->identifier() ] = $projection;
				$baseRows[ $projection->identifier() ]    = array(
					'badges' => array(),
					'status' => '',
				);
			}
		}

		try {
			return $this->normalizeExtensionRows(
				apply_filters(
					'ran_booster_admin_package_management_rows',
					$baseRows,
					$this->type,
					$projections
				),
				$projections,
				$baseRows
			);
		} catch ( Throwable $failure ) {
			$this->logFailure( 'package management filter unavailable', 'package_management_filter', $failure );
		}

		return array();
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function extensionActions( array $packages ): array {
		$actions    = array();
		$normalizer = new AdminActionNormalizer();

		foreach ( $packages as $package ) {
			if ( ! $package instanceof Package ) {
				continue;
			}
			$projection = $this->projection( $package );
			try {
				$actions[ $projection->identifier() ] = $normalizer->normalize(
					apply_filters(
						'ran_booster_admin_package_management_actions',
						array(),
						$this->type,
						$projection
					)
				);
			} catch ( Throwable $failure ) {
				$this->logFailure( 'package management actions unavailable', 'package_management_actions', $failure );
			}
		}

		return $actions;
	}

	/**
	 * @param mixed $presented
	 * @param array<string, AdminPackageProjection> $projections
	 * @param array<string, array{badges: array<mixed>, status: string}> $baseRows
	 * @return array<string, array{badges: list<array{label: string, tone: string}>, status: string}>
	 */
	private function normalizeExtensionRows( mixed $presented, array $projections, array $baseRows ): array {
		if ( ! is_array( $presented ) ) {
			throw new LogicException( 'Package management rows must be a keyed array.' );
		}
		if ( array_diff_key( $presented, $baseRows ) !== array()
			|| array_diff_key( $baseRows, $presented ) !== array() ) {
			throw new LogicException( 'Package management filters must preserve every projected package row.' );
		}

		$normalized = array();
		foreach ( $presented as $identifier => $row ) {
			if ( ! is_string( $identifier ) || ! isset( $projections[ $identifier ] ) || ! is_array( $row ) ) {
				throw new LogicException( 'Package management rows may address only projected packages.' );
			}
			if ( array_diff( array_keys( $row ), array( 'badges', 'status' ) ) !== array() ) {
				throw new LogicException( 'Package management rows may contain only badges and status.' );
			}

			$badges          = array();
			$presentedBadges = $row['badges'] ?? array();
			if ( ! is_array( $presentedBadges ) || count( $presentedBadges ) > 20 ) {
				throw new LogicException( 'Package management badges must be a bounded list.' );
			}
			foreach ( $presentedBadges as $badge ) {
				if ( ! is_array( $badge )
					|| ! is_string( $badge['label'] ?? null )
					|| '' === trim( $badge['label'] )
					|| strlen( $badge['label'] ) > 96
					|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $badge['label'] )
					|| ! in_array( $badge['tone'] ?? null, array( 'neutral', 'ok', 'pending', 'warning', 'error' ), true ) ) {
					throw new LogicException( 'Package management badges must be bounded display values.' );
				}
				$badges[] = array(
					'label' => $badge['label'],
					'tone'  => $badge['tone'],
				);
			}

			if ( isset( $row['status'] ) && ! is_string( $row['status'] ) ) {
				throw new LogicException( 'Package management status must be a string.' );
			}
			$status = trim( $row['status'] ?? '' );
			if ( strlen( $status ) > 255 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $status ) ) {
				throw new LogicException( 'Package management status must be bounded.' );
			}
			$normalized[ $identifier ] = array(
				'badges' => $badges,
				'status' => $status,
			);
		}

		return $normalized;
	}

	private function projection( Package $package ): AdminPackageProjection {
		$subdirectory = is_string( $package->getSubdirectory() )
			? trim( $package->getSubdirectory() )
			: '';
		return new AdminPackageProjection(
			$this->type,
			(string) $package->getIdentifier(),
			$package->getDisplayName(),
			(string) ( $package->getProviderCode() ?? '' ),
			$package->getSource()->value,
			$package->getSourceRevision(),
			$package->getDeploymentPolicy()->value,
			add_query_arg(
				array(
					'page'    => $this->pageSlug,
					'package' => (string) $package->getIdentifier(),
				),
				$this->getAdminUrl()
			),
			$subdirectory
		);
	}

	private function cleanBuffer( int $bufferLevel ): void {
		while ( ob_get_level() > $bufferLevel ) {
			ob_end_clean();
		}
	}

	private function logFailure( string $message, string $step, Throwable $failure ): void {
		BoosterLogger::logException(
			$message,
			$failure,
			array(
				'source'    => 'admin',
				'step'      => $step,
				'operation' => $this->type,
			)
		);
	}
}
