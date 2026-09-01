<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use RAN\AddOn\ReleaseTracking\ReleaseTrackingFacade;
use RAN\AddOn\ReleaseTracking\ReleaseTrackingStatus;
use Throwable;

/** GitHub API 2 assessment, preview, mutation, readback and outcome owner. */
final class WorkflowApplicationCoordinator {
	private const PREVIEW_PREFIX         = 'ran_booster_github_release_workflow_preview_';
	private const PREVIEW_FIELDS         = array( 'schema_version', 'kind', 'user_id', 'type', 'identifier', 'revision', 'repo_id', 'repository', 'default_branch', 'base_sha', 'preflight_channel', 'profile_id', 'pack_version', 'manifest_hash', 'new_template_identity', 'old_template_identity', 'bundle_hash', 'changed_path_hash', 'allowlist_hash', 'changes' );
	private const IDENTITY_FIELDS        = array( 'repository_name', 'repository_id', 'release_id', 'release_tag', 'release_commit', 'release_target', 'tag_target', 'release_draft', 'release_prerelease', 'release_immutable', 'asset_count', 'asset_id', 'asset_name', 'asset_state', 'asset_content_type', 'asset_size', 'asset_digest', 'asset_sha256' );
	private const PREFLIGHT_REASON_CODES = array( 'provider_unavailable', 'no_releases', 'invalid_release', 'release_identity_mismatch', 'release_incompatible', 'release_version_mismatch', 'package_header_missing', 'package_header_invalid', 'package_archive_unreadable', 'package_zip_extension_unavailable', 'package_archive_size_invalid', 'package_archive_too_large', 'package_archive_path_unsafe', 'package_archive_path_duplicate', 'package_archive_root_invalid', 'package_archive_entry_duplicate', 'package_archive_entry_limit', 'release_version_invalid', 'package_update_uri_missing', 'package_update_uri_invalid', 'package_compatibility_missing', 'package_compatibility_invalid', 'package_header_ambiguous' );

	public function __construct(
		private readonly ReleaseTrackingFacade $releases,
		private readonly GitHubRepositoryClient $github,
		private readonly TemplatePackRepositoryClient $templates,
		private readonly SourceReadyAssessor $assessor,
		private readonly SetupRecordStore $records
	) {
	}

	public function inspect( ReleaseTrackingStatus $status, string $channel, string $nonce, string $token ): array {
		if ( ! in_array( $channel, array( 'stable', 'prerelease' ), true ) || $this->records->occupied( $status->providerRepositoryId() ) ) {
			return $this->result( $status, 'invalid_request' );
		}
		$preflight = $this->releases->assessmentPreflight( $status->type(), $status->identifier(), $status->sourceRevision(), $channel, $nonce );
		if ( null === $preflight ) {
			return $this->result( $status, 'preflight_unavailable', false, '', 'release_preflight', 'preflight_contract_unavailable' );
		}
		if ( 'preflight_unavailable' === $preflight->code() ) {
			$reason = '' !== $preflight->reasonCode() ? $preflight->reasonCode() : 'provider_unavailable';
			return $this->result( $status, 'preflight_unavailable', false, '', 'release_preflight', $reason );
		}
		if ( ! $this->acceptsBootstrapPreflight( $preflight->code() ) ) {
			return $this->result( $status, $preflight->code(), 'ready' === $preflight->code(), '', 'release_preflight', $this->preflightDiagnostic( $preflight->code(), $preflight->reasonCode() ) );
		}
		$remote = $this->bootstrapBundle( $status, $token, null, true );
		if ( 'ok' !== $remote['code'] ) {
			return $this->result( $status, $remote['code'], 'release_automation_present' === $remote['code'] );
		}
		$preview = $this->previewRecord( 'bootstrap', $status, $remote, $channel );
		try {
			$key = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			return $this->result( $status, 'remote_unavailable', false, '', 'unexpected' );
		}
		return set_transient( self::PREVIEW_PREFIX . $key, $preview, 15 * MINUTE_IN_SECONDS )
			? $this->result( $status, 'inspected', true, $key ) : $this->result( $status, 'remote_unavailable', false, '', 'preview_storage' );
	}

	/** @param array<string,string> $preflightNonces */
	public function setup( ReleaseTrackingStatus $status, string $key, string $confirmation, array $preflightNonces, string $token ): array {
		$preview = $this->preview( $key, $status );
		if ( null === $preview || 'bootstrap' !== $preview['kind'] || '' === $token || ! hash_equals( $preview['repository'], trim( $confirmation ) ) ) {
			return $this->result( $status, 'invalid_request', false, $key );
		}
		$nonce     = $preflightNonces[ $preview['preflight_channel'] ] ?? '';
		$preflight = $this->releases->assessmentPreflight( $status->type(), $status->identifier(), $status->sourceRevision(), $preview['preflight_channel'], $nonce );
		if ( null === $preflight ) {
			return $this->result( $status, 'preflight_unavailable', false, $key, 'release_preflight', 'preflight_contract_unavailable' );
		}
		if ( 'preflight_unavailable' === $preflight->code() ) {
			$reason = '' !== $preflight->reasonCode() ? $preflight->reasonCode() : 'provider_unavailable';
			return $this->result( $status, 'preflight_unavailable', false, $key, 'release_preflight', $reason );
		}
		if ( ! $this->acceptsBootstrapPreflight( $preflight->code() ) ) {
			return $this->result( $status, 'target_changed', false, $key, 'release_preflight', $this->preflightDiagnostic( $preflight->code(), $preflight->reasonCode() ) );
		}
		$latest = $this->templates->discover( $token );
		$exact  = 'ok' === $latest['code'] && $latest['pack']->identity() === $preview['new_template_identity']
			? $this->templates->exact( $preview['new_template_identity'], $token ) : array( 'code' => 'template_superseded' );
		if ( 'ok' !== $exact['code'] ) {
			return $this->result( $status, $exact['code'], false, $key );
		}
		$remote = $this->bootstrapBundle( $status, $token, $exact['pack'] );
		if ( 'ok' !== $remote['code'] || ! $this->previewMatchesBundle( $preview, $remote ) ) {
			return $this->result( $status, 'target_changed', false, $key );
		}
		return delete_transient( self::PREVIEW_PREFIX . $key )
			? $this->openDraft( $status, $key, $remote, 'bootstrap', '', $token )
			: $this->result( $status, 'invalid_request', false, $key );
	}

	public function inspectUpdate( ReleaseTrackingStatus $status, string $token ): array {
		$record = $this->currentRecord( $status );
		if ( null === $record ) {
			return $this->result( $status, 'invalid_request' );
		}
		$remote = $this->updateBundle( $status, $token );
		if ( 'managed_profile_current' === $remote['code'] ) {
			return $this->result( $status, 'template_current', true );
		}
		if ( 'ok' !== $remote['code'] ) {
			return $this->result( $status, $remote['code'] );
		}
		$preview = $this->previewRecord( 'template_update', $status, $remote, '' );
		try {
			$key = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable ) {
			return $this->result( $status, 'remote_unavailable', false, '', 'unexpected' );
		}
		return set_transient( self::PREVIEW_PREFIX . $key, $preview, 15 * MINUTE_IN_SECONDS )
			? $this->result( $status, 'template_update_available', true, $key ) : $this->result( $status, 'remote_unavailable', false, '', 'preview_storage' );
	}

	private function acceptsBootstrapPreflight( string $code ): bool {
		return in_array( $code, array( 'ready', 'release_unavailable' ), true );
	}

	private function preflightDiagnostic( string $code, string $reason ): string {
		if ( in_array( $reason, self::PREFLIGHT_REASON_CODES, true ) ) {
			return $reason;
		}

		return in_array( $code, self::PREFLIGHT_REASON_CODES, true ) ? $code : 'preflight_contract_unavailable';
	}

	public function setupUpdate( ReleaseTrackingStatus $status, string $key, string $confirmation, string $token ): array {
		$preview = $this->preview( $key, $status );
		$record  = $this->currentRecord( $status );
		if ( null === $record || null === $preview || 'template_update' !== $preview['kind']
			|| '' === $token || ! hash_equals( $preview['repository'], trim( $confirmation ) ) ) {
			return $this->result( $status, 'invalid_request', false, $key );
		}
		$remote = $this->updateBundle( $status, $token );
		if ( 'ok' !== $remote['code'] ) {
			return $this->result( $status, $remote['code'], false, $key );
		}
		if ( ! $this->previewMatchesBundle( $preview, $remote ) || $remote['old_template_identity'] !== $preview['old_template_identity'] ) {
			return $this->result( $status, 'template_superseded', false, $key );
		}
		return delete_transient( self::PREVIEW_PREFIX . $key )
			? $this->openDraft( $status, $key, $remote, 'template_update', $remote['old_pack_version'], $token )
			: $this->result( $status, 'invalid_request', false, $key );
	}

	public function outcome( ReleaseTrackingStatus $status, string $token ): array {
		$record = $this->currentRecord( $status );
		if ( null === $record ) {
			return $this->result( $status, 'invalid_request' );
		}
		$repo = $this->github->repository( $record['repository'], $token );
		$pull = $this->github->pullRequest( $record['repository'], $record['pr_number'], $token );
		if ( 'ok' !== $repo['code'] || 'ok' !== $pull['code'] ) {
			return $this->result( $status, 'ok' !== $repo['code'] ? $repo['code'] : $pull['code'] );
		}
		$pr = $pull['pull'];
		if ( ! hash_equals( $record['repo_id'], $repo['repository_id'] ) || ! hash_equals( $record['default_branch'], $repo['default_branch'] )
			|| ! hash_equals( $record['setup_branch'], $pr['head'] ) || ! hash_equals( $record['head_sha'], $pr['head_sha'] ) || ! hash_equals( $record['default_branch'], $pr['base'] )
			|| ( ! $pr['merged'] && ! hash_equals( $record['base_sha'], $pr['base_sha'] ) ) ) {
			return $this->result( $status, 'target_changed' );
		}
		if ( 'open' === $pr['state'] ) {
			return $this->result( $status, 'pr_open', true );
		}
		if ( ! $pr['merged'] ) {
			return $this->result( $status, 'pr_closed' );
		}
		$base     = $this->github->branchRef( $record['repository'], $record['default_branch'], $token );
		$snapshot = 'ok' === $base['code'] ? $this->github->snapshot( $record['repository'], $record['repo_id'], $record['default_branch'], $base['sha'], $token ) : $base;
		if ( 'ok' !== $snapshot['code'] ) {
			return $this->result( $status, $snapshot['code'] );
		}
		$receipt = 'ok' === $snapshot['code'] ? $snapshot['snapshot']->document( ManagedReleaseBundle::RECEIPT_PATH ) : null;
		$valid   = is_string( $receipt ) && hash_equals( $record['receipt_digest'], hash( 'sha256', $receipt ) )
			&& $this->receiptMatchesRecord( $receipt, $record, $snapshot['snapshot'] );
		return $this->result( $status, $valid ? 'pr_merged' : 'target_changed', $valid );
	}

	/** @return array<string,int|string>|null */
	private function currentRecord( ReleaseTrackingStatus $status ): ?array {
		$record = $this->records->find( $status->providerRepositoryId() );
		if ( null === $record || ! hash_equals( $status->type(), $record['package_type'] )
			|| ! hash_equals( $status->identifier(), $record['package_identifier'] ) ) {
			return null;
		}
		if ( $status->sourceRevision() === $record['source_revision'] ) {
			return $record;
		}

		return $this->records->refreshSourceRevision(
			$status->providerRepositoryId(),
			$status->type(),
			$status->identifier(),
			$status->sourceRevision()
		);
	}

	/** Return only a strict, current-user, current-package schema 2 preview. */
	public function preview( string $key, ReleaseTrackingStatus $status ): ?array {
		$preview       = get_transient( self::PREVIEW_PREFIX . $key );
		$validIdentity = static function ( mixed $identity ): bool {
			if ( ! is_array( $identity ) || array_keys( $identity ) !== self::IDENTITY_FIELDS
				|| 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates' !== $identity['repository_name']
				|| '1322743261' !== $identity['repository_id'] || ! is_int( $identity['release_id'] ) || $identity['release_id'] < 1
				|| ! is_string( $identity['release_tag'] ) || 1 !== preg_match( '/\Av[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $identity['release_tag'] )
				|| ! is_string( $identity['release_commit'] ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $identity['release_commit'] )
				|| ! hash_equals( $identity['release_commit'], (string) $identity['release_target'] ) || ! hash_equals( $identity['release_commit'], (string) $identity['tag_target'] )
				|| false !== $identity['release_draft'] || false !== $identity['release_prerelease'] || true !== $identity['release_immutable']
				|| 1 !== $identity['asset_count'] || ! is_int( $identity['asset_id'] ) || $identity['asset_id'] < 1
				|| 'ran-booster-release-bootstrap-templates.zip' !== $identity['asset_name'] || 'uploaded' !== $identity['asset_state']
				|| 'application/zip' !== $identity['asset_content_type'] || ! is_int( $identity['asset_size'] ) || $identity['asset_size'] < 1 || $identity['asset_size'] > 2097152
				|| ! is_string( $identity['asset_sha256'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $identity['asset_sha256'] )
				|| ! hash_equals( 'sha256:' . $identity['asset_sha256'], (string) $identity['asset_digest'] ) ) {
				return false;
			}
			return true;
		};
		if ( 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $key ) || ! is_array( $preview ) || array_keys( $preview ) !== self::PREVIEW_FIELDS || 2 !== $preview['schema_version']
			|| ! is_int( $preview['user_id'] ) || $preview['user_id'] < 1 || get_current_user_id() !== $preview['user_id']
			|| ! is_int( $preview['revision'] ) || $preview['revision'] < 1 || $status->sourceRevision() !== $preview['revision']
			|| ! is_string( $preview['repo_id'] ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $preview['repo_id'] ) || ! hash_equals( $status->providerRepositoryId(), $preview['repo_id'] )
			|| ! in_array( $preview['type'], array( 'plugin', 'theme' ), true ) || ! hash_equals( $status->type(), $preview['type'] )
			|| ! is_string( $preview['identifier'] ) || '' === trim( $preview['identifier'] ) || strlen( $preview['identifier'] ) > 255
			|| 1 !== preg_match( '//u', $preview['identifier'] ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $preview['identifier'] )
			|| ! hash_equals( $status->identifier(), $preview['identifier'] ) || ! in_array( $preview['kind'], array( 'bootstrap', 'template_update' ), true )
			|| ! is_string( $preview['repository'] ) || 1 !== preg_match( '#\A[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z#D', $preview['repository'] )
			|| ! is_string( $preview['default_branch'] ) || 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9_-])?\z/D', $preview['default_branch'] )
			|| str_contains( $preview['default_branch'], '..' ) || str_contains( $preview['default_branch'], '//' ) || str_contains( $preview['default_branch'], '@{' )
			|| ! is_string( $preview['base_sha'] ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $preview['base_sha'] )
			|| ! in_array( $preview['preflight_channel'], array( '', 'stable', 'prerelease' ), true )
			|| ( 'bootstrap' === $preview['kind'] ) !== in_array( $preview['preflight_channel'], array( 'stable', 'prerelease' ), true )
			|| ! in_array( $preview['profile_id'], array( 'source-ready-wordpress-plugin/2', 'source-ready-wordpress-theme/2' ), true )
			|| ! hash_equals( 'source-ready-wordpress-' . $preview['type'] . '/2', $preview['profile_id'] )
			|| ! is_string( $preview['pack_version'] ) || 1 !== preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $preview['pack_version'] )
			|| ! is_string( $preview['manifest_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $preview['manifest_hash'] )
			|| ! is_string( $preview['bundle_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $preview['bundle_hash'] )
			|| ! is_string( $preview['changed_path_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $preview['changed_path_hash'] )
			|| ! is_string( $preview['allowlist_hash'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $preview['allowlist_hash'] )
			|| ! $validIdentity( $preview['new_template_identity'] ) || ! hash_equals( 'v' . $preview['pack_version'], $preview['new_template_identity']['release_tag'] )
			|| ( 'bootstrap' === $preview['kind'] && array() !== $preview['old_template_identity'] )
			|| ( 'template_update' === $preview['kind'] && ( ! $validIdentity( $preview['old_template_identity'] ) || $preview['old_template_identity'] === $preview['new_template_identity'] ) )
			|| ! is_array( $preview['changes'] ) || array() === $preview['changes'] || count( $preview['changes'] ) > 32 ) {
			return null;
		}
		$previous = '';
		foreach ( $preview['changes'] as $change ) {
			if ( ! is_array( $change ) || array_keys( $change ) !== array( 'path', 'operation', 'mode', 'sha256' )
				|| ! is_string( $change['path'] ) || '' === $change['path'] || strlen( $change['path'] ) > 512 || str_starts_with( $change['path'], '/' )
				|| str_contains( $change['path'], '\\' ) || str_contains( $change['path'], "\0" ) || 1 !== preg_match( '//u', $change['path'] )
				|| 1 === preg_match( '#(?:\A|/)\.\.?(/|\z)#', $change['path'] ) || ( '' !== $previous && strcmp( $previous, $change['path'] ) >= 0 )
				|| ! in_array( $change['operation'], array( 'added', 'modified' ), true )
				|| ! in_array( $change['mode'], array( '100644', '100755' ), true ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $change['sha256'] ) ) {
				return null;
			}
			$previous = $change['path'];
		}
		return $preview;
	}

	private function bootstrapBundle( ReleaseTrackingStatus $status, string $token, ?TemplatePack $pack = null, bool $adopt = false ): array {
		$target = $this->target( $status, $token );
		if ( 'ok' !== $target['code'] ) {
			return $target;
		}
		if ( $adopt && $target['snapshot']->has( ManagedReleaseBundle::RECEIPT_PATH ) ) {
			return $this->existingManagedRelease( $status, $target, $token );
		}
		$template = null === $pack ? $this->templates->discover( $token ) : array(
			'code' => 'ok',
			'pack' => $pack,
		);
		if ( 'ok' !== $template['code'] ) {
			return $template;
		}
		$assessment = $this->assessor->assess( $target['snapshot'], $status->type(), $status->packageRoot(), $status->installedVersion(), $status->eligibility()->expectedUpdateUri() );
		if ( ! $assessment->readyForBootstrap() ) {
			return array( 'code' => $assessment->code() );
		}
		$made = ManagedReleaseBundle::bootstrap( $template['pack'], $assessment, $target['snapshot'], $status->eligibility()->expectedUpdateUri() );
		return 'ok' === $made['code'] ? array_merge(
			$target,
			array(
				'code'   => 'ok',
				'pack'   => $template['pack'],
				'bundle' => $made['bundle'],
			)
		) : $made;
	}

	/** @param array{repository:string,default_branch:string,snapshot:RepositorySnapshot} $target */
	private function existingManagedRelease( ReleaseTrackingStatus $status, array $target, string $token ): array {
		$receipt = ManagedReleaseBundle::receipt( (string) $target['snapshot']->document( ManagedReleaseBundle::RECEIPT_PATH ) );
		if ( null === $receipt ) {
			return array( 'code' => 'managed_profile_modified' );
		}
		$inputs = $receipt['inputs'];
		if ( ! hash_equals( $status->type(), $inputs['package_type'] )
			|| ! hash_equals( $status->packageRoot(), $inputs['package_slug'] )
			|| ! hash_equals( $target['default_branch'], $inputs['default_branch'] )
			|| ! hash_equals( rtrim( $status->eligibility()->expectedUpdateUri(), '/' ), $inputs['update_uri'] )
			|| ! hash_equals( 'https://github.com/' . $target['repository'], $inputs['update_uri'] ) ) {
			return array( 'code' => 'managed_profile_modified' );
		}
		$assessment = $this->assessor->assessManaged( $target['snapshot'], $status->type(), $status->packageRoot(), $status->installedVersion(), $status->eligibility()->expectedUpdateUri() );
		if ( ! $assessment->readyForBootstrap() ) {
			return array( 'code' => $assessment->code() );
		}
		$historical = $this->templates->exact( array_slice( $receipt['template'], 2, null, true ), $token );
		if ( 'ok' !== $historical['code'] ) {
			return $historical;
		}
		$verified = ManagedReleaseBundle::templateUpdate( $historical['pack'], $historical['pack'], $target['snapshot'] );
		return 'managed_profile_current' === $verified['code'] ? array( 'code' => 'release_automation_present' ) : $verified;
	}

	private function updateBundle( ReleaseTrackingStatus $status, string $token ): array {
		$target = $this->target( $status, $token );
		if ( 'ok' !== $target['code'] ) {
			return $target;
		}
		$receipt = ManagedReleaseBundle::receipt( (string) $target['snapshot']->document( ManagedReleaseBundle::RECEIPT_PATH ) );
		if ( null === $receipt ) {
			return array( 'code' => 'managed_profile_missing' );
		}
		$identity = array_slice( $receipt['template'], 2, null, true );
		$old      = $this->templates->exact( $identity, $token );
		$new      = $this->templates->discover( $token );
		if ( 'ok' !== $old['code'] || 'ok' !== $new['code'] ) {
			return 'ok' !== $old['code'] ? $old : $new;
		}
		$update = ManagedReleaseBundle::templateUpdate( $old['pack'], $new['pack'], $target['snapshot'] );
		return 'ok' === $update['code'] ? array_merge(
			$target,
			array(
				'code'                  => 'ok',
				'pack'                  => $new['pack'],
				'bundle'                => $update['bundle'],
				'old_pack_version'      => $update['old_pack_version'],
				'old_template_identity' => $identity,
			)
		) : $update;
	}

	private function target( ReleaseTrackingStatus $status, string $token ): array {
		$url = $status->eligibility()->expectedUpdateUri();
		if ( 1 !== preg_match( '#\Ahttps://github\.com/([A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99})/?\z#D', $url, $match ) ) {
			return array( 'code' => 'invalid_request' );
		}
		$repo = $this->github->repository( $match[1], $token );
		if ( 'ok' !== $repo['code'] || ! hash_equals( $status->providerRepositoryId(), (string) ( $repo['repository_id'] ?? '' ) ) || 0 !== strcasecmp( $match[1], (string) ( $repo['full_name'] ?? '' ) ) ) {
			return array( 'code' => 'ok' === $repo['code'] ? 'target_changed' : $repo['code'] );
		}
		$base   = $this->github->branchRef( $repo['full_name'], $repo['default_branch'], $token );
		$commit = 'ok' === $base['code'] ? $this->github->gitCommit( $repo['full_name'], $base['sha'], $token ) : $base;
		$tree   = 'ok' === $commit['code'] ? $this->github->snapshot( $repo['full_name'], $repo['repository_id'], $repo['default_branch'], $base['sha'], $token ) : $commit;
		return 'ok' !== $tree['code'] ? $tree : array(
			'code'           => 'ok',
			'repository_id'  => $repo['repository_id'],
			'repository'     => $repo['full_name'],
			'default_branch' => $repo['default_branch'],
			'base_sha'       => $base['sha'],
			'base_tree_sha'  => $commit['tree_sha'],
			'snapshot'       => $tree['snapshot'],
		);
	}

	private function openDraft( ReleaseTrackingStatus $status, string $previewKey, array $remote, string $operation, string $oldPackVersion, string $token ): array {
		$bundle = $remote['bundle'];
		$branch = 'bootstrap' === $operation
			? sprintf( 'ran-booster/release-setup-v2-%s-%s', substr( $remote['base_sha'], 0, 12 ), substr( $bundle->hash(), 0, 8 ) )
			: sprintf( 'ran-booster/release-setup-v2-%s-%s-to-%s-%s', substr( $remote['base_sha'], 0, 12 ), str_replace( '.', '-', $oldPackVersion ), str_replace( '.', '-', $bundle->packVersion() ), substr( $bundle->hash(), 0, 8 ) );
		$lookup = $this->findPull( $remote['repository'], $branch, $remote['default_branch'], $token );
		if ( 'ok' !== $lookup['code'] ) {
			return $this->result( $status, $lookup['code'], false, $previewKey );
		}
		$pull = $lookup['pull'];
		$head = null === $pull ? $this->createAtomicCommit( $remote, $bundle, $branch, $operation, $token ) : $pull['head_sha'];
		if ( null === $head || ! $this->verifyBranch( $remote, $branch, $head, $bundle, $token ) ) {
			return $this->result( $status, 'partial', false, $previewKey, 'repository_mutation' );
		}
		$recovered = null !== $pull;
		if ( null === $pull ) {
			$title     = 'bootstrap' === $operation ? 'Bootstrap source-ready releases' : 'Update the RAN Booster release template pack';
			$body      = sprintf( "Draft only. Review every generated file before merging.\n\nTemplate pack: `%s` (`%s`)\nConsumer API: `%d`\nBundle: `%s`\n", $bundle->packVersion(), $bundle->packIdentity()['release_tag'], TemplatePack::CONSUMER_API, $bundle->hash() );
			$created   = $this->github->createDraftPullRequest( $remote['repository'], $branch, $remote['default_branch'], $title, $body, $token );
			$recovered = 'ok' !== $created['code'];
			$lookup    = 'ok' === $created['code'] ? array(
				'code' => 'ok',
				'pull' => $created['pull'],
			) : $this->findPull( $remote['repository'], $branch, $remote['default_branch'], $token );
			$pull      = 'ok' === $lookup['code'] ? $lookup['pull'] : null;
		}
		$files = null !== $pull ? $this->github->pullRequestFileSet( $remote['repository'], $pull['number'], $token ) : array( 'code' => 'invalid_request' );
		if ( null === $pull || ! $pull['draft'] || 'open' !== $pull['state'] || ! hash_equals( $head, $pull['head_sha'] )
			|| ! hash_equals( $remote['base_sha'], $pull['base_sha'] ) || 'ok' !== $files['code'] || $files['files'] !== $bundle->expectedPullFiles() ) {
			return $this->result( $status, 'partial', false, $previewKey, 'repository_mutation' );
		}
		$record = $this->record( $status, $remote, $bundle, $operation, $branch, $head, $pull['number'] );
		if ( ! $this->records->save( $record ) ) {
			return $this->result( $status, 'partial', false, $previewKey, 'local_persistence' );
		}
		return $this->result( $status, $recovered ? 'setup_recovered' : 'setup_open', true );
	}

	private function createAtomicCommit( array $remote, ManagedReleaseBundle $bundle, string $branch, string $operation, string $token ): ?string {
		$repo = '' !== $token ? $this->github->repository( $remote['repository'], $token ) : array( 'code' => 'invalid_request' );
		$base = 'ok' === $repo['code'] ? $this->github->branchRef( $remote['repository'], $remote['default_branch'], $token ) : $repo;
		if ( 'ok' !== $base['code'] || ! hash_equals( $remote['repository_id'], (string) ( $repo['repository_id'] ?? '' ) )
			|| ! hash_equals( $remote['repository'], (string) ( $repo['full_name'] ?? '' ) ) || ! hash_equals( $remote['default_branch'], (string) ( $repo['default_branch'] ?? '' ) )
			|| ! hash_equals( $remote['base_sha'], $base['sha'] ) ) {
			return null;
		}
		$existing = $this->github->branchRef( $remote['repository'], $branch, $token );
		if ( 'ok' === $existing['code'] ) {
			return $this->verifyBranch( $remote, $branch, $existing['sha'], $bundle, $token ) ? $existing['sha'] : null;
		}
		if ( 'missing' !== $existing['code'] ) {
			return null;
		}
		$entries = array();
		foreach ( $bundle->files() as $file ) {
			$blob = $this->github->createBlob( $remote['repository'], $file['content'], $token );
			if ( 'ok' !== $blob['code'] || ! hash_equals( $file['git_sha'], $blob['sha'] ) ) {
				$read = $this->github->blob( $remote['repository'], $file['git_sha'], $token );
				if ( 'ok' !== $read['code'] || ! hash_equals( hash( 'sha256', $file['content'] ), hash( 'sha256', $read['content'] ) ) ) {
					return null;
				}
				$blob = array(
					'code' => 'ok',
					'sha'  => $file['git_sha'],
				);
			}
			$entries[] = array(
				'path' => $file['path'],
				'sha'  => $blob['sha'],
				'mode' => $file['mode'],
			);
		}
		$tree    = $this->github->createTree( $remote['repository'], $remote['base_tree_sha'], $entries, $token );
		$message = 'bootstrap' === $operation ? 'chore: bootstrap source-ready releases' : 'chore: update release template pack';
		$commit  = 'ok' === $tree['code'] ? $this->github->createCommit( $remote['repository'], $tree['sha'], $remote['base_sha'], $message, $token ) : $tree;
		$repo    = 'ok' === $commit['code'] ? $this->github->repository( $remote['repository'], $token ) : $commit;
		$base    = 'ok' === $repo['code'] ? $this->github->branchRef( $remote['repository'], $remote['default_branch'], $token ) : $repo;
		if ( 'ok' !== $commit['code'] || 'ok' !== $base['code'] || ! hash_equals( $remote['repository_id'], (string) ( $repo['repository_id'] ?? '' ) )
			|| ! hash_equals( $remote['repository'], (string) ( $repo['full_name'] ?? '' ) ) || ! hash_equals( $remote['default_branch'], (string) ( $repo['default_branch'] ?? '' ) )
			|| ! hash_equals( $remote['base_sha'], $base['sha'] ) ) {
			return null;
		}
		$created = $this->github->createRef( $remote['repository'], $branch, $remote['default_branch'], $commit['sha'], $token );
		$ref     = 'ok' === $created['code'] ? $created : $this->github->branchRef( $remote['repository'], $branch, $token );
		return 'ok' === $ref['code'] && hash_equals( $commit['sha'], $ref['sha'] ) && $this->verifyBranch( $remote, $branch, $commit['sha'], $bundle, $token ) ? $commit['sha'] : null;
	}

	private function verifyBranch( array $remote, string $branch, string $head, ManagedReleaseBundle $bundle, string $token ): bool {
		$ref    = $this->github->branchRef( $remote['repository'], $branch, $token );
		$commit = 'ok' === $ref['code'] && hash_equals( $head, $ref['sha'] ) ? $this->github->gitCommit( $remote['repository'], $head, $token ) : $ref;
		$tree   = 'ok' === $commit['code'] && $commit['parents'] === array( $remote['base_sha'] ) ? $this->github->snapshot( $remote['repository'], $remote['repository_id'], $remote['default_branch'], $head, $token ) : $commit;
		if ( 'ok' !== $tree['code'] ) {
			return false;
		}
		foreach ( $bundle->files() as $path => $file ) {
			$entry = $tree['snapshot']->entries()[ $path ] ?? null;
			if ( ! is_array( $entry ) || ! hash_equals( $file['git_sha'], $entry['sha'] ) || ! hash_equals( $file['mode'], $entry['mode'] ) ) {
				return false;
			}
		}
		return true;
	}

	private function findPull( string $repository, string $branch, string $base, string $token ): array {
		$result = $this->github->pullRequests( $repository, $branch, $token );
		if ( 'ok' !== $result['code'] ) {
			return $result;
		}
		if ( array() === $result['pulls'] ) {
			return array(
				'code' => 'ok',
				'pull' => null,
			);
		}
		if ( 1 !== count( $result['pulls'] ) || 'open' !== $result['pulls'][0]['state']
			|| ! hash_equals( $branch, $result['pulls'][0]['head'] ) || ! hash_equals( $base, $result['pulls'][0]['base'] ) ) {
			return array( 'code' => 'invalid_response' );
		}
		return array(
			'code' => 'ok',
			'pull' => $result['pulls'][0],
		);
	}

	private function previewRecord( string $kind, ReleaseTrackingStatus $status, array $remote, string $channel ): array {
		$bundle  = $remote['bundle'];
		$changes = array();
		foreach ( $bundle->files() as $file ) {
			$changes[] = array(
				'path'      => $file['path'],
				'operation' => $file['operation'],
				'mode'      => $file['mode'],
				'sha256'    => $file['sha256'],
			);
		}
		return array(
			'schema_version'        => 2,
			'kind'                  => $kind,
			'user_id'               => get_current_user_id(),
			'type'                  => $status->type(),
			'identifier'            => $status->identifier(),
			'revision'              => $status->sourceRevision(),
			'repo_id'               => $status->providerRepositoryId(),
			'repository'            => $remote['repository'],
			'default_branch'        => $remote['default_branch'],
			'base_sha'              => $remote['base_sha'],
			'preflight_channel'     => $channel,
			'profile_id'            => $bundle->profile(),
			'pack_version'          => $bundle->packVersion(),
			'manifest_hash'         => $bundle->manifestHash(),
			'new_template_identity' => $bundle->packIdentity(),
			'old_template_identity' => $remote['old_template_identity'] ?? array(),
			'bundle_hash'           => $bundle->hash(),
			'changed_path_hash'     => $bundle->changedPathHash(),
			'allowlist_hash'        => $bundle->allowlistHash(),
			'changes'               => $changes,
		);
	}

	private function previewMatchesBundle( array $preview, array $remote ): bool {
		$bundle  = $remote['bundle'];
		$changes = array();
		foreach ( $bundle->files() as $file ) {
			$changes[] = array(
				'path'      => $file['path'],
				'operation' => $file['operation'],
				'mode'      => $file['mode'],
				'sha256'    => $file['sha256'],
			);
		}
		return hash_equals( $preview['repository'], $remote['repository'] ) && hash_equals( $preview['default_branch'], $remote['default_branch'] )
			&& hash_equals( $preview['base_sha'], $remote['base_sha'] ) && hash_equals( $preview['profile_id'], $bundle->profile() )
			&& hash_equals( $preview['pack_version'], $bundle->packVersion() ) && hash_equals( $preview['manifest_hash'], $bundle->manifestHash() )
			&& hash_equals( $preview['bundle_hash'], $bundle->hash() ) && hash_equals( $preview['changed_path_hash'], $bundle->changedPathHash() )
			&& hash_equals( $preview['allowlist_hash'], $bundle->allowlistHash() ) && $preview['new_template_identity'] === $bundle->packIdentity()
			&& $preview['changes'] === $changes;
	}

	private function record( ReleaseTrackingStatus $status, array $remote, ManagedReleaseBundle $bundle, string $operation, string $branch, string $head, int $pull ): array {
		$identity = $bundle->packIdentity();
		return array(
			'schema_version'        => 2,
			'operation'             => $operation,
			'repo_id'               => $status->providerRepositoryId(),
			'repository'            => $remote['repository'],
			'package_type'          => $status->type(),
			'package_identifier'    => $status->identifier(),
			'source_revision'       => $status->sourceRevision(),
			'default_branch'        => $remote['default_branch'],
			'base_sha'              => $remote['base_sha'],
			'setup_branch'          => $branch,
			'head_sha'              => $head,
			'pr_number'             => $pull,
			'profile_id'            => $bundle->profile(),
			'template_repo_name'    => $identity['repository_name'],
			'template_repo_id'      => $identity['repository_id'],
			'template_release_id'   => $identity['release_id'],
			'template_tag'          => $identity['release_tag'],
			'template_commit'       => $identity['release_commit'],
			'template_asset_id'     => $identity['asset_id'],
			'template_asset_name'   => $identity['asset_name'],
			'template_asset_size'   => $identity['asset_size'],
			'template_asset_digest' => $identity['asset_sha256'],
			'manifest_digest'       => $bundle->manifestHash(),
			'receipt_digest'        => $bundle->files()[ ManagedReleaseBundle::RECEIPT_PATH ]['sha256'],
			'consumer_api'          => TemplatePack::CONSUMER_API,
			'pack_version'          => $bundle->packVersion(),
			'bundle_hash'           => $bundle->hash(),
			'changed_path_hash'     => $bundle->changedPathHash(),
		);
	}

	private function receiptMatchesRecord( string $bytes, array $record, RepositorySnapshot $snapshot ): bool {
		$receipt = ManagedReleaseBundle::receipt( $bytes );
		if ( null === $receipt ) {
			return false;
		}
		$identity = array_slice( $receipt['template'], 2, null, true );
		$inputs   = $receipt['inputs'];
		$managed  = $receipt['managed_files'];
		$valid    = hash_equals( $record['profile_id'], $receipt['profile']['id'] )
			&& ( 'template_update' === $record['operation'] || hash_equals( $record['base_sha'], (string) ( $inputs['bootstrap_sha'] ?? '' ) ) )
			&& hash_equals( $record['default_branch'], $inputs['default_branch'] ) && hash_equals( $record['package_type'], $inputs['package_type'] )
			&& hash_equals( $record['repository'], substr( $inputs['update_uri'], strlen( 'https://github.com/' ) ) )
			&& hash_equals( $record['pack_version'], $receipt['template']['pack_version'] ) && hash_equals( $record['manifest_digest'], $receipt['template']['manifest_sha256'] )
			&& hash_equals( $record['template_repo_name'], $identity['repository_name'] ) && hash_equals( $record['template_repo_id'], $identity['repository_id'] )
			&& $record['template_release_id'] === $identity['release_id'] && hash_equals( $record['template_tag'], $identity['release_tag'] ) && hash_equals( $record['template_commit'], $identity['release_commit'] )
			&& $record['template_asset_id'] === $identity['asset_id'] && hash_equals( $record['template_asset_name'], $identity['asset_name'] )
			&& $record['template_asset_size'] === $identity['asset_size'] && hash_equals( $record['template_asset_digest'], $identity['asset_sha256'] );
		if ( ! $valid ) {
			return false;
		}
		foreach ( $managed as $path => $digest ) {
			$document = is_string( $path ) && is_string( $digest ) ? $snapshot->document( $path ) : null;
			if ( ! is_string( $document ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $digest ) || ! hash_equals( $digest, hash( 'sha256', $document ) ) ) {
				return false;
			}
		}
		return true;
	}

	private function result( ReleaseTrackingStatus $status, string $code, bool $successful = false, string $preview = '', string $stage = '', string $diagnostic = '' ): array {
		$mapped = in_array( $code, self::PREFLIGHT_REASON_CODES, true ) ? 'workflow_' . $code : match ( $code ) {
			'ready', 'invalid_release_assets', 'release_version_mismatch', 'release_header_missing', 'release_header_invalid', 'release_archive_unreadable', 'preflight_unavailable' => 'workflow_' . ( 'ready' === $code ? 'release_ready' : $code ),
			'unauthorised' => 'workflow_unauthorised', 'template_superseded', 'template_pack_changed' => 'workflow_template_superseded',
			'target_changed' => 'workflow_target_changed', 'template_pack_unavailable' => 'workflow_template_unavailable',
			'remote_unavailable' => 'workflow_remote_unavailable', 'rate_limited' => 'workflow_rate_limited', 'invalid_response' => 'workflow_invalid_response',
			'template_pack_incompatible' => 'workflow_template_incompatible', 'template_pack_invalid' => 'workflow_template_invalid',
			'managed_profile_missing' => 'workflow_profile_missing', 'managed_profile_modified' => 'workflow_profile_modified',
			'package_ambiguous', 'version_mismatch', 'version_contract_custom', 'runtime_paths_unknown', 'release_automation_conflict', 'release_automation_present', 'release_path_conflict', 'prettier_contract_custom', 'repository_unsupported' => 'workflow_' . $code,
			'inspected', 'setup_open', 'setup_recovered', 'partial', 'pr_open', 'pr_closed', 'pr_merged', 'template_current', 'template_update_available' => 'workflow_' . $code,
			default => 'workflow_invalid_request',
		};
		return array(
			'type'            => $status->type(),
			'identifier'      => $status->identifier(),
			'code'            => $mapped,
			'successful'      => $successful,
			'preview_key'     => $preview,
			'failure_stage'   => $successful ? '' : $this->failureStage( $code, $stage ),
			'diagnostic_code' => $successful ? '' : $this->diagnosticCode( $code, $stage, $diagnostic ),
		);
	}

	private function diagnosticCode( string $code, string $stage, string $diagnostic ): string {
		$allowed = array( 'credential_authorisation_unavailable', 'preflight_contract_unavailable', ...self::PREFLIGHT_REASON_CODES, 'release_automation_detected', 'repository_snapshot_unavailable', 'template_pack_unavailable', 'preview_storage_unavailable', 'repository_mutation_unverified', 'local_persistence_unavailable', 'unexpected_runtime_failure' );
		if ( in_array( $diagnostic, $allowed, true ) ) {
			return $diagnostic;
		}
		$resolvedStage = '' !== $stage ? $stage : $this->failureStage( $code, '' );
		if ( 'release_automation_conflict' === $code ) {
			return 'release_automation_detected';
		}
		return match ( $resolvedStage ) {
			'credential_authorisation' => 'credential_authorisation_unavailable',
			'release_preflight' => 'preflight_contract_unavailable',
			'repository_snapshot' => 'repository_snapshot_unavailable',
			'template_pack' => 'template_pack_unavailable',
			'preview_storage' => 'preview_storage_unavailable',
			'repository_mutation' => 'repository_mutation_unverified',
			'local_persistence' => 'local_persistence_unavailable',
			default => 'unexpected_runtime_failure',
		};
	}

	private function failureStage( string $code, string $stage ): string {
		if ( in_array( $stage, array( 'credential_authorisation', 'release_preflight', 'repository_snapshot', 'template_pack', 'preview_storage', 'repository_mutation', 'local_persistence', 'unexpected' ), true ) ) {
			return $stage;
		}
		return match ( $code ) {
			'unauthorised' => 'credential_authorisation',
			'preflight_unavailable' => 'release_preflight',
			'release_automation_conflict' => 'repository_snapshot',
			'template_pack_unavailable', 'template_pack_invalid' => 'template_pack',
			'remote_unavailable', 'rate_limited', 'invalid_response' => 'repository_snapshot',
			default => '',
		};
	}
}
