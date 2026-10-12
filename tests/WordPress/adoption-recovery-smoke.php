<?php

use RAN\AdoptionIdentity;
use RAN\Deployment\DeploymentAttemptRepository;
use RAN\Deployment\DeploymentRequest;
use RAN\Deployment\DeploymentPolicy;
use RAN\Deployment\DeploymentOutcome;
use RAN\Internal\CoreContainer;
use RAN\Admin\PackageRepositoryRequestResolver;
use RAN\PackageOperation;
use RAN\PackageOperationService;
use RAN\RepositoryProvider\ProviderRegistry;
use RAN\RepositoryProvider\RepositoryDescriptor;
use RAN\RepositoryProvider\RepositoryLookupRequest;
use RAN\RepositoryProvider\ProviderCode;
use RAN\Secrets\SecretsFile;
use RAN\Secrets\SiteKeyStore;
use RAN\PackageRemoval\PackageRemovalService;
use RAN\PackageSource;
use RAN\Storage\Database;
use RAN\Storage\PluginRepository;
use RAN\Storage\ThemeRepository;
use RAN\WordPress\ManagedReleaseConfiguration;
use RAN\WordPress\ManagedReleaseStore;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'RAN_BOOSTER_RECOVERY_TEST_DISPOSABLE' ) ) {
	throw new RuntimeException( 'This proof requires an explicitly disposable WP-CLI site.' );
}

$ran_booster_recovery_root     = realpath( ABSPATH );
$ran_booster_recovery_expected = realpath( (string) getenv( 'RAN_BOOSTER_WORDPRESS_PATH' ) );
if ( false === $ran_booster_recovery_root || $ran_booster_recovery_root !== $ran_booster_recovery_expected
	|| is_link( ABSPATH ) || is_link( WP_CONTENT_DIR ) || is_link( WP_PLUGIN_DIR )
	|| realpath( WP_CONTENT_DIR ) !== $ran_booster_recovery_root . '/wp-content'
	|| realpath( WP_PLUGIN_DIR ) !== $ran_booster_recovery_root . '/wp-content/plugins'
	|| realpath( get_theme_root() ) !== $ran_booster_recovery_root . '/wp-content/themes'
	|| (string) get_option( 'siteurl' ) !== getenv( 'RAN_BOOSTER_RECOVERY_TEST_URL' )
	|| is_link( ABSPATH . '.ran-booster-disposable-test-site' )
	|| "RAN Booster disposable test site\n" !== file_get_contents( ABSPATH . '.ran-booster-disposable-test-site' ) ) {
	throw new RuntimeException( 'The exact disposable site boundaries could not be verified.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
require_once __DIR__ . '/../fixtures/ran-booster-release-capability-provider/src/Providers.php';
$ran_booster_recovery_container = require __DIR__ . '/core-container-fixture.php';
if ( ! $ran_booster_recovery_container instanceof CoreContainer ) {
	throw new RuntimeException( 'The installed Core container is unavailable.' );
}

/** Installed storage/service proof; provider network validation and browser UI remain separate. */
final class RAN_Booster_AdoptionRecoveryProof {
	private PluginRepository $plugins;
	private ThemeRepository $themes;
	private PackageRemovalService $removal;
	private ManagedReleaseStore $release;
	private PackageRepositoryRequestResolver $resolver;
	private PackageOperationService $operations;
	private DeploymentAttemptRepository $attempts;
	private string $original_theme;
	private WP_Filesystem_Direct $filesystem;
	private SecretsFile $secrets;
	private SiteKeyStore $key_store;
	private bool $created_key;
	private string $secret_directory;
	private string $credential_id;
	private string $secret_hash;
	/** @var list<array{type:string,id:string,path:string}> */
	private array $fixtures = array();

	public function __construct( CoreContainer $container, private string $run_id ) {
		$this->plugins          = $container->make( PluginRepository::class );
		$provider               = new class() extends \RAN_Booster_ReleaseCapabilityFixture\BaseProvider {
			public function __construct() {
				parent::__construct( 'p2-recovery' );
			}
			public function resolve_repository( RepositoryLookupRequest $request ): RepositoryDescriptor {
				return new RepositoryDescriptor( ProviderCode::parse( 'p2-recovery' ), $request->locator, basename( $request->locator ), sha1( 'p2-recovery' . "\0" . $request->locator ), false, 'main', $request->credential_id );
			}
		};
		$this->resolver         = new PackageRepositoryRequestResolver( new ProviderRegistry( array( $provider ) ) );
		$this->operations       = $container->make( PackageOperationService::class );
		$this->themes           = $container->make( ThemeRepository::class );
		$this->removal          = $container->make( PackageRemovalService::class );
		$this->release          = $container->make( ManagedReleaseStore::class );
		$this->attempts         = $container->make( DeploymentAttemptRepository::class );
		$this->original_theme   = get_stylesheet();
		$this->filesystem       = new WP_Filesystem_Direct( null );
		$this->secret_directory = sys_get_temp_dir() . '/ran-recovery-secret-' . $run_id;
		$this->check( ! file_exists( $this->secret_directory ) && $this->filesystem->mkdir( $this->secret_directory, 0700 ), 'create isolated encrypted credential directory' );
		$this->key_store     = new SiteKeyStore();
		$this->created_key   = null === $this->key_store->load();
		$this->secrets       = new SecretsFile( $this->secret_directory . '/secrets.json', key_store: $this->key_store );
		$this->credential_id = $this->secrets->save_credential(
			'p2-recovery',
			null,
			array(
				'label'         => 'Recovery shared credential',
				'kind'          => 'fixture-token',
				'configuration' => array(),
			),
			'disposable-proof-value'
		);
		$hash                = hash_file( 'sha256', $this->secret_directory . '/secrets.json' );
		if ( false === $hash ) {
			throw new RuntimeException( 'Encrypted credential fixture is unavailable.' );
		}
		$this->secret_hash = $hash;

		$container->make( Database::class )->require_supported();
	}

	public function run(): void {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			foreach ( array( 'branch', 'release' ) as $source ) {
				$this->exercise( $type, $source );
			}
		}
		echo esc_html( 'Four installed recovery combinations preserve files, activation and package data; stale record/file confirmations and native settings authority are rejected.' ) . PHP_EOL;
	}

	private function exercise( string $type, string $source ): void {
		global $wpdb;
		$slug = 'ran-recovery-' . $this->run_id . '-' . $type . '-' . $source;
		$id   = 'plugin' === $type ? $slug . '/entry.php' : $slug;
		$path = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $slug;
		if ( file_exists( $path ) || is_link( $path ) || ! wp_mkdir_p( $path ) ) {
			throw new RuntimeException( 'The proof refuses an existing or unavailable target.' );
		}
		$this->fixtures[] = array(
			'type' => $type,
			'id'   => $id,
			'path' => $path,
		);
		$file             = $path . ( 'plugin' === $type ? '/entry.php' : '/style.css' );
		$bytes            = 'plugin' === $type ? "<?php\n/* Plugin Name: RAN recovery proof\nVersion: 1.0.0\n*/\n" : "/* Theme Name: RAN recovery proof\nVersion: 1.0.0\n*/\n";
		$this->check( $this->filesystem->put_contents( $file, $bytes, 0644 ), 'create installed fixture' );
		if ( 'theme' === $type ) {
			$this->check( $this->filesystem->put_contents( $path . '/index.php', '<?php // Disposable recovery theme.', 0644 ), 'create theme template' );
			wp_clean_themes_cache( false );
			switch_theme( $id );
		} else {
			wp_clean_plugins_cache( false );
			$this->check( null === activate_plugin( $id ), 'activate installed plugin' );
		}
		$data_key = 'ran_recovery_proof_' . $slug;
		update_option( $data_key, array( 'keep' => $slug ), false );
		$this->adopt( $type, $id, $slug );
		$package   = $this->package( $type, $id );
		$old_token = AdoptionIdentity::of( $package );
		$this->check( is_string( $old_token ), 'capture adoption token' );
		if ( ! is_string( $old_token ) ) {
			throw new RuntimeException( 'The stored identity is unavailable.' );
		}
		$request       = new DeploymentRequest( 'example/' . $slug, $this->credential_id, false, 'main', $slug, null, DeploymentPolicy::MANUAL, null, installed_identifier: $id, adoption_token: $old_token );
		$attempt       = $this->attempts->admit_and_claim_manual( 'update', $type, 'p2-recovery', sha1( 'p2-recovery' . "\0" . 'example/' . $slug ), $request, 'main', 'branch', 1 );
		$configuration = new ManagedReleaseConfiguration( $slug, 'plugin' === $type ? 'entry.php' : 'style.css' );
		if ( 'release' === $source ) {
			// This is real branch history preceding the release switch, not native release work.
			$this->attempts->finish( $attempt->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_NO_CHANGE ) );
			$this->check( $this->release->transition( $type, $id, PackageSource::BRANCH, 1, PackageSource::RELEASE_ASSET, $configuration, 0, $old_token ), 'enable release after link' );
		}
		$this->check(
			1 === $wpdb->update(
				ran_booster_table_name(),
				array( 'source_revision' => 0 ),
				array(
					'package'      => $id,
					'package_type' => 'plugin' === $type ? 1 : 2,
				)
			),
			'corrupt stored revision'
		);
		$this->expect_failure( fn () => $this->package( $type, $id ), 'ordinary hydration rejects corruption' );
		$this->check( isset( $this->removal->recovery_targets( $type )[ $id ] ), 'recovery inventory remains reachable' );
		$confirmation = $this->removal->recovery_confirmation( $type, $id );
		if ( 'branch' === $source ) {
			$this->expect_failure( fn () => $this->recover( $type, $id, $confirmation ), 'running admission blocks recovery' );
			$this->attempts->finish( $attempt->get_id(), DeploymentOutcome::from_code( DeploymentOutcome::CODE_NO_CHANGE ) );
		}
		$history_before = $this->attempts->find_exact( $attempt->get_id() )?->safe_data();
		$this->check( $this->filesystem->put_contents( $file, $bytes . "\n/* changed */\n", 0644 ), 'replace confirmed bytes' );
		$this->expect_failure( fn () => $this->recover( $type, $id, $confirmation ), 'changed installed target refuses recovery' );
		$this->check( $this->filesystem->put_contents( $file, $bytes, 0644 ), 'restore fixture bytes' );
		$confirmation = $this->removal->recovery_confirmation( $type, $id );
		$this->check( 1 === $wpdb->update( ran_booster_table_name(), array( 'branch' => 'changed-after-confirmation' ), array( 'package' => $id ) ), 'change confirmed record' );
		$this->expect_failure( fn () => $this->recover( $type, $id, $confirmation ), 'changed row refuses recovery' );
		$confirmation     = $this->removal->recovery_confirmation( $type, $id );
		$transient_name   = 'plugin' === $type ? 'update_plugins' : 'update_themes';
		$before_transient = get_site_transient( $transient_name );
		set_site_transient(
			$transient_name,
			(object) array(
				'response'  => array(
					$id         => (object) array( 'version' => '2.0' ),
					'unrelated' => 'keep',
				),
				'no_update' => array(
					$id         => 'remove',
					'unrelated' => 'keep',
				),
			)
		);
		try {
			$this->recover( $type, $id, $confirmation );
			$this->check( ! ( 'plugin' === $type ? $this->plugins : $this->themes )->has_management_record( $id ), 'only management record removed' );
			$after      = get_site_transient( $transient_name );
			$responses  = is_object( $after ) ? ( $after->response ?? null ) : null;
			$no_updates = is_object( $after ) ? ( $after->no_update ?? null ) : null;
			$this->check( is_array( $responses ) && is_array( $no_updates ) && ! isset( $responses[ $id ] ) && ! isset( $no_updates[ $id ] ) && 'keep' === ( $responses['unrelated'] ?? null ) && 'keep' === ( $no_updates['unrelated'] ?? null ), 'target-only native invalidation' );
			$this->check( $this->filesystem->get_contents( $file ) === $bytes, 'installed bytes unchanged' );
			$this->check( $history_before === $this->attempts->find_exact( $attempt->get_id() )?->safe_data(), 'departed history unchanged' );
			$this->check( 'plugin' === $type ? is_plugin_active( $id ) : get_stylesheet() === $id, 'activation unchanged' );
			$this->check( array( 'keep' => $slug ) === get_option( $data_key ), 'package data unchanged' );
			$this->check( hash_file( 'sha256', $this->secret_directory . '/secrets.json' ) === $this->secret_hash && null !== $this->secrets->credential_material( 'p2-recovery', $this->credential_id ), 'shared encrypted credential retained' );
			foreach ( $this->fixtures as $fixture ) {
				if ( $fixture['id'] !== $id ) {
					$this->check( $this->package( $fixture['type'], $fixture['id'] )->get_credential_id() === $this->credential_id, 'unrelated shared credential reference retained' );
				}
			}
			$this->adopt( $type, $id, $slug );
			$new_token = AdoptionIdentity::of( $this->package( $type, $id ) );
			$this->check( is_string( $new_token ) && $new_token !== $old_token, 'fresh lifetime on retained-file re-adoption' );
			$this->expect_failure( fn () => $this->recover( $type, $id, $confirmation ), 'old recovery form rejects replacement adoption' );
			$this->expect_failure( fn () => $this->attempts->admit_and_claim_manual( 'update', $type, 'p2-recovery', sha1( 'p2-recovery' . "\0" . 'example/' . $slug ), $request, 'main', 'branch', 1 ), 'delayed old admission rejects replacement adoption' );
			try {
				$stale_changed = $this->release->transition( $type, $id, PackageSource::BRANCH, 1, PackageSource::RELEASE_ASSET, $configuration, 0, $old_token );
			} catch ( RuntimeException ) {
				// The real SQL reader rejects the missing old-token row before any source mutation.
				$stale_changed = false;
			}
			$current = $this->package( $type, $id );
			$this->check( ! $stale_changed && AdoptionIdentity::of( $current ) === $new_token && PackageSource::BRANCH === $current->get_source() && 1 === $current->get_source_revision(), 'departed native settings token rejects recreated revision one without mutation' );
			if ( 'release' === $source && is_string( $new_token ) ) {
				$this->check( $this->release->transition( $type, $id, PackageSource::BRANCH, 1, PackageSource::RELEASE_ASSET, $configuration, 0, $new_token ), 'release relink then enable succeeds' );
			}
		} finally {
			set_site_transient( $transient_name, $before_transient );
			delete_option( $data_key );
		}
	}

	private function adopt( string $type, string $id, string $slug ): void {
		$verified = $this->resolver->resolve(
			array(
				'credential_id'                            => $this->credential_id,
				'provider'                                 => 'p2-recovery',
				'repository'                               => 'example/' . $slug,
				'provider_repository_id'                   => 'untrusted-browser-value',
				'branch'                                   => 'main',
				'dry-run'                                  => '1',
				'exact_identifier'                         => '1',
				'plugin' === $type ? 'file' : 'stylesheet' => $id,
			)
		);
		$result   = $this->operations->execute( PackageOperation::from_input( 'install-' . $type, $verified ) );
		$this->check( 'linked' === $result['status'], 'provider-validated existing-target link' );
		$this->check( sha1( 'p2-recovery' . "\0" . 'example/' . $slug ) === $this->package( $type, $id )->get_provider_repository_id(), 'provider identity replaces browser input' );
	}

	private function package( string $type, string $id ): \RAN\Plugin|\RAN\Theme {
		return 'plugin' === $type ? $this->plugins->booster_plugin_from_file( $id ) : $this->themes->booster_theme_from_stylesheet( $id );
	}

	/** @param array{adoption_token:string,digest:string,installed_digest:string} $confirmation */
	private function recover( string $type, string $id, array $confirmation ): void {
		$this->removal->recover( $type, $id, $confirmation['adoption_token'], $confirmation['digest'], $confirmation['installed_digest'] );
	}

	/** @param callable(): mixed $operation */
	private function expect_failure( callable $operation, string $message ): void {
		try {
			$operation();
		} catch ( Throwable ) {
			return;
		}
		$this->check( false, $message );
	}

	private function check( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( 'Installed recovery proof failed: ' . esc_html( $message ) );
		}
	}

	public function cleanup(): void {
		global $wpdb;
		switch_theme( $this->original_theme );
		foreach ( array_reverse( $this->fixtures ) as $fixture ) {
			$this->check( ! is_link( $fixture['path'] ) && realpath( $fixture['path'] ) === $fixture['path'], 'cleanup exact isolated path' );
			$wpdb->delete(
				ran_booster_table_name(),
				array(
					'package'      => $fixture['id'],
					'package_type' => 'plugin' === $fixture['type'] ? 1 : 2,
				)
			);
			if ( 'plugin' === $fixture['type'] ) {
				deactivate_plugins( $fixture['id'], true );
				$result = delete_plugins( array( $fixture['id'] ) );
			} else {
				$result = delete_theme( $fixture['id'] );
			}
			$this->check( true === $result, 'cleanup installed fixture' );
		}
		$this->check( $this->filesystem->delete( $this->secret_directory, true ), 'cleanup isolated encrypted credentials' );
		if ( $this->created_key ) {
			$key = $this->key_store->load();
			$this->check( null !== $key && $this->key_store->delete_exact( $key ), 'cleanup newly created site key' );
		}
	}
}

$ran_booster_recovery_proof = new RAN_Booster_AdoptionRecoveryProof( $ran_booster_recovery_container, bin2hex( random_bytes( 5 ) ) );
try {
	$ran_booster_recovery_proof->run();
} finally {
	$ran_booster_recovery_proof->cleanup();
}
