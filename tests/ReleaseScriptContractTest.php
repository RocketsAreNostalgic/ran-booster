<?php

declare(strict_types=1);

namespace RAN\Tests;

use PHPUnit\Framework\TestCase;

final class ReleaseScriptContractTest extends TestCase {
	public function test_archive_binds_core_bytes_and_manifest_to_the_commit_across_umasks(): void {
		$this->run_contract(
			<<<'BASH'
			mkdir scripts tools RAN assets views
			for path in scripts/{build-release,verify-release,make-pot}.sh scripts/verify-runtime-dependencies.php \
				.gitattributes .release-please-manifest.json composer.{json,lock} runtime-packaging-policy.json \
				release-files.txt NOTICE.md autoload.php index.php license.txt ran-booster.php readme.txt uninstall.php \
				RAN/{Booster,BoosterServiceProvider}.php RAN/Secrets/SecretsFile.php \
				RAN/AddOn/Portability/PortabilityFacade.php RAN/AddOn/WebhookAssistance/WebhookAssistanceFacade.php \
				RAN/RepositoryProvider/RepositoryWebhook{Fitness,FitnessResult,Management,OperationResult}.php; do
				mkdir -p "$(dirname "$path")"
				cp "$source_root/$path" "$path"
			done
			printf 'body {}\n' > assets/release-fixture.css
			printf '<?php // View fixture.\n' > views/release-fixture.php

			# Provide small local files for the committed packaging policy's surfaces.
			# The real verifier still checks the lock, paths and archive bytes.
			cat > tools/composer <<'COMPOSER'
			#!/usr/bin/env bash
			set -euo pipefail
			case "$1" in
				validate)
					[[ " $* " == *' --strict '* ]]
					touch .validated
					;;
				install)
					[[ -f .validated && " $* " == *' --no-dev '* ]]
					php -r '
						$policy = json_decode(file_get_contents("runtime-packaging-policy.json"), true, 16, JSON_THROW_ON_ERROR);
						foreach ($policy["packages"] as $package) {
							$root = $package["archive_root"];
							mkdir($root, 0755, true);
							foreach ($package["surfaces"] as $surface) {
								$path = $root . "/" . $surface["path"];
								if ($surface["kind"] === "directory") {
									mkdir($path, 0755);
									$path .= "/Fixture.php";
								}
								file_put_contents($path, "<?php // Runtime package fixture.\n");
							}
						}
					'
					;;
				*) exit 1 ;;
			esac
			COMPOSER
			cat > tools/wp <<'WP'
			<?php
			if ($argv[1] === '--info') { exit(0); }
			if ($argv[1] === '--version') { echo "WP-CLI 2.12.0\n"; exit(0); }
			if ($argv[1] !== 'i18n' || $argv[2] !== 'make-pot') { exit(1); }
			file_put_contents($argv[4], "Fixed POT fixture\n");
			WP
			chmod +x tools/{composer,wp}
			export PATH="$PWD/tools:$PATH"

			record_commit 'Seed release fixture'
			committed=$(git rev-parse HEAD)
			printf '# No active entries\n' > release-files.txt
			record_commit 'Commit an empty release manifest'
			empty_manifest=$(git rev-parse HEAD)
			printf 'working-tree-only.txt\n' > release-files.txt

			version=$(php -r 'echo json_decode(file_get_contents(".release-please-manifest.json"), true)["."];')
			archive="build/ran-booster-$version.zip"
			(umask 022; bash scripts/build-release.sh "$committed" "$version")
			cp "$archive" baseline.zip
			cp "$archive.sha256" baseline.sha256
			for mask in 002 077; do
				(umask "$mask"; bash scripts/build-release.sh "$committed" "$version")
				cmp baseline.zip "$archive"
				cmp baseline.sha256 "$archive.sha256"
			done

			reject_with 'does not contain the complete Core runtime allowlist' \
				bash scripts/build-release.sh "$empty_manifest" "$version"
			reject_with 'does not contain the complete Core runtime allowlist' \
				bash scripts/verify-release.sh "$archive" "$version" "$empty_manifest"

			unzip -q "$archive" -d changed
			mkdir tampered
			for path in assets/release-fixture.css ran-booster.php readme.txt; do
				cp "$archive" "tampered/${archive##*/}"
				printf '\n/* Changed fixture bytes. */\n' >> "changed/ran-booster/$path"
				(cd changed && zip -q -0 -X "../tampered/${archive##*/}" "ran-booster/$path")
				reject_with "archived Core file $path does not match the release ref." \
					bash scripts/verify-release.sh "tampered/${archive##*/}" "$version" "$committed"
			done
			BASH
		);
	}

	public function test_candidate_preserves_modes_and_accepts_only_single_value_headers(): void {
		$this->run_contract(
			<<<'BASH'
			printf '{".":"1.2.3"}\n' > .release-please-manifest.json
			printf '# Changelog\n\n## [1.2.3](previous)\n\nPrevious release.\n' > CHANGELOG.md
			printf '<?php\n/**\n * Version: 1.2.3\n */\n' > ran-booster.php
			printf 'Stable tag: 1.2.3\n' > readme.txt
			record_commit 'Seed candidate fixture'
			base=$(git rev-parse HEAD)

			printf '{".":"1.2.4"}\n' > .release-please-manifest.json
			printf '# Changelog\n\n## [1.2.4](current)\n\nCurrent release.\n\n## [1.2.3](previous)\n\nPrevious release.\n' > CHANGELOG.md
			printf '<?php\n/**\n * Version: 1.2.4\n */\n' > ran-booster.php
			printf 'Stable tag: 1.2.4\n' > readme.txt
			record_commit 'Valid release candidate'
			candidate=$(git rev-parse HEAD)
			validator="$source_root/scripts/validate-release-candidate.sh"
			bash "$validator" "$base" "$candidate"

			chmod +x ran-booster.php
			record_commit 'Change bootstrap file mode'
			reject_with 'must preserve generated release file modes' bash "$validator" "$base" HEAD
			git reset --quiet --hard "$candidate"

			printf '<?php\n/**\n * Version: 1.2.4 trailing text\n */\n' > ran-booster.php
			record_commit 'Append text to plugin Version'
			reject_with 'release plugin header does not match' bash "$validator" "$base" HEAD
			git reset --quiet --hard "$candidate"

			printf 'Stable tag: 1.2.4 trailing text\n' > readme.txt
			record_commit 'Append text to Stable tag'
			reject_with 'release Stable tag does not match' bash "$validator" "$base" HEAD
			git reset --quiet --hard "$candidate"

			printf 'Stable tag: 1.2.4\nStable tag: invalid duplicate\n' > readme.txt
			record_commit 'Add a malformed duplicate Stable tag'
			reject_with 'release Stable tag does not match' bash "$validator" "$base" HEAD
			BASH
		);
	}

	public function test_i18n_scripts_clean_up_failed_pot_and_accept_an_empty_jed_set(): void {
		$this->run_contract(
			<<<'BASH'
			mkdir -p scripts tools tests/fixtures/i18n
			cp "$source_root"/scripts/{make-pot,make-test-i18n-fixture}.sh scripts/
			touch tests/fixtures/i18n/ran-booster-fr_FR.po
			touch tests/fixtures/i18n/ran-booster-fr_FR-obsolete.json
			cat > tools/wp <<'WP'
			<?php
			if ($argv[1] === '--info') { exit(0); }
			if ($argv[1] === '--version') { echo "WP-CLI 2.12.0\n"; exit(0); }
			switch ($argv[2]) {
				case 'make-pot': file_put_contents($argv[4], "Partial POT\n"); exit(1);
				case 'make-mo': file_put_contents($argv[4], "MO fixture\n"); exit(0);
				case 'make-json': exit(0);
				default: exit(1);
			}
			WP
			chmod +x tools/wp
			export PATH="$PWD/tools:$PATH"

			reject_with 'WP-CLI could not generate the catalogue' bash scripts/make-pot.sh --check
			[[ -z "$(ls -A "$TMPDIR")" ]]

			/bin/bash scripts/make-test-i18n-fixture.sh
			/bin/bash scripts/make-test-i18n-fixture.sh --check
			[[ ! -e tests/fixtures/i18n/ran-booster-fr_FR-obsolete.json ]]
			[[ -z "$(ls -A "$TMPDIR")" ]]
			BASH
		);
	}

	private function run_contract( string $contract ): void {
		$setup = <<<'BASH'
			exec 2>&1
			set -euo pipefail
			source_root=$1
			work_dir=$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-release-contract.XXXXXX")
			trap 'rm -rf "$work_dir"' EXIT
			mkdir "$work_dir/fixture" "$work_dir/tmp#[fixture]"
			export TMPDIR="$work_dir/tmp#[fixture]"
			cd "$work_dir/fixture"
			git init --quiet --template=''
			git config core.hooksPath /dev/null
			git config core.filemode true
			git config user.name 'Release Contract'
			git config user.email 'release-contract@example.invalid'

			record_commit() {
				git add -A
				git -c commit.gpgsign=false commit --quiet -m "$1"
			}

			reject_with() {
				local expected=$1
				shift
				local output
				if output=$("$@" 2>&1) || [[ "$output" != *"$expected"* ]]; then
					printf 'Expected rejection: %s\nObserved: %s\n' "$expected" "$output" >&2
					return 1
				fi
			}
			BASH;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the actual release scripts against an isolated local fixture.
		$process = proc_open(
			array( '/bin/bash', '-c', $setup . "\n" . $contract, 'release-contract', dirname( __DIR__ ) ),
			array(
				1 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		$output = (string) stream_get_contents( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the subprocess output pipe.
		fclose( $pipes[1] );

		self::assertSame( 0, proc_close( $process ), $output );
	}
}
