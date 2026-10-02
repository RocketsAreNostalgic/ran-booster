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
		private readonly string $identifier_field, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
		private readonly string $page_slug // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
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

	public function get_type(): string {
		return $this->type;
	}

	public function get_singular_label(): string {
		return 'plugin' === $this->type
			? _x( 'Plugin', 'Managed package type singular label', 'ran-booster' )
			: _x( 'Theme', 'Managed package type singular label', 'ran-booster' );
	}

	public function get_plural_label(): string {
		return 'plugin' === $this->type
			? _x( 'Plugins', 'Managed package type plural label', 'ran-booster' )
			: _x( 'Themes', 'Managed package type plural label', 'ran-booster' );
	}

	public function get_identifier_field(): string {
		return $this->identifier_field; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
	}

	public function get_page_slug(): string {
		return $this->page_slug; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
	}

	public function get_create_page_slug(): string {
		return $this->page_slug . '-create'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
	}

	public function get_admin_url(): string {
		return is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	}

	public function get_action( string $operation ): string {
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
		array $package_providers, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		array $package_list_state, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		DeploymentAdminPresenter $deployments
	): array {
		$package_provider_options = $this->provider_filter_options( $packages, $package_providers ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		if ( '' !== $package_list_state['provider'] // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			&& ! in_array( $package_list_state['provider'], array_column( $package_provider_options, 'code' ), true ) // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		) {
			$package_list_state['provider'] = ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		}
		$filtered_packages = $this->filter_packages( $packages, $package_list_state, $package_provider_options ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.

		return array(
			'packages'                => $filtered_packages,
			'package_list_total'        => count( $packages ),
			'package_list_state'        => $package_list_state, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'package_provider_options'  => $package_provider_options,
			'package_view'             => $this,
			'package_providers'        => $package_providers, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'package_activity'         => $deployments->package_activity( $filtered_packages, $this->type ),
			'package_extension_rows'    => $this->extension_rows( $filtered_packages ),
			'package_extension_actions' => $this->extension_actions( $filtered_packages ),
		);
	}

	/** @return array<string, mixed> */
	public function edit(
		Package $package,
		array $package_provider_settings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		?array $package_branch_readiness, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		string $requested_source_view, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		bool $open_advanced = false // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
	): array {
		return array(
			'package_provider_settings' => $package_provider_settings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'package_branch_readiness'  => $package_branch_readiness, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'package'                 => $package,
			'package_view'             => $this,
			'package_extension_panels'  => $this->extension_panels( $package ),
			'package_source'           => $this->source_composition( 'edit', $requested_source_view, $package, $open_advanced ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		);
	}

	/** @return array<string, mixed> */
	public function create(
		array $package_provider_settings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		bool $explicit_provider, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		bool $open_repository_picker, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		string $requested_source_view, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		?string $managed_package_identifier = null, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		bool $open_advanced = false // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
	): array {
		return array(
			'package_provider_settings'  => $package_provider_settings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'package_view'              => $this,
			'explicit_provider'         => $explicit_provider, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'open_repository_picker'     => $open_repository_picker, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'package_source'            => $this->source_composition( 'create', $requested_source_view, null, $open_advanced ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'managed_package_identifier' => $managed_package_identifier, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
		);
	}

	/** @return array<string, mixed> */
	public function unavailable_create( array $package_provider_settings, bool $explicit_provider ): array { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve caller compatibility.
		return array(
			'package_provider_settings'  => $package_provider_settings, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'package_view'              => $this,
			'explicit_provider'         => $explicit_provider, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public parameter names preserve named-argument compatibility.
			'open_repository_picker'     => false,
			'package_mutation_available' => false,
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
			$code = (string) ( $package->get_provider_code() ?? '' );
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

					$provider = (string) ( $package->get_provider_code() ?? '' );
					if ( '' !== $state['provider'] && $provider !== $state['provider'] ) {
						return false;
					}
					if ( '' !== $state['source'] && $package->get_source()->value !== $state['source'] ) {
						return false;
					}
					if ( '' !== $state['policy'] && $package->get_deployment_policy()->value !== $state['policy'] ) {
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
									$package->get_display_name(),
									(string) $package->get_identifier(),
									(string) $package->get_repository(),
									$provider,
									$provider_labels[ $provider ] ?? '',
									(string) $package->get_branch(),
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
			? add_query_arg( 'page', $this->get_create_page_slug(), $this->get_admin_url() )
			: $projection->settings_url();
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

		$current  = null === $package ? PackageSource::BRANCH->value : $package->get_source()->value;
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
				null !== $package && '' !== (string) $package->get_branch()
					? (string) $package->get_branch()
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
			? $package->get_source()->value
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
			do_action( 'ran_booster_admin_package_settings_sections', $projection, $projection->settings_url() );
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
		$subdirectory = is_string( $package->get_subdirectory() )
			? trim( $package->get_subdirectory() )
			: '';
		return new AdminPackageProjection(
			$this->type,
			(string) $package->get_identifier(),
			$package->get_display_name(),
			(string) ( $package->get_provider_code() ?? '' ),
			$package->get_source()->value,
			$package->get_source_revision(),
			$package->get_deployment_policy()->value,
			add_query_arg(
				array(
					'page'    => $this->page_slug, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Promoted constructor property preserves its named parameter contract.
					'package' => (string) $package->get_identifier(),
				),
				$this->get_admin_url()
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
		BoosterLogger::log_exception(
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
