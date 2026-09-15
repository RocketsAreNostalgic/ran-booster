from pathlib import Path
import re


def replace_method(text: str, name: str, replacement: str) -> str:
    pattern = re.compile(
        rf"\tpublic function {re.escape(name)}\(\): void \{{.*?(?=\n\tpublic function |\n\tprivate function )",
        re.S,
    )
    text, count = pattern.subn(replacement.rstrip(), text, count=1)
    if count != 1:
        raise SystemExit(f"method {name} replacement count: {count}")
    return text


quality_path = Path('.github/workflows/quality.yml')
quality = quality_path.read_text()

old_trust = '''            for trust_path in \\
              composer.json \\
              composer.lock \\
              package.json \\
'''
new_trust = '''            for trust_path in \\
              composer.json \\
              composer.lock \\
              runtime-packaging-policy.json \\
              package.json \\
'''
if old_trust in quality:
    quality = quality.replace(old_trust, new_trust, 1)
elif new_trust not in quality:
    raise SystemExit('Quality trust-path block is neither old nor updated')

first_checkout = re.compile(
    r'\n      - name: Check out locked neutral updater source\n.*?(?=\n      - name: Build and verify fresh runtime archive)',
    re.S,
)
quality, first_count = first_checkout.subn('', quality, count=1)
if first_count not in (0, 1):
    raise SystemExit(f'Runtime-archive updater checkout removal count: {first_count}')

second_checkout = re.compile(
    r'\n          - name: Check out locked neutral updater source\n.*?(?=\n          - name: Prepare private test temporary directory)',
    re.S,
)
quality, second_count = second_checkout.subn('', quality, count=1)
if second_count not in (0, 1):
    raise SystemExit(f'Quality updater checkout removal count: {second_count}')

readback = re.compile(
    r'\n          - name: Read back the neutral updater runtime contract\n.*?(?=\n          - name: Prove database activation and storage)',
    re.S,
)
replacement = r'''
          - name: Read back the neutral updater runtime contract
            shell: bash
            env:
                RAN_SOURCE_COMMIT: ${{ needs.runtime-archive.outputs.source-commit }}
            run: |
              set -euo pipefail
              source_commit="$RAN_SOURCE_COMMIT"
              [[ "$source_commit" =~ ^[0-9a-f]{40}$ ]]

              expected_project="$(mktemp -d)"
              composer_home="$(mktemp -d)"
              lock_file="$expected_project/composer.lock"
              policy_file="$expected_project/runtime-packaging-policy.json"
              verifier_file="$expected_project/verify-runtime-dependencies.php"
              trap 'rm -rf "$expected_project" "$composer_home"' EXIT

              git show "${source_commit}:composer.json" > "$expected_project/composer.json"
              git show "${source_commit}:composer.lock" > "$lock_file"
              git show "${source_commit}:runtime-packaging-policy.json" > "$policy_file"
              git show "${source_commit}:scripts/verify-runtime-dependencies.php" > "$verifier_file"

              projection="$(php "$verifier_file" --packaging "$lock_file" "$policy_file")"
              mapfile -t neutral_records < <(awk -F '\t' '$7 == "neutral-updater" { print }' <<< "$projection")
              test "${#neutral_records[@]}" -eq 1
              IFS=$'\t' read -r package_name package_version package_reference package_repository package_root surface_specs build_role <<< "${neutral_records[0]}"
              test "$build_role" = 'neutral-updater'
              [[ "$package_version" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]
              [[ "$package_reference" =~ ^[0-9a-f]{40}$ ]]
              [[ "$package_repository" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]
              [[ "$package_root" == vendor/* ]]
              IFS=',' read -r -a neutral_surfaces <<< "$surface_specs"
              printf '%s\n' "${neutral_surfaces[@]}" | grep -Fxq 'file:runtime-copy.json'
              printf '%s\n' "${neutral_surfaces[@]}" | grep -Fxq 'file:runtime.php'

              runtime_version=${package_version#v}
              runtime_protocol="$(jq -er '.extra["ran-updater-runtime-protocol"] | select(type == "number" and . > 0)' "$expected_project/composer.json")"
              COMPOSER_HOME="$composer_home" composer install \
                --working-dir="$expected_project" \
                --no-dev \
                --no-interaction \
                --prefer-dist \
                --no-progress \
                --no-scripts \
                --no-plugins

              plugin_root="$GITHUB_WORKSPACE/build/wordpress/wp-content/plugins/ran-booster"
              runtime_root="$plugin_root/$package_root"
              expected_runtime_root="$expected_project/$package_root"
              runtime_copy="$runtime_root/runtime-copy.json"
              runtime_file="$runtime_root/runtime.php"
              expected_runtime_copy="$expected_runtime_root/runtime-copy.json"
              expected_runtime_file="$expected_runtime_root/runtime.php"

              test -f "$runtime_copy"
              test -f "$runtime_file"
              test -f "$expected_runtime_copy"
              test -f "$expected_runtime_file"
              cmp -s "$expected_runtime_copy" "$runtime_copy"
              cmp -s "$expected_runtime_file" "$runtime_file"
              jq -e \
                --arg version "$runtime_version" \
                --argjson protocol "$runtime_protocol" \
                '.package_version == $version
                  and .runtime_protocol == $protocol
                  and (.package_revision | type == "string" and test("^[0-9a-f]{64}$"))
                  and .runtime_file == "runtime.php"' \
                "$runtime_copy" >/dev/null
              grep -Fq 'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePluginUpdater' "$runtime_file"
'''
quality, count = readback.subn('\n' + replacement.strip('\n'), quality, count=1)
if count != 1:
    raise SystemExit(f'Neutral updater readback replacement count: {count}')

if 'Check out locked neutral updater source' in quality:
    raise SystemExit('Obsolete neutral-updater checkout remains')
if 'dcd9ce2ca20769dc35d6b6bfd46042c17aa53bd3' in quality:
    raise SystemExit('Duplicated release-updater commit remains in Quality')
quality_path.write_text(quality)

platform_path = Path('tests/ReleasePlatformContractTest.php')
platform = platform_path.read_text()
if "self::assertStringContainsString( 'unexpected production package', $result['stderr'] );" not in platform:
    needle = "\t\t\tself::assertNotSame( 0, $result['exit'] );\n\t\t} finally {\n\t\t\t$this->removeTemporaryFile( $path );\n\t\t}\n\t}\n\n\tpublic function testRuntimeDependencyVerifierRejectsRepositoryDrift"
    replacement_test = "\t\t\tself::assertNotSame( 0, $result['exit'] );\n\t\t\tself::assertStringContainsString( 'unexpected production package', $result['stderr'] );\n\t\t} finally {\n\t\t\t$this->removeTemporaryFile( $path );\n\t\t}\n\t}\n\n\tpublic function testRuntimeDependencyVerifierRejectsRepositoryDrift"
    if platform.count(needle) != 1:
        raise SystemExit(f'Unexpected-package assertion target count: {platform.count(needle)}')
    platform = platform.replace(needle, replacement_test, 1)
platform_path.write_text(platform)

workflow_test_path = Path('tests/ReleaseWorkflowContractTest.php')
workflow_test = workflow_test_path.read_text()
trust_old = "\t\t\t'composer.json',\n\t\t\t'composer.lock',\n\t\t\t'package.json',"
trust_new = "\t\t\t'composer.json',\n\t\t\t'composer.lock',\n\t\t\t'runtime-packaging-policy.json',\n\t\t\t'package.json',"
if trust_old in workflow_test:
    workflow_test = workflow_test.replace(trust_old, trust_new, 1)
elif trust_new not in workflow_test:
    raise SystemExit('Workflow contract trust-path block is neither old nor updated')

build_verify_old = "\t\t\t'scripts/build-release.sh',\n\t\t\t'scripts/verify-release.sh',"
build_verify_new = "\t\t\t'scripts/build-release.sh',\n\t\t\t'scripts/verify-runtime-dependencies.php',\n\t\t\t'scripts/verify-release.sh',"
if build_verify_old in workflow_test:
    workflow_test = workflow_test.replace(build_verify_old, build_verify_new, 1)
elif build_verify_new not in workflow_test:
    raise SystemExit('Workflow contract verifier-path block is neither old nor updated')

workflow_test = replace_method(
    workflow_test,
    'testQualityMaterializesTheExactLockedUpdaterForFreshArchives',
    r'''	public function testQualityMaterializesTheExactLockedUpdaterForFreshArchives(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertSame( 0, substr_count( $workflow, 'Check out locked neutral updater source' ) );
		self::assertStringContainsString( 'runtime-packaging-policy.json', $workflow );
		self::assertStringNotContainsString( '.name == "ran/wp-release-updater"', $workflow );
		self::assertStringNotContainsString( 'updater_repository="$(dirname "$GITHUB_WORKSPACE")/ran-wp-release-updater"', $workflow );
		self::assertStringNotContainsString( 'dcd9ce2ca20769dc35d6b6bfd46042c17aa53bd3', $workflow );
	}'''
)

workflow_test = replace_method(
    workflow_test,
    'testQualityReadbackUsesNeutralRuntimeMetadataRatherThanTheRemovedGitHubFacade',
    r'''	public function testQualityReadbackUsesNeutralRuntimeMetadataRatherThanTheRemovedGitHubFacade(): void {
		$workflow = $this->workflow( 'quality.yml' );

		self::assertStringContainsString( 'Read back the neutral updater runtime contract', $workflow );
		self::assertStringContainsString( 'RAN_SOURCE_COMMIT: ${{ needs.runtime-archive.outputs.source-commit }}', $workflow );
		self::assertStringContainsString( 'php "$verifier_file" --packaging "$lock_file" "$policy_file"', $workflow );
		self::assertStringContainsString( '$7 == "neutral-updater"', $workflow );
		self::assertStringContainsString( 'runtime_version=${package_version#v}', $workflow );
		self::assertStringContainsString( '.extra["ran-updater-runtime-protocol"]', $workflow );
		self::assertStringContainsString( 'composer install \\', $workflow );
		self::assertStringContainsString( 'cmp -s "$expected_runtime_copy" "$runtime_copy"', $workflow );
		self::assertStringContainsString( 'cmp -s "$expected_runtime_file" "$runtime_file"', $workflow );
		self::assertStringContainsString( 'RAN\\\\\\\\WPReleaseUpdater\\\\\\\\V1\\\\\\\\WordPress\\\\\\\\NativePluginUpdater', $workflow );
		self::assertStringNotContainsString( 'WP_PLUGIN_DIR . "/ran-booster/vendor/ran/wp-release-updater"', $workflow );
		self::assertStringNotContainsString( '"package_version" => "0.1.0-beta.4"', $workflow );
		self::assertStringNotContainsString( 'ran_booster_release_updater', $workflow );
		self::assertStringNotContainsString( 'selection_fixed', $workflow );
	}'''
)
workflow_test_path.write_text(workflow_test)

Path('.github/workflows/pr139-quality-update.yml').unlink()
Path('scripts/pr139-quality-update.py').unlink()
