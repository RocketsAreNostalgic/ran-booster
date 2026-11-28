<?php

declare(strict_types=1);

namespace RAN\RepositoryProvider;

use RAN\Provider\ProviderCapability;

/**
 * Stateless webhook lifecycle for repository-webhook-management/4.
 *
 * Every action finds the remote hook by this site's callback URL; there is no
 * hook ID in the contract. A hook matches only when its configured delivery URL
 * is byte-for-byte identical to the `$callback_url` Core supplies. Providers
 * must not normalize either side (case, trailing slash, default port, query
 * order or encoding), so a `?rest_route=` route or an explicit non-default port
 * matches only in exactly the form Core sends.
 *
 * Before acting, the provider reads the repository's complete hook list. An
 * unreadable, invalid or incomplete list, or more than one matching hook, fails
 * closed with no remote change. A hook ID a result reports is informational;
 * Core never stores it or passes it back.
 *
 * Results use RepositoryWebhookOperationResult. A succeeded result whose
 * delivery is `absent` proves that no hook matches. Only an authenticated
 * inbound delivery verifies the signing secret, so no action reports `verified`.
 */
interface RepositoryWebhookLifecycle extends ProviderCapability {
	public const OPERATION = 'repository-webhook-management';
	public const VERSION   = 4;

	/**
	 * Set up or repair: create the hook when none matches, or update the single
	 * matching hook to Core's settings and signing secret. Either path succeeds
	 * only after a readback confirms the settings, with delivery
	 * `configured_pending_delivery`.
	 */
	public function upsert(
		string $repository_id,
		string $repository,
		string $callback_url,
		?string $credential_profile_id,
		#[\SensitiveParameter] string $signing_secret
	): RepositoryWebhookOperationResult;

	/**
	 * Validate readback, with no remote change. One matching hook succeeds with
	 * its configuration compared to Core's settings; no matching hook succeeds
	 * with delivery `absent`.
	 */
	public function check(
		string $repository_id,
		string $repository,
		string $callback_url,
		?string $credential_profile_id
	): RepositoryWebhookOperationResult;

	/**
	 * Ask the provider to send a ping to the single matching hook. Success means
	 * the provider accepted the request, not that a delivery arrived. A provider
	 * with no remote ping endpoint fails with its own code and remediation.
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
