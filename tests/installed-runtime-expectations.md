# Installed runtime expectations

The maintained core-updater, core-executor, native-lock, managed-theme,
release-capability and localisation installed shell proofs accept
`RAN_BOOSTER_TEST_PHP_VERSION` as an exact PHP `major.minor` expectation
(for example `8.5`); leaving it unset retains PHP 8.2. Where a proof already
checks WordPress, `RAN_BOOSTER_TEST_WORDPRESS_VERSION` selects an exact release
(for example `7.1.3`); leaving it unset retains the existing 7.0 branch fixture.
Empty or malformed overrides, mismatched versions and failed version probes
fail the proof. These expectations do not install a runtime, change supported
minimums or relax disposable-site checks. Historical fixtures keep their own pins.

`composer test:localisation-installed-safety` also runs the six harnesses' runtime
assertion contract (`bash tests/installed-runtime-expectations-contract.sh`),
using controlled probes without installing WordPress. This contract is separate
from actual installed compatibility qualification.
