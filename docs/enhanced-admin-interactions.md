# Enhanced administration interaction API

RAN Booster Core owns the shared HTMX request lifecycle, busy-button treatment,
authoritative region refresh, persistent error presentation and transient
success feedback used on eligible Booster administration screens.

Compatible add-ons can opt an add-on-owned mutation into that experience
without shipping an HTMX controller, toast implementation or DOM updater. The
add-on continues to own the operation and its complete security boundary.

This document is normative for Administration Interaction API 2.

## Compatibility and service delivery

Core publishes:

```php
RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION === 2
```

An add-on must require that exact generation before using the contract:

```php
if ( ! defined( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' )
	|| 2 !== RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION ) {
	return;
}

add_action(
	'ran_booster_admin_interaction_ready',
	static function (
		\RAN\Admin\Interaction\AdminInteractionFacade $interaction
	): void {
		// Retain this request-local facade in the add-on's controller.
	},
	10,
	1
);
```

Core fires `ran_booster_admin_interaction_ready` once on `plugins_loaded` at
priority 100, after provider registration and before resolving the dashboard or
request dispatcher. Register the listener during normal plugin loading. A late
listener is not replayed.

The supplied `AdminInteractionFacade` is request-local. Do not persist it and
do not perform remote work in the ready callback. Core
contains and redacts a failed listener so the remaining administration UI stays
available.

The Administration Interaction API is independently versioned from the Add-on,
Provider and operation-specific facade APIs. Supporting one generation does not
imply support for another.

## Automatic actions and explicit forms

Core already enhances normalized structured POST actions returned through
`ran_booster_admin_provider_repository_rows` or
`ran_booster_admin_package_management_actions`. For a structured action with
`'type' => 'post'`, Core renders the HTMX and shared-feedback attributes. The
add-on supplies the normalized action data and its protected handler; it does
not call this interaction facade merely to make that Core-rendered button busy
or eligible for shared success feedback.

The following are not automatically intercepted:

- structured actions with `'type' => 'link'`;
- ordinary WordPress plugin or theme action links;
- raw forms emitted by an add-on section or operation panel; and
- arbitrary buttons, links or forms elsewhere in WordPress administration.

Links remain navigation. Raw add-on forms that need a bounded partial refresh
must use this API explicitly. Core deliberately does not apply `hx-boost`, scan
for arbitrary selectors or infer mutation semantics from markup.

Core-owned package forms that contain `ran_booster[action]` have an additional
internal package-screen enhancement. That is not a public add-on contract and
must not be used as a substitute for a structured action or this facade.

## Original API 1 scope retained by API 2

API 1 exposes the Core-owned provider repositories refresh target:

```php
$request = \RAN\Admin\Interaction\AdminInteractionRequest::providerRepositories(
	'assisted-hooks:manage-webhook',
	$canonicalUrl,
	'assisted-hooks-error'
);
```

`providerRepositories()` refreshes the complete provider repositories task
panel. Core rerenders the repository table and the selected-repository
operation panel together through their normal hooks. An add-on never returns
HTML for that Core region and never supplies a CSS selector.

The arguments are:

- `$operation`: a stable, namespaced key matching
  `^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$`;
- `$canonicalUrl`: the current, same-origin WordPress `admin.php` URL for
  `page=ran-booster`, `panel=repositories` and a valid provider `tab`; and
- `$errorRegionId`: the element ID of the add-on-owned local error region,
  without `#`.

The selected repository and heading fragment may remain in the canonical URL.
Core validates the route before rendering attributes or responding, signs the
one-request result marker and restores the clean canonical URL after an
enhanced refresh.

Core may also implement the additive
`TransporterRowAdminInteractionFacade`. Consumers must feature-detect that
interface with `instanceof`. This additive capability advanced the exact Admin
Interaction marker to API 2 without changing the existing
`AdminInteractionFacade` interface.

The additive capability declares one exact add-on-rendered Transporter source
row:

```php
$request = \RAN\Admin\Interaction\AdminInteractionRequest::transporterMigrationSourceRow(
	'wp-pusher:review-package',
	'wp-pusher:package-' . substr( hash( 'sha256', $type . ':' . $installedIdentifier ), 0, 40 ),
	$canonicalUrl,
	'wp-pusher-migration-error'
);
```

The row namespace is bounded presentation identity. Derive it from the
installed package candidate so it remains stable when another migration row is
removed. Do not use a source row ID, source key, source revision, repository
locator or fingerprint. Core hashes the namespace into the exact target key and
selector; transport identity never authorizes review, Apply or source cleanup.

The canonical URL must be the same-origin `admin.php` route with exactly
`page=ran-booster` and `tab=portability`; a heading fragment may remain. Use
`$request->targetElementId()` for the add-on-owned `<tr>` wrapper. Callers never
supply a CSS selector.

Create the same request declaration from trusted server-side values in the form
renderer and its handler. Do not reconstruct the canonical URL, operation or
error-region ID from the submitted interaction metadata.

## Rendering an eligible form

Keep a complete normal WordPress POST form, including the add-on's own
`admin-post.php` action, operation fields and nonce. Ask Core to append its
allowlisted attributes to the opening form tag:

```php
<?php
$request = \RAN\Admin\Interaction\AdminInteractionRequest::providerRepositories(
	'assisted-hooks:manage-webhook',
	$canonicalUrl,
	'assisted-hooks-error'
);
?>
<form
	method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	<?php $interaction->renderFormAttributes( $request ); ?>
>
	<input type="hidden" name="action" value="ran_assisted_hooks_manage_webhook">
	<?php wp_nonce_field( $nonceAction ); ?>

	<!-- Add-on-owned, escaped operation fields. -->

	<div
		id="assisted-hooks-error"
		class="notice notice-error inline"
		data-ran-booster-admin-mutation-error
		role="alert"
		tabindex="-1"
		hidden
	><p></p></div>

	<button type="submit" class="button button-primary">
		<?php esc_html_e( 'Configure webhook', 'ran-assisted-hooks' ); ?>
	</button>
</form>
```

`renderFormAttributes()` prints escaped attributes. It currently declares the
provider repositories target, the local error target, the exact operation,
duplicate-submit suppression and the Core-owned `admin-post.php` HTMX
transport. It does not print the add-on's handler action, nonce or operation
payload.

The declared error-region element must exist when the form is submitted. Core
replaces only that element for an expected failure.

Do not copy attributes generated by `AdminInteractionFacade`, including its
`hx-*` or `data-ran-booster-*` attributes, into an add-on. Their exact
representation belongs to Core and may change within the same public semantic
contract. This rule does not reclassify Core's older, separate package-mutation
refresh contract as Admin Interaction API adoption; migrating one of those
operations requires its own authorization and truthful target design.

## Handling the mutation

The `admin_post_*` handler remains add-on owned. Its order of work is:

1. require the expected Core and add-on API generations;
2. check the applicable WordPress capability;
3. verify the add-on's purpose-specific nonce;
4. validate and normalize the submitted operation fields;
5. reauthorize the exact provider, repository and current state;
6. perform the operation and determine its truthful outcome;
7. recreate the trusted `AdminInteractionRequest`; and
8. pass one typed `AdminInteractionOutcome` to `respond()`.

For example:

```php
public function handleManageWebhook(): never {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage webhooks.', 'ran-assisted-hooks' ) );
	}

	check_admin_referer( $this->nonceAction() );

	// Validate input, reauthorize the exact repository and run the operation.
	$result = $this->operations->manageWebhook( $this->validatedInput() );

	$request = \RAN\Admin\Interaction\AdminInteractionRequest::providerRepositories(
		'assisted-hooks:manage-webhook',
		$this->canonicalRepositoriesUrl(),
		'assisted-hooks-error'
	);

	$outcome = $result->succeeded()
		? \RAN\Admin\Interaction\AdminInteractionOutcome::success(
			$request,
			__( 'GitHub webhook configured.', 'ran-assisted-hooks' )
		)
		: \RAN\Admin\Interaction\AdminInteractionOutcome::validationFailure(
			$request,
			$result->displayMessage()
		);

	$this->interaction->respond( $outcome );
}
```

`respond()` handles both enhanced and ordinary requests and always terminates.
An add-on must not redirect or emit markup after calling it.

For a feature-detected Transporter row capability, a successful review or Apply
may return the newly authoritative row directly:

```php
if ( $interaction instanceof \RAN\Admin\Interaction\TransporterRowAdminInteractionFacade ) {
	$interaction->respondWithTransporterRowFragment(
		$outcome,
		static function ( string $targetElementId ) use ( $row ): void {
			// Emit one fully escaped <tr> with id="$targetElementId".
			render_migration_row( $row, $targetElementId );
		}
	);
}

$interaction->respond( $outcome );
```

The direct response is used only for an exact enhanced success or accepted
request. Validation and unexpected failures retain the existing persistent
local error response. Ordinary requests retain signed POST/redirect/GET and do
not call the fragment renderer.

The renderer must emit one escaped `<tr>` with the Core-supplied ID, no sibling
or nested row, no active-content element, and no more than 512 KiB. If
presentation rendering fails after a truthful successful outcome, Core performs
an exceptional full refresh and preserves the success feedback; it does not
misreport the completed operation as failed.

`isEnhancedRequest()` is available when a handler must choose between an
eligible enhanced protocol and a separate established response protocol. It
checks the HTMX target and the exact declared operation/target metadata only.
It never authorizes a request. Capability, nonce, identity, source revision and
operation validation must already stand independently of its result.

## Outcome semantics

Create outcomes only with these factories:

| Factory                                                            | HTTP status | Meaning and presentation                                                                                                                                      |
| ------------------------------------------------------------------ | ----------: | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AdminInteractionOutcome::success( $request, $message )`           |       `200` | The claimed operation is verified complete. Core refreshes the authoritative region and announces a transient success toast.                                  |
| `AdminInteractionOutcome::accepted( $request, $message )`          |       `202` | Work is truthfully accepted or queued, but not complete. The message must say so precisely; Core refreshes the region and uses the shared transient feedback. |
| `AdminInteractionOutcome::validationFailure( $request, $message )` |       `422` | An expected validation or safe operational failure. Core replaces and focuses the declared persistent inline error. No toast appears.                         |
| `AdminInteractionOutcome::unexpectedFailure( $request )`           |       `500` | An unexpected failure already logged with redacted context. Core supplies fixed generic retry copy and persistent error presentation.                         |

Messages must be non-empty display-safe text of at most 255 bytes and cannot
contain control characters. They cannot contain HTML, exception text,
credentials, tokens, webhook secrets, signed URLs or provider response bodies.
`unexpectedFailure()` intentionally accepts no message so raw internal details
cannot cross the presentation boundary.

Never report `success()` for queued, partial, uncertain or unverified work.
Never turn a warning or failure into transient feedback. A toast is feedback
after authorization and mutation; it is not confirmation and cannot replace a
destructive action's explicit confirmation.

## Enhanced and no-JavaScript response paths

For an enhanced success or accepted outcome, Core follows a signed
post-redirect-get-style HTMX flow:

1. the handler returns the typed outcome to Core;
2. Core directs HTMX to a signed canonical GET;
3. the normal Core renderer rebuilds the operation's declared authoritative
   region, which may be a bounded panel or the complete package page;
4. that canonical authoritative region swaps into place;
5. Core emits the shared success event after the swap; and
6. the clean canonical URL replaces the signed intermediate URL.

The toast is fully populated before it animates. It is fixed to the browser
viewport, remains visible across a canonical region swap, has no dismiss button
and stays visible long enough to be read before dismissing itself.

For an enhanced validation or unexpected failure, Core keeps the current
authoritative panel in place and replaces only the declared error region. The
error remains visible and receives focus.

Core's repository-credential and webhook-secret save modals are a narrow
internal exception to the enhanced success presentation. Their correctable
failures remain in the open modal, but verified save success directs the browser
to the signed canonical result URL as a full-page navigation. The rebuilt page
closes the modal, renders the authoritative profile list and presents the normal
WordPress success notice. Provider-profile deletion continues to use the bounded
authoritative-region refresh. This exception does not change the public add-on
interaction contract or permit add-ons to select their own navigation mode.

Without JavaScript or HTMX, the same form submits to the same protected
handler. Core redirects to a signed canonical URL and renders a normal
WordPress success, info or error notice. The interaction metadata is transport
metadata only; removing it must not weaken security or make the operation
unusable.

## Assets and accessibility

Core owns and enqueues HTMX, the vanilla interaction lifecycle, Booster's
non-reflowing animated-stripe busy state, toast/error CSS and `wp-a11y` on
eligible Booster screens. Participating add-ons must not enqueue another copy
of HTMX, Alpine, jQuery glue, a toast library or a competing mutation
controller.

Core's runtime:

- disables duplicate submission and restores the initiating control;
- preserves the button's layout while showing its busy treatment;
- uses `aria-busy` and the declared idle/busy labels where available;
- announces success through the shared polite live region and
  `wp.a11y.speak()`;
- gives persistent errors `role="alert"` and focuses their local region;
- never moves focus to the transient toast; and
- limits motion to opacity/translation and respects
  `prefers-reduced-motion`.

Add-ons remain responsible for meaningful button labels, field labels,
descriptions, confirmation language and focus order inside their own markup.

## Required tests

Core contract tests must cover:

- the exact version marker and ready-action signature;
- bounded request and outcome validation;
- escaped, allowlisted form attributes;
- exact HTMX target and operation matching;
- signed result markers and rejection of tampering;
- authoritative success/accepted refreshes and exactly one toast event;
- save-modal full-page success navigation with unchanged local failures;
- scoped `422` and generic `500` persistent errors; and
- the normal signed redirect-and-notice fallback.

A participating add-on must test:

- exact API compatibility and fail-closed behaviour when Core is absent or
  incompatible;
- ready-facade capture without work in the callback;
- identical capability, nonce and target reauthorization in enhanced and
  ordinary requests;
- the stable request declaration in both renderer and handler;
- truthful mapping of every operation result to an outcome factory;
- no secret or exception leakage; and
- co-activation with the add-on's other Core facade dependencies.

Use a browser test for the complete interaction when changing markup or
presentation: verify duplicate-submit prevention, button dimensions, scoped
swap, persistent error focus, reduced motion, viewport-fixed toast geometry and
the ordinary fallback.

## Non-goals

API 1 is not:

- a global form or link interceptor;
- a JavaScript framework for add-ons;
- an arbitrary selector, target, route or view registry;
- permission to replace a Core table or panel with add-on HTML;
- authorization, nonce validation, target reauthorization or idempotency;
- a replacement for confirmation, recovery or operation-specific progress;
- a download, polling or streaming protocol; or
- a guarantee that future refresh targets exist before their own versioned API
  generation publishes them.

If an operation cannot truthfully fit the provider repositories target and
these four outcomes, leave its existing protocol intact until Core publishes a
separately designed contract.
