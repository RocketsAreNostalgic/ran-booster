<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

/**
 * Stateless webhook lifecycle for repository-webhook-management/4.
 *
 * A provider implements either this interface or the version 3 pair
 * (RepositoryWebhookFitness and RepositoryWebhookManagement), never both: their
 * OPERATION and VERSION constants and their check() signatures conflict.
 *
 * Every action that contacts the provider follows the same order before it
 * lists or changes any hook:
 *
 * 1. Confirm remotely that `$repository` still resolves to the stable
 *    `$repository_id`. It fails with `repository_identity_mismatch` on a
 *    mismatch, or with a provider-owned code when the repository cannot be
 *    read; either way no hook is read or changed and no secret is sent.
 * 2. Read the repository's complete hook list. An unreadable, invalid or
 *    incomplete list fails with a provider-owned code.
 * 3. Find the hook by this site's callback URL. A hook matches only when its
 *    configured delivery URL is byte-for-byte identical to the `$callback_url`
 *    Core supplies. Providers must not normalize either side (case, trailing
 *    slash, default port, query order or encoding), so a `?rest_route=` route
 *    or an explicit non-default port matches only in exactly the form Core
 *    sends. More than one matching hook fails with code
 *    `matching_hooks_ambiguous`.
 *
 * Every refusal before a remote change has state `failed`, never `ambiguous`
 * or `partial`. Once a remote change is attempted, an unconfirmed outcome is
 * `partial` or `ambiguous` unless the provider proves that no remote change
 * remains or that it was undone. There is no hook ID in the contract; a hook
 * ID a result reports is informational, and Core never stores it or passes it
 * back.
 *
 * Results use RepositoryWebhookOperationResult. A succeeded result whose
 * delivery is `absent` proves that no hook matches. Only an authenticated
 * inbound delivery verifies the signing secret, so no action reports `verified`.
 */
interface RepositoryWebhookLifecycle extends ProviderCapability {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 4;

	/**
	 * Set up or repair. With no matching hook, create one; with one, update it
	 * to Core's settings and signing secret. Success requires a readback that
	 * confirms the settings, with delivery `configured_pending_delivery` and code
	 * `configured_pending_delivery` for a created hook or
	 * `repaired_pending_delivery` for an updated one. upsert() never requests a
	 * ping itself; Core calls ping() when it wants one.
	 *
	 * Interface attributes are not inherited, so implementations must repeat
	 * #[\SensitiveParameter] on `$signing_secret`.
	 */
	public function upsert(
		string $repository_id,
		string $repository,
		string $callback_url,
		?string $credential_profile_id,
		#[\SensitiveParameter] string $signing_secret
	): RepositoryWebhookOperationResult;

	/**
	 * Validate readback, with no remote change. One matching hook succeeds only
	 * when every configuration value is `matched` or `mismatched`; any `unknown`
	 * value fails. No matching hook succeeds with delivery `absent`.
	 */
	public function check(
		string $repository_id,
		string $repository,
		string $callback_url,
		?string $credential_profile_id
	): RepositoryWebhookOperationResult;

	/**
	 * Ask the provider to send a ping to the single matching hook. Success means
	 * the provider accepted the request, not that a delivery arrived. No
	 * matching hook fails with delivery `absent`. A provider with no remote ping
	 * endpoint fails at once with its own code and remediation, without
	 * contacting the remote.
	 */
	public function ping(
		string $repository_id,
		string $repository,
		string $callback_url,
		?string $credential_profile_id
	): RepositoryWebhookOperationResult;

	/**
	 * Remove the single matching hook. Success requires delivery `absent`,
	 * proven by a readable hook list without a match; a hook that was already
	 * absent is a success.
	 */
	public function delete(
		string $repository_id,
		string $repository,
		string $callback_url,
		?string $credential_profile_id
	): RepositoryWebhookOperationResult;
}
