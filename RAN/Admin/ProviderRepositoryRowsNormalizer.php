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
	// Placeholder meanings are fixed by the named projection fields below.
	// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
	/** Build the managed-repository projection consumed by the provider page. */
	public function projectPage( array $data, ?RepositoryWebhookManagementControls $webhookManagement = null, ?ReleaseWorkflowControls $releaseWorkflow = null ): array {
		$provider      = is_array( $data['provider'] ?? null ) ? $data['provider'] : array();
		$providerCode  = is_string( $provider['code'] ?? null ) ? $provider['code'] : '';
		$providerLabel = is_string( $provider['label'] ?? null ) ? $provider['label'] : '';
		$ownerLabel    = is_string( $provider['owner_label'] ?? null ) && '' !== trim( $provider['owner_label'] )
			? $provider['owner_label']
			: __( 'Owner', 'ran-booster' );
		$managed       = $this->inventory( $data['managed_webhook_repositories'] ?? null );
		$repositories  = $this->inventory( $data['provider_repositories'] ?? $managed );
		$readiness     = is_array( $data['webhook_assistance_readiness'] ?? null ) ? $data['webhook_assistance_readiness'] : array();
		$site          = is_array( $readiness['site'] ?? null ) ? $readiness['site'] : null;
		$endpoint      = rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $providerCode ) );
		$siteEndpoint  = is_string( $site['callback_url'] ?? null ) ? $site['callback_url'] : $endpoint;
		$reasonCodes   = is_array( $site['reason_codes'] ?? null ) ? $site['reason_codes'] : array();
		$siteReady     = null !== $site && 'ready' === ( $site['status'] ?? null );
		$baseUrl       = ( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) )
			. '?page=ran-booster&tab=' . rawurlencode( $providerCode );
		$providerUrl   = static fn ( array $args = array() ): string => add_query_arg( $args, $baseUrl );
		$taskUrls      = array();
		$taskRequests  = array();
		foreach ( array( 'status', 'repositories', 'setup' ) as $task ) {
			$taskUrls[ $task ]     = $providerUrl( array( 'panel' => $task ) );
			$taskRequests[ $task ] = add_query_arg(
				array(
					'page'  => 'ran-booster',
					'tab'   => $providerCode,
					'panel' => $task,
				),
				'admin.php'
			);
		}
		$counts                    = $this->counts( $managed['repositories'] );
		$sharedLabel               = sprintf( /* translators: %s is the repository owner label. */ __( '%s secret', 'ran-booster' ), $ownerLabel );
		$webhookLabel              = sprintf( /* translators: %s is the repository provider name. */ __( '%s webhooks', 'ran-booster' ), $providerLabel );
		$model                     = $this->project(
			$repositories['repositories'],
			$providerCode,
			$providerLabel,
			$webhookLabel,
			$sharedLabel,
			$siteEndpoint,
			$siteReady,
			$this->readinessIndexes( $readiness['repositories'] ?? null, $providerCode ),
			$webhookManagement,
			is_string( $data['requestedRepositoryId'] ?? null ) ? $data['requestedRepositoryId'] : '',
			$providerUrl,
			$taskUrls['repositories'],
			$releaseWorkflow
		);
		$repositorySummary         = $this->repositorySummary( $model['webhook_rows'], $model['rows'] );
		$repositoryView            = in_array( $data['repositoryView'] ?? null, array( 'status', 'branch', 'releases' ), true ) ? $data['repositoryView'] : 'status';
		$repositoryViewUrls        = array();
		$repositoryViewRequestUrls = array();
		foreach ( array( 'status', 'branch', 'releases' ) as $view ) {
			$args        = array(
				'panel'           => 'repositories',
				'repository_view' => $view,
			);
			$requestArgs = array(
				'page'            => 'ran-booster',
				'tab'             => $providerCode,
				'panel'           => 'repositories',
				'repository_view' => $view,
			);
			if ( '' !== $model['requested_id'] ) {
				$args        = array(
					'panel'           => 'repositories',
					'repository'      => $model['requested_id'],
					'repository_view' => $view,
				);
				$requestArgs = array(
					'page'            => 'ran-booster',
					'tab'             => $providerCode,
					'panel'           => 'repositories',
					'repository'      => $model['requested_id'],
					'repository_view' => $view,
				);
			}
			$repositoryViewUrls[ $view ]        = $providerUrl( $args );
			$repositoryViewRequestUrls[ $view ] = add_query_arg( $requestArgs, 'admin.php' );
		}

		return array(
			'providerTask'                     => in_array( $data['providerTask'] ?? null, array( 'repositories', 'setup' ), true ) ? $data['providerTask'] : 'status',
			'managedRepositories'              => $managed,
			'webhookEndpoint'                  => $endpoint,
			'webhookAssistanceProviderCapable' => null !== $site,
			'webhookAssistanceSiteReady'       => $siteReady,
			'repositoryIntegrationAvailable'   => array() !== $model['rows'] || ( ! empty( $provider['capabilities']['webhooks'] ) && ! empty( $provider['webhook_scopes'] ) ),
			'repositoryIntegrationSummary'     => $repositorySummary,
			'webhookSiteReasons'               => $this->siteReasons( $reasonCodes, $siteEndpoint ),
			'webhookHasHardFailure'            => array() !== array_intersect( $reasonCodes, array( 'database_unavailable', 'secrets_storage_unavailable', 'managed_packages_unavailable' ) ),
			'taskUrls'                         => $taskUrls,
			'taskRequestUrls'                  => $taskRequests,
			'wordpressUrlsUrl'                 => admin_url( 'options-general.php' ),
			'webhookOperationsUrl'             => admin_url( 'admin.php?page=ran-booster&tab=documentation#ran-booster-push-to-deploy' ),
			'installPluginUrl'                 => admin_url( 'admin.php?page=ran-booster-plugins-create&provider=' . rawurlencode( $providerCode ) ),
			'installThemeUrl'                  => admin_url( 'admin.php?page=ran-booster-themes-create&provider=' . rawurlencode( $providerCode ) ),
			'automaticPackageCount'            => $counts['automatic'],
			'requestedRepositoryId'            => $model['requested_id'],
			'repositoryView'                   => $repositoryView,
			'repositoryViewUrls'               => $repositoryViewUrls,
			'repositoryViewRequestUrls'        => $repositoryViewRequestUrls,
			'repositoryListUrl'                => $model['list_url'],
			'providerReturnUrl'                => $model['return_url'],
			'repositoryTableRows'              => array_values( $model['rows'] ),
			'repositoryRowCountLabel'          => sprintf( _nx( /* translators: %d is the number of repositories shown. */ '%d repository shown', '%d repositories shown', count( $model['rows'] ), 'Provider table repository count', 'ran-booster' ), count( $model['rows'] ) ),
			'selectedRepositoryRow'            => $model['selected'],
			'activityUrl'                      => admin_url( 'admin.php?page=ran-booster&tab=troubleshooting&panel=activity' ),
		) + $this->copy( $providerLabel, is_array( $provider['webhook_setup'] ?? null ) ? $provider['webhook_setup'] : null, $counts, $sharedLabel );
	}

	/**
	 * @param array<string, array<string, mixed>> $baseRows
	 * @param mixed                               $presented
	 * @return array<string, array<string, mixed>>
	 */
	public function normalize( array $baseRows, mixed $presented, string $providerCode, bool $allowCoreDetailAppend = false ): array {
		if ( ! is_array( $presented ) ) {
			throw new LogicException( 'Provider repository rows must be a keyed array.' );
		}

		$normalizer = new AdminActionNormalizer();
		$rows       = array();
		foreach ( $baseRows as $key => $baseRow ) {
			if ( ! isset( $presented[ $key ] ) || ! is_array( $presented[ $key ] ) ) {
				throw new LogicException( 'Provider filters must preserve every Core repository row.' );
			}

			$row = $presented[ $key ];
			foreach ( array_keys( $row ) as $field ) {
				if ( ! array_key_exists( $field, $baseRow ) && ! in_array( $field, array( 'details', 'actions' ), true ) ) {
					throw new LogicException( 'Provider filters may enrich Core rows only with details and actions.' );
				}
			}
			foreach ( $baseRow as $field => $value ) {
				if ( in_array( $field, array( 'details', 'actions' ), true ) ) {
					continue;
				}
				if ( ! array_key_exists( $field, $row ) || $row[ $field ] !== $value ) {
					throw new LogicException( 'Provider filters must not rewrite Core repository fields.' );
				}
			}

			$baseDetails = is_array( $baseRow['details'] ?? null ) ? array_values( $baseRow['details'] ) : array();
			$details     = is_array( $row['details'] ?? null ) ? array_values( $row['details'] ) : array();
			if ( array_slice( $details, 0, count( $baseDetails ) ) !== $baseDetails ) {
				throw new LogicException( 'Provider filters may append but not replace Core details.' );
			}
			$this->assertDetails( $details, count( $baseDetails ), $allowCoreDetailAppend );

			$baseActions = is_array( $baseRow['actions'] ?? null ) ? $baseRow['actions'] : array();
			$actions     = is_array( $row['actions'] ?? null ) ? $row['actions'] : array();
			foreach ( $baseActions as $actionKey => $baseAction ) {
				if ( ! isset( $actions[ $actionKey ] ) || ! is_array( $actions[ $actionKey ] ) ) {
					throw new LogicException( 'Provider filters must preserve every Core action.' );
				}
				if ( 'core:webhook-management' !== $actionKey ) {
					if ( $actions[ $actionKey ] !== $baseAction ) {
						throw new LogicException( 'Provider filters must not rewrite Core actions.' );
					}
					continue;
				}
				foreach ( $baseAction as $field => $value ) {
					if ( in_array( $field, array( 'url', 'disabled', 'described_by' ), true ) ) {
						continue;
					}
					if ( ! array_key_exists( $field, $actions[ $actionKey ] ) || $actions[ $actionKey ][ $field ] !== $value ) {
						throw new LogicException( 'Webhook management may change only its reserved action state.' );
					}
				}
			}
			$normalizedRow            = $baseRow;
			$normalizedRow['details'] = $details;
			$normalizedRow['actions'] = $normalizer->normalize( $actions );
			$rows[ $key ]             = $normalizedRow;
		}

		foreach ( $presented as $key => $row ) {
			if ( isset( $baseRows[ $key ] ) ) {
				continue;
			}
			if ( ! is_string( $key )
				|| 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}:[a-z0-9:-]{1,127}$/', $key )
				|| ! is_array( $row )
				|| true !== ( $row['historical'] ?? false )
				|| $providerCode !== ( $row['provider_code'] ?? null ) ) {
				throw new LogicException( 'Provider filters may append only namespaced historical rows.' );
			}
			$rowActions = is_array( $row['actions'] ?? null ) ? $row['actions'] : array();
			if ( isset( $rowActions['core:webhook-management'] ) ) {
				throw new LogicException( 'Historical rows must not claim Core actions.' );
			}
			$row['actions'] = $normalizer->normalize( $rowActions );
			foreach ( $row['actions'] as $action ) {
				if ( 'post' === $action['type'] ) {
					throw new LogicException( 'Historical rows may contain link actions only.' );
				}
			}
			$rows[ $key ] = $this->normalizeHistoricalRow( $key, $row, $providerCode );
		}

		return $rows;
	}

	/**
	 * @param list<array<string,mixed>> $repositories
	 * @param array{by_id:array<string,array<string,mixed>>,by_repository:array<string,array<string,mixed>>} $readiness
	 * @param callable(array<string,mixed>):string $providerUrl
	 * @return array{requested_id:string,list_url:string,return_url:string,webhook_rows:array<string,array<string,mixed>>,rows:array<string,array<string,mixed>>,selected:?array}
	 */
	public function project(
		array $repositories,
		string $providerCode,
		string $providerLabel,
		string $providerWebhookSettingsLabel,
		string $sharedSecretLabel,
		string $endpoint,
		bool $siteReady,
		array $readiness,
		?RepositoryWebhookManagementControls $webhookManagement,
		string $requestedId,
		callable $providerUrl,
		string $listUrl,
		?ReleaseWorkflowControls $releaseWorkflow = null
	): array {
		$returnUrl   = '' === $requestedId ? $listUrl : $providerUrl(
			array(
				'panel'      => 'repositories',
				'repository' => $requestedId,
			)
		);
		$rows        = array();
		$projections = array();
		$issueLabels = array(
			'repository_identity_unavailable' => __( 'Repository identity unavailable', 'ran-booster' ),
			'repository_identity_conflict'    => __( 'Repository identity conflict', 'ran-booster' ),
			'repository_locator_invalid'      => __( 'Repository address invalid', 'ran-booster' ),
		);
		foreach ( $repositories as $index => $repository ) {
			if ( ! is_array( $repository ) ) {
				continue; }
			$managedId       = is_string( $repository['repository_id'] ?? null ) ? $repository['repository_id'] : '';
			$locator         = is_string( $repository['target'] ?? null ) ? $repository['target'] : '';
			$source          = is_string( $repository['source'] ?? null ) ? $repository['source'] : 'branch';
			$isRelease       = 'release_asset' === $source;
			$sourceConflict  = 'mixed' === $source;
			$hasBranch       = in_array( $source, array( 'branch', 'mixed' ), true );
			$isMixed         = 'mixed' === $source;
			$historical      = ! empty( $repository['historical'] ) || '' === trim( $managedId );
			$retained        = $isRelease && is_array( $repository['retained_webhook'] ?? null ) ? $repository['retained_webhook'] : array();
			$branchConsumers = array_values( array_filter( $retained['branch_package_references'] ?? array(), 'is_string' ) );
			$readinessRow    = ! $isRelease && '' !== $managedId && isset( $readiness['by_id'][ $managedId ] )
				? $readiness['by_id'][ $managedId ]
				: ( ! $isRelease ? ( $readiness['by_repository'][ strtolower( $locator ) ] ?? null ) : null );
			$repositoryId    = $isRelease
				? $managedId
				: ( is_string( $readinessRow['repository_id'] ?? null ) && '' !== $readinessRow['repository_id'] ? $readinessRow['repository_id'] : $managedId );
			$rowKey          = '' !== $repositoryId ? $repositoryId : 'repository:' . hash( 'sha256', $providerCode . '|' . strtolower( $locator ) . '|' . $source );
			$reasonCodes     = is_array( $readinessRow['reason_codes'] ?? null ) ? $readinessRow['reason_codes'] : array();
			if ( true === ( $repository['identity_conflict'] ?? false ) && ! in_array( 'repository_identity_conflict', $reasonCodes, true ) ) {
				$reasonCodes[] = 'repository_identity_conflict';
			}
			$historical              = $historical || array() !== array_intersect( $reasonCodes, array( 'repository_identity_unavailable', 'repository_identity_conflict' ) );
			$issues                  = array_values( array_filter( array_map( static fn ( mixed $code ): ?string => is_string( $code ) ? ( $issueLabels[ $code ] ?? null ) : null, $reasonCodes ) ) );
			$coverage                = $isRelease
				? ( is_string( $retained['local_secret_coverage'] ?? null ) ? $retained['local_secret_coverage'] : 'unknown' )
				: ( is_string( $readinessRow['local_secret_coverage'] ?? null ) ? $readinessRow['local_secret_coverage'] : 'unknown' );
			$repositoryPolicies      = is_array( $repository['deployment_policies'] ?? null ) ? $repository['deployment_policies'] : array();
			$repositoryReferences    = is_array( $repository['package_references'] ?? null ) ? $repository['package_references'] : array();
			$readinessPolicies       = is_array( $readinessRow['deployment_policies'] ?? null ) ? $readinessRow['deployment_policies'] : null;
			$readinessReferences     = is_array( $readinessRow['package_references'] ?? null ) ? $readinessRow['package_references'] : null;
			$policies                = $isMixed ? $repositoryPolicies : ( $readinessPolicies ?? $repositoryPolicies );
			$references              = $isMixed ? $repositoryReferences : ( $readinessReferences ?? $repositoryReferences );
			$references              = array_values( array_filter( $references, 'is_string' ) );
			$branchReferences        = is_array( $repository['branch_package_references'] ?? null )
				? array_values( array_filter( $repository['branch_package_references'], 'is_string' ) )
				: ( $hasBranch ? $references : array() );
			$packageSummaries        = $this->packageSummaries( $repository['package_summaries'] ?? array() );
			$packageSummariesOmitted = max( 0, (int) ( $repository['package_summaries_omitted'] ?? 0 ) );
			$inventoryIncomplete     = 0 < $packageSummariesOmitted;
			$automatic               = (int) ( $policies['automatic'] ?? $repository['automatic_count'] ?? 0 );
			$manual                  = (int) ( $policies['manual'] ?? 0 );
			$disabled                = (int) ( $policies['disabled'] ?? 0 );
			$policyBadges            = array(
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
				$policyBadges = array(
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
			$typeLabel = match ( count( $types ) ) {
				0 => __( 'Package', 'ran-booster' ), 1 => (string) array_key_first( $types ), default => __( 'Plugins and themes', 'ran-booster' ) };
			$reasonId = 'ran-booster-provider-readiness-reason-' . (int) $index;
			$statuses = array();
			if ( '' !== ( $issues[0] ?? '' ) ) {
				$statuses[] = array(
					'label' => $issues[0],
					'tone'  => 'error',
					'id'    => $reasonId,
				); }
			if ( $sourceConflict ) {
				$statuses[] = array(
					'label' => __( 'Conflicting sources', 'ran-booster' ),
					'tone'  => 'warning',
					'id'    => $reasonId . '-source-conflict',
				);
			}
			$statuses[] = array(
				'label' => match ( $coverage ) {
				'repository' => __( 'Repository secret', 'ran-booster' ), 'shared' => $sharedSecretLabel, 'none' => __( 'No secret', 'ran-booster' ), default => __( 'Secret coverage unavailable', 'ran-booster' ) },
				'tone'  => in_array( $coverage, array( 'repository', 'shared' ), true ) ? 'ok' : 'warning',
			);
			if ( ! $siteReady ) {
				$statuses[] = array(
					'label' => __( 'Push-to-Deploy disabled', 'ran-booster' ),
					'tone'  => 'error',
					'id'    => $reasonId . '-site',
				); }
			if ( $isRelease ) {
				$statuses = array(); }
			$nonZero         = array_filter(
				array(
					'automatic' => $automatic,
					'manual'    => $manual,
					'disabled'  => $disabled,
				),
				static fn ( int $count ): bool => 0 < $count
			);
			$managementLabel = 1 === count( $nonZero ) ? match ( (string) array_key_first( $nonZero ) ) {
				'automatic' => __( 'Automatic', 'ran-booster' ), 'manual' => __( 'Manual', 'ran-booster' ), default => __( 'Disabled', 'ran-booster' ) } : __( 'Mixed policies', 'ran-booster' );
			$managementDetail = match ( $coverage ) {
				'repository' => __( 'Repository secret', 'ran-booster' ), 'shared' => $sharedSecretLabel, 'none' => __( 'No secret', 'ran-booster' ), 'not_applicable' => '', default => __( 'Secret coverage unavailable', 'ran-booster' ) };
			$managementTone = in_array( $coverage, array( 'repository', 'shared' ), true ) ? 'ok' : 'warning';
			$consequence    = match ( true ) {
				$sourceConflict => __( 'Conflicting sources. Review the package settings before using release workflow.', 'ran-booster' ),
				$isRelease && array() !== $branchConsumers => __( 'This package ignores pushes. Branch-managed packages in this repository still use webhook setup.', 'ran-booster' ),
				$isRelease && in_array( $coverage, array( 'repository', 'shared' ), true ) => __( 'This package ignores pushes. Local signing setup is retained for an easier return to Branch.', 'ran-booster' ),
				$isRelease => __( 'Pushes are ignored.', 'ran-booster' ),
				1 === count( $nonZero ) && isset( $nonZero['disabled'] ) => __( 'Push-to-Deploy disabled; pushes are ignored.', 'ran-booster' ),
				'' !== ( $issues[0] ?? '' ) => (string) $issues[0],
				! $siteReady => __( 'Push-to-Deploy is unavailable until the site-level readiness issue is resolved.', 'ran-booster' ),
				'none' === $coverage => __( 'Push-to-Deploy is blocked until a signing secret is selected.', 'ran-booster' ),
				1 === count( $nonZero ) && isset( $nonZero['automatic'] ) => __( 'Push-to-Deploy enabled; signed pushes can queue eligible packages.', 'ran-booster' ),
				1 === count( $nonZero ) && isset( $nonZero['manual'] ) => __( 'Push-to-Deploy remains off until the package Updates setting is Automatic.', 'ran-booster' ),
				default => __( 'Only Automatic packages can respond to signed pushes.', 'ran-booster' ),
			};
			if ( $isRelease ) {
				$managementLabel  = __( 'Releases', 'ran-booster' );
				$managementDetail = __( 'Push-to-Deploy unavailable', 'ran-booster' );
				$managementTone   = 'info'; }
			if ( $inventoryIncomplete ) {
				$managementLabel  = __( 'Package inventory incomplete', 'ran-booster' );
				$managementDetail = __( 'Workflow controls disabled', 'ran-booster' );
				$managementTone   = 'warning';
				$consequence      = sprintf( /* translators: %d is the number of omitted package summaries. */ __( '%d package summary is not shown. Refresh the repository inventory before relying on aggregate deployment state or workflow controls.', 'ran-booster' ), $packageSummariesOmitted );
			}
			$releaseReasonId = ( $isRelease || $sourceConflict ) && '' !== $consequence ? $reasonId . '-release-source' : '';
			$describedBy     = array_filter( array( $releaseReasonId, '' !== ( $issues[0] ?? '' ) ? $reasonId : '', ! $siteReady && ! $isRelease ? $reasonId . '-site' : '' ) );
			$actions         = ! $inventoryIncomplete && null !== $webhookManagement && $webhookManagement->supportsProvider( $providerCode ) && $hasBranch && ! $historical
				? $this->webhookManagementAction( $locator, $describedBy )
				: array();
			$secretTarget    = 'shared' === $coverage ? (string) strtok( $locator, '/' ) : $locator;
			$secretLink      = 'none' === $coverage ? array(
				'label'  => __( 'Add repository secret', 'ran-booster' ),
				'url'    => $providerUrl(
					array_filter(
						array(
							'panel'              => 'repositories',
							'repository'         => $repositoryId,
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
					'url'    => $providerUrl(
						array(
							'view' => 'secrets',
							's'    => $secretTarget,
						)
					),
					'modal'  => '',
					'scope'  => '',
					'target' => '',
				) : null );
			$detailUrl       = '' !== $repositoryId && ! $historical
				? $providerUrl(
					array(
						'panel'      => 'repositories',
						'repository' => $repositoryId,
					)
				)
				: '';
			if ( ! $inventoryIncomplete && ! $historical ) {
				$this->appendRepositoryActions( $actions, $repository, $references, $isRelease, $coverage, $providerWebhookSettingsLabel, $releaseReasonId, $locator, $detailUrl );
			}
			$rows[ $rowKey ] = array(
				'key'                           => $rowKey,
				'provider_code'                 => $providerCode,
				'repository_id'                 => $repositoryId,
				'historical'                    => $historical,
				'provider_label'                => $providerLabel,
				'repository'                    => $locator,
				'repository_url'                => is_string( $repository['repository_url'] ?? null ) ? $repository['repository_url'] : '',
				'detail_url'                    => $detailUrl,
				'package_type_label'            => $typeLabel,
				'source_key'                    => $source,
				'source_label'                  => match ( $source ) {
					'mixed' => __( 'Conflicting sources', 'ran-booster' ),
					'release_asset' => __( 'Releases', 'ran-booster' ),
					default => __( 'Branch', 'ran-booster' ),
				},
				'management_label'              => $managementLabel,
				'management_detail'             => $managementDetail,
				'management_tone'               => $managementTone,
				'consequence'                   => $consequence,
				'consequence_id'                => $releaseReasonId,
				'types'                         => array_values( $types ),
				'policies'                      => $policyBadges,
				'package_references'            => $references,
				'has_branch_consumer'           => array() !== $branchReferences,
				'has_automatic_branch_consumer' => ! $inventoryIncomplete && true === ( $repository['has_automatic_branch_consumer'] ?? false ),
				'package_summaries'             => $packageSummaries,
				'package_summaries_omitted'     => $packageSummariesOmitted,
				'statuses'                      => $statuses,
				'status_links'                  => null === $secretLink ? array() : array( $secretLink ),
				'actions'                       => $actions,
			);
			if ( $hasBranch && ! $historical ) {
				$projections[ $rowKey ] = array(
					'provider_code'         => $providerCode,
					'repository_id'         => $repositoryId,
					'repository'            => $locator,
					'label'                 => $locator,
					'package_references'    => $branchReferences,
					'deployment_policies'   => array(
						'automatic' => $automatic,
						'manual'    => $manual,
						'disabled'  => $disabled,
					),
					'endpoint'              => $endpoint,
					'eligible'              => is_array( $readinessRow ) && true === ( $readinessRow['eligible'] ?? false ) && $siteReady && '' !== $repositoryId,
					'reason_codes'          => $reasonCodes,
					'local_secret_coverage' => $coverage,
				);
			}
		}
		$coreRows    = null !== $webhookManagement
			? $webhookManagement->enrichRepositoryRows( $rows, $providerCode, $projections, $returnUrl )
			: $rows;
		$webhookRows = $this->normalize( $rows, $coreRows, $providerCode, true );
		$coreRows    = null !== $releaseWorkflow
			? $releaseWorkflow->enrichRepositoryRows( $webhookRows, $providerCode, $projections, $returnUrl )
			: $webhookRows;
		$coreRows    = $this->normalize( $webhookRows, $coreRows, $providerCode, true );
		try {
			$presented = apply_filters(
				'ran_booster_provider_repository_rows',
				$coreRows,
				$providerCode,
				$projections,
				$returnUrl
			);
			$rows      = $this->normalize( $coreRows, $presented, $providerCode );
		} catch ( Throwable $failure ) {
			$rows = $coreRows;
			BoosterLogger::logException(
				'provider repository row enrichment unavailable',
				$failure,
				array(
					'source'   => 'admin',
					'step'     => 'provider_repository_row_enrichment',
					'provider' => $providerCode,
				)
			);
		}
		$selected = null;
		foreach ( $rows as $row ) {
			if ( '' !== $requestedId && false === ( $row['historical'] ?? false ) && $requestedId === ( $row['repository_id'] ?? null ) ) {
				$selected = $row;
				break; }
		}

		return array(
			'requested_id' => $requestedId,
			'list_url'     => $listUrl,
			'return_url'   => $returnUrl,
			'webhook_rows' => $webhookRows,
			'rows'         => $rows,
			'selected'     => $selected,
		);
	}

	/** @param list<string> $describedBy @return array<string,array<string,mixed>> */
	private function webhookManagementAction( string $repository, array $describedBy ): array {
		return array(
			'core:webhook-management' => array(
				'key'           => 'core:webhook-management',
				'label'         => __( 'Manage webhook', 'ran-booster' ),
				'type'          => 'link',
				'url'           => '',
				'hidden'        => array(),
				'disabled'      => true,
				'external'      => false,
				'described_by'  => implode( ' ', $describedBy ),
				'screen_reader' => $repository,
			),
		);
	}

	/** @param array<string,array<string,mixed>> $actions @param array<string,mixed> $repository @param list<string> $references */
	private function appendRepositoryActions( array &$actions, array $repository, array $references, bool $isRelease, string $coverage, string $providerLabel, string $reasonId, string $locator, string $detailUrl ): void {
		if ( $isRelease ) {
			$url             = '' === $detailUrl ? '' : add_query_arg( 'repository_view', 'branch', $detailUrl ) . '#ran-booster-repository-webhook-setup-heading';
			$key             = in_array( $coverage, array( 'repository', 'shared' ), true ) ? 'core:webhook-cleanup-review' : 'core:provider-webhooks';
			$actions[ $key ] = array(
				'key'           => $key,
				'label'         => 'core:webhook-cleanup-review' === $key ? __( 'Review webhook cleanup', 'ran-booster' ) : $providerLabel,
				'type'          => 'link',
				'url'           => $url,
				'hidden'        => array(),
				'disabled'      => '' === $url,
				'external'      => 'core:provider-webhooks' === $key,
				'described_by'  => $reasonId,
				'screen_reader' => $locator,
			);
		} elseif ( is_string( $repository['webhook_settings_url'] ?? null ) ) {
			$actions['core:provider-webhooks'] = array(
				'key'           => 'core:provider-webhooks',
				'label'         => $providerLabel,
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
			$isPlugin = str_ends_with( strtolower( $reference ), '.php' );
			if ( ! $isPlugin && 1 !== preg_match( '/^[A-Za-z0-9_.-]+$/', $reference ) ) {
				continue; }
			$url = ( is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' ) ) . '?page=' . ( $isPlugin ? 'ran-booster-plugins' : 'ran-booster-themes' ) . '&package=' . rawurlencode( $reference );
			if ( ! $isRelease ) {
				$url = add_query_arg( 'source_view', 'branch', $url ) . '#ran-booster-branch-readiness'; }
			$key             = 'core:package-' . substr( hash( 'sha256', $reference ), 0, 16 );
			$actions[ $key ] = array(
				'key'           => $key,
				'label'         => $isPlugin ? __( 'Plugin settings', 'ran-booster' ) : __( 'Theme settings', 'ran-booster' ),
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
	private function assertDetails( array $details, int $coreDetailCount = 0, bool $allowCoreDetailAppend = false ): void {
		if ( count( $details ) > 20 ) {
			throw new LogicException( 'Repository details must be bounded.' );
		}

		foreach ( $details as $index => $detail ) {
			if ( ! is_array( $detail ) ) {
				throw new LogicException( 'Repository details must be display maps.' );
			}
			$key = $this->boundedString( $detail['key'] ?? '', 96, true );
			if ( ! $allowCoreDetailAppend && $index >= $coreDetailCount && str_starts_with( $key, 'core:' ) ) {
				throw new LogicException( 'Provider filters may not append Core detail keys.' );
			}
			$this->boundedString( $detail['label'] ?? null, 96, false );
			$this->boundedString( $detail['value'] ?? null, 255, true );
			$tone = $this->boundedString( $detail['tone'] ?? '', 16, true );
			if ( '' !== $tone && ! in_array( $tone, $this->tones(), true ) ) {
				throw new LogicException( 'Repository detail tones are invalid.' );
			}
			$category = $this->boundedString( $detail['category'] ?? '', 32, true );
			if ( '' !== $category && ! in_array( $category, array( 'webhook', 'release_workflow' ), true ) ) {
				throw new LogicException( 'Repository detail categories are invalid.' );
			}
			$this->boundedString( $detail['datetime'] ?? '', 64, true );
			$this->boundedString( $detail['state'] ?? '', 64, true );
			if ( isset( $detail['recorded'] ) && ! is_bool( $detail['recorded'] ) ) {
				throw new LogicException( 'Repository detail recorded flags must be boolean.' );
			}
			if ( isset( $detail['review_summary'] ) && ! is_bool( $detail['review_summary'] ) ) {
				throw new LogicException( 'Repository detail review summary flags must be boolean.' );
			}
		}
	}

	/**
	 * @param array<string,array<string,mixed>> $webhookRows Core and webhook-management rows, before provider extensions.
	 * @param array<string,array<string,mixed>> $rows        Provider-enriched rows.
	 * @return array{repositories:int,recorded_hooks:int,needs_review:int,release_packages:int,release_repositories:int,release_totals_incomplete:bool,release_workflows_inventory_incomplete:bool,release_workflows_needing_review:int}
	 */
	private function repositorySummary( array $webhookRows, array $rows ): array {
		$recordedHooks                 = 0;
		$needsReview                   = 0;
		$releasePackages               = 0;
		$releaseRepositories           = 0;
		$releaseTotalsIncomplete       = false;
		$releaseWorkflowsIncomplete    = false;
		$releaseWorkflowsNeedingReview = 0;
		$releaseWorkflowKeys           = array();
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
				++$recordedHooks;
			}
			$automaticBranch = true === ( $row['has_automatic_branch_consumer'] ?? false );
			if ( ( $automaticBranch && ! $recorded ) || ( $recorded && ! $healthy ) ) {
				++$needsReview;
			}
		}
		foreach ( $rows as $row ) {
			if ( true === ( $row['historical'] ?? false ) ) {
				continue;
			}
			$source                      = is_string( $row['source_key'] ?? null ) ? $row['source_key'] : '';
			$packageSummariesOmitted     = max( 0, (int) ( $row['package_summaries_omitted'] ?? 0 ) );
			$releasePackagesInRepository = 0;
			foreach ( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array() as $summary ) {
				if ( is_array( $summary ) && 'release_asset' === ( $summary['source'] ?? null ) ) {
					++$releasePackagesInRepository;
				}
			}
			if ( 'release_asset' === $source && 0 < $packageSummariesOmitted ) {
				$releasePackagesInRepository = is_array( $row['package_references'] ?? null )
					? count( array_filter( $row['package_references'], 'is_string' ) )
					: 0;
				if ( 0 === $releasePackagesInRepository ) {
					$releasePackagesInRepository = count( is_array( $row['package_summaries'] ?? null ) ? $row['package_summaries'] : array() ) + $packageSummariesOmitted;
				}
				$releaseWorkflowsIncomplete = true;
			} elseif ( 'mixed' === $source && 0 < $packageSummariesOmitted ) {
				$releasePackagesInRepository = max( 1, $releasePackagesInRepository );
				$releaseTotalsIncomplete     = true;
				$releaseWorkflowsIncomplete  = true;
			}
			if ( 0 < $releasePackagesInRepository ) {
				$releasePackages += $releasePackagesInRepository;
				++$releaseRepositories;
			}
			if ( 0 < $packageSummariesOmitted ) {
				continue;
			}
			foreach ( is_array( $row['details'] ?? null ) ? $row['details'] : array() as $detail ) {
				if ( ! is_array( $detail )
					|| ! $this->isReleaseWorkflowDetail( $detail )
					|| ! in_array( $detail['tone'] ?? null, array( 'pending', 'warning' ), true ) ) {
					continue;
				}
				$key = is_string( $detail['key'] ?? null ) ? $detail['key'] : '';
				if ( '' === $key || isset( $releaseWorkflowKeys[ $key ] ) ) {
					continue;
				}
				$releaseWorkflowKeys[ $key ] = true;
				++$releaseWorkflowsNeedingReview;
			}
		}

		return array(
			'repositories'                           => count( $rows ),
			'recorded_hooks'                         => $recordedHooks,
			'needs_review'                           => $needsReview,
			'release_packages'                       => $releasePackages,
			'release_repositories'                   => $releaseRepositories,
			'release_totals_incomplete'              => $releaseTotalsIncomplete,
			'release_workflows_inventory_incomplete' => $releaseWorkflowsIncomplete,
			'release_workflows_needing_review'       => $releaseWorkflowsNeedingReview,
		);
	}

	/** @param array<string, mixed> $detail */
	private function isReleaseWorkflowDetail( array $detail ): bool {
		return 'release_workflow' === ( $detail['kind'] ?? null )
			|| ( 'release_workflow' === ( $detail['category'] ?? null ) && true === ( $detail['review_summary'] ?? false ) );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalizeHistoricalRow( string $key, array $row, string $providerCode ): array {
		$repositoryId = $this->boundedString( $row['repository_id'] ?? null, 191, false );
		$details      = is_array( $row['details'] ?? null ) ? array_values( $row['details'] ) : array();
		$this->assertDetails( $details );

		return array(
			'key'                => $key,
			'provider_code'      => $providerCode,
			'provider_label'     => $this->boundedString( $row['provider_label'] ?? null, 96, false ),
			'repository_id'      => $repositoryId,
			'repository'         => $this->boundedString( $row['repository'] ?? null, 255, false ),
			'repository_url'     => $this->safeUrl( $row['repository_url'] ?? '' ),
			'detail_url'         => '',
			'historical'         => true,
			'types'              => $this->badges( $row['types'] ?? array() ),
			'package_message'    => $this->boundedString( $row['package_message'] ?? '', 255, true ),
			'package_references' => $this->strings( $row['package_references'] ?? array(), 20, 255 ),
			'policies'           => $this->badges( $row['policies'] ?? array() ),
			'statuses'           => $this->badges( $row['statuses'] ?? array(), true ),
			'status_links'       => $this->links( $row['status_links'] ?? array() ),
			'status_message'     => $this->boundedString( $row['status_message'] ?? '', 255, true ),
			'action_message'     => $this->boundedString( $row['action_message'] ?? '', 255, true ),
			'actions'            => $row['actions'],
			'details'            => $details,
		);
	}

	/**
	 * @return list<array<string, string>>
	 */
	private function badges( mixed $badges, bool $allowRelationships = false ): array {
		if ( ! is_array( $badges ) || count( $badges ) > 20 ) {
			throw new LogicException( 'Repository badges must be bounded.' );
		}

		$normalized = array();
		foreach ( $badges as $badge ) {
			if ( ! is_array( $badge ) ) {
				throw new LogicException( 'Repository badges must be display maps.' );
			}
			$tone = $this->boundedString( $badge['tone'] ?? 'neutral', 16, false );
			if ( ! in_array( $tone, $this->tones(), true ) ) {
				throw new LogicException( 'Repository badge tones are invalid.' );
			}
			$item = array(
				'label' => $this->boundedString( $badge['label'] ?? null, 96, false ),
				'tone'  => $tone,
			);
			if ( $allowRelationships ) {
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
				'label'  => $this->boundedString( $link['label'] ?? null, 96, false ),
				'url'    => $this->safeUrl( $link['url'] ?? null, false ),
				'modal'  => $this->boundedString( $link['modal'] ?? '', 64, true ),
				'scope'  => $this->boundedString( $link['scope'] ?? '', 64, true ),
				'target' => $this->boundedString( $link['target'] ?? '', 255, true ),
			);
		}

		return $normalized;
	}

	/** @return list<string> */
	private function strings( mixed $values, int $maximumItems, int $maximumLength ): array {
		if ( ! is_array( $values ) || count( $values ) > $maximumItems ) {
			throw new LogicException( 'Repository string lists must be bounded.' );
		}

		return array_map(
			fn ( mixed $value ): string => $this->boundedString( $value, $maximumLength, false ),
			array_values( $values )
		);
	}

	/**
	 * @return list<array{type:string,identifier:string,display_name:string,settings_url:string,source:string,source_revision:int,branch:string,subdirectory:string,deployment_policy:string}>
	 */
	private function packageSummaries( mixed $summaries ): array {
		if ( ! is_array( $summaries ) || count( $summaries ) > 20 ) {
			throw new LogicException( 'Repository package summaries must be bounded.' );
		}

		$normalized = array();
		foreach ( $summaries as $summary ) {
			if ( ! is_array( $summary ) ) {
				throw new LogicException( 'Repository package summaries must be display maps.' );
			}
			$type     = $this->boundedString( $summary['type'] ?? null, 16, false );
			$source   = $this->boundedString( $summary['source'] ?? null, 32, false );
			$policy   = $this->boundedString( $summary['deployment_policy'] ?? null, 16, false );
			$revision = is_int( $summary['source_revision'] ?? null ) ? $summary['source_revision'] : 0;
			if ( ! in_array( $type, array( 'plugin', 'theme' ), true )
				|| ! in_array( $source, array( 'branch', 'release_asset' ), true )
				|| ! in_array( $policy, array( 'automatic', 'manual', 'disabled' ), true )
				|| 1 > $revision ) {
				throw new LogicException( 'Repository package summary values are invalid.' );
			}
			$normalized[] = array(
				'type'              => $type,
				'identifier'        => $this->boundedString( $summary['identifier'] ?? null, 255, false ),
				'display_name'      => $this->boundedString( $summary['display_name'] ?? null, 255, false ),
				'settings_url'      => $this->safeUrl( $summary['settings_url'] ?? null, false ),
				'source'            => $source,
				'source_revision'   => $revision,
				'branch'            => $this->boundedString( $summary['branch'] ?? '', 255, true ),
				'subdirectory'      => $this->boundedString( $summary['subdirectory'] ?? '', 255, true ),
				'deployment_policy' => $policy,
			);
		}

		return $normalized;
	}

	private function safeUrl( mixed $value, bool $allowEmpty = true ): string {
		$url = $this->boundedString( $value, 2048, $allowEmpty );
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
		$relationship = $this->boundedString( $value, 255, true );
		$pattern      = $multiple
			? '/^[A-Za-z][A-Za-z0-9_-]*(?: [A-Za-z][A-Za-z0-9_-]*)*$/'
			: '/^[A-Za-z][A-Za-z0-9_-]*$/';
		if ( '' !== $relationship && 1 !== preg_match( $pattern, $relationship ) ) {
			throw new LogicException( 'Repository relationship identifiers are invalid.' );
		}

		return $relationship;
	}

	private function boundedString( mixed $value, int $maximum, bool $allowEmpty ): string {
		if ( ! is_string( $value )
			|| ( ! $allowEmpty && '' === trim( $value ) )
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
	private function readinessIndexes( mixed $candidates, string $providerCode ): array {
		$byId         = array();
		$byRepository = array();
		foreach ( is_array( $candidates ) ? $candidates : array() as $candidate ) {
			if ( ! is_array( $candidate ) || $providerCode !== ( $candidate['provider_code'] ?? null ) ) {
				continue;
			}
			$id         = is_string( $candidate['repository_id'] ?? null ) ? $candidate['repository_id'] : '';
			$repository = is_string( $candidate['repository'] ?? null ) ? strtolower( $candidate['repository'] ) : '';
			if ( '' !== $id ) {
				$byId[ $id ] = $candidate; }
			if ( '' !== $repository ) {
				$byRepository[ $repository ] = $candidate; }
		}

		return array(
			'by_id'         => $byId,
			'by_repository' => $byRepository,
		);
	}

	/** @param list<mixed> $codes @return list<string> */
	private function siteReasons( array $codes, string $endpoint ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Display-safe endpoint parsing performs no network I/O.
		$host    = parse_url( $endpoint, PHP_URL_HOST );
		$isLocal = is_string( $host ) && ( in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1' ), true ) || str_ends_with( strtolower( $host ), '.local' ) );
		$labels  = array(
			'database_unavailable'           => __( 'Booster database storage must be healthy before Push-to-Deploy can run.', 'ran-booster' ),
			'secrets_storage_unavailable'    => __( 'Encrypted credential storage must be healthy before Push-to-Deploy can verify signed deliveries.', 'ran-booster' ),
			'callback_requires_public_https' => $isLocal ? __( 'This site uses a local URL, so providers cannot deliver webhooks to it. Configure a public HTTPS site URL before using Push-to-Deploy.', 'ran-booster' ) : __( 'The payload URL must use public HTTPS before providers can deliver webhooks to it.', 'ran-booster' ),
			'managed_packages_unavailable'   => __( 'Booster could not read the managed package inventory needed for Push-to-Deploy.', 'ran-booster' ),
		);

		return array_values( array_filter( array_map( static fn ( mixed $code ): ?string => is_string( $code ) ? ( $labels[ $code ] ?? null ) : null, $codes ) ) );
	}

	/** @param array{repositories:int,packages:int,automatic:int} $counts */
	private function copy( string $label, ?array $setup, array $counts, string $sharedSecretLabel ): array {
		$automaticLabel = 0 < $counts['automatic']
			? sprintf( _n( /* translators: %d is the number of packages with Automatic updates. */ '%d package is Automatic', '%d packages are Automatic', $counts['automatic'], 'ran-booster' ), $counts['automatic'] )
			: __( 'None set to Automatic', 'ran-booster' );

		return array(
			'providerPushDescription'      => sprintf( /* translators: %s is the repository provider name. */ __( '%s push webhooks can trigger managed branch deployments whose Updates setting is Automatic.', 'ran-booster' ), $label ),
			'automaticPackageLabel'        => $automaticLabel,
			'managedPackageDescription'    => sprintf( _n( /* translators: 1: number of repositories, 2: number of managed packages. */ '%1$d repository contains %2$d managed package.', '%1$d repositories contain %2$d managed packages.', $counts['repositories'], 'ran-booster' ), $counts['repositories'], $counts['packages'] ),
			'providerInstructionsLabel'    => sprintf( /* translators: %s is the repository provider name. */ __( 'Open %s instructions', 'ran-booster' ), $label ),
			'secretChoiceDescription'      => sprintf( /* translators: %s is the shared secret label. */ __( 'Use a saved %s or create a repository-scoped secret when isolation is required.', 'ran-booster' ), strtolower( $sharedSecretLabel ) ),
			'createProviderWebhookLabel'   => sprintf( /* translators: %s is the repository provider name. */ __( 'Create the %s webhook', 'ran-booster' ), $label ),
			'manualSetupDescription'       => null === $setup ? '' : sprintf( /* translators: 1: repository provider name, 2: provider webhook settings location. */ __( 'In %1$s, go to %2$s and create the remote webhook.', 'ran-booster' ), $label, $setup['location'] ),
			'repositoryWebhookDescription' => sprintf(
				/* translators: %s is the repository provider name. */
				__( 'Each repository needs its own %s webhook. A saved shared secret may serve multiple repositories.', 'ran-booster' ),
				$label
			),
			'emptyRepositoryDescription'   => sprintf(
				/* translators: %s is the repository provider name. */
				__( 'No managed %s repositories are available yet. Install a package to add its repository.', 'ran-booster' ),
				$label
			),
		);
	}
	// phpcs:enable WordPress.WP.I18n.MissingTranslatorsComment
}
