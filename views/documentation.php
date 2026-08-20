<?php

defined( 'WPINC' ) || die;

$providerDocumentation = isset( $providerDocumentation ) && is_array( $providerDocumentation )
	? $providerDocumentation
	: array();
$documentationUrl      = isset( $documentationUrl ) && is_string( $documentationUrl ) ? $documentationUrl : '';
$documentationScope    = isset( $documentationScope ) && is_string( $documentationScope ) ? $documentationScope : 'site';
$documentationHooks    = new \RAN\Admin\DocumentationHookRenderer();
$tabUrls               = array();

foreach ( $tabs as $documentationTab ) {
	if ( isset( $documentationTab['key'], $documentationTab['url'] ) && is_string( $documentationTab['key'] ) && is_string( $documentationTab['url'] ) ) {
		$tabUrls[ $documentationTab['key'] ] = $documentationTab['url'];
	}
}

$adminUrl                 = is_multisite() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
$installPluginUrl         = $adminUrl . '?page=ran-booster-plugins-create';
$installThemeUrl          = $adminUrl . '?page=ran-booster-themes-create';
$managePluginsUrl         = $adminUrl . '?page=ran-booster-plugins';
$manageThemesUrl          = $adminUrl . '?page=ran-booster-themes';
$portabilityUrl           = $tabUrls['portability'] ?? $adminUrl . '?page=ran-booster-transporter';
$troubleshootingUrl       = $tabUrls['troubleshooting'] ?? $adminUrl . '?page=ran-booster&tab=troubleshooting';
$archiveLimitStatus       = ( new \RAN\Deployment\DeploymentArchivePreflight() )->configurationStatus();
$compressedLimitMiB       = is_int( $archiveLimitStatus['compressed'] ) ? intdiv( $archiveLimitStatus['compressed'], 1048576 ) : null;
$expandedLimitMiB         = is_int( $archiveLimitStatus['expanded'] ) ? intdiv( $archiveLimitStatus['expanded'], 1048576 ) : null;
$archiveProviders         = array_values(
	array_filter(
		array_map(
			static fn ( mixed $provider ): string => is_array( $provider ) && is_string( $provider['label'] ?? null ) ? $provider['label'] : '',
			$providerDocumentation
		)
	)
);
$archiveProviderLabels    = 0 === count( $archiveProviders )
	? __( 'Repository providers', 'ran-booster' )
	: ( 1 === count( $archiveProviders ) ? $archiveProviders[0] : implode( ' and ', $archiveProviders ) );
$documentationIndex       = array();
$documentationIds         = array();
$reservedDocumentationIds = array_fill_keys(
	array(
		'ran-booster-documentation-heading',
		'ran-booster-documentation-index-heading',
		'ran-booster-quick-start',
		'ran-booster-portability-guidance',
		'ran-booster-wp-pusher-migration',
		'ran-booster-file-protection',
		'ran-booster-credential-storage',
		'ran-booster-installing-and-managing-packages',
		'ran-booster-push-to-deploy',
		'ran-booster-webhook-cleanup',
		'ran-booster-about',
	),
	true
);

foreach ( $providerDocumentation as $providerGuide ) {
	if ( isset( $providerGuide['code'] ) && is_string( $providerGuide['code'] ) && '' !== $providerGuide['code'] ) {
		$reservedDocumentationIds[ 'ran-booster-documentation-provider-' . $providerGuide['code'] ] = true;
		$reservedDocumentationIds[ 'ran-booster-documentation-webhook-' . $providerGuide['code'] ]  = true;
	}
}
$addDocumentationItem = static function ( string $id, string $summary ) use ( &$documentationIndex, &$documentationIds ): bool {
	if ( '' === $id || isset( $documentationIds[ $id ] ) ) {
		return false;
	}

	$documentationIds[ $id ] = true;
	$documentationIndex[]    = array(
		'id'      => $id,
		'summary' => $summary,
	);

	return true;
};

$addDocumentationItem( 'ran-booster-quick-start', __( 'Quick start', 'ran-booster' ) );
$addDocumentationItem( 'ran-booster-portability-guidance', __( 'Move packages between sites', 'ran-booster' ) );
$addDocumentationItem( 'ran-booster-wp-pusher-migration', __( 'Migrate from WP Pusher', 'ran-booster' ) );
$addDocumentationItem( 'ran-booster-file-protection', __( 'Protect your files and know what actually moves', 'ran-booster' ) );
$addDocumentationItem( 'ran-booster-credential-storage', __( 'Credential storage, retention and removal', 'ran-booster' ) );
$preparedProviderDocumentation = array();

foreach ( $providerDocumentation as $providerGuide ) {
	$providerCode  = isset( $providerGuide['code'] ) && is_string( $providerGuide['code'] ) ? $providerGuide['code'] : '';
	$providerLabel = isset( $providerGuide['label'] ) && is_string( $providerGuide['label'] ) ? $providerGuide['label'] : '';
	$providerId    = 'ran-booster-documentation-provider-' . $providerCode;
	/* translators: %s: Repository provider name. */
	$providerTitle = sprintf( __( '%s credentials and access', 'ran-booster' ), $providerLabel );

	if ( ! $addDocumentationItem( $providerId, $providerTitle ) ) {
		continue;
	}

	$sections                        = $documentationHooks->prepareSections( 'ran_booster_documentation_sections_after_provider_' . $providerCode, $documentationUrl, $documentationScope, $providerCode );
	$sections                        = array_values(
		array_filter(
			$sections,
			static function ( array $section ) use ( $addDocumentationItem, $reservedDocumentationIds ): bool {
				return ! isset( $reservedDocumentationIds[ $section['id'] ] ) && $addDocumentationItem( $section['id'], $section['summary'] );
			}
		)
	);
	$preparedProviderDocumentation[] = array(
		'guide'    => $providerGuide,
		'sections' => $sections,
	);
}

$addDocumentationItem( 'ran-booster-installing-and-managing-packages', __( 'Installing and managing packages', 'ran-booster' ) );
$addDocumentationItem( 'ran-booster-push-to-deploy', __( 'Push-to-Deploy', 'ran-booster' ) );
$preparedGlobalSections = $documentationHooks->prepareSections( 'ran_booster_documentation_sections_before_about', $documentationUrl, $documentationScope );
$preparedGlobalSections = array_values(
	array_filter(
		$preparedGlobalSections,
		static function ( array $section ) use ( $addDocumentationItem, $reservedDocumentationIds ): bool {
			return ! isset( $reservedDocumentationIds[ $section['id'] ] ) && $addDocumentationItem( $section['id'], $section['summary'] );
		}
	)
);
$addDocumentationItem( 'ran-booster-about', __( 'About RAN Booster', 'ran-booster' ) );

?>
<div class="ran-booster-documentation ran-booster-documentation__layout" data-ran-booster-documentation-layout>
<aside class="ran-booster-documentation__index ran-booster-panel" data-ran-booster-documentation-index>
	<nav aria-labelledby="ran-booster-documentation-index-heading">
		<h2 id="ran-booster-documentation-index-heading" class="ran-booster-documentation__index-heading"><?php esc_html_e( 'On this page', 'ran-booster' ); ?></h2>
		<ul class="ran-booster-documentation__index-list">
			<?php foreach ( $documentationIndex as $documentationIndexItem ) { ?>
				<li><a class="ran-booster-documentation__index-link" href="#<?php echo esc_attr( $documentationIndexItem['id'] ); ?>"><?php echo esc_html( $documentationIndexItem['summary'] ); ?></a></li>
			<?php } ?>
		</ul>
		<p class="ran-booster-tile ran-booster-documentation__search-hint"><span class="ran-booster-tile__label"><?php esc_html_e( 'Search this page with', 'ran-booster' ); ?></span> <kbd>⌘F</kbd> <span><?php esc_html_e( 'or', 'ran-booster' ); ?></span> <kbd>Ctrl+F</kbd></p>
	</nav>
</aside>
<section class="ran-booster-page-shell ran-booster-panel ran-booster-documentation__main" aria-labelledby="ran-booster-documentation-heading">
	<header class="ran-booster-page-shell__header ran-booster-documentation__header">
		<p class="ran-booster-eyebrow">Guidance</p>
		<h2 id="ran-booster-documentation-heading" class="ran-booster-page-heading__title"><?php esc_html_e( 'Documentation', 'ran-booster' ); ?></h2>
		<p class="ran-booster-page-heading__description"><?php esc_html_e( 'Install from a public repository first. Add private access and Push-to-Deploy only when you need them.', 'ran-booster' ); ?></p>
	</header>

	<div class="ran-booster-page-shell__body">
		<div class="ran-booster-documentation__sections">
		<details id="ran-booster-quick-start" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section open>
			<summary><?php esc_html_e( 'Quick start', 'ran-booster' ); ?></summary>
			<div class="ran-booster-documentation__content">
				<p><strong><?php esc_html_e( 'Current limitation:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'This plugin currently supports single-site WordPress installations only. Multisite and network activation are not yet supported.', 'ran-booster' ); ?></p>
				<p><strong><?php esc_html_e( 'Database:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Use MySQL 8.0 or newer or MariaDB 10.11 or newer with InnoDB available. MySQL 8.4 LTS is recommended for production. SQLite, PostgreSQL and unverified database-translation drop-ins are not supported.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'If an active site moves outside that database envelope, Booster leaves its stored data unchanged and pauses package storage, Transporter and deployments. Restore a supported database, then use read-only Troubleshooting to confirm compatibility. For planned moves, keep the normal site backup and a current Transporter Blueprint; Booster does not convert custom tables between database engines.', 'ran-booster' ); ?></p>
				<p><strong><?php esc_html_e( 'PHP:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'PHP 8.2 or newer is required, 8.4 LTS or newer recommended.', 'ran-booster' ); ?></p>
				<ol>
					<li>
						<strong><?php esc_html_e( 'Install a package.', 'ran-booster' ); ?></strong>
						<a href="<?php echo esc_url( $installPluginUrl ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a>
						<?php esc_html_e( 'or', 'ran-booster' ); ?>
						<a href="<?php echo esc_url( $installThemeUrl ); ?>"><?php esc_html_e( 'install a theme', 'ran-booster' ); ?></a><?php esc_html_e( ', then choose a public repository and branch. No credential is required.', 'ran-booster' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Add private access only if needed.', 'ran-booster' ); ?></strong>
						<?php esc_html_e( 'If anonymous access fails, open the provider settings and save the narrowest credential that works for this site.', 'ran-booster' ); ?>
						<?php if ( array() !== $providerDocumentation ) { ?>
							<ul class="ran-booster-documentation__inline-links">
								<?php foreach ( $providerDocumentation as $providerGuide ) { ?>
									<?php if ( isset( $providerGuide['code'], $providerGuide['label'], $tabUrls[ $providerGuide['code'] ] ) ) { ?>
										<li><a class="button" href="<?php echo esc_url( $tabUrls[ $providerGuide['code'] ] ); ?>"><?php echo esc_html( $providerGuide['label'] ); ?></a></li>
									<?php } ?>
								<?php } ?>
							</ul>
						<?php } ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Verify the result.', 'ran-booster' ); ?></strong>
						<?php esc_html_e( 'Use Troubleshooting to check this site, then enable Push-to-Deploy only for packages that should update from signed webhooks.', 'ran-booster' ); ?>
						<a href="<?php echo esc_url( $troubleshootingUrl ); ?>"><?php esc_html_e( 'Open Troubleshooting', 'ran-booster' ); ?></a>.
					</li>
				</ol>
			</div>
		</details>

		<details id="ran-booster-portability-guidance" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section>
			<summary><?php esc_html_e( 'Move packages between sites', 'ran-booster' ); ?></summary>
			<div class="ran-booster-documentation__content">
				<h3><?php esc_html_e( 'Move managed packages without copying a development checkout', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'A Transporter Blueprint is a ZIP file that records the Booster-managed packages you select on this site and where their repos live. It is not a copy of the plugin or theme files installed here. The site that opens the Blueprint downloads its files from the committed repository, so local uncommitted changes, and local-only', 'ran-booster' ); ?> <code>node_modules</code> <?php esc_html_e( 'folders, and other ignored and uncommitted dependencies are never included.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Before a database or environment move', 'ran-booster' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Export a current Transporter Blueprint containing every managed package.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Optionally include the selected packages’ file-stored repository credentials in the password-protected archive.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Preview the ZIP successfully and retain a copy off-site.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Keep the normal database and filesystem backup.', 'ran-booster' ); ?></li>
				</ol>
				<p><?php esc_html_e( 'Complete database copies inside the supported MySQL or MariaDB and InnoDB envelope are best effort. A Transporter Blueprint is the supported reconstruction route when the copied Booster tables cannot be trusted, but the target database must still satisfy Booster’s requirements. Booster does not convert custom tables between database engines.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Deployment attempts and delivery-replay history, webhook secrets and provider-side hooks, constants, locks and worker state, and source deployment policy remain target-local. Every installed or adopted package starts with deployment Disabled, so an administrator must deliberately choose a new target policy.', 'ran-booster' ); ?></p>
				<p><strong><?php esc_html_e( 'Transporter workflow:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Select packages for a Transporter Blueprint on this site, open it on the other site, review what would change for each package, then apply only the selected changes. Excluded and unchecked packages remain untouched. Reviewing a Transporter Blueprint never installs anything, changes package settings, or stores the uploaded file — nothing happens until you apply it.', 'ran-booster' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( $portabilityUrl ); ?>"><?php esc_html_e( 'Open Transporter', 'ran-booster' ); ?></a></p>

				<h3><?php esc_html_e( 'Step-by-step: moving a package to another site', 'ran-booster' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Commit and push the exact branch, tag or commit you want the other site to install. A Transporter Blueprint never includes uncommitted local changes.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'If you are actively editing a package’s files on this site, set it to Disabled first so the Transporter Blueprint does not propose replacing files you are working on. Use Manual only when you want administrators on the other site to be able to update it through Booster.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Choose which managed plugins and themes to include, then create a Transporter Blueprint. Credential transfer applies only to those selected packages and only when their credential is stored as a file rather than, for example, a constant. A package-only ZIP is unencrypted; including credentials requires a password and AES-256 encryption.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'On the other site, upload the ZIP, enter its password if you set one, then select Review Transporter Blueprint. Booster shows which packages it can install or adopt and which are already managed, protected, or blocked. An already-managed row can import its verified Blueprint credential without changing package settings; select only the actions you want Booster to apply.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Apply only the changes you choose. Booster re-reads the ZIP and re-checks each package and repository right before applying it, so if something changed since your review, that one change is skipped safely and the others continue.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'On the other site, confirm repository access works and the package behaves as expected. Set up Push-to-Deploy separately, and only once that site is ready to receive automatic deployments.', 'ran-booster' ); ?></li>
				</ol>
			</div>
		</details>

		<details id="ran-booster-wp-pusher-migration" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section>
			<summary><?php esc_html_e( 'Migrate from WP Pusher', 'ran-booster' ); ?></summary>
			<div class="ran-booster-documentation__content">
				<h3><?php esc_html_e( 'Move retained WP Pusher packages into Booster', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'If this site used WP Pusher 3.0.13, the temporary RAN Booster WP Pusher Migrator can adopt supported installed plugins and themes into Booster without reinstalling them. Each adopted package starts with deployment Disabled.', 'ran-booster' ); ?></p>

				<h3><?php esc_html_e( 'Before you start', 'ran-booster' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Back up the site and deactivate WP Pusher. Booster and WP Pusher must not control package changes at the same time.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Install and activate the migrator from its verified release package.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'For private repositories, add the replacement repository credential to Booster first. WP Pusher credentials are not copied.', 'ran-booster' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'Migration workflow', 'ran-booster' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Open Transporter and choose Migrate from WP Pusher.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Review one retained plugin or theme at a time.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Adopt the package into Booster as Disabled.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Confirm the package is managed by Booster before continuing.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'After every package is migrated, review the optional WP Pusher data cleanup and remove the temporary migrator.', 'ran-booster' ); ?></li>
				</ol>
				<p><?php esc_html_e( 'The migrator does not copy credentials, reinstall package files, enable deployments, remove remote webhooks, delete WP Pusher, or contact its license service. Remove provider-side deployment webhooks separately.', 'ran-booster' ); ?></p>
				<p><a class="button" href="<?php echo esc_url( $portabilityUrl ); ?>"><?php esc_html_e( 'Open migration tools', 'ran-booster' ); ?></a></p>
			</div>
		</details>

		<details id="ran-booster-file-protection" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section>
			<summary><?php esc_html_e( 'Protect your files and know what actually moves', 'ran-booster' ); ?></summary>
			<div class="ran-booster-documentation__content">
				<h3><?php esc_html_e( 'Deployment modes protect files you are editing', 'ran-booster' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Disabled', 'ran-booster' ); ?></strong> <?php esc_html_e( 'blocks any replacement, whether triggered by an administrator or by a webhook. Use this for a package whose files on disk you are currently editing.', 'ran-booster' ); ?></li>
					<li><strong><?php esc_html_e( 'Manual', 'ran-booster' ); ?></strong> <?php esc_html_e( 'blocks webhook-triggered replacement (Push-to-Deploy) but still lets an administrator replace the package’s files through Booster.', 'ran-booster' ); ?></li>
					<li><strong><?php esc_html_e( 'Automatic', 'ran-booster' ); ?></strong> <?php esc_html_e( 'allows a matching, signed push from the repository provider to replace the package automatically. Only turn this on for a site that is ready to receive live deployments.', 'ran-booster' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'Booster never changes a package’s mode on its own. It may show a reminder when WordPress reports a local or development environment, plugin or theme development mode is active, WP_DEBUG is enabled, the site uses localhost or a loopback address, or its URL has a nonstandard port. These are safety hints only: Booster will not switch a package to Disabled or Manual for you. Choose each package’s mode explicitly.', 'ran-booster' ); ?></p>

				<h3><?php esc_html_e( 'What a Transporter Blueprint includes', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'A Transporter Blueprint records only the Booster-managed plugins and themes selected on the source site. It can optionally include file-stored repository credentials used by those selected packages inside a password-protected ZIP. The ZIP password is only used for that one request and is never saved anywhere. When a package is installed or adopted from a Transporter Blueprint, it starts with deployment Disabled.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'A Transporter Blueprint does not include WordPress content, uploads, the database, the package files themselves, constants, webhook secrets, provider-side hooks, deployment attempts or delivery-replay history, locks or worker state, or source deployment policy. Push-to-Deploy is always configured separately on each site.', 'ran-booster' ); ?></p>

				<h3><?php esc_html_e( 'Prepare the branch you are deploying', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Booster installs exactly what is committed to the repository ref you select. It does not remove files, run build tools or install dependencies on the target site.', 'ran-booster' ); ?></p>
				<ul>
					<li><?php esc_html_e( 'You, or the package’s maintainer, are responsible for what the repository’s ignore, build and release process leaves in that ref.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Include compiled or built assets and anything the package needs at runtime.', 'ran-booster' ); ?></li>
					<li><code>node_modules</code> <?php esc_html_e( 'is normally only needed to build the package, so it should usually be excluded from the ref.', 'ran-booster' ); ?></li>
					<li><?php esc_html_e( 'Include the Composer', 'ran-booster' ); ?> <code>vendor</code> <?php esc_html_e( 'directory if the package needs it at runtime and does not install it itself.', 'ran-booster' ); ?></li>
				</ul>
			</div>
		</details>

		<details id="ran-booster-credential-storage" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section>
			<summary><?php esc_html_e( 'Credential storage, retention and removal', 'ran-booster' ); ?></summary>
			<div class="ran-booster-documentation__content">
				<h3><?php esc_html_e( 'Where credentials are stored', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Booster stores repository credentials and webhook secrets as authenticated ciphertext in a private JSON file outside the WordPress and plugin directories. Conventional single-site POSIX installations can use the protected Overview’s automatic location. Containers and uncommon layouts should define', 'ran-booster' ); ?> <code>RAN_BOOSTER_ENCRYPTED_SECRETS_DIR</code> <?php esc_html_e( 'as a private directory outside the public web root on durable local storage. The configured value must evaluate to an absolute canonical path; in wp-config.php, an expression such as', 'ran-booster' ); ?> <code>dirname( __DIR__ ) . '/private/ran-booster'</code> <?php esc_html_e( 'provides a safe relative layout anchored to that file. The directory must be owned by PHP, readable and writable by PHP, and mode 0700; Booster manages secrets.json inside it. Existing configurations may continue using the exact-file', 'ran-booster' ); ?> <code>RAN_BOOSTER_ENCRYPTED_SECRETS_FILE</code> <?php esc_html_e( 'with the same dirname( __DIR__ ) anchoring. Raw unanchored relative strings are rejected because web, cron and CLI processes may have different working directories. Booster never displays a saved secret back to you once it is stored.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'The file and lock are owner-only. Its independent encryption key is held in the non-autoloaded', 'ran-booster' ); ?> <code>ran_booster_secrets_key_v1</code> <?php esc_html_e( 'WordPress option, so neither the filesystem nor database alone contains usable credentials. Restore the matching encrypted file and database key from the same backup, and protect that backup as secret material.', 'ran-booster' ); ?></p>

				<h3><?php esc_html_e( 'Use a different storage location', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Choose a durable directory outside the public web root that is owned by the same PHP process user used by WordPress web, cron and CLI requests. The directory must use mode 0700. Do not add group access as a workaround: Booster deliberately requires owner-only access. Define the preferred directory constant in wp-config.php before WordPress loads plugins; Booster creates and manages secrets.json and secrets.json.lock inside it with mode 0600:', 'ran-booster' ); ?></p>
				<code>define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', dirname( __DIR__ ) . '/private/ran-booster' );</code>
				<p><?php esc_html_e( 'In wp-config.php, __DIR__ is the directory containing wp-config.php and dirname( __DIR__ ) is its parent. If wp-config.php is in public_html, do not append public_html again: choose a sibling directory outside it. Every existing parent directory must let the PHP process traverse it; on shared hosting that may require a host-managed execute permission or ACL on an ancestor. The final Booster storage directory must still be owned by PHP and mode 0700.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'To use a hosting environment variable, bridge it to the PHP constant in wp-config.php. An environment variable by itself is not read by Booster:', 'ran-booster' ); ?></p>
				<pre><code>$ran_booster_secrets_dir = getenv( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR' );
if ( is_string( $ran_booster_secrets_dir ) &amp;&amp; '' !== trim( $ran_booster_secrets_dir ) ) {
	define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_DIR', $ran_booster_secrets_dir );
}</code></pre>
				<p><?php esc_html_e( 'The legacy exact-file constant remains supported and can use the same wp-config.php-relative anchor:', 'ran-booster' ); ?></p>
				<code>define( 'RAN_BOOSTER_ENCRYPTED_SECRETS_FILE', dirname( __DIR__ ) . '/private/ran-booster/secrets.json' );</code>
				<p><?php esc_html_e( 'If credentials already exist, move or restore secrets.json, secrets.json.lock and the matching database key together. Do not create an empty secrets file, copy only the encrypted file, or reset only the database key. If either the file or database key is permanently unavailable, the protected Overview can offer an explicit typed reset after Booster verifies the remaining filesystem material and private path; this discards unrecoverable credentials and webhook secrets before fresh storage can be initialized.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Moving credentials between sites', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'A plugin ZIP export or a database-only site migration cannot recreate the encrypted store. Transporter, described above, is the supported way to move selected repository credentials: it protects them inside the Transporter Blueprint ZIP, confirms repository identity and access on the target, and re-encrypts them with the target site’s key. Installing or adopting a package associates the imported credential with that package. Credential-only recovery for an already-managed package imports the credential under a new target-local ID but deliberately leaves the package’s saved credential selection unchanged; assign the recovered credential through the package settings if needed. Constants, webhook secrets and credentials already saved on the target are never exported. When Transporter is not the right fit, add credentials directly through the provider settings screens on the target site, or use the supported deployment constants instead.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Do not overwrite an existing target site’s credentials file. If you ever need to restore the whole file as a recovery step, keep a copy of the target’s existing file first, and treat that restore as something only an approved recovery process should do.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Keep the encrypted file and key protected, and exclude the file from version control and from any release archive.', 'ran-booster' ); ?></p>

				<h3><?php esc_html_e( 'What happens on uninstall', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Deactivating Booster keeps all credentials, package settings and deployment history in place. Deleting Booster through WordPress is the permanent local cleanup action: it removes both Booster custom tables, the encrypted credentials file and its separate database key, other Booster options, user notices, scheduled work, locks and temporary Logging capture. If Booster cannot verify or remove one of those artifacts safely, WordPress keeps the plugin files so an administrator can correct the problem and retry.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Before deleting Booster, export a password-protected Transporter Blueprint with the selected packages and any file-stored repository credentials they use that you may need. A Transporter Blueprint does not include webhook secrets, provider-side webhook registrations, deployment history or constants, and every restored package starts with deployment Disabled.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Uninstall cannot revoke provider credentials or remove remote webhooks. Revoke credentials with each provider and remove remote hooks before uninstalling. Booster removes only the exact wp-config.php definition it created; a manually authored', 'ran-booster' ); ?> <code>RAN_BOOSTER_ENCRYPTED_SECRETS_FILE</code> <?php esc_html_e( 'definition remains under the site operator’s control.', 'ran-booster' ); ?></p>
			</div>
		</details>

		<?php foreach ( $preparedProviderDocumentation as $preparedProviderGuide ) { ?>
			<?php
			$providerGuide  = $preparedProviderGuide['guide'];
			$providerCode   = isset( $providerGuide['code'] ) && is_string( $providerGuide['code'] ) ? $providerGuide['code'] : '';
			$providerLabel  = isset( $providerGuide['label'] ) && is_string( $providerGuide['label'] ) ? $providerGuide['label'] : '';
			$setupAvailable = ! empty( $providerGuide['setup_available'] );
			$credentials    = isset( $providerGuide['credentials'] ) && is_array( $providerGuide['credentials'] ) ? $providerGuide['credentials'] : array();
			$settingsUrl    = $tabUrls[ $providerCode ] ?? '';
			?>
			<details class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section id="ran-booster-documentation-provider-<?php echo esc_attr( $providerCode ); ?>">
				<summary>
					<?php
					/* translators: %s: Repository provider name. */
					echo esc_html( sprintf( __( '%s credentials and access', 'ran-booster' ), $providerLabel ) );
					?>
				</summary>
				<div class="ran-booster-documentation__content">
					<?php if ( $setupAvailable ) { ?>
						<h3><?php esc_html_e( 'Minimum credential permissions', 'ran-booster' ); ?></h3>
						<p><?php echo esc_html( $credentials['summary'] ?? '' ); ?></p>
						<?php if ( '' !== $settingsUrl ) { ?>
							<p><a class="button" href="<?php echo esc_url( $settingsUrl ); ?>">
								<?php
								/* translators: %s: Repository provider name. */
								echo esc_html( sprintf( __( 'Open %s settings', 'ran-booster' ), $providerLabel ) );
								?>
							</a></p>
						<?php } ?>
						<?php if ( ! empty( $credentials['links'] ) && is_array( $credentials['links'] ) ) { ?>
							<h3><?php esc_html_e( 'Official setup guidance', 'ran-booster' ); ?></h3>
							<ul>
								<?php foreach ( $credentials['links'] as $credentialLink ) { ?>
									<?php if ( isset( $credentialLink['label'], $credentialLink['url'] ) ) { ?>
										<li>
											<a href="<?php echo esc_url( $credentialLink['url'] ); ?>" target="_blank" rel="noopener noreferrer">
												<?php echo esc_html( $credentialLink['label'] ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span>
											</a>
										</li>
									<?php } ?>
								<?php } ?>
							</ul>
						<?php } ?>
					<?php } else { ?>
						<p><?php echo esc_html( $credentials['summary'] ?? __( 'Setup guidance is not available for this provider yet.', 'ran-booster' ) ); ?></p>
						<p><?php esc_html_e( 'You can still use a public repository or enter repository details manually when the provider supports package deployment.', 'ran-booster' ); ?></p>
					<?php } ?>
				</div>
				</details>
					<?php $documentationHooks->renderPreparedSections( $preparedProviderGuide['sections'] ); ?>
				<?php } ?>

		<details id="ran-booster-installing-and-managing-packages" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section>
			<summary><?php esc_html_e( 'Installing and managing packages', 'ran-booster' ); ?></summary>
			<div class="ran-booster-documentation__content">
				<p><?php esc_html_e( 'Choose a provider, then search for a repository or enter its account and name manually. Booster resolves repository visibility, stable identity, and the default branch before installation.', 'ran-booster' ); ?></p>
				<ul>
					<li><a href="<?php echo esc_url( $installPluginUrl ); ?>"><?php esc_html_e( 'Install a plugin', 'ran-booster' ); ?></a></li>
					<li><a href="<?php echo esc_url( $installThemeUrl ); ?>"><?php esc_html_e( 'Install a theme', 'ran-booster' ); ?></a></li>
					<li><a href="<?php echo esc_url( $managePluginsUrl ); ?>"><?php esc_html_e( 'Manage deployed plugins', 'ran-booster' ); ?></a></li>
					<li><a href="<?php echo esc_url( $manageThemesUrl ); ?>"><?php esc_html_e( 'Manage deployed themes', 'ran-booster' ); ?></a></li>
				</ul>
				<p><?php esc_html_e( 'A saved credential is required only when the selected repository is private or otherwise inaccessible anonymously. Keep the configured branch aligned with the branch you intend to deploy.', 'ran-booster' ); ?></p>

				<h3><?php esc_html_e( 'Large repository archives', 'ran-booster' ); ?></h3>
					<p>
					<?php
						/* translators: %s: registered repository provider labels. */
						echo esc_html( sprintf( __( '%s provide Booster with a ZIP of the whole repository. Choosing a plugin or theme subdirectory does not make that download smaller. Keep development-only trees such as committed node_modules, caches, test artifacts and unused build output out of the deployed repository ref.', 'ran-booster' ), $archiveProviderLabels ) );
					?>
						</p>
				<?php if ( $archiveLimitStatus['valid'] && null !== $compressedLimitMiB && null !== $expandedLimitMiB ) { ?>
					<p>
						<?php
						$archiveLimitMessage = sprintf(
							/* translators: 1: compressed ZIP limit in MiB, 2: expanded ZIP limit in MiB, 3: configuration source. */
							__( 'This site currently allows repository ZIPs up to %1$d MiB compressed and %2$d MiB expanded (%3$s).', 'ran-booster' ),
							$compressedLimitMiB,
							$expandedLimitMiB,
							'default' === $archiveLimitStatus['source'] ? __( 'Booster default', 'ran-booster' ) : __( 'configured in wp-config.php', 'ran-booster' )
						);
						echo esc_html( $archiveLimitMessage );
						?>
					</p>
				<?php } else { ?>
					<p><strong><?php esc_html_e( 'Archive deployments are currently blocked:', 'ran-booster' ); ?></strong> <?php esc_html_e( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES must be an integer between 1 MiB and 512 MiB.', 'ran-booster' ); ?></p>
				<?php } ?>
				<p><?php esc_html_e( 'If a legitimate repository needs more room, set one target-local, site-wide compressed limit in wp-config.php before WordPress loads Booster:', 'ran-booster' ); ?></p>
				<pre><code>define( 'RAN_BOOSTER_MAX_ARCHIVE_BYTES', 150 * 1024 * 1024 );</code></pre>
				<p><?php esc_html_e( 'The value may be 1–512 MiB. Booster derives an expanded-content limit four times as large and still applies its entry-count, path, identity and free-space checks. The setting affects manual installs, manual and webhook updates, and package installation from a Transporter Blueprint; reviewing a Transporter Blueprint and adopting an already-installed package do not download a repository archive.', 'ran-booster' ); ?></p>
			</div>
		</details>

		<details id="ran-booster-push-to-deploy" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section>
			<summary><?php esc_html_e( 'Push-to-Deploy', 'ran-booster' ); ?></summary>
			<div class="ran-booster-documentation__content">
				<p><?php esc_html_e( 'Push-to-Deploy is optional and configured separately on every target site. Use the matching provider tab as the source of truth for the secret scope, payload URL, content type and event. Follow that provider’s webhook instructions, test delivery, and only then deliberately set the installed package to Automatic.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'The provider screen reports site readiness, stable repository identity and whether a local signing secret applies. Local secret coverage means Booster can authenticate a matching delivery; it does not prove that a remote provider webhook exists. Configure and verify every remote webhook separately.', 'ran-booster' ); ?></p>
				<h3><?php esc_html_e( 'Security, delivery history and hosting', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'HMAC verification authorizes deployment after WordPress accepts the request; it is not DDoS protection for the network, web server, PHP workers or WordPress bootstrap. Use public HTTPS with certificate verification enabled, a unique generated secret for each repository, the provider’s JSON delivery option and only the required push event. Rotate or disable a suspected secret.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'Do not cache, challenge, redirect or transform either accepted callback form: the pretty /wp-json/ route or the equivalent ?rest_route= query route. Proxies must preserve the raw body and signature headers. WordPress may already have buffered the body before Booster runs, so Core enforces its measured 256 KiB limit but does not trust or parse Content-Length as transport protection.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'A provider can time out after Booster has durably admitted work. Provider delivery history remains authoritative for request duration, response status and redelivery. GitHub does not automatically redeliver failed deliveries. For Bitbucket, enable Request History before you need it and treat its request UUID only as a cross-reference; do not assume it remains stable across automatic attempts. Compare the provider identifier with the Provider request ID shown on a webhook attempt in Booster Activity. Probes, ignored events and zero-target deliveries may create no attempt, so absence from Activity is inconclusive.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'At a trusted edge or host, you may bound body and header size, read time and connections for both callback forms. Provider-published IP ranges are optional defence in depth only where the real peer address is trustworthy; keep them current and retain HMAC. Host, Origin, Referer, forwarded headers, reverse DNS, hidden paths and in-WordPress IP checks do not prove webhook identity.', 'ran-booster' ); ?></p>
				<h3 id="ran-booster-webhook-cleanup"><?php esc_html_e( 'Retained webhook setup and cleanup', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Switching a package to Published releases does not remove any existing remote webhook or local signing-secret setup. That package ignores pushes while it remains release-managed, while any branch-managed package using the same repository can continue to need the webhook. Keeping the setup is useful for a temporary switch back and forth.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'For a long-term move to Published releases, a retired site or repository, or a changed callback or credential, review the retained setup. Confirm that no branch-managed package still needs the repository hook or its shared owner secret. Remove the remote provider webhook first, then remove only a local signing secret that is no longer used. Never remove an owner-shared secret merely because one package changed source.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'For an identified GitHub hook, the verified Remove action can remove the remote hook and release only a repository secret created specifically for it. For other providers, open the repository webhook settings from the provider screen, remove the hook at the provider, then use Manage secrets to remove an unused local secret. If ownership or remaining use is uncertain, leave the setup in place.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'The provider must be able to reach this WordPress site over public HTTPS. Localhost sites need a development tunnel. Test delivery from the provider, then review Deployment activity in Troubleshooting for Booster-managed results.', 'ran-booster' ); ?> <a href="<?php echo esc_url( $troubleshootingUrl ); ?>"><?php esc_html_e( 'Open Troubleshooting', 'ran-booster' ); ?></a>.</p>
				<?php foreach ( $providerDocumentation as $providerGuide ) { ?>
					<?php if ( ! empty( $providerGuide['setup_available'] ) && isset( $providerGuide['code'], $providerGuide['label'], $providerGuide['webhook'] ) && is_array( $providerGuide['webhook'] ) ) { ?>
						<?php $webhook = $providerGuide['webhook']; ?>
						<section class="ran-booster-documentation__provider-webhook" aria-labelledby="ran-booster-documentation-webhook-<?php echo esc_attr( $providerGuide['code'] ); ?>">
							<h3 id="ran-booster-documentation-webhook-<?php echo esc_attr( $providerGuide['code'] ); ?>">
								<?php
								/* translators: %s: Repository provider name. */
								echo esc_html( sprintf( __( '%s webhook settings', 'ran-booster' ), $providerGuide['label'] ) );
								?>
							</h3>
							<dl>
								<dt><?php esc_html_e( 'Provider location', 'ran-booster' ); ?></dt>
								<dd><?php echo esc_html( $webhook['location'] ?? '' ); ?></dd>
								<dt><?php esc_html_e( 'Payload URL', 'ran-booster' ); ?></dt>
								<dd><code><?php echo esc_html( rest_url( 'ran-booster/v1/webhooks/' . rawurlencode( $providerGuide['code'] ) ) ); ?></code></dd>
								<dt><?php esc_html_e( 'Content type', 'ran-booster' ); ?></dt>
								<dd><code>application/json</code></dd>
								<dt><?php esc_html_e( 'Event', 'ran-booster' ); ?></dt>
								<dd><?php echo esc_html( $webhook['event'] ?? '' ); ?></dd>
							</dl>
							<p>
								<a href="<?php echo esc_url( $webhook['documentation_url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Webhook setup instructions', 'ran-booster' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span></a>
								<span aria-hidden="true"> · </span>
								<a href="<?php echo esc_url( $webhook['delivery_documentation_url'] ?? '' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Delivery troubleshooting', 'ran-booster' ); ?><span class="screen-reader-text"><?php esc_html_e( ' (opens in a new tab)', 'ran-booster' ); ?></span></a>
							</p>
						</section>
					<?php } ?>
				<?php } ?>
			</div>
			</details>

				<?php $documentationHooks->renderPreparedSections( $preparedGlobalSections ); ?>

			<details id="ran-booster-about" class="ran-booster-documentation__section ran-booster-panel" data-ran-booster-documentation-section>
			<summary><?php esc_html_e( 'About RAN Booster', 'ran-booster' ); ?></summary>
			<div class="ran-booster-documentation__content">
				<h3><?php esc_html_e( 'Where this came from', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Booster is a developer-focused repository deployment plugin for WordPress. It began as a modified derivative of WP Pusher 3.0.13. WP Pusher was created by Peter Suhm, and the inherited source was distributed under GPLv2. We are grateful for Peter’s original work and its central idea: use WordPress’s updater to deploy plugins and themes from version-controlled repositories.', 'ran-booster' ); ?></p>
				<p><?php esc_html_e( 'WP Pusher changed ownership in 2021 and is a separate product. Booster is an independent fork maintained by Rockets Are Nostalgic. It is not affiliated with or endorsed by Peter Suhm, WP Pusher or its owners. The distributed NOTICE.md and license.txt record the full source provenance and license.', 'ran-booster' ); ?></p>

				<h3><?php esc_html_e( 'Why we built this', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'The inherited release depended on a vendor-hosted licensing, OAuth and repository-picker service just to work at all — repository access, credential storage and even the repository browser all ran through that service. We forked it to remove that dependency. Every credential, webhook and deployment decision now lives on your own site, not a third party’s.', 'ran-booster' ); ?></p>

				<h3><?php esc_html_e( 'What’s different', 'ran-booster' ); ?></h3>
				<ul>
					<li><strong><?php esc_html_e( 'Safety.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Booster verifies a repository’s identity and an archive’s contents before it touches a file, and will not let a delayed or out-of-order webhook roll back code that has already been updated. Saved credentials use authenticated encryption in a file with restrictive', 'ran-booster' ); ?> <code>0600</code> <?php esc_html_e( 'permissions; the independent key remains in the WordPress database, so neither a file-only nor database-only copy contains usable credentials.', 'ran-booster' ); ?></li>
					<li><strong><?php esc_html_e( 'Transporter.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'A password-protected Transporter Blueprint moves package and repository configuration, and any credentials you choose, between sites without copying a development checkout.', 'ran-booster' ); ?></li>
					<li><strong><?php esc_html_e( 'Independence & Security.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'No vendor-hosted licensing, OAuth broker or repository-picker service. Further, the inherited codebase stored a private repository’s access token as a WordPress option in the database; Booster instead saves your repository credential in a file on your own site, never in the WordPress database.', 'ran-booster' ); ?></li>
					<li><strong><?php esc_html_e( 'Extensibility.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Additional git providers can be added through a documented registration hook without modifying Booster itself.', 'ran-booster' ); ?></li>
				</ul>

				<h3><?php esc_html_e( 'How a deployment stays honest', 'ran-booster' ); ?></h3>
				<p><?php esc_html_e( 'Every deployment is recorded as one attempt with a clear status —', 'ran-booster' ); ?> <code>queued</code>, <code>running</code>, <code>succeeded</code>, <code>failed</code> <?php esc_html_e( 'or', 'ran-booster' ); ?> <code>needs attention</code> <?php esc_html_e( '— so there is always an honest history and something concrete to point to if something goes wrong. Beyond that record, Booster protects a deployment in a few concrete ways:', 'ran-booster' ); ?></p>
				<ul>
					<li><strong><?php esc_html_e( 'It will not deploy an old version over a newer one.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'If a webhook arrives late or out of order, Booster checks the repository’s current commit before replacing anything, so a delayed notification cannot roll back code that has already been updated.', 'ran-booster' ); ?></li>
					<li><strong><?php esc_html_e( 'It verifies the download before touching any files.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Before installing anything, Booster checks the downloaded archive’s exact contents, confirms it matches the expected package, and confirms every file it contains stays inside the expected folder.', 'ran-booster' ); ?></li>
					<li><strong><?php esc_html_e( 'WordPress Core does the actual file replacement.', 'ran-booster' ); ?></strong> <?php esc_html_e( 'Booster hands the verified files to WordPress’s own updater, which owns backup behaviour during the swap. Booster then checks the result and will show you a warning rather than reporting success when the outcome is unclear.', 'ran-booster' ); ?></li>
				</ul>
				<p><?php esc_html_e( 'Booster delegates package replacement to WordPress Core. It does not build, test or make untrusted repository code safe.', 'ran-booster' ); ?></p>
			</div>
		</details>

		</div>
	</div>
</section>
</div>
