<?php

declare(strict_types=1);

namespace RAN\Admin;

use LogicException;
use RAN\Admin\Component\AdminActionNormalizer;
use RAN\Admin\ReleaseManagement\ReleaseWorkflowControls;
use RAN\Admin\WebhookManagement\RepositoryWebhookManagementControls;
use RAN\Logging\BoosterLogger;
use Throwable;

/**
 * Builds and protects Core-owned provider repository rows.
 */
final class ProviderRepositoryRowsNormalizer {
	/** Build the managed-repository projection consumed by the provider page. */
	public function project_page( array $data, ?RepositoryWebhookManagementControls $webhook_management = null, ?ReleaseWorkflowControls $release_workflow = null ): array {
		$provider       = is_array( $data['provider'] ?? null ) ? $data['provider'] : array();
		$provider_code  = is_string( $provider['code'] ?? null ) ? $provider['code'] : '';
		$provider_label = is_string( $provider['label'] ?? null ) ? $provider['label'] : '';
		$owner_label    = is_string( $provider['owner_label'] ?? null ) && '' !== trim( $provider['owner_label'] )
			? $provider['owner_label']
			: __( 'Owner', 'ran-booster' );
		$managed        = $this->inventory( $data['managed_webhook_repositories'] ?? null );
		$repositories   = $this->inventory( $data['provider_repositories'] ?? $managed );
		$readiness      = is_array( $data['webhook_assistance_readiness'] ?? null ) ? $data['webhook_assistance_readiness'] : array();
		$site           = is_array( $readiness['site'] ?? null ) ? $readiness['site'] : null;
		$endpoint       = rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $provider_code ) );
		$site_endpoint  = is_string( $site['callback_url'] ?? null ) ? $site['callback_url'] : $endpoint;
		$reason_codes   = is_array( $site['reason_codes'] ?? null ) ? $site['reason_codes'] : array();
		$site_ready     = null !== $site && 'ready' === ( $site['status'] ?? null );
		$base_url       = ( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) )
			. '?page=ran-booster&tab=' . rawurlencode( $provider_code );
		$provider_url   = static fn ( array $args = array() ): string => add_query_arg( $args, $base_url );
		$task_urls      = array();
		$task_requests  = array();
		foreach ( array( 'status', 'repositories', 'setup' ) as $task ) {
			$task_urls[ $task ]     = $provider_url( array( 'panel' => $task ) );
			$task_requests[ $task ] = add_query_arg(
				array(
					'page'  => 'ran-booster',
					'tab'   => $provider_code,
					'panel' => $task,
				),
				'admin.php'
			);
		}
		$counts                       = $this->counts( $managed['repositories'] );
		$shared_label                 = sprintf( /* translators: %s is the repository owner label. */ __( '%s secret', 'ran-booster' ), $owner_label );
		$webhook_label                = sprintf( /* translators: %s is the repository provider name. */ __( '%s webhooks', 'ran-booster' ), $provider_label );
		$model                        = $this->project(
			$repositories['repositories'],
			$provider_code,
			$provider_label,
			$webhook_label,
			$shared_label,
			$site_endpoint,
			$site_ready,
			$this->readiness_indexes( $readiness['repositories'] ?? null, $provider_code ),
			$webhook_management,
			is_string( $data['requested_repository_id'] ?? null ) ? $data['requested_repository_id'] : '',
			$provider_url,
			$task_urls['repositories'],
			$release_workflow
		);
		$repository_summary           = $this->repository_summary( $model['rows'] );
		$repository_view              = in_array( $data['repository_view'] ?? null, array( 'status', 'branch', 'releases' ), true ) ? $data['repository_view'] : 'status';
		$repository_view_urls         = array();
		$repository_view_request_urls = array();
		foreach ( array( 'status', 'branch', 'releases' ) as $view ) {
			$args         = array(
				'panel'           => 'repositories',
				'repository_view' => $view,
			);
			$request_args = array(
				'page'            => 'ran-booster',
				'tab'             => $provider_code,
				'panel'           => 'repositories',
				'repository_view' => $view,
			);
			if ( '' !== $model['requested_id'] ) {
				$args         = array(
					'panel'           => 'repositories',
					'repository'      => $model['requested_id'],
					'repository_view' => $view,
				);
				$request_args = array(
					'page'            => 'ran-booster',
					'tab'             => $provider_code,
					'panel'           => 'repositories',
					'repository'      => $model['requested_id'],
					'repository_view' => $view,
				);
			}
			$repository_view_urls[ $view ]         = $provider_url( $args );
			$repository_view_request_urls[ $view ] = add_query_arg( $request_args, 'admin.php' );
		}

		return array(
			'provider_task'                       => in_array( $data['provider_task'] ?? null, array( 'repositories', 'setup' ), true ) ? $data['provider_task'] : 'status',
			'managed_repositories'                => $managed,
			'webhook_endpoint'                    => $endpoint,
			'webhook_assistance_provider_capable' => null !== $site,
			'webhook_assistance_site_ready'       => $site_ready,
			'repository_integration_available'    => array() !== $model['rows'] || ( ! empty( $provider['capabilities']['webhooks'] ) && ! empty( $provider['webhook_scopes'] ) ),
			'repository_integration_summary'      => $repository_summary,
			'webhook_site_reasons'                => $this->site_reasons( $reason_codes, $site_endpoint ),
			'webhook_has_hard_failure'            => array() !== array_intersect( $reason_codes, array( 'database_unavailable', 'secrets_storage_unavailable', 'managed_packages_unavailable' ) ),
			'task_urls'                           => $task_urls,
			'task_request_urls'                   => $task_requests,
			'wordpress_urls_url'                  => admin_url( 'options-general.php' ),
			'webhook_operations_url'              => admin_url( 'admin.php?page=ran-booster&tab=documentation#ran-booster-push-to-deploy' ),
			'install_plugin_url'                  => admin_url( 'admin.php?page=ran-booster-plugins-create&provider=' . rawurlencode( $provider_code ) ),
			'install_theme_url'                   => admin_url( 'admin.php?page=ran-booster-themes-create&provider=' . rawurlencode( $provider_code ) ),
			'automaticPackageCount'               => $counts['automatic'],
			'requested_repository_id'             => $model['requested_id'],
			'repository_view'                     => $repository_view,
			'repository_view_urls'                => $repository_view_urls,
			'repository_view_request_urls'        => $repository_view_request_urls,
			'repository_list_url'                 => $model['list_url'],
			'provider_return_url'                 => $model['return_url'],
			'repository_table_rows'               => array_values( $model['rows'] ),
			'repository_row_count_label'          => sprintf( /* translators: %d is the number of repositories shown. */ _nx( '%d repository shown', '%d repositories shown', count( $model['rows'] ), 'Provider table repository count', 'ran-booster' ), count( $model['rows'] ) ),
			'selected_repository_row'             => $model['selected'],
			'activity_url'                        => admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' ),
		) + $this->copy( $provider_label, is_array( $provider['webhook_setup'] ?? null ) ? $provider['webhook_setup'] : null, $counts, $shared_label );
	}

	/**
	 * @param array<string, array<string, mixed>> $base_rows
	 * @param mixed                               $presented
	 * @return array<string, array<string, mixed>>
	 */
	public function normalize( array $base_rows, mixed $presented, string $provider_code, bool $allow_core_detail_append = false ): array {
		if ( ! is_array( $presented ) ) {
			throw new LogicException( 'Provider repository rows must be a keyed array.' );
		}

		$normalizer = new AdminActionNormalizer();
		$rows       = array();
		foreach ( $base_rows as $key => $base_row ) {
			if ( ! isset( $presented[ $key ] ) || ! is_array( $presented[ $key ] ) ) {
				throw new LogicException( 'Provider filters must preserve every Core repository row.' );
			}

			$row = $presented[ $key ];
			foreach ( array_keys( $row ) as $field ) {
				if ( ! array_key_exists( $field, $base_row ) && ! in_array( $field, array( 'details', 'actions' ), true ) ) {
					throw new LogicException( 'Provider filters may enrich Core rows only with details and actions.' );
				}
			}
			foreach ( $base_row as $field => $value ) {
				if ( in_array( $field, array( 'details', 'actions' ), true ) ) {
					continue;
				}
				if ( ! array_key_exists( $field, $row ) || $row[ $field ] !== $value ) {
					throw new LogicException( 'Provider filters must not rewrite Core repository fields.' );
				}
			}

			$base_details = is_array( $base_row['details'] ?? null ) ? array_values( $base_row['details'] ) : array();
			$details      = is_array( $row['details'] ?? null ) ? array_values( $row['details'] ) : array();
			if ( array_slice( $details, 0, count( $base_details ) ) !== $base_details ) {
				throw new LogicException( 'Provider filters may append but not replace Core details.' );
			}
			$this->assert_details( $details, count( $base_details ), $allow_core_detail_append );

			$base_actions = is_array( $base_row['actions'] ?? null ) ? $base_row['actions'] : array();
			$actions      = is_array( $row['actions'] ?? null ) ? $row['actions'] : array();
			foreach ( $base_actions as $action_key => $base_action ) {
				if ( ! isset( $actions[ $action_key ] ) || ! is_array( $actions[ $action_key ] ) ) {
					throw new LogicException( 'Provider filters must preserve every Core action.' );
				}
				if ( 'core:webhook-management' !== $action_key ) {
					if ( $actions[ $action_key ] !== $base_action ) {
						throw new LogicException( 'Provider filters must not rewrite Core actions.' );
					}
					continue;
				}
				foreach ( $base_action as $field => $value ) {
					if ( in_array( $field, array( 'url', 'disabled', 'described_by' ), true ) ) {
						continue;
					}
					if ( ! array_key_exists( $field, $actions[ $action_key ] ) || $actions[ $action_key ][ $field ] !== $value ) {
						throw new LogicException( 'Webhook management may change only its reserved action state.' );
					}
				}
			}
			$normalized_row            = $base_row;
			$normalized_row['details'] = $details;
			$normalized_row['actions'] = $normalizer->normalize( $actions );
			$rows[ $key ]              = $normalized_row;
		}

		foreach ( $presented as $key => $row ) {
			if ( isset( $base_rows[ $key ] ) ) {
				continue;
			}
			if ( ! is_string( $key )
				|| 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z0-9:-]{1,127}$/', $key )
				|| ! is_array( $row )
				|| true !== ( $row['historical'] ?? false )
				|| ( $row['provider_code'] ?? null ) !== $provider_code ) {
				throw new LogicException( 'Provider filters may append only namespaced historical rows.' );
			}
			$row_actions = is_array( $row['actions'] ?? null ) ? $row['actions'] : array();
			if ( isset( $row_actions['core:webhook-management'] ) ) {
				throw new LogicException( 'Historical rows must not claim Core actions.' );
			}
			$row['actions'] = $normalizer->normalize( $row_actions );
			foreach ( $row['actions'] as $action ) {
				if ( 'post' === $action['type'] ) {
					throw new LogicException( 'Historical rows may contain link actions only.' );
				}
			}
			$rows[ $key ] = $this->normalize_historical_row( $key, $row, $provider_code );
		}

		return $rows;
	}

	/**
	 * @param list<array<string,mixed>> $repositories
	 * @param array{by_id:array<string,array<string,mixed>>,by_repository:array<string,array<string,mixed>>} $readiness
	 * @param callable(array<string,mixed>):string $provider_url
	 * @return array{requested_id:string,list_url:string,return_url:string,webhook_rows:array<string,array<string,mixed>>,rows:array<string,array<string,mixed>>,selected:?array}
	 */
	public function project(
		array $repositories,
		string $provider_code,
		string $provider_label,
		string $provider_webhook_settings_label,
		string $shared_secret_label,
		string $endpoint,
		bool $site_ready,
		array $readiness,
		?RepositoryWebhookManagementControls $webhook_management,
		string $requested_id,
		callable $provider_url,
		string $list_url,
		?ReleaseWorkflowControls $release_workflow = null
	): array {
		$return_url   = '' === $requested_id ? $list_url : $provider_url(
			array(
				'panel'      => 'repositories',
				'repository' => $requested_id,
			)
		);
		$rows         = array();
		$projections  = array();
		$issue_labels = array(
			'repository_identity_unavailable' => __( 'Repository identity unavailable', 'ran-booster' ),
			'repository_identity_conflict'    => __( 'Repository identity conflict', 'ran-booster' ),
			'repository_locator_invalid'      => __( 'Repository address invalid', 'ran-booster' ),
		);
		foreach ( $repositories as $index => $repository ) {
			if ( ! is_array( $repository ) ) {
				continue; }
			$managed_id       = is_string( $repository['repository_id'] ?? null ) ? $repository['repository_id'] : '';
			$locator          = is_string( $repository['target'] ?? null ) ? $repository['target'] : '';
			$source           = is_string( $repository['source'] ?? null ) ? $repository['source'] : 'branch';
			$is_release       = 'release_asset' === $source;
			$source_conflict  = 'mixed' === $source;
			$has_branch       = in_array( $source, array( 'branch', 'mixed' ), true );
			$is_mixed         = 'mixed' === $source;
			$historical       = ! empty( $repository['historical'] ) || '' === trim( $managed_id );
			$retained         = $is_release && is_array( $repository['retained_webhook'] ?? null ) ? $repository['retained_webhook'] : array();
			$branch_consumers = array_values( array_filter( $retained['branch_package_references'] ?? array(), 'is_string' ) );
			$readiness_row    = ! $is_release && '' !== $managed_id && isset( $readiness['by_id'][ $managed_id ] )
				? $readiness['by_id'][ $managed_id ]
				: ( ! $is_release ? ( $readiness['by_repository'][ strtolower( $locator ) ] ?? null ) : null );
			$repository_id    = $is_release
				? $managed_id
				: ( is_string( $readiness_row['repository_id'] ?? null ) && '' !== $readiness_row['repository_id'] ? $readiness_row['repository_id'] : $managed_id );
			$row_key          = '' !== $repository_id ? $repository_id : 'repository:' . hash( 'sha256', $provider_code . '|' . strtolower( $locator ) . '|' . $source );
			$reason_codes     = is_array( $readiness_row['reason_codes'] ?? null ) ? $readiness_row['reason_codes'] : array();
			if ( true === ( $repository['identity_conflict'] ?? false ) && ! in_array( 'repository_identity_conflict', $reason_codes, true ) ) {
				$reason_codes[] = 'repository_identity_conflict';
			}
			$historical                = $historical || array() !== array_intersect( $reason_codes, array( 'repository_identity_unavailable', 'repository_identity_conflict' ) );
			$issues                    = array_values( array_filter( array_map( static fn ( mixed $code ): ?string => is_string( $code ) ? ( $issue_labels[ $code ] ?? null ) : null, $reason_codes ) ) );
			$coverage                  = $is_release
				? ( is_string( $retained['local_secret_coverage'] ?? null ) ? $retained['local_secret_coverage'] : 'unknown' )
				: ( is_string( $readiness_row['local_secret_coverage'] ?? null ) ? $readiness_row['local_secret_coverage'] : 'unknown' );
			$repository_policies       = is_array( $repository['deployment_policies'] ?? null ) ? $repository['deployment_policies'] : array();
			$repository_references     = is_array( $repository['package_references'] ?? null ) ? $repository['package_references'] : array();
			$readiness_policies        = is_array( $readiness_row['deployment_policies'] ?? null ) ? $readiness_row['deployment_policies'] : null;
			$readiness_references      = is_array( $readiness_row['package_references'] ?? null ) ? $readiness_row['package_references'] : null;
			$policies                  = $is_mixed ? $repository_policies : ( $readiness_policies ?? $repository_policies );
			$references                = $is_mixed ? $repository_references : ( $readiness_references ?? $repository_references );
			$references                = array_values( array_filter( $references, 'is_string' ) );
			$branch_references         = is_array( $repository['branch_package_references'] ?? null )
				? array_values( array_filter( $repository['branch_package_references'], 'is_string' ) )
				: ( $has_branch ? $references : array() );
			$package_summaries         = $this->package_summaries( $repository['package_summaries'] ?? array() );
			$package_summaries_omitted = max( 0, (int) ( $repository['package_summaries_omitted'] ?? 0 ) );
			$inventory_incomplete      = 0 < $package_summaries_omitted;
			$automatic                 = (int) ( $policies['automatic'] ?? $repository['automatic_count'] ?? 0 );
			$manual                    = (int) ( $policies['manual'] ?? 0 );
			$disabled                  = (int) ( $policies['disabled'] ?? 0 );
			$policy_badges             = array(
				array(
					'label' => sprintf( /* translators: %d is the number of packages with Automatic updates. */ __( 'Automatic: %d', 'ran-booster' ), $automatic ),
					'tone'  => 'neutral',
				),
				array(
					'label' => sprintf( /* translators: %d is the number of packages with Manual updates. */ __( 'Manual: %d', 'ran-booster' ), $manual ),
					'tone'  => 'neutral',
				),
				array(
					'label' => sprintf( /* translators: %d is the number of packages with Disabled updates. */ __( 'Disabled: %d', 'ran-booster' ), $disabled ),
					'tone'  => 'neutral',
				),
			);
			if ( 1 === count( $references ) ) {
				$policy_badges = array(
					match ( true ) {
					1 === $automatic => array(
						'label' => __( 'Automatic', 'ran-booster' ),
						'tone'  => 'ok',
					),
									1 === $manual => array(
										'label' => __( 'Manual', 'ran-booster' ),
										'tone'  => 'pending',
									),
									default => array(
										'label' => __( 'Disabled', 'ran-booster' ),
										'tone'  => 'neutral',
									),
					},
				);
			}
			$types = array();
			foreach ( $references as $reference ) {
				$type           = str_ends_with( strtolower( $reference ), '.php' ) ? __( 'Plugin', 'ran-booster' ) : __( 'Theme', 'ran-booster' );
				$types[ $type ] = array(
					'label' => $type,
					'tone'  => 'pending',
				);
			}
			$type_label = match ( count( $types ) ) {
				0 => __( 'Package', 'ran-booster' ), 1 => (string) array_key_first( $types ), default => __( 'Plugins and themes', 'ran-booster' ) };
			$reason_id = 'ran-booster-provider-readiness-reason-' . (int) $index;
			$statuses  = array();
			if ( '' !== ( $issues[0] ?? '' ) ) {
				$statuses[] = array(
					'label' => $issues[0],
					'tone'  => 'error',
					'id'    => $reason_id,
				); }
			if ( $source_conflict ) {
				$statuses[] = array(
					'label' => __( 'Conflicting sources', 'ran-booster' ),
					'tone'  => 'warning',
					'id'    => $reason_id . '-source-conflict',
				);
			}
			$statuses[] = array(
				'label' => match ( $coverage ) {
				'repository' => __( 'Repository secret', 'ran-booster' ), 'shared' => $shared_secret_label, 'none' => __( 'No secret', 'ran-booster' ), default => __( 'Secret coverage unavailable', 'ran-booster' ) },
				'tone'  => in_array( $coverage, array( 'repository', 'shared' ), true ) ? 'ok' : 'warning',
			);
			if ( ! $site_ready ) {
				$statuses[] = array(
					'label' => __( 'Push-to-Deploy disabled', 'ran-booster' ),
					'tone'  => 'error',
					'id'    => $reason_id . '-site',
				); }
			if ( $is_release ) {
				$statuses = array(); }
			$non_zero         = array_filter(
				array(
					'automatic' => $automatic,
					'manual'    => $manual,
					'disabled'  => $disabled,
				),
				static fn ( int $count ): bool => 0 < $count
			);
			$management_label = 1 === count( $non_zero ) ? match ( (string) array_key_first( $non_zero ) ) {
				'automatic' => __( 'Automatic', 'ran-booster' ), 'manual' => __( 'Manual', 'ran-booster' ), default => __( 'Disabled', 'ran-booster' ) } : __( 'Mixed policies', 'ran-booster' );
			$management_detail = match ( $coverage ) {
				'repository' => __( 'Repository secret', 'ran-booster' ), 'shared' => $shared_secret_label, 'none' => __( 'No secret', 'ran-booster' ), 'not_applicable' => '', default => __( 'Secret coverage unavailable', 'ran-booster' ) };
			$management_tone = in_array( $coverage, array( 'repository', 'shared' ), true ) ? 'ok' : 'warning';
			$consequence     = match ( true ) {
				$source_conflict => __( 'Conflicting sources. Review the package settings before using release workflow.', 'ran-booster' ),
				$is_release && array() !== $branch_consumers => __( 'This package ignores pushes. Branch-managed packages in this repository still use webhook setup.', 'ran-booster' ),
				$is_release && in_array( $coverage, array( 'repository', 'shared' ), true ) => __( 'This package ignores pushes. Local signing setup is retained for an easier return to Branch.', 'ran-booster' ),
				$is_release => __( 'Pushes are ignored.', 'ran-booster' ),
				1 === count( $non_zero ) && isset( $non_zero['disabled'] ) => __( 'Push-to-Deploy disabled; pushes are ignored.', 'ran-booster' ),
				'' !== ( $issues[0] ?? '' ) => (string) $issues[0],
				! $site_ready => __( 'Push-to-Deploy is unavailable until the site-level readiness issue is resolved.', 'ran-booster' ),
				'none' === $coverage => __( 'Push-to-Deploy is blocked until a signing secret is selected.', 'ran-booster' ),
				1 === count( $non_zero ) && isset( $non_zero['automatic'] ) => __( 'Push-to-Deploy enabled; signed pushes can queue eligible packages.', 'ran-booster' ),
				1 === count( $non_zero ) && isset( $non_zero['manual'] ) => __( 'Push-to-Deploy remains off until the package Updates setting is Automatic.', 'ran-booster' ),
				default => __( 'Only Automatic packages can respond to signed pushes.', 'ran-booster' ),
			};
			if ( $is_release ) {
				$management_label  = __( 'Releases', 'ran-booster' );
				$management_detail = __( 'Push-to-Deploy unavailable', 'ran-booster' );
				$management_tone   = 'info'; }
			if ( $inventory_incomplete ) {
				$management_label  = __( 'Package inventory incomplete', 'ran-booster' );
				$management_detail = __( 'Workflow controls disabled', 'ran-booster' );
				$management_tone   = 'warning';
				$consequence       = sprintf( /* translators: %d is the number of omitted package summaries. */ __( '%d package summary is not shown. Refresh the repository inventory before relying on aggregate deployment state or workflow controls.', 'ran-booster' ), $package_summaries_omitted );
			}
			$release_reason_id = ( $is_release || $source_conflict ) && '' !== $consequence ? $reason_id . '-release-source' : '';
			$described_by      = array_filter( array( $release_reason_id, ( '' !== ( $issues[0] ?? '' ) ) ? $reason_id : '', ! $site_ready && ! $is_release ? $reason_id . '-site' : '' ) );
			$actions           = ! $inventory_incomplete && null !== $webhook_management && $webhook_management->supports_provider( $provider_code ) && $has_branch && ! $historical
				? $this->webhook_management_action( $locator, $described_by )
				: array();
			$secret_target     = 'shared' === $coverage ? (string) strtok( $locator, '/' ) : $locator;
			$secret_link       = 'none' === $coverage ? array(
				'label'  => __( 'Add repository secret', 'ran-booster' ),
				'url'    => $provider_url(
					array_filter(
						array(
							'panel'              => 'repositories',
							'repository'         => $repository_id,
							'add_webhook_secret' => 1,
							'webhook_scope'      => 'repository',
							'webhook_target'     => $locator,
						),
						static fn ( mixed $item ): bool => '' !== $item
					)
				),
				'modal'  => 'webhook',
				'scope'  => 'repository',
				'target' => $locator,
			)
				: ( in_array( $coverage, array( 'repository', 'shared' ), true ) ? array(
					'label'  => 'shared' === $coverage ? __( 'Review shared owner secret', 'ran-booster' ) : __( 'Review repository secret', 'ran-booster' ),
					'url'    => $provider_url(
						array(
							'view' => 'secrets',
							's'    => $secret_target,
						)
					),
					'modal'  => '',
					'scope'  => '',
					'target' => '',
				) : null );
			$detail_url        = '' !== $repository_id && ! $historical
				? $provider_url(
					array(
						'panel'      => 'repositories',
						'repository' => $repository_id,
					)
				)
				: '';
			if ( ! $inventory_incomplete && ! $historical ) {
				$this->append_repository_actions( $actions, $repository, $references, $is_release, $coverage, $provider_webhook_settings_label, $release_reason_id, $locator, $detail_url );
			}
			$rows[ $row_key ] = array(
				'key'                           => $row_key,
				'provider_code'                 => $provider_code,
				'repository_id'                 => $repository_id,
				'historical'                    => $historical,
				'provider_label'                => $provider_label,
				'repository'                    => $locator,
				'repository_url'                => is_string( $repository['repository_url'] ?? null ) ? $repository['repository_url'] : '',
				'detail_url'                    => $detail_url,
				'package_type_label'            => $type_label,
				'source_key'                    => $source,
				'source_label'                  => match ( $source ) {
					'mixed' => __( 'Conflicting sources', 'ran-booster' ),
					'release_asset' => __( 'Releases', 'ran-booster' ),
					default => __( 'Branch', 'ran-booster' ),
				},
				'management_label'              => $management_label,
				'management_detail'             => $management_detail,
				'management_tone'               => $management_tone,
				'consequence'                   => $consequence,
				'consequence_id'                => $release_reason_id,
				'types'                         => array_values( $types ),
				'policies'                      => $policy_badges,
				'package_references'            => $references,
				'has_branch_consumer'           => array() !== $branch_references,
				'has_automatic_branch_consumer' => ! $inventory_incomplete && true === ( $repository['has_automatic_branch_consumer'] ?? false ),
				'package_summaries'             => $package_summaries,
				'package_summaries_omitted'     => $package_summaries_omitted,
				'statuses'                      => $statuses,
				'status_links'                  => null === $secret_link ? array() : array( $secret_link ),
				'actions'                       => $actions,
			);
			if ( $has_branch && ! $historical ) {
				$projections[ $row_key ] = array(
					'provider_code'         => $provider_code,
					'repository_id'         => $repository_id,
					'repository'            => $locator,
					'label'                 => $locator,
					'package_references'    => $branch_references,
					'deployment_policies'   => array(
						'automatic' => $automatic,
						'manual'    => $manual,
						'disabled'  => $disabled,
					),
					'endpoint'              => $endpoint,
					'eligible'              => is_array( $readiness_row ) && true === ( $readiness_row['eligible'] ?? false ) && $site_ready && '' !== $repository_id,
					'reason_codes'          => $reason_codes,
					'local_secret_coverage' => $coverage,
				);
			}
		}
		$core_rows    = null !== $webhook_management
			? $webhook_management->enrich_repository_rows( $rows, $provider_code, $projections, $return_url )
			: $rows;
		$webhook_rows = $this->normalize( $rows, $core_rows, $provider_code, true );
		$core_rows    = null !== $release_workflow
			? $release_workflow->enrich_repository_rows( $webhook_rows, $provider_code, $projections, $return_url )
			: $webhook_rows;
		$core_rows    = $this->normalize( $webhook_rows, $core_rows, $provider_code, true );
		try {
			$presented = apply_filters(
				'ran_booster_provider_repository_rows',
				$core_rows,
				$provider_code,
				$projections,
				$return_url
			);
			$rows      = $this->normalize( $core_rows, $presented, $provider_code );
		} catch ( Throwable $failure ) {
			$rows = $core_rows;
			BoosterLogger::log_exception(
				'provider repository row enrichment unavailable',
				$failure,
				array(
					'source'   => 'admin',
					'step'     => 'provider_repository_row_enrichment',
					'provider' => $provider_code,
				)
			);
		}
		$selected = null;
		foreach ( $rows as $row ) {
			if ( '' !== $requested_id && false === ( $row['historical'] ?? false ) && ( $row['repository_id'] ?? null ) === $requested_id ) {
				$selected = $row;
				break; }
		}

		return array(
			'requested_id' => $requested_id,
			'list_url'     => $list_url,
			'return_url'   => $return_url,
			'webhook_rows' => $webhook_rows,
			'rows'         => $rows,
			'selected'     => $selected,
		);
	}

	/** @param list<string> $described_by @return array<string,array<string,mixed>> */
	private function webhook_management_action( string $repository, array $described_by ): array {
		return array(
			'core:webhook-management' => array(
				'key'           => 'core:webhook-management',
				'label'         => __( 'Manage webhook', 'ran-booster' ),
				'type'          => 'link',
				'url'           => '',
				'hidden'        => array(),
				'disabled'      => true,
				'external'      => false,
				'described_by'  => implode( ' ', $described_by ),
				'screen_reader' => $repository,
			),
		);
	}

	/** @param array<string,array<string,mixed>> $actions @param array<string,mixed> $repository @param list<string> $references */
	private function append_repository_actions( array &$actions, array $repository, array $references, bool $is_release, string $coverage, string $provider_label, string $reason_id, string $locator, string $detail_url ): void {
		if ( $is_release ) {
			$url             = '' === $detail_url ? '' : add_query_arg( 'repository_view', 'branch', $detail_url ) . '#ran-booster-repository-webhook-setup-heading';
			$key             = in_array( $coverage, array( 'repository', 'shared' ), true ) ? 'core:webhook-cleanup-review' : 'core:provider-webhooks';
			$actions[ $key ] = array(
				'key'           => $key,
				'label'         => 'core:webhook-cleanup-review' === $key ? __( 'Review webhook cleanup', 'ran-booster' ) : $provider_label,
				'type'          => 'link',
				'url'           => $url,
				'hidden'        => array(),
				'disabled'      => '' === $url,
				'external'      => 'core:provider-webhooks' === $key,
				'described_by'  => $reason_id,
				'screen_reader' => $locator,
			);
		} elseif ( is_string( $repository['webhook_settings_url'] ?? null ) ) {
			$actions['core:provider-webhooks'] = array(
				'key'           => 'core:provider-webhooks',
				'label'         => $provider_label,
				'type'          => 'link',
				'url'           => $repository['webhook_settings_url'],
				'hidden'        => array(),
				'disabled'      => false,
				'external'      => true,
				'described_by'  => '',
				'screen_reader' => $locator,
			);
		}
		foreach ( $references as $reference ) {
			$is_plugin = str_ends_with( strtolower( $reference ), '.php' );
			if ( ! $is_plugin && 1 !== preg_match( '/^[A-Za-z0-9_.-]+$/', $reference ) ) {
				continue; }
			$url = ( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) ) . '?page=' . ( $is_plugin ? 'ran-booster-plugins' : 'ran-booster-themes' ) . '&package=' . rawurlencode( $reference );
			if ( ! $is_release ) {
				$url = add_query_arg( 'source_view', 'branch', $url ) . '#ran-booster-branch-readiness'; }
			$key             = 'core:package-' . substr( hash( 'sha256', $reference ), 0, 16 );
			$actions[ $key ] = array(
				'key'           => $key,
				'label'         => $is_plugin ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ),
				'type'          => 'link',
				'url'           => $url,
				'hidden'        => array(),
				'disabled'      => false,
				'external'      => false,
				'described_by'  => '',
				'screen_reader' => $reference,
			);
		}
	}
	/** @param list<mixed> $details */
	private function assert_details( array $details, int $core_detail_count = 0, bool $allow_core_detail_append = false ): void {
		if ( count( $details ) > 20 ) {
			throw new LogicException( 'Repository details must be bounded.' );
		}

		foreach ( $details as $index => $detail ) {
			if ( ! is_array( $detail ) ) {
				throw new LogicException( 'Repository details must be display maps.' );
			}
			$key = $this->bounded_string( $detail['key'] ?? '', 96, true );
			if ( ! $allow_core_detail_append && $index >= $core_detail_count && str_starts_with( $key, 'core:' ) ) {
				throw new LogicException( 'Provider filters may not append Core detail keys.' );
			}
			$this->bounded_string( $detail['label'] ?? null, 96, false );
			$this->bounded_string( $detail['value'] ?? null, 255, true );
			$tone = $this->bounded_string( $detail['tone'] ?? '', 16, true );
			if ( '' !== $tone && ! in_array( $tone, $this->tones(), true ) ) {
				throw new LogicException( 'Repository detail tones are invalid.' );
			}
			$category = $this->bounded_string( $detail['category'] ?? '', 32, true );
			if ( '' !== $category && ! in_array( $category, array( 'webhook', 'release_workflow' ), true ) ) {
				throw new LogicException( 'Repository detail categories are invalid.' );
			}
			$this->bounded_string( $detail['datetime'] ?? '', 64, true );
			$this->bounded_string( $detail['state'] ?? '', 64, true );
			if ( isset( $detail['recorded'] ) && ! is_bool( $detail['recorded'] ) ) {
				throw new LogicException( 'Repository detail recorded flags must be boolean.' );
			}
			if ( isset( $detail['review_summary'] ) && ! is_bool( $detail['review_summary'] ) ) {
				throw new LogicException( 'Repository detail review summary flags must be boolean.' );
			}
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $rows        Provider-enriched rows.
	 * @return array{repositories:int,recorded_hooks:int,needs_review:int,release_packages:int,release_repositories:int,release_totals_incomplete:bool,release_workflows_inventory_incomplete:bool,release_workflows_needing_review:int}
	 */
	private function repository_summary( array $rows ): array {
		$recorded_hooks                   = 0;
		$needs_review                     = 0;
		$release_packages                 = 0;
		$release_repositories             = 0;
		$release_totals_incomplete        = false;
		$release_workflows_incomplete     = false;
		$release_workflows_needing_review = 0;
		$release_workflow_keys            = array();
		foreach ( $rows as $row ) {
			$recorded = false;
			$healthy  = false;
			foreach ( is_array( $row['details'] ?? null ) ? $row['details'] : array() as $detail ) {
				if ( ! is_array( $detail ) || 'core:webhook-recorded-status' !== ( $detail['key'] ?? null ) ) {
					continue;
				}
				$recorded = true === ( $detail['recorded'] ?? false );
				$healthy  = 'configured' === ( $detail['state'] ?? null );
				break;
			}
			if ( $recorded ) {
				++$recorded_hooks;
			}
			$automatic_branch = true === ( $row['has_automatic_branch_consumer'] ?? false );
			if ( ( $automatic_branch && ! $recorded ) || ( $recorded && ! $healthy ) ) {
				++$needs_review;
			}
		}
		foreach ( $rows as $row ) {
			if ( true === ( $row['historical'] ?? false ) ) {
				continue;
			}
			$source                         = is_string( $row['source_key'] ?? null ) ? $row['source_key'] : '';
			$package_summaries_omitted      = max( 0, (int) ( $row['package_summaries_omitted'] ?? 0 ) );
			$release_packages_in_repository = 0;
			foreach ( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array() as $summary ) {
				if ( is_array( $summary ) && 'release_asset' === ( $summary['source'] ?? null ) ) {
					++$release_packages_in_repository;
				}
			}
			if ( 'release_asset' === $source && 0 < $package_summaries_omitted ) {
				$release_packages_in_repository = is_array( $row['package_references'] ?? null )
					? count( array_filter( $row['package_references'], 'is_string' ) )
					: 0;
				if ( 0 === $release_packages_in_repository ) {
					$release_packages_in_repository = count( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array() ) + $package_summaries_omitted;
				}
				$release_workflows_incomplete = true;
			} elseif ( 'mixed' === $source && 0 < $package_summaries_omitted ) {
				$release_packages_in_repository = max( 1, $release_packages_in_repository );
				$release_totals_incomplete      = true;
				$release_workflows_incomplete   = true;
			}
			if ( 0 < $release_packages_in_repository ) {
				$release_packages += $release_packages_in_repository;
				++$release_repositories;
			}
			if ( 0 < $package_summaries_omitted ) {
				continue;
			}
			foreach ( is_array( $row['details'] ?? null ) ? $row['details'] : array() as $detail ) {
				if ( ! is_array( $detail )
					|| ! $this->is_release_workflow_detail( $detail )
					|| ! in_array( $detail['tone'] ?? null, array( 'pending', 'warning' ), true ) ) {
					continue;
				}
				$key = is_string( $detail['key'] ?? null ) ? $detail['key'] : '';
				if ( '' === $key || isset( $release_workflow_keys[ $key ] ) ) {
					continue;
				}
				$release_workflow_keys[ $key ] = true;
				++$release_workflows_needing_review;
			}
		}

		return array(
			'repositories'                           => count( $rows ),
			'recorded_hooks'                         => $recorded_hooks,
			'needs_review'                           => $needs_review,
			'release_packages'                       => $release_packages,
			'release_repositories'                   => $release_repositories,
			'release_totals_incomplete'              => $release_totals_incomplete,
			'release_workflows_inventory_incomplete' => $release_workflows_incomplete,
			'release_workflows_needing_review'       => $release_workflows_needing_review,
		);
	}

	/** @param array<string, mixed> $detail */
	private function is_release_workflow_detail( array $detail ): bool {
		return 'release_workflow' === ( $detail['kind'] ?? null )
			|| ( 'release_workflow' === ( $detail['category'] ?? null ) && true === ( $detail['review_summary'] ?? false ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalize_historical_row( string $key, array $row, string $provider_code ): array {
		$repository_id = $this->bounded_string( $row['repository_id'] ?? null, 191, false );
		$details       = is_array( $row['details'] ?? null ) ? array_values( $row['details'] ) : array();
		$this->assert_details( $details );

		return array(
			'key'                => $key,
			'provider_code'      => $provider_code,
			'provider_label'     => $this->bounded_string( $row['provider_label'] ?? null, 96, false ),
			'repository_id'      => $repository_id,
			'repository'         => $this->bounded_string( $row['repository'] ?? null, 255, false ),
			'repository_url'     => $this->safe_url( $row['repository_url'] ?? '' ),
			'detail_url'         => '',
			'historical'         => true,
			'types'              => $this->badges( $row['types'] ?? array() ),
			'package_message'    => $this->bounded_string( $row['package_message'] ?? '', 255, true ),
			'package_references' => $this->strings( $row['package_references'] ?? array(), 20, 255 ),
			'policies'           => $this->badges( $row['policies'] ?? array() ),
			'statuses'           => $this->badges( $row['statuses'] ?? array(), true ),
			'status_links'       => $this->links( $row['status_links'] ?? array() ),
			'status_message'     => $this->bounded_string( $row['status_message'] ?? '', 255, true ),
			'action_message'     => $this->bounded_string( $row['action_message'] ?? '', 255, true ),
			'actions'            => $row['actions'],
			'details'            => $details,
		);
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function badges( mixed $badges, bool $allow_relationships = false ): array {
		if ( ! is_array( $badges ) || count( $badges ) > 20 ) {
			throw new LogicException( 'Repository badges must be bounded.' );
		}

		$normalized = array();
		foreach ( $badges as $badge ) {
			if ( ! is_array( $badge ) ) {
				throw new LogicException( 'Repository badges must be display maps.' );
			}
			$tone = $this->bounded_string( $badge['tone'] ?? 'neutral', 16, false );
			if ( ! in_array( $tone, $this->tones(), true ) ) {
				throw new LogicException( 'Repository badge tones are invalid.' );
			}
			$item = array(
				'label' => $this->bounded_string( $badge['label'] ?? null, 96, false ),
				'tone'  => $tone,
			);
			if ( $allow_relationships ) {
				$item['id']           = $this->relationship( $badge['id'] ?? '' );
				$item['described_by'] = $this->relationship( $badge['described_by'] ?? '', true );
			}
			$normalized[] = $item;
		}

		return $normalized;
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function links( mixed $links ): array {
		if ( ! is_array( $links ) || count( $links ) > 10 ) {
			throw new LogicException( 'Repository status links must be bounded.' );
		}

		$normalized = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				throw new LogicException( 'Repository status links must be display maps.' );
			}
			$normalized[] = array(
				'label'  => $this->bounded_string( $link['label'] ?? null, 96, false ),
				'url'    => $this->safe_url( $link['url'] ?? null, false ),
				'modal'  => $this->bounded_string( $link['modal'] ?? '', 64, true ),
				'scope'  => $this->bounded_string( $link['scope'] ?? '', 64, true ),
				'target' => $this->bounded_string( $link['target'] ?? '', 255, true ),
			);
		}

		return $normalized;
	}

	/** @return list<string> */
	private function strings( mixed $values, int $maximum_items, int $maximum_length ): array {
		if ( ! is_array( $values ) || count( $values ) > $maximum_items ) {
			throw new LogicException( 'Repository string lists must be bounded.' );
		}

		return array_map(
			fn ( mixed $value ): string => $this->bounded_string( $value, $maximum_length, false ),
			array_values( $values )
		);
	}

	/**
	 * @return list<array{type:string,identifier:string,display_name:string,settings_url:string,source:string,source_revision:int,branch:string,subdirectory:string,deployment_policy:string}>
	 */
	private function package_summaries( mixed $summaries ): array {
		if ( ! is_array( $summaries ) || count( $summaries ) > 20 ) {
			throw new LogicException( 'Repository package summaries must be bounded.' );
		}

		$normalized = array();
		foreach ( $summaries as $summary ) {
			if ( ! is_array( $summary ) ) {
				throw new LogicException( 'Repository package summaries must be display maps.' );
			}
			$type     = $this->bounded_string( $summary['type'] ?? null, 16, false );
			$source   = $this->bounded_string( $summary['source'] ?? null, 32, false );
			$policy   = $this->bounded_string( $summary['deployment_policy'] ?? null, 16, false );
			$revision = is_int( $summary['source_revision'] ?? null ) ? $summary['source_revision'] : 0;
			if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
				|| ! in_array( $source, array( 'branch', 'release_asset' ), true )
				|| ! in_array( $policy, array( 'automatic', 'manual', 'disabled' ), true )
				|| 1 > $revision ) {
				throw new LogicException( 'Repository package summary values are invalid.' );
			}
			$normalized[] = array(
				'type'              => $type,
				'identifier'        => $this->bounded_string( $summary['identifier'] ?? null, 255, false ),
				'display_name'      => $this->bounded_string( $summary['display_name'] ?? null, 255, false ),
				'settings_url'      => $this->safe_url( $summary['settings_url'] ?? null, false ),
				'source'            => $source,
				'source_revision'   => $revision,
				'branch'            => $this->bounded_string( $summary['branch'] ?? '', 255, true ),
				'subdirectory'      => $this->bounded_string( $summary['subdirectory'] ?? '', 255, true ),
				'deployment_policy' => $policy,
			);
		}

		return $normalized;
	}

	private function safe_url( mixed $value, bool $allow_empty = true ): string {
		$url = $this->bounded_string( $value, 2048, $allow_empty );
		if ( '' === $url ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Validation must happen before rendering.
		$parts = parse_url( $url );
		if ( ! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] ) ) {
			throw new LogicException( 'Repository URLs must be safe and absolute.' );
		}

		return $url;
	}

	private function relationship( mixed $value, bool $multiple = false ): string {
		$relationship = $this->bounded_string( $value, 255, true );
		$pattern      = $multiple
			? '/^[A-Za-z][A-Za-z0-9_-]*(?: [A-Za-z][A-Za-z0-9_-]*)*$/'
			: '/^[A-Za-z][A-Za-z0-9_-]*$/';
		if ( '' !== $relationship && 1 !== preg_match( $pattern, $relationship ) ) {
			throw new LogicException( 'Repository relationship identifiers are invalid.' );
		}

		return $relationship;
	}

	private function bounded_string( mixed $value, int $maximum, bool $allow_empty ): string {
		if ( ! is_string( $value )
			|| ( ! $allow_empty && '' === trim( $value ) )
			|| strlen( $value ) > $maximum
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new LogicException( 'Repository display values must be bounded strings.' );
		}

		return $value;
	}

	/** @return list<string> */
	private function tones(): array {
		return array( 'neutral', 'ok', 'pending', 'warning', 'error' );
	}

	/** @return array{available:bool,owners:list<mixed>,repositories:list<mixed>} */
	private function inventory( mixed $inventory ): array {
		$inventory = is_array( $inventory ) ? $inventory : array();

		return array(
			'available'    => ! empty( $inventory['available'] ),
			'owners'       => is_array( $inventory['owners'] ?? null ) ? array_values( $inventory['owners'] ) : array(),
			'repositories' => is_array( $inventory['repositories'] ?? null ) ? array_values( $inventory['repositories'] ) : array(),
		);
	}

	/** @return array{repositories:int,packages:int,automatic:int} */
	private function counts( array $repositories ): array {
		$packages  = 0;
		$automatic = 0;
		foreach ( $repositories as $repository ) {
			$packages  += (int) ( $repository['package_count'] ?? 0 );
			$automatic += (int) ( $repository['automatic_count'] ?? 0 );
		}

		return array(
			'repositories' => count( $repositories ),
			'packages'     => $packages,
			'automatic'    => $automatic,
		);
	}

	/** @return array{by_id:array<string,array<string,mixed>>,by_repository:array<string,array<string,mixed>>} */
	private function readiness_indexes( mixed $candidates, string $provider_code ): array {
		$by_id         = array();
		$by_repository = array();
		foreach ( is_array( $candidates ) ? $candidates : array() as $candidate ) {
			if ( ! is_array( $candidate ) || ( $candidate['provider_code'] ?? null ) !== $provider_code ) {
				continue;
			}
			$id         = is_string( $candidate['repository_id'] ?? null ) ? $candidate['repository_id'] : '';
			$repository = is_string( $candidate['repository'] ?? null ) ? strtolower( $candidate['repository'] ) : '';
			if ( '' !== $id ) {
				$by_id[ $id ] = $candidate; }
			if ( '' !== $repository ) {
				$by_repository[ $repository ] = $candidate; }
		}

		return array(
			'by_id'         => $by_id,
			'by_repository' => $by_repository,
		);
	}

	/** @param list<mixed> $codes @return list<string> */
	private function site_reasons( array $codes, string $endpoint ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Display-safe endpoint parsing performs no network I/O.
		$host     = parse_url( $endpoint, PHP_URL_HOST );
		$is_local = is_string( $host ) && ( in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1' ), true ) || str_ends_with( strtolower( $host ), '.local' ) );
		$labels   = array(
			'database_unavailable'           => __( 'Booster database storage must be healthy before Push-to-Deploy can run.', 'ran-booster' ),
			'secrets_storage_unavailable'    => __( 'Encrypted credential storage must be healthy before Push-to-Deploy can verify signed deliveries.', 'ran-booster' ),
			'callback_requires_public_https' => $is_local ? __( 'This site uses a local URL, so providers cannot deliver webhooks to it. Configure a public HTTPS site URL before using Push-to-Deploy.', 'ran-booster' ) : __( 'The payload URL must use public HTTPS before providers can deliver webhooks to it.', 'ran-booster' ),
			'managed_packages_unavailable'   => __( 'Booster could not read the managed package inventory needed for Push-to-Deploy.', 'ran-booster' ),
		);

		return array_values( array_filter( array_map( static fn ( mixed $code ): ?string => is_string( $code ) ? ( $labels[ $code ] ?? null ) : null, $codes ) ) );
	}

	/** @param array{repositories:int,packages:int,automatic:int} $counts */
	private function copy( string $label, ?array $setup, array $counts, string $shared_secret_label ): array {
		$automatic_label = 0 < $counts['automatic']
			? sprintf( /* translators: %d is the number of packages with Automatic updates. */ _n( '%d package is Automatic', '%d packages are Automatic', $counts['automatic'], 'ran-booster' ), $counts['automatic'] )
			: __( 'None set to Automatic', 'ran-booster' );

		return array(
			'providerPushDescription'        => sprintf( /* translators: %s is the repository provider name. */ __( '%s push webhooks can trigger managed branch deployments whose Updates setting is Automatic.', 'ran-booster' ), $label ),
			'automaticPackageLabel'          => $automatic_label,
			'managedPackageDescription'      => sprintf( /* translators: 1: number of repositories, 2: number of managed packages. */ _n( '%1$d repository contains %2$d managed package.', '%1$d repositories contain %2$d managed packages.', $counts['repositories'], 'ran-booster' ), $counts['repositories'], $counts['packages'] ),
			'provider_instructions_label'    => sprintf( /* translators: %s is the repository provider name. */ __( 'Open %s instructions', 'ran-booster' ), $label ),
			'secretChoiceDescription'        => sprintf( /* translators: %s is the shared secret label. */ __( 'Use a saved %s or create a repository-scoped secret when isolation is required.', 'ran-booster' ), strtolower( $shared_secret_label ) ),
			'createProviderWebhookLabel'     => sprintf( /* translators: %s is the repository provider name. */ __( 'Create the %s webhook', 'ran-booster' ), $label ),
			'manual_setup_description'       => null === $setup ? '' : sprintf( /* translators: 1: repository provider name, 2: provider webhook settings location. */ __( 'In %1$s, go to %2$s and create the remote webhook.', 'ran-booster' ), $label, $setup['location'] ),
			'repository_webhook_description' => sprintf(
				/* translators: %s is the repository provider name. */
				__( 'Each repository needs its own %s webhook. A saved shared secret may serve multiple repositories.', 'ran-booster' ),
				$label
			),
			'empty_repository_description'   => sprintf(
				/* translators: %s is the repository provider name. */
				__( 'No managed %s repositories are available yet. Install a package to add its repository.', 'ran-booster' ),
				$label
			),
		);
	}
}
