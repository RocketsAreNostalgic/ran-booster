<?php

declare(strict_types=1);

namespace RAN\Booster\GitHub\ReleaseDeployments\WorkflowAssistance;

use RuntimeException;
use Throwable;

/** One deterministic, previewable source-ready bootstrap tree. */
final readonly class ManagedReleaseBundle {
	public const RECEIPT_PATH  = '.ran-booster-release-profile.json';
	public const WORKFLOW_PATH = '.github/workflows/release-please.yml';
	/**
	 * Generated release contract files which must remain present for managed
	 * assessment. Receipt verification additionally owns immutable content where appropriate.
	 */
	public const REQUIRED_GENERATED_CONTRACT_PATHS = array(
		'.release-please-manifest.json',
		'release-please-config.json',
		'version.txt',
		'release-contents.txt',
	);

	/** @var array<string, array{path:string,mode:string,operation:string,content:string,sha256:string,git_sha:string,managed:bool}> */
	private array $files;
	private string $hash;
	private string $changedPathHash;
	private string $allowlistHash;

	/**
	 * @param array<string, array{path:string,mode:string,operation:string,content:string,sha256:string,git_sha:string,managed:bool}> $files
	 */
	private function __construct(
		private string $profile,
		private string $packVersion,
		private array $packIdentity,
		private string $manifestHash,
		array $files,
		string $allowlist
	) {
		ksort( $files, SORT_STRING );
		$this->files           = $files;
		$this->changedPathHash = hash( 'sha256', implode( "\n", array_keys( $files ) ) . "\n" );
		$this->allowlistHash   = hash( 'sha256', $allowlist );
		$this->hash            = hash(
			'sha256',
			self::json(
				array(
					'profile'           => $profile,
					'pack_version'      => $packVersion,
					'pack_identity'     => $packIdentity,
					'manifest_hash'     => $manifestHash,
					'changed_path_hash' => $this->changedPathHash,
					'allowlist_hash'    => $this->allowlistHash,
					'files'             => array_map(
						static fn ( array $file ): array => array(
							'path'      => $file['path'],
							'mode'      => $file['mode'],
							'operation' => $file['operation'],
							'sha256'    => $file['sha256'],
							'git_sha'   => $file['git_sha'],
							'managed'   => $file['managed'],
						),
						$files
					),
				)
			)
		);
	}

	/** @return array{code:string,bundle?:self} */
	public static function bootstrap(
		TemplatePack $pack,
		SourceReadyAssessment $assessment,
		RepositorySnapshot $snapshot,
		string $updateUri
	): array {
		if ( ! $assessment->readyForBootstrap() || ! in_array( $assessment->profile(), $pack->profiles(), true )
			|| ! hash_equals( 'https://github.com/' . $snapshot->repository(), rtrim( $updateUri, '/' ) ) ) {
			return array( 'code' => 'invalid_bundle' );
		}

		$extraFiles = self::json( $assessment->extraFiles(), false );
		$rendered   = array(
			self::WORKFLOW_PATH                => self::render(
				$pack,
				$assessment->profile(),
				'release-workflow',
				array(
					'DEFAULT_BRANCH' => $snapshot->defaultBranch(),
					'PACKAGE_SLUG'   => $assessment->packageSlug(),
				)
			),
			'release-please-config.json'       => self::render(
				$pack,
				$assessment->profile(),
				'release-please-config',
				array(
					'BASE_SHA'         => $snapshot->sha(),
					'EXTRA_FILES_JSON' => $extraFiles,
					'PACKAGE_SLUG'     => $assessment->packageSlug(),
				)
			),
			'scripts/build-release.sh'         => self::render(
				$pack,
				$assessment->profile(),
				'build-release-script',
				array(
					'HEADER_PATH'  => $assessment->headerPath(),
					'PACKAGE_SLUG' => $assessment->packageSlug(),
					'PACKAGE_TYPE' => self::packageType( $assessment->profile() ),
				)
			),
			'scripts/verify-release.sh'        => self::render(
				$pack,
				$assessment->profile(),
				'verify-release-script',
				array(
					'HEADER_PATH'  => $assessment->headerPath(),
					'PACKAGE_SLUG' => $assessment->packageSlug(),
					'PACKAGE_TYPE' => self::packageType( $assessment->profile() ),
					'UPDATE_URI'   => rtrim( $updateUri, '/' ),
				)
			),
			'scripts/upload-release-assets.sh' => self::render( $pack, $assessment->profile(), 'upload-release-assets-script', array() ),
		);
		if ( in_array( null, $rendered, true ) ) {
			return array( 'code' => 'invalid_bundle' );
		}

		$allowlist = '# RAN Booster managed runtime allowlist for ' . $assessment->packageSlug() . ".\n\n"
			. implode( "\n", $assessment->releaseFiles() ) . "\n";
		$generated = array_merge(
			$rendered,
			array(
				'.release-please-manifest.json' => self::json( array( '.' => $assessment->version() ) ),
				'version.txt'                   => $assessment->version() . "\n",
				'release-contents.txt'          => $allowlist,
			)
		);

		$files = array();
		foreach ( $generated as $path => $content ) {
			if ( ! is_string( $content ) || $snapshot->has( $path ) ) {
				return array( 'code' => 'invalid_bundle' );
			}
			$managed        = isset( $rendered[ $path ] );
			$files[ $path ] = self::file( $path, $content, str_starts_with( $path, 'scripts/' ) ? '100755' : '100644', 'added', $managed );
		}
		foreach ( $assessment->modifiedFiles() as $path => $content ) {
			$files[ $path ] = self::file( $path, $content, '100644', $snapshot->has( $path ) ? 'modified' : 'added', false );
		}

		$managedHashes = array();
		foreach ( $files as $path => $file ) {
			if ( $file['managed'] ) {
				$managedHashes[ $path ] = $file['sha256'];
			}
		}
		ksort( $managedHashes, SORT_STRING );
		$receipt                     = self::json(
			array(
				'schema_version' => 1,
				'consumer_api'   => TemplatePack::CONSUMER_API,
				'profile'        => array(
					'id'      => $assessment->profile(),
					'version' => 1,
				),
				'template'       => array_merge(
					array(
						'pack_version'    => $pack->packVersion(),
						'manifest_sha256' => $pack->manifestHash(),
					),
					$pack->identity()
				),
				'inputs'         => array(
					'bootstrap_sha'  => $snapshot->sha(),
					'default_branch' => $snapshot->defaultBranch(),
					'extra_files'    => $assessment->extraFiles(),
					'header_path'    => $assessment->headerPath(),
					'package_slug'   => $assessment->packageSlug(),
					'package_type'   => self::packageType( $assessment->profile() ),
					'release_policy' => 'stable',
					'update_uri'     => rtrim( $updateUri, '/' ),
				),
				'managed_files'  => $managedHashes,
			)
		);
		$files[ self::RECEIPT_PATH ] = self::file( self::RECEIPT_PATH, $receipt, '100644', 'added', true );

		try {
			return array(
				'code'   => 'ok',
				'bundle' => new self( $assessment->profile(), $pack->packVersion(), $pack->identity(), $pack->manifestHash(), $files, $allowlist ),
			);
		} catch ( Throwable ) {
			return array( 'code' => 'invalid_bundle' );
		}
	}

	/** @return array{code:string,bundle?:self,old_pack_version?:string} */
	public static function templateUpdate( TemplatePack $historicalPack, TemplatePack $proposedPack, RepositorySnapshot $snapshot ): array {
		$receiptBytes = $snapshot->document( self::RECEIPT_PATH );
		if ( ! is_string( $receiptBytes ) ) {
			return array( 'code' => 'managed_profile_missing' );
		}
		$receipt = self::receipt( $receiptBytes );
		if ( null === $receipt ) {
			return array( 'code' => 'managed_profile_modified' );
		}
		$identity = self::receiptIdentity( $receipt );
		if ( null === $identity || $historicalPack->identity() !== $identity
			|| ! hash_equals( $historicalPack->packVersion(), $receipt['template']['pack_version'] )
			|| ! hash_equals( $historicalPack->manifestHash(), $receipt['template']['manifest_sha256'] ) ) {
			return array( 'code' => 'managed_profile_modified' );
		}
		$profile = $receipt['profile']['id'];
		if ( ! in_array( $profile, $historicalPack->profiles(), true ) || ! in_array( $profile, $proposedPack->profiles(), true ) ) {
			return array( 'code' => 'template_pack_incompatible' );
		}
		$oldRendered = self::renderManagedFiles( $historicalPack, $profile, $receipt['inputs'] );
		$newRendered = self::renderManagedFiles( $proposedPack, $profile, $receipt['inputs'] );
		if ( null === $oldRendered || null === $newRendered || array_keys( $receipt['managed_files'] ) !== array_keys( $oldRendered ) ) {
			return array( 'code' => 'managed_profile_modified' );
		}
		foreach ( $oldRendered as $path => $content ) {
			$current = $snapshot->document( $path );
			if ( ! is_string( $current ) || ! hash_equals( $receipt['managed_files'][ $path ], hash( 'sha256', $current ) )
				|| ! hash_equals( hash( 'sha256', $content ), hash( 'sha256', $current ) ) ) {
				return array( 'code' => 'managed_profile_modified' );
			}
		}
		if ( version_compare( $proposedPack->packVersion(), $historicalPack->packVersion(), '<' ) ) {
			return array( 'code' => 'template_pack_changed' );
		}

		$managedHashes = array();
		$files         = array();
		foreach ( $newRendered as $path => $content ) {
			$managedHashes[ $path ] = hash( 'sha256', $content );
			if ( ! hash_equals( hash( 'sha256', $oldRendered[ $path ] ), $managedHashes[ $path ] ) ) {
				$files[ $path ] = self::file( $path, $content, str_starts_with( $path, 'scripts/' ) ? '100755' : '100644', 'modified', true );
			}
		}
		$receipt['template']      = array_merge(
			array(
				'pack_version'    => $proposedPack->packVersion(),
				'manifest_sha256' => $proposedPack->manifestHash(),
			),
			$proposedPack->identity()
		);
		$receipt['managed_files'] = $managedHashes;
		$newReceipt               = self::json( $receipt );
		if ( array() === $files && hash_equals( hash( 'sha256', $receiptBytes ), hash( 'sha256', $newReceipt ) ) ) {
			return array(
				'code'             => 'managed_profile_current',
				'old_pack_version' => $historicalPack->packVersion(),
			);
		}
		$files[ self::RECEIPT_PATH ] = self::file( self::RECEIPT_PATH, $newReceipt, '100644', 'modified', true );

		try {
			return array(
				'code'             => 'ok',
				'old_pack_version' => $historicalPack->packVersion(),
				'bundle'           => new self( $profile, $proposedPack->packVersion(), $proposedPack->identity(), $proposedPack->manifestHash(), $files, '' ),
			);
		} catch ( Throwable ) {
			return array( 'code' => 'invalid_bundle' );
		}
	}

	public function profile(): string {
		return $this->profile;
	}

	public function packVersion(): string {
		return $this->packVersion;
	}

	/** @return array<string,mixed> */
	public function packIdentity(): array {
		return $this->packIdentity;
	}

	public function manifestHash(): string {
		return $this->manifestHash;
	}

	public function hash(): string {
		return $this->hash;
	}

	public function changedPathHash(): string {
		return $this->changedPathHash;
	}

	public function allowlistHash(): string {
		return $this->allowlistHash;
	}

	/** @return array<string, array{path:string,mode:string,operation:string,content:string,sha256:string,git_sha:string,managed:bool}> */
	public function files(): array {
		return $this->files;
	}

	/** @return list<array{path:string,status:string,sha:string}> */
	public function expectedPullFiles(): array {
		return array_values(
			array_map(
				static fn ( array $file ): array => array(
					'path'   => $file['path'],
					'status' => $file['operation'],
					'sha'    => $file['git_sha'],
				),
				$this->files
			)
		);
	}

	/** @return array{path:string,mode:string,operation:string,content:string,sha256:string,git_sha:string,managed:bool} */
	private static function file( string $path, string $content, string $mode, string $operation, bool $managed ): array {
		if ( '' === $path || strlen( $content ) > 262144 || ! in_array( $mode, array( '100644', '100755' ), true )
			|| ! in_array( $operation, array( 'added', 'modified' ), true ) ) {
			throw new RuntimeException( 'Managed release bundle file is invalid.' );
		}
		return array(
			'path'      => $path,
			'mode'      => $mode,
			'operation' => $operation,
			'content'   => $content,
			'sha256'    => hash( 'sha256', $content ),
			'git_sha'   => sha1( 'blob ' . strlen( $content ) . "\0" . $content ),
			'managed'   => $managed,
		);
	}

	/** @param array<string,mixed> $values */
	private static function render( TemplatePack $pack, string $profile, string $logicalId, array $values ): ?string {
		$result = $pack->render( $profile, $logicalId, $values );
		return 'ok' === $result['code'] && is_string( $result['content'] ?? null ) ? $result['content'] : null;
	}

	private static function packageType( string $profile ): string {
		return str_contains( $profile, '-theme/' ) ? 'theme' : 'plugin';
	}

	/** @param array<string,mixed> $inputs @return array<string,string>|null */
	private static function renderManagedFiles( TemplatePack $pack, string $profile, array $inputs ): ?array {
		$required = array( 'bootstrap_sha', 'default_branch', 'extra_files', 'header_path', 'package_slug', 'package_type', 'release_policy', 'update_uri' );
		if ( array_keys( $inputs ) !== $required || 'stable' !== ( $inputs['release_policy'] ?? null )
			|| ! is_array( $inputs['extra_files'] ?? null ) ) {
			return null;
		}
		$rendered = array(
			self::WORKFLOW_PATH                => self::render(
				$pack,
				$profile,
				'release-workflow',
				array(
					'DEFAULT_BRANCH' => $inputs['default_branch'],
					'PACKAGE_SLUG'   => $inputs['package_slug'],
				)
			),
			'release-please-config.json'       => self::render(
				$pack,
				$profile,
				'release-please-config',
				array(
					'BASE_SHA'         => $inputs['bootstrap_sha'],
					'EXTRA_FILES_JSON' => self::json( $inputs['extra_files'], false ),
					'PACKAGE_SLUG'     => $inputs['package_slug'],
				)
			),
			'scripts/build-release.sh'         => self::render(
				$pack,
				$profile,
				'build-release-script',
				array(
					'HEADER_PATH'  => $inputs['header_path'],
					'PACKAGE_SLUG' => $inputs['package_slug'],
					'PACKAGE_TYPE' => $inputs['package_type'],
				)
			),
			'scripts/verify-release.sh'        => self::render(
				$pack,
				$profile,
				'verify-release-script',
				array(
					'HEADER_PATH'  => $inputs['header_path'],
					'PACKAGE_SLUG' => $inputs['package_slug'],
					'PACKAGE_TYPE' => $inputs['package_type'],
					'UPDATE_URI'   => $inputs['update_uri'],
				)
			),
			'scripts/upload-release-assets.sh' => self::render( $pack, $profile, 'upload-release-assets-script', array() ),
		);
		if ( in_array( null, $rendered, true ) ) {
			return null;
		}
		ksort( $rendered, SORT_STRING );
		return $rendered;
	}

	/** @return array<string,mixed>|null */
	public static function receipt( string $bytes ): ?array {
		try {
			$receipt = json_decode( $bytes, true, 24, JSON_THROW_ON_ERROR );
		} catch ( Throwable ) {
			return null;
		}
		$identityKeys = array(
			'repository_name',
			'repository_id',
			'release_id',
			'release_tag',
			'release_commit',
			'release_target',
			'tag_target',
			'release_draft',
			'release_prerelease',
			'release_immutable',
			'asset_count',
			'asset_id',
			'asset_name',
			'asset_state',
			'asset_content_type',
			'asset_size',
			'asset_digest',
			'asset_sha256',
		);
		$managedPaths = array(
			self::WORKFLOW_PATH,
			'release-please-config.json',
			'scripts/build-release.sh',
			'scripts/upload-release-assets.sh',
			'scripts/verify-release.sh',
		);
		$validPath    = static fn ( mixed $path ): bool => is_string( $path ) && '' !== $path && strlen( $path ) <= 512
			&& ! str_starts_with( $path, '/' ) && ! str_contains( $path, "\0" ) && ! str_contains( $path, '\\' )
			&& 1 !== preg_match( '#(?:\A|/)\.\.?(/|\z)#', $path ) && 1 === preg_match( '//u', $path );
		if ( ! is_array( $receipt ) || array_keys( $receipt ) !== array( 'schema_version', 'consumer_api', 'profile', 'template', 'inputs', 'managed_files' )
			|| 1 !== $receipt['schema_version'] || TemplatePack::CONSUMER_API !== $receipt['consumer_api']
			|| ! is_array( $receipt['profile'] ?? null ) || array_keys( $receipt['profile'] ) !== array( 'id', 'version' ) || 1 !== $receipt['profile']['version']
			|| ! in_array( $receipt['profile']['id'], array( 'source-ready-wordpress-plugin/2', 'source-ready-wordpress-theme/2' ), true )
			|| ! is_array( $receipt['template'] ?? null ) || array_keys( $receipt['template'] ) !== array_merge( array( 'pack_version', 'manifest_sha256' ), $identityKeys )
			|| ! is_string( $receipt['template']['pack_version'] ) || 1 !== preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/D', $receipt['template']['pack_version'] )
			|| ! is_string( $receipt['template']['manifest_sha256'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $receipt['template']['manifest_sha256'] )
			|| 'RocketsAreNostalgic/ran-booster-release-bootstrap-templates' !== $receipt['template']['repository_name']
			|| '1322743261' !== $receipt['template']['repository_id'] || ! is_int( $receipt['template']['release_id'] ) || $receipt['template']['release_id'] < 1
			|| ! is_string( $receipt['template']['release_tag'] ) || ! hash_equals( 'v' . $receipt['template']['pack_version'], $receipt['template']['release_tag'] )
			|| ! is_string( $receipt['template']['release_commit'] ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $receipt['template']['release_commit'] )
			|| ! hash_equals( $receipt['template']['release_commit'], (string) $receipt['template']['release_target'] )
			|| ! hash_equals( $receipt['template']['release_commit'], (string) $receipt['template']['tag_target'] )
			|| false !== $receipt['template']['release_draft'] || false !== $receipt['template']['release_prerelease'] || true !== $receipt['template']['release_immutable']
			|| 1 !== $receipt['template']['asset_count'] || ! is_int( $receipt['template']['asset_id'] ) || $receipt['template']['asset_id'] < 1
			|| 'ran-booster-release-bootstrap-templates.zip' !== $receipt['template']['asset_name'] || 'uploaded' !== $receipt['template']['asset_state']
			|| 'application/zip' !== $receipt['template']['asset_content_type'] || ! is_int( $receipt['template']['asset_size'] )
			|| $receipt['template']['asset_size'] < 1 || $receipt['template']['asset_size'] > 2097152
			|| ! is_string( $receipt['template']['asset_sha256'] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $receipt['template']['asset_sha256'] )
			|| ! hash_equals( 'sha256:' . $receipt['template']['asset_sha256'], (string) $receipt['template']['asset_digest'] )
			|| ! is_array( $receipt['inputs'] ?? null ) || array_keys( $receipt['inputs'] ) !== array( 'bootstrap_sha', 'default_branch', 'extra_files', 'header_path', 'package_slug', 'package_type', 'release_policy', 'update_uri' )
			|| ! is_string( $receipt['inputs']['bootstrap_sha'] ) || 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $receipt['inputs']['bootstrap_sha'] )
			|| ! is_string( $receipt['inputs']['default_branch'] ) || 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._\/-]*[A-Za-z0-9_-])?\z/D', $receipt['inputs']['default_branch'] )
			|| ! is_array( $receipt['inputs']['extra_files'] ) || ! array_is_list( $receipt['inputs']['extra_files'] ) || count( $receipt['inputs']['extra_files'] ) > 256
			|| ! $validPath( $receipt['inputs']['header_path'] )
			|| ! is_string( $receipt['inputs']['package_slug'] ) || 1 !== preg_match( '/\A[a-z0-9](?:[a-z0-9-]{0,198}[a-z0-9])?\z/D', $receipt['inputs']['package_slug'] )
			|| ! in_array( $receipt['inputs']['package_type'], array( 'plugin', 'theme' ), true ) || 'stable' !== $receipt['inputs']['release_policy']
			|| ! is_string( $receipt['inputs']['update_uri'] ) || 1 !== preg_match( '#\Ahttps://github\.com/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\z#D', $receipt['inputs']['update_uri'] )
			|| ( str_contains( $receipt['profile']['id'], '-theme/' ) ? 'theme' : 'plugin' ) !== $receipt['inputs']['package_type']
			|| ! is_array( $receipt['managed_files'] ?? null ) || array_keys( $receipt['managed_files'] ) !== $managedPaths ) {
			return null;
		}
		foreach ( $receipt['inputs']['extra_files'] as $extra ) {
			if ( ! is_array( $extra ) || ! in_array( $extra['type'] ?? null, array( 'generic', 'json' ), true )
				|| ! $validPath( $extra['path'] ?? null )
				|| ( 'generic' === $extra['type'] && array_keys( $extra ) !== array( 'type', 'path' ) )
				|| ( 'json' === $extra['type'] && ( array_keys( $extra ) !== array( 'type', 'path', 'jsonpath' ) || '$.version' !== $extra['jsonpath'] ) ) ) {
				return null;
			}
		}
		foreach ( $receipt['managed_files'] as $path => $hash ) {
			if ( ! is_string( $hash ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $hash ) ) {
				return null;
			}
		}
		return $receipt;
	}

	/** @param array<string,mixed> $receipt @return array<string,mixed>|null */
	private static function receiptIdentity( array $receipt ): ?array {
		$template = $receipt['template'];
		$keys     = array(
			'repository_name',
			'repository_id',
			'release_id',
			'release_tag',
			'release_commit',
			'release_target',
			'tag_target',
			'release_draft',
			'release_prerelease',
			'release_immutable',
			'asset_count',
			'asset_id',
			'asset_name',
			'asset_state',
			'asset_content_type',
			'asset_size',
			'asset_digest',
			'asset_sha256',
		);
		$identity = array_intersect_key( $template, array_flip( $keys ) );
		return array_keys( $identity ) === $keys ? $identity : null;
	}

	private static function json( mixed $value, bool $pretty = true ): string {
		$options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
		if ( $pretty ) {
			$options |= JSON_PRETTY_PRINT;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Throwing deterministic JSON is required for the reviewed bundle hash.
		return json_encode( $value, $options ) . ( $pretty ? "\n" : '' );
	}
}
