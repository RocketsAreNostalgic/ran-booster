# Core V3 base Dashboard C3-2 evidence

Date: 2026-08-11

**Characterized source object:**
`c8671ca75417d9661f0c3cc72a45eb8d136c7e41`

This is the read-only base `Dashboard`/`Dispatcher` gate after the accepted C1
through C3-1 vertical packets. It changes no production or test PHP, public
API, hook, action, capability, nonce, dependency, persistent state, release or
installed WordPress state.

## Decision

**NO-GO for another base ownership extraction. Retain the current
coordinators.** The remaining responsibilities are complete browser-bound
routes or compatibility seams around existing application and presentation
owners. Moving them would either relocate code without physical contraction,
add a runtime type, cross the separate C4 security boundary, or change a
public/protected constructor or method surface.

The programme has removed 410 physical production PHP lines while retaining
253 named runtime types. The visible 390-line remainder to the 800-line
programme floor and 430-line remainder to the original 46,254 shipped / 45,602
backend target remain honest measurements. They do not justify weakening
authority, presentation, outcome evidence or compatibility, and this gate does
not silently lower either target.

One private three-line `Dashboard::addPackageSuccessNotice()` forwarding
method has no production caller. Four signing and isolation tests deliberately
invoke it through reflection. Retargeting those tests directly to
`PackageAdminController::addSuccessNotice()` would permit a tiny dead-code
deletion, but it is not a remaining ownership family or meaningful base
cohesion packet. It is recorded as optional later hygiene rather than used to
misstate this gate as a GO.

## Exact counters

The reviewed passive allowlist is the original 207-line list plus the
presentation-only 445-line `views/provider.php` accepted in C1.

| Measure | Current value |
| --- | ---: |
| Shipped PHP | 46,684 |
| Reviewed passive PHP | 652 |
| Backend PHP | 46,032 |
| Named shipped runtime types | 253 |
| `RAN/Dashboard.php` | 930 lines |
| `RAN/Dispatcher.php` | 570 lines |
| Two-class concentration | 1,500 lines |

`Dashboard` has 16 public methods including its constructor, one protected
method and 28 private methods. `Dispatcher` has two public methods including
its constructor, three protected methods and six private methods. Method count
alone is not a responsibility or deletion case.

## Remaining request and page inventory

`Dispatcher::dispatchPostRequests()` remains the one `admin_init` browser
adapter. It reads the scalar action from the posted `ran_booster` envelope,
selects one of 26 closed actions, and returns for unknown actions:

| Family | Actions | Existing owner after routing | Disposition |
| --- | ---: | --- | --- |
| Diagnostics | 1 | `TroubleshootingService`; Dashboard same-request result | Retain browser capability/nonce, HTMX and termination boundary. |
| Temporary debug capture | 1 | `TemporaryDebugCapture`; Dashboard bounded snapshot/fragment | Retain cohesive capture lifecycle and adapter response. |
| Secure storage | 3 | `SecretsStorageProvisioner`; Dashboard same-request/next-request status | Defer create, adopt and reset custody to C4. |
| Provider profiles | 6 | `ProviderProfileAdminController` | Retain the explicit router and shared HTMX detection. |
| Deployment administration | 3 | `DeploymentAdminController` | Retain the explicit router. |
| Bulk package administration | 2 | `PackageAdminController` | Retain router, native/HTMX redirect and termination. |
| Single-package administration | 10 | `PackageAdminController` | Retain router, native/HTMX redirect and termination. |

The adapter also owns one shared native/HTMX redirect, HTMX detection, and the
two Core-owned debug/diagnostics fragment responses. Those are transport
responsibilities and do not duplicate the application owners.

`Dashboard` remains the registered callback for five exact WordPress pages:

- `ran-booster` through `getIndex()`;
- `ran-booster-plugins-create` through `getPluginsCreate()`;
- `ran-booster-plugins` through `getPlugins()`;
- `ran-booster-themes-create` through `getThemesCreate()`; and
- `ran-booster-themes` through `getThemes()`.

The four package callbacks are thin compatibility wrappers over the accepted
`PackagePagePresenter`, with Dashboard retaining request normalization,
repository/provider/database acquisition, signed feedback and final render.
The root callback retains the allowlisted tab and add-on-tab selection plus
these complete page branches:

| Page branch | Existing owner and Dashboard work | Disposition |
| --- | --- | --- |
| Overview | `OnboardingPresenter`, storage status/recovery projection and the established migration-prompt hook | Retain; storage mutation is C4. |
| Provider tabs | C1 provider presenters, row normalizer and composition/render helpers | Retain the accepted C1 boundary. |
| Portability | Existing package/credential export projection and published portability facade/controller | Retain; no duplicate operation or safe deletion case. |
| Documentation | `ProviderDocumentationPresenter` and exact documentation URL/scope | Retain the already focused owner. |
| Troubleshooting | Diagnostics, debug-capture and deployment-activity projections | Retain beside their same-request fragments and existing owners. |
| Bounded add-on tab | `AdminAddOnRegistry` context and passive add-on rendering | Retain the public bounded registry contract. |
| Base navigation/render | `AdminTabRegistry`, network-aware URLs, capability check, messages, development notice and fixed view locals | Retain the stable final composition boundary. |

The package hooks remain owned by `PackagePagePresenter`; the provider
repository hooks remain owned by `ProviderRepositoryCompositionRenderer`; the
public facade-ready and add-on registration hooks remain in bootstrap. Moving
those hooks into the base classes would reverse the accepted vertical
ownership. The Overview migration-prompt hook remains in the existing rendered
overview view, which is still backend/mixed under the frozen classifier, and
does not justify a new owner.

## Public compatibility and construction residue

Checked-out sibling production code does not construct, subclass or name
`Dashboard` or `Dispatcher`. That evidence cannot prove the absence of unknown
external consumers, so every current public method and the three protected
Dispatcher response/redirect seams remain compatibility surfaces. Dashboard's
protected final-render seam is retained for the same subclass-compatibility
reason.

Core constructs both classes through the request-local reflection container
and binds one shared Dashboard instance before runtime initialization. Tests
still contain three direct `new Dashboard(...)` calls, five direct
`new Dispatcher(...)` calls, one Dashboard subclass, three Dispatcher
subclasses including one anonymous subclass, one further indirect Dispatcher
subclass, two constructor-free Dashboard reflections and five private-method
Dashboard reflection calls across four methods. This coupling is not itself
public API, but it confirms that constructor changes are not a zero-risk
physical deletion.

Two `Dispatcher` constructor arguments are now unused by Dispatcher:

- required positional `PackageRepositoryRequestResolver $packageRepositories`;
  and
- optional positional `?BulkPackageActionService $bulkPackageActions`.

The latter also causes the current reflection container to resolve a redundant
request-local stateless service because that container intentionally resolves
typed optional parameters rather than applying their defaults. Removing either
argument would shift the remaining positional parameters and break current
direct/subclass construction as well as possible unknown external
construction. Replacing the container's optional-parameter behavior would
instead change every optional dependency injection in Core. A custom
Dispatcher factory would add composition code merely to avoid one request-local
object. None is a complete zero-type, compatibility-preserving cohesion case,
so both parameters remain explicit residue.

The public Dashboard fragment, message, storage-result, troubleshooting and
package compatibility methods all retain production callers in the accepted
controllers or composition root. `Dashboard::postPackageOperation()` and
`bulkPackageRedirect()` deliberately keep the established compatibility path
back into `PackageAdminController`; deleting those forwarding calls would
change public behavior rather than remove an owner.

## Rejected further splits

- A diagnostics or debug controller would add a runtime type and mostly move
  the existing browser adapter around already cohesive application owners.
- Folding diagnostics or debug capture into `Dashboard` would mix mutation
  admission and response termination into the page coordinator.
- Secure-storage movement is not an admin LoC packet; its failure and custody
  contract belongs to the separate C4 threat model.
- Moving root tab branches into their presenters would either make presenters
  acquire request/WordPress state or add page-owner types with no proven
  physical deletion.
- Moving portability projection into a public facade would mix browser display
  with the published operation boundary.
- A generic router, command bus, page DTO, presenter hierarchy, registry or
  facade would add concepts without removing an existing responsibility.
- Moving composition into passive views or counting the C1 presentation
  reclassification again would manufacture deletion credit.

## Verification

- The exact Git-object counters above reproduce at
  `c8671ca75417d9661f0c3cc72a45eb8d136c7e41`.
- Source call-site inspection confirms all 26 Dispatcher actions, five
  Dashboard page callbacks, remaining public controller calls and both unused
  constructor arguments.
- Checked-out sibling production searches find no Dashboard or Dispatcher
  construction, subclass or named dependency.
- Full `composer check` reruns characterization 35, 2,076 PHPUnit tests /
  12,729 assertions, updater bootstrap smoke, release-uploader checks and
  PHPCS successfully.
- `pnpm check` reruns formatting, ESLint, Stylelint and 137 asset tests
  successfully.
- `git diff --check` passes for this evidence-only result.

## Next Core gate

Close C3 with this NO-GO/retain result. The next Core ledger action is the
read-only C4 `SecretsFile` threat-model gate. It must characterize encrypted
document custody, canonical path and reachability boundaries, file/lock/key
state, failure behavior and recovery before any source child is authorized.
It may not treat this admin-programme LoC remainder as authority to change
storage.
