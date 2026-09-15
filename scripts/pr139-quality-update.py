from pathlib import Path
import re

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
if quality.count(old_trust) != 1:
    raise SystemExit(f'Quality trust-path insertion target count: {quality.count(old_trust)}')
quality = quality.replace(old_trust, new_trust, 1)

first_checkout = re.compile(
    r'\n      - name: Check out locked neutral updater source\n.*?(?=\n      - name: Build and verify fresh runtime archive)',
    re.S,
)
quality, count = first_checkout.subn('', quality, count=1)
if count != 1:
    raise SystemExit(f'Runtime-archive updater checkout removal count: {count}')

second_checkout = re.compile(
    r'\n          - name: Check out locked neutral updater source\n.*?(?=\n          - name: Prepare private test temporary directory)',
    re.S,
)
quality, count = second_checkout.subn('', quality, count=1)
if count != 1:
    raise SystemExit(f'Quality updater checkout removal count: {count}')

readback = re.compile(
    r'\n          - name: Read back the neutral updater runtime contract\n.*?(?=\n          - name: Prove database activation and storage)',
    re.S,
)
replacement = r'''
          - name: Read back the neutral updater runtime contract
            shell: bash
            run: |
              set -euo pipefail
              projection="$(php scripts/verify-runtime-dependencies.php --packaging composer.lock runtime-packaging-policy.json)"
              mapfile -t neutral_records < <(awk -F '\t' '$7 == "neutral-updater" { print }' <<< "$projection")
              test "${#neutral_records[@]}" -eq 1
              IFS=$'\t' read -r package_name package_version package_reference package_repository package_root surface_specs build_role <<< "${neutral_records[0]}"
              test "$build_role" = 'neutral-updater'
              [[ "$package_version" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]
              [[ "$package_reference" =~ ^[0-9a-f]{40}$ ]]
              [[ "$package_root" == vendor/* ]]
              IFS=',' read -r -a neutral_surfaces <<< "$surface_specs"
              printf '%s\n' "${neutral_surfaces[@]}" | grep -Fxq 'file:runtime-copy.json'
              printf '%s\n' "${neutral_surfaces[@]}" | grep -Fxq 'file:runtime.php'

              runtime_version=${package_version#v}
              runtime_protocol="$(jq -er '.extra["ran-updater-runtime-protocol"] | select(type == "number" and . > 0)' composer.json)"
              plugin_root="$GITHUB_WORKSPACE/build/wordpress/wp-content/plugins/ran-booster"
              runtime_root="$plugin_root/$package_root"
              runtime_copy="$runtime_root/runtime-copy.json"
              runtime_file="$runtime_root/runtime.php"
              test -f "$runtime_copy"
              test -f "$runtime_file"
              jq -e \
                --arg version "$runtime_version" \
                --argjson protocol "$runtime_protocol" \
                'keys == ["package_revision", "package_version", "php_floor", "runtime_file", "runtime_protocol", "wordpress_floor"]
                  and .package_version == $version
                  and .runtime_protocol == $protocol
                  and (.package_revision | type == "string" and test("^[0-9a-f]{64}$"))
                  and .runtime_file == "runtime.php"
                  and (.php_floor | type == "string" and test("^[0-9]+\\.[0-9]+(?:\\.[0-9]+)?$"))
                  and (.wordpress_floor | type == "string" and test("^[0-9]+\\.[0-9]+(?:\\.[0-9]+)?$"))' \
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

test_path = Path('tests/ReleasePlatformContractTest.php')
test = test_path.read_text()
needle = "\t\t\tself::assertNotSame( 0, $result['exit'] );\n\t\t} finally {\n\t\t\t$this->removeTemporaryFile( $path );\n\t\t}\n\t}\n\n\tpublic function testRuntimeDependencyVerifierRejectsRepositoryDrift"
replacement_test = "\t\t\tself::assertNotSame( 0, $result['exit'] );\n\t\t\tself::assertStringContainsString( 'unexpected production package', $result['stderr'] );\n\t\t} finally {\n\t\t\t$this->removeTemporaryFile( $path );\n\t\t}\n\t}\n\n\tpublic function testRuntimeDependencyVerifierRejectsRepositoryDrift"
if test.count(needle) != 1:
    raise SystemExit(f'Unexpected-package assertion target count: {test.count(needle)}')
test_path.write_text(test.replace(needle, replacement_test, 1))

Path('.github/workflows/pr139-quality-update.yml').unlink()
Path('scripts/pr139-quality-update.py').unlink()
