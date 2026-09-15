from pathlib import Path
import json
import re


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one target, found {count}")
    return text.replace(old, new, 1)


policy_path = Path("runtime-packaging-policy.json")
policy = json.loads(policy_path.read_text())
expected_surfaces = {
    "ran/updater-support": [("LICENSE", "file"), ("src", "directory")],
    "ran/wp-branch-updater": [
        ("LICENSE", "file"),
        ("bootstrap.php", "file"),
        ("src", "directory"),
    ],
    "ran/wp-release-updater": [
        ("LICENSE", "file"),
        ("bootstrap.php", "file"),
        ("runtime-copy.json", "file"),
        ("runtime.php", "file"),
        ("src", "directory"),
    ],
}
if policy.get("schema") != "ran-booster-runtime-packaging" or policy.get("schema_version") != 1:
    raise SystemExit("unexpected runtime packaging schema")
records = policy.get("packages", [])
if set(expected_surfaces) != {record.get("name") for record in records}:
    raise SystemExit("unexpected runtime package set")
for record in records:
    name = record.get("name")
    expected = expected_surfaces[name]
    if record.get("surfaces") != [path for path, _ in expected]:
        raise SystemExit(f"unexpected current surfaces for {name}")
    record["surfaces"] = [{"path": path, "kind": kind} for path, kind in expected]
policy_path.write_text(json.dumps(policy, indent=4) + "\n")


verifier = Path("scripts/verify-runtime-dependencies.php")
text = verifier.read_text()
pattern = re.compile(
    r"\t\$validatedSurfaces = array\(\);\n\tforeach \( \$surfaces as \$surface \) \{.*?\n\t\}\n\n\tif \( 'neutral-updater' === \$buildRole \) \{",
    re.S,
)
replacement = r'''	$validatedSurfaces = array();
	$seenSurfaces      = array();
	foreach ( $surfaces as $surface ) {
		if ( ! is_array( $surface ) ) {
			fwrite( STDERR, "Runtime packaging policy contains an invalid surface record.\n" );
			exit( 1 );
		}

		$surfaceKeys = array_keys( $surface );
		sort( $surfaceKeys );
		if ( array( 'kind', 'path' ) !== $surfaceKeys ) {
			fwrite( STDERR, "Runtime packaging policy surface record contains an unsupported field.\n" );
			exit( 1 );
		}

		$surfacePath = $surface['path'] ?? null;
		$surfaceKind = $surface['kind'] ?? null;
		if (
			! is_string( $surfacePath )
			|| 1 !== preg_match( '/^[A-Za-z0-9._-]+$/D', $surfacePath )
			|| ! is_string( $surfaceKind )
			|| ! in_array( $surfaceKind, array( 'directory', 'file' ), true )
		) {
			fwrite( STDERR, "Runtime packaging policy contains an unsafe surface record.\n" );
			exit( 1 );
		}

		if ( isset( $seenSurfaces[ $surfacePath ] ) ) {
			fwrite( STDERR, "Runtime packaging policy contains a duplicate surface path.\n" );
			exit( 1 );
		}
		$validatedSurfaces[] = array(
			'path' => $surfacePath,
			'kind' => $surfaceKind,
		);
		$seenSurfaces[ $surfacePath ] = true;
	}

	if ( 'neutral-updater' === $buildRole ) {'''
text, count = pattern.subn(replacement, text, count=1)
if count != 1:
    raise SystemExit(f"verifier surface validation replacement count {count}")
text = replace_once(
    text,
    "\tif ( $packaging ) {\n\t\tprintf(",
    """\tif ( $packaging ) {\n\t\t$surfaceProjection = array();\n\t\tforeach ( $identity['surfaces'] as $surface ) {\n\t\t\t$surfaceProjection[] = $surface['path'] . ':' . $surface['kind'];\n\t\t}\n\n\t\tprintf(""",
    "packaging projection insertion",
)
text = replace_once(
    text,
    "implode( ',', $identity['surfaces'] )",
    "implode( ',', $surfaceProjection )",
    "packaging projection surfaces",
)
verifier.write_text(text)


validation_block = r'''for index in "${!package_roots[@]}"; do
	installed_package=${package_installed[$index]}
	IFS=',' read -r -a surface_specs <<< "${package_surfaces[$index]}"
	[[ -d "$installed_package" && ! -L "$installed_package" ]] \
		|| fail "locked runtime package root is missing or symbolic: $installed_package"
	for surface_spec in "${surface_specs[@]}"; do
		surface=${surface_spec%%:*}
		surface_kind=${surface_spec#*:}
		[[ -n "$surface" && -n "$surface_kind" && "$surface" != "$surface_spec" ]] \
			|| fail 'runtime packaging projection contains an invalid surface record.'
		required_path="$installed_package/$surface"
		[[ ! -L "$required_path" ]] \
			|| fail "runtime dependency surface must not be symbolic: $required_path"
		case "$surface_kind" in
			file)
				[[ -f "$required_path" ]] \
					|| fail "locked runtime package file surface is missing or changed kind: $required_path"
				;;
			directory)
				[[ -d "$required_path" ]] \
					|| fail "locked runtime package directory surface is missing or changed kind: $required_path"
				if find "$required_path" -type l -print -quit | grep -q .; then
					fail 'runtime dependency directory surface must not contain symbolic links.'
				fi
				;;
			*)
				fail "runtime packaging projection contains an unsupported surface kind: $surface_kind"
				;;
		esac
	done
done'''


build = Path("scripts/build-release.sh")
b = build.read_text()
start = b.index('for index in "${!package_roots[@]}"; do', b.index('COMPOSER_HOME="$composer_home" composer install'))
end = b.index("\n\ngit archive \\", start)
b = b[:start] + validation_block + b[end:]
start = b.index('for index in "${!package_roots[@]}"; do', b.index("git archive \\"))
end = b.index("\n\n# This positive provenance marker", start)
copy_block = r'''for index in "${!package_roots[@]}"; do
	package_root=${package_roots[$index]}
	installed_package=${package_installed[$index]}
	IFS=',' read -r -a surface_specs <<< "${package_surfaces[$index]}"
	mkdir -p "$stage_root/$package_root"
	for surface_spec in "${surface_specs[@]}"; do
		surface=${surface_spec%%:*}
		surface_kind=${surface_spec#*:}
		target="$stage_root/$package_root/$surface"
		mkdir -p "$(dirname -- "$target")"
		case "$surface_kind" in
			file)
				cp -- "$installed_package/$surface" "$target"
				;;
			directory)
				cp -R -- "$installed_package/$surface" "$target"
				;;
			*)
				fail "runtime packaging projection contains an unsupported surface kind: $surface_kind"
				;;
		esac
	done
done'''
build.write_text(b[:start] + copy_block + b[end:])


verify = Path("scripts/verify-release.sh")
v = verify.read_text()
start = v.index('for index in "${!package_roots[@]}"; do', v.index('COMPOSER_HOME="$composer_home" composer install'))
end = v.index("\n\narchive_paths=", start)
v = v[:start] + validation_block.replace("locked runtime package root", "committed lock runtime package root") + v[end:]
start = v.index("append_runtime_files() {")
end = v.index('\nfor index in "${!package_roots[@]}"; do', start)
append_block = r'''append_runtime_files() {
	local installed_root=$1
	local package_root=$2
	local surfaces_csv=$3
	local surface_spec surface_path surface_kind
	local -a surface_specs
	IFS=',' read -r -a surface_specs <<< "$surfaces_csv"
	for surface_spec in "${surface_specs[@]}"; do
		surface_path=${surface_spec%%:*}
		surface_kind=${surface_spec#*:}
		case "$surface_kind" in
			directory)
				find "$installed_root/$surface_path" -type f -print
				;;
			file)
				printf '%s\n' "$installed_root/$surface_path"
				;;
			*)
				fail "runtime packaging projection contains an unsupported surface kind: $surface_kind"
				;;
		esac
	done | sed "s#^$installed_root/#ran-booster/$package_root/#" >> "$expected_files"
}'''
v = v[:start] + append_block + v[end:]
start = v.index("compare_runtime_package() {")
end = v.index('\nfor index in "${!package_roots[@]}"; do', start)
compare_block = r'''compare_runtime_package() {
	local installed_root=$1
	local archived_root=$2
	local surfaces_csv=$3
	local surface_spec surface_path surface_kind package_file relative_package_file
	local -a surface_specs
	IFS=',' read -r -a surface_specs <<< "$surfaces_csv"
	for surface_spec in "${surface_specs[@]}"; do
		surface_path=${surface_spec%%:*}
		surface_kind=${surface_spec#*:}
		case "$surface_kind" in
			directory)
				while IFS= read -r package_file || [[ -n "$package_file" ]]; do
					[[ -n "$package_file" ]] || continue
					relative_package_file=${package_file#"$installed_root/"}
					cmp -s "$package_file" "$archived_root/$relative_package_file" \
						|| fail "archived runtime dependency $relative_package_file does not match the committed Composer lock."
				done < <(find "$installed_root/$surface_path" -type f -print | LC_ALL=C sort)
				;;
			file)
				cmp -s "$installed_root/$surface_path" "$archived_root/$surface_path" \
					|| fail "archived runtime dependency $surface_path does not match the committed Composer lock."
				;;
			*)
				fail "runtime packaging projection contains an unsupported surface kind: $surface_kind"
				;;
		esac
	done
}'''
verify.write_text(v[:start] + compare_block + v[end:])


test_path = Path("tests/ReleasePlatformContractTest.php")
t = test_path.read_text()
constant_pattern = re.compile(
    r"\tprivate const RUNTIME_PACKAGING_POLICY = array\(.*?\n\t\);\n\n(?=\tpublic function testComposerDeclaresTheZipRuntimeRequirement)",
    re.S,
)
constant = r'''	private const RUNTIME_PACKAGING_POLICY = array(
		'ran/updater-support'    => array(
			'repository'   => 'RocketsAreNostalgic/ran-updater-support',
			'archive_root' => 'vendor/ran/updater-support',
			'surfaces'     => array(
				array( 'path' => 'LICENSE', 'kind' => 'file' ),
				array( 'path' => 'src', 'kind' => 'directory' ),
			),
			'build_role'   => null,
		),
		'ran/wp-branch-updater'  => array(
			'repository'   => 'RocketsAreNostalgic/ran-wp-branch-updater',
			'archive_root' => 'vendor/ran/wp-branch-updater',
			'surfaces'     => array(
				array( 'path' => 'LICENSE', 'kind' => 'file' ),
				array( 'path' => 'bootstrap.php', 'kind' => 'file' ),
				array( 'path' => 'src', 'kind' => 'directory' ),
			),
			'build_role'   => null,
		),
		'ran/wp-release-updater' => array(
			'repository'   => 'RocketsAreNostalgic/ran-wp-release-updater',
			'archive_root' => 'vendor/ran/wp-release-updater',
			'surfaces'     => array(
				array( 'path' => 'LICENSE', 'kind' => 'file' ),
				array( 'path' => 'bootstrap.php', 'kind' => 'file' ),
				array( 'path' => 'runtime-copy.json', 'kind' => 'file' ),
				array( 'path' => 'runtime.php', 'kind' => 'file' ),
				array( 'path' => 'src', 'kind' => 'directory' ),
			),
			'build_role'   => 'neutral-updater',
		),
	);

'''
t, count = constant_pattern.subn(constant, t, count=1)
if count != 1:
    raise SystemExit(f"ReleasePlatform policy constant replacement count {count}")
t = replace_once(
    t,
    "$policy['packages'][0]['surfaces'][] = '../outside-package';",
    "$policy['packages'][0]['surfaces'][] = array( 'path' => '../outside-package', 'kind' => 'file' );",
    "unsafe surface fixture",
)
marker = '\n\tpublic function testReleaseScriptsConsumeTheSharedPackagingPolicyProjection(): void {'
insert = r'''
	public function testRuntimeDependencyVerifierRejectsNestedSurfacePolicy(): void {
		$policy = $this->readPackagingPolicy();
		self::assertIsArray( $policy['packages'][0] ?? null );
		self::assertIsArray( $policy['packages'][0]['surfaces'] ?? null );
		$policy['packages'][0]['surfaces'][] = array(
			'path' => 'runtime/bootstrap.php',
			'kind' => 'file',
		);
		$path = $this->writeTemporaryJson( $policy );

		try {
			$result = $this->runRuntimeDependencyVerifier(
				dirname( __DIR__ ) . '/composer.lock',
				$path
			);
			self::assertNotSame( 0, $result['exit'] );
		} finally {
			$this->removeTemporaryFile( $path );
		}
	}

	public function testRuntimeDependencyVerifierRejectsUnknownSurfaceKind(): void {
		$policy = $this->readPackagingPolicy();
		self::assertIsArray( $policy['packages'][0] ?? null );
		self::assertIsArray( $policy['packages'][0]['surfaces'][0] ?? null );
		$policy['packages'][0]['surfaces'][0]['kind'] = 'anything';
		$path = $this->writeTemporaryJson( $policy );

		try {
			$result = $this->runRuntimeDependencyVerifier(
				dirname( __DIR__ ) . '/composer.lock',
				$path
			);
			self::assertNotSame( 0, $result['exit'] );
		} finally {
			$this->removeTemporaryFile( $path );
		}
	}
'''
t = replace_once(t, marker, insert + marker, "ReleasePlatform test insertion")
old_assertions = """\t\t\tself::assertStringContainsString(\n\t\t\t\t'-type l -print -quit | grep -q .',\n\t\t\t\t$script\n\t\t\t);\n\t\t\tself::assertStringContainsString(\n\t\t\t\t'runtime dependency allowlist must not contain symbolic links.',\n\t\t\t\t$script\n\t\t\t);"""
new_assertions = """\t\t\tself::assertStringContainsString( '[[ -d \"$installed_package\" && ! -L \"$installed_package\" ]]', $script );\n\t\t\tself::assertStringContainsString( '[[ ! -L \"$required_path\" ]]', $script );\n\t\t\tself::assertStringContainsString( '-type l -print -quit | grep -q .', $script );\n\t\t\tself::assertStringContainsString( 'surface_kind=${surface_spec#*:}', $script );"""
test_path.write_text(replace_once(t, old_assertions, new_assertions, "symlink assertions"))


docs = Path("docs/runtime-packaging-policy.md")
d = docs.read_text()
old = "`runtime-packaging-policy.json` owns Booster's product-level packaging decision: which production packages may enter the release ZIP, the GitHub repository each package is expected to resolve from, the archive root for that package, and the files/directories that are permitted to ship. The policy also identifies the single neutral release-updater package needed by the release build environment. It does not carry versions or commit SHAs."
new = old + " Each surface is a direct child of its package root and declares whether it must remain a `file` or `directory`; nested surface declarations are rejected. This prevents a dependency update from silently broadening a file-valued surface into a directory and keeps every possible surface ancestor inside the package root subject to the symlink checks."
docs.write_text(replace_once(d, old, new, "runtime packaging docs"))
