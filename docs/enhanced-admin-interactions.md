# Enhanced admin interactions

Admin Interaction API 2 gives approved add-ons a request-local way to add HTMX
attributes and shared success or error handling to their own protected forms.
It does not grant authorization, discover handlers, expose credentials, or turn
arbitrary markup into a mutation surface.

## Compatibility and service delivery

Consumers must require the exact marker before capturing the facade:

```php
if ( ! defined( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION' )
	|| 2 !== RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION ) {
	return;
}

add_action(
	'ran_booster_admin_interaction_ready',
	static function ( \RAN\Admin\Interaction\AdminInteractionFacade $interaction ): void {
		// Retain for this request only. Do no remote work here.
	}
);
```

The Admin Interaction API is independently versioned from the Add-on, Provider,
Portability, and release APIs. Core contains and redacts a failed listener so
the rest of administration remains available.

## Structured actions

Core automatically enhances normalized structured POST actions returned through
`ran_booster_admin_package_management_actions`. The add-on supplies normalized
action data and its protected handler; it does not call the interaction facade
merely to make a Core-rendered button busy.

Links, ordinary WordPress action links, arbitrary buttons, and raw add-on forms
are not intercepted. A raw form that needs a bounded partial refresh must use
this API explicitly. Core does not scan selectors or infer mutation semantics
from markup.

Bundled GitHub webhook management uses the provider-repositories request target
internally. That is first-party Core composition, not a public provider-row or
operation-panel hook. External add-ons cannot replace the provider table or
register a generic webhook-management panel.

## Eligible forms

Keep a complete normal WordPress POST form with the add-on's own action and
purpose-specific nonce. Create the `AdminInteractionRequest` from trusted
server-side values, then ask Core to append its allowlisted attributes:

```php
<form
	method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	<?php $interaction->renderFormAttributes( $request ); ?>
>
	<input type="hidden" name="action" value="example_protected_action">
	<?php wp_nonce_field( $nonceAction ); ?>
	<!-- Add-on-owned, escaped fields and local error region. -->
</form>
```

Do not copy the generated `hx-*` or `data-ran-booster-*` representation. It
belongs to Core and may change while the semantic contract remains stable. The
declared error element must exist when the form is submitted.

## Handling a mutation

An add-on-owned handler must, in order:

1. require its exact API generations;
2. check the applicable WordPress capability;
3. verify its purpose-specific nonce;
4. validate and normalize submitted fields;
5. reauthorize the exact target and current state;
6. perform the operation;
7. recreate the trusted `AdminInteractionRequest`; and
8. pass one typed `AdminInteractionOutcome` to `respond()`.

`respond()` terminates both enhanced and ordinary requests. An expected
validation failure may replace only the declared local error region. Success
refreshes the request's fixed Core-owned target. Unexpected failures remain on
Core's normal safe error path.

The submitted interaction metadata is transport metadata, not authority. Never
reconstruct the operation, canonical URL, target, capability, nonce action, or
error-region ID from it.

## Assets and accessibility

Core enqueues enhanced-interaction assets only on Booster admin screens. A form
must still work without JavaScript. Busy state must prevent duplicate submits,
errors must be announced, focus must move to meaningful refreshed content, and
the normal POST/redirect/GET path must remain truthful.

## Required tests

Cover at least:

- exact marker and facade delivery, including mismatch behavior;
- capability and nonce failure before mutation;
- allowlisted request construction and same-origin canonical URLs;
- enhanced success and expected validation failure;
- ordinary no-JavaScript POST/redirect/GET behavior;
- duplicate-submit suppression and local error replacement;
- output escaping and focus behavior; and
- no credentials, secrets, provider responses, exceptions, or filesystem paths
  in outcomes, redirects, logs, or markup.

## Non-goals

This API is not a generic router, command bus, modal system, notification
service, credential broker, renderer registry, background transport, or whole
view replacement mechanism.
