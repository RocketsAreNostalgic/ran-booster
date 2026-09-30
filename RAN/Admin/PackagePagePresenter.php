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
		private readonly string $identifierField, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
		private readonly string $pageSlug // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
	) {
	}

	public static function plugin(): self {
		return new self(
			'plugin',
			'file',
			'ran-booster-plugins'
		);
	}

	public static function theme(): self {
		return new self(
			'theme',
			'stylesheet',
			'ran-booster-themes'
		);
	}

	public function getType(): string { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public method preserves the existing caller contract.
		return $this->type;
	}

	public function getSingularLabel(): string { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public method preserves the existing caller contract.
		return 'plugin' === $this->type
			? _x( 'Plugin', 'Managed package type singular label', 'ran-booster' )
			: _x( 'Theme', 'Managed package type singular label', 'ran-booster' );
	}

	public function getPluralLabel(): string { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public method preserves the existing caller contract.
		return 'plugin' === $this->type
			? _x( 'Plugins', 'Managed package type plural label', 'ran-booster' )
			: _x( 'Themes', 'Managed package type plural label', 'ran-booster' );
	}

	public function getIdentifierField(): string { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public method preserves the existing caller contract.
		return $this->identifierField; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
	}

	public function getPageSlug(): string { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public method preserves the existing caller contract.
		return $this->pageSlug; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
	}

	public function getCreatePageSlug(): string { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public method preserves the existing caller contract.
		return $this->pageSlug . '-create'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
	}

	public function getAdminUrl(): string { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public method preserves the existing caller contract.
		return is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	}

	public function getAction( string $operation ): string { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- Public method preserves the existing caller contract.
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
		array $packageProviders, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		array $packageListState, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		DeploymentAdminPresenter $deployments
	): array {
		$package_provider_options = $this->provider_filter_options( $packages, $packageProviders ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		if ( '' !== $packageListState['provider'] // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			&& ! in_array( $packageListState['provider'], array_column( $package_provider_options, 'code' ), true ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		) {
			$packageListState['provider'] = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		}
		$filtered_packages = $this->filter_packages( $packages, $packageListState, $package_provider_options ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.

		return array(
			'packages'                => $filtered_packages,
			'packageListTotal'        => count( $packages ),
			'packageListState'        => $packageListState, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'packageProviderOptions'  => $package_provider_options,
			'packageView'             => $this,
			'packageProviders'        => $packageProviders, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'packageActivity'         => $deployments->packageActivity( $filtered_packages, $this->type ),
			'packageExtensionRows'    => $this->extension_rows( $filtered_packages ),
			'packageExtensionActions' => $this->extension_actions( $filtered_packages ),
		);
	}

	/** @return array<string, mixed> */
	public function edit(
		Package $package,
		array $packageProviderSettings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		?array $packageBranchReadiness, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		string $requestedSourceView, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		bool $openAdvanced = false // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
	): array {
		return array(
			'packageProviderSettings' => $packageProviderSettings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'packageBranchReadiness'  => $packageBranchReadiness, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'package'                 => $package,
			'packageView'             => $this,
			'packageExtensionPanels'  => $this->extension_panels( $package ),
			'packageSource'           => $this->source_composition( 'edit', $requestedSourceView, $package, $openAdvanced ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		);
	}

	/** @return array<string, mixed> */
	public function create(
		array $packageProviderSettings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		bool $explicitProvider, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		bool $openRepositoryPicker, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		string $requestedSourceView, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		?string $managedPackageIdentifier = null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		bool $openAdvanced = false // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
	): array {
		return array(
			'packageProviderSettings'  => $packageProviderSettings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'packageView'              => $this,
			'explicitProvider'         => $explicitProvider, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'openRepositoryPicker'     => $openRepositoryPicker, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'packageSource'            => $this->source_composition( 'create', $requestedSourceView, null, $openAdvanced ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'managedPackageIdentifier' => $managedPackageIdentifier, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		);
	}

	/** @return array<string, mixed> */
	public function unavailableCreate( array $packageProviderSettings, bool $explicitProvider ): array { // phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase,WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public method and parameter names preserve caller compatibility.
		return array(
			'packageProviderSettings'  => $packageProviderSettings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'packageView'              => $this,
			'explicitProvider'         => $explicitProvider, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'openRepositoryPicker'     => false,
			'packageMutationAvailable' => false,
		);
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @param list<array<string, mixed>> $package_providers
	 * @return list<array{code: string, label: string}>
	 */
	private function provider_filter_options( array $packages, array $package_providers ): array {
		$provider_labels = array();
		foreach ( $package_providers as $provider ) {
			if ( is_string( $provider['code'] ?? null ) && is_string( $provider['label'] ?? null ) ) {
				$provider_labels[ $provider['code'] ] = $provider['label'];
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
					'label' => $provider_labels[ $code ] ?? $code,
				);
			}
		}

		uasort( $options, static fn ( array $left, array $right ): int => strnatcasecmp( $left['label'], $right['label'] ) );

		return array_values( $options );
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @param array{search: string, provider: string, source: string, policy: string} $state
	 * @param list<array{code: string, label: string}> $provider_options
	 * @return list<Package>
	 */
	private function filter_packages( array $packages, array $state, array $provider_options ): array {
		$provider_labels = array_column( $provider_options, 'label', 'code' );
		$search          = strtolower( $state['search'] );

		return array_values(
			array_filter(
				$packages,
				static function ( mixed $package ) use ( $state, $provider_labels, $search ): bool {
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
									$provider_labels[ $provider ] ?? '',
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
	private function source_composition( string $mode, string $requested, ?Package $package = null, bool $open_advanced = false ): array {
		$projection = null === $package ? null : $this->projection( $package );
		$page_url   = null === $projection
			? add_query_arg( 'page', $this->getCreatePageSlug(), $this->getAdminUrl() )
			: $projection->settingsUrl();
		$base       = array(
			'branch'        => array(
				'key'               => 'branch',
				'heading'           => __( 'Branch', 'ran-booster' ),
				'description'       => __( 'Deploy the saved branch manually or on a signed push.', 'ran-booster' ),
				'meta'              => __( 'Included with Booster', 'ran-booster' ),
				'url'               => add_query_arg( 'source_view', 'branch', $page_url ),
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
					$page_url
				)
			);
		} catch ( Throwable $failure ) {
			$choices = ( new AdminPackageSourceChoiceNormalizer() )->normalize( $base );
			$this->log_failure( 'package source choices unavailable', 'package_source_choices', $failure );
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
			'advanced_sections'           => $this->advanced_source_sections( $mode, $selected, $projection, $page_url ),
			'advanced_summary'            => $this->advanced_source_summary( $mode, $selected, $choices, $projection, $package ),
			'advanced_summary_projection' => $this->advanced_source_summary_projection( $mode, $selected, $package, $projection ),
			'selected'                    => $selected,
			'current'                     => $current,
			'advanced_open'               => $open_advanced,
			'unavailable'                 => PackageSource::BRANCH->value !== $current
				&& ( ! isset( $choices[ $current ] ) || ! $choices[ $current ]['hydrated'] ),
		);
	}

	/** @return list<string> */
	private function advanced_source_sections(
		string $mode,
		string $selected,
		?AdminPackageProjection $projection,
		string $page_url
	): array {
		$buffer_level = ob_get_level();
		ob_start();
		try {
			do_action(
				'ran_booster_admin_package_advanced_source_sections',
				$mode,
				$this->type,
				$selected,
				$projection,
				$page_url
			);
			$content = (string) ob_get_clean();

			return '' === trim( $content ) ? array() : array( $content );
		} catch ( Throwable $failure ) {
			$this->clean_buffer( $buffer_level );
			$this->log_failure( 'advanced package source section unavailable', 'advanced_package_source_section', $failure );
		}

		return array();
	}

	/** @param array<string, array<string, mixed>> $choices */
	private function advanced_source_summary(
		string $mode,
		string $selected,
		array $choices,
		?AdminPackageProjection $projection,
		?Package $package
	): string {
		$source_label = is_string( $choices[ $selected ]['heading'] ?? null )
			? $choices[ $selected ]['heading']
			: __( 'Update source', 'ran-booster' );
		$summary      = PackageSource::BRANCH->value === $selected
			? sprintf(
				/* translators: 1: source label, 2: branch. */
				__( '%1$s · %2$s', 'ran-booster' ),
				$source_label,
				null !== $package && '' !== (string) $package->getBranch()
					? (string) $package->getBranch()
					: __( 'provider default', 'ran-booster' )
			)
			: $source_label;

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
			$this->log_failure( 'advanced package source summary unavailable', 'advanced_package_source_summary', $failure );
		}

		return $summary;
	}

	/**
	 * @return array{heading:string,badges:list<array{label:string}>,status:string}
	 */
	private function advanced_source_summary_projection(
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
			$this->log_failure( 'advanced package source summary projection unavailable', 'advanced_package_source_summary_projection', $failure );

			return array(
				'heading' => $heading,
				'badges'  => $badges,
				'status'  => $status,
			);
		}

		if ( is_array( $baseline ) && isset( $baseline['heading'] ) && is_string( $baseline['heading'] ) ) {
			$validated_heading = trim( wp_strip_all_tags( $baseline['heading'], true ) );
			if ( '' === $validated_heading || strlen( $validated_heading ) > 80 ) {
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
			$validated_status = is_string( $baseline['status'] ?? null )
				? trim( wp_strip_all_tags( $baseline['status'], true ) )
				: '';
			return array(
				'heading' => $validated_heading,
				'badges'  => $badges,
				'status'  => strlen( $validated_status ) <= 40 ? $validated_status : '',
			);
		}

		return array(
			'heading' => $heading,
			'badges'  => $badges,
			'status'  => $status,
		);
	}

	/** @return list<string> */
	private function extension_panels( Package $package ): array {
		$projection   = $this->projection( $package );
		$buffer_level = ob_get_level();
		ob_start();
		try {
			do_action( 'ran_booster_admin_package_settings_sections', $projection, $projection->settingsUrl() );
			$content = (string) ob_get_clean();

			return '' === trim( $content ) ? array() : array( $content );
		} catch ( Throwable $failure ) {
			$this->clean_buffer( $buffer_level );
			$this->log_failure( 'package settings action unavailable', 'package_settings_action', $failure );
		}

		return array();
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, array{badges: list<array{label: string, tone: string}>, status: string}>
	 */
	private function extension_rows( array $packages ): array {
		if ( array() === $packages ) {
			return array();
		}

		$projections = array();
		$base_rows   = array();
		foreach ( $packages as $package ) {
			if ( $package instanceof Package ) {
				$projection                               = $this->projection( $package );
				$projections[ $projection->identifier() ] = $projection;
				$base_rows[ $projection->identifier() ]   = array(
					'badges' => array(),
					'status' => '',
				);
			}
		}

		try {
			return $this->normalize_extension_rows(
				apply_filters(
					'ran_booster_admin_package_management_rows',
					$base_rows,
					$this->type,
					$projections
				),
				$projections,
				$base_rows
			);
		} catch ( Throwable $failure ) {
			$this->log_failure( 'package management filter unavailable', 'package_management_filter', $failure );
		}

		return array();
	}

	/**
	 * @param array<string, Package>|list<Package> $packages
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function extension_actions( array $packages ): array {
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
				$this->log_failure( 'package management actions unavailable', 'package_management_actions', $failure );
			}
		}

		return $actions;
	}

	/**
	 * @param mixed $presented
	 * @param array<string, AdminPackageProjection> $projections
	 * @param array<string, array{badges: array<mixed>, status: string}> $base_rows
	 * @return array<string, array{badges: list<array{label: string, tone: string}>, status: string}>
	 */
	private function normalize_extension_rows( mixed $presented, array $projections, array $base_rows ): array {
		if ( ! is_array( $presented ) ) {
			throw new LogicException( 'Package management rows must be a keyed array.' );
		}
		if ( array_diff_key( $presented, $base_rows ) !== array()
			|| array_diff_key( $base_rows, $presented ) !== array() ) {
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

			$badges           = array();
			$presented_badges = $row['badges'] ?? array();
			if ( ! is_array( $presented_badges ) || count( $presented_badges ) > 20 ) {
				throw new LogicException( 'Package management badges must be a bounded list.' );
			}
			foreach ( $presented_badges as $badge ) {
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
					'page'    => $this->pageSlug, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
					'package' => (string) $package->getIdentifier(),
				),
				$this->getAdminUrl()
			),
			$subdirectory
		);
	}

	private function clean_buffer( int $buffer_level ): void {
		while ( ob_get_level() > $buffer_level ) {
			ob_end_clean();
		}
	}

	private function log_failure( string $message, string $step, Throwable $failure ): void {
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
