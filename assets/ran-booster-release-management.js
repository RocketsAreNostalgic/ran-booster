const managedReleaseBrowsers = new WeakSet();
let managedReleaseFormId = 0;

const earlierReleasesDisclosure = (summaryText) => {
	const host = document.createElement('div');
	host.setAttribute(
		'data-ran-booster-ui-disclosure',
		'release-earlier-releases'
	);
	host.setAttribute('data-ran-booster-ui-disclosure-density', 'compact');
	host.className = 'ran-booster-release-settings-disclosure';
	const disclosure = document.createElement('details');
	disclosure.className = 'ran-booster-disclosure';
	disclosure.setAttribute('data-ran-booster-ui-disclosure-fallback', '');
	const summary = document.createElement('summary');
	summary.textContent = summaryText;
	const body = document.createElement('div');
	body.className = 'ran-booster-disclosure__body';
	body.setAttribute('data-ran-booster-ui-disclosure-content', '');
	disclosure.append(summary, body);
	host.append(disclosure);
	return { host, body };
};
const mountDisclosure = (host) =>
	host.dispatchEvent(
		new CustomEvent('ran-booster:ui-disclosure-update', {
			bubbles: true,
		})
	);

// The shared anonymous rate-limit hint from the server: its sentence and a link to the lookup card.
const appendLookupHint = (target, hint) => {
	if (!target || !hint || !hint.text || !hint.link || !hint.url) {
		return;
	}
	try {
		const url = new URL(hint.url, window.location.href);
		if (url.origin !== window.location.origin) {
			return;
		}
		const link = document.createElement('a');
		link.href = url.href;
		link.textContent = hint.link;
		target.append(` ${hint.text} `, link);
	} catch {
		// An unusable link leaves the notice without the hint.
	}
};

// The shared rate-limit sentence: the provider and its retry time on this browser's clock.
const rateLimitMessage = (rateLimit) =>
	window.ranBoosterRateLimit.message(rateLimit);

const renderListingObservations = (
	target,
	data = null,
	showZeroSkips = true
) => {
	if (!target) {
		return;
	}
	target.replaceChildren();
	target.hidden = true;
	if (!data) {
		return;
	}
	const { __, sprintf } = wp.i18n;
	const paragraph = (text) => {
		const row = document.createElement('p');
		row.textContent = text;
		target.append(row);
		target.hidden = false;
	};
	if (data.search_exhausted === true) {
		paragraph(
			__(
				'The release search stopped at its limit. Older eligible releases may not be shown.',
				'ran-booster'
			)
		);
	}
	const report = data.diagnostics;
	if (!report) {
		if (Array.isArray(data.candidates) && data.candidates.length === 0) {
			paragraph(
				__(
					'Skipped-release details are unavailable for this search.',
					'ran-booster'
				)
			);
		}
		return;
	}
	if (showZeroSkips || report.dropped_count > 0) {
		paragraph(
			sprintf(
				/* translators: 1: number of release records scanned, 2: number rejected by listing rules. */
				__(
					'%1$d release records checked; %2$d skipped by listing rules.',
					'ran-booster'
				),
				report.scanned_count,
				report.dropped_count
			)
		);
	}

	const reasons = {
		release_metadata_invalid: __(
			'Release metadata is invalid.',
			'ran-booster'
		),
		release_immutable_invalid: __(
			'The release immutable field is missing or invalid.',
			'ran-booster'
		),
		release_publication_invalid: __(
			'The release publication time is invalid.',
			'ran-booster'
		),
		release_page_invalid: __(
			'The release page does not match this repository.',
			'ran-booster'
		),
		release_draft: __('Draft releases are excluded.', 'ran-booster'),
		tag_not_version: __(
			'The release tag is not an accepted version.',
			'ran-booster'
		),
		prerelease_not_allowed: __(
			'Prereleases are excluded from the selected channel.',
			'ran-booster'
		),
	};
	for (const drop of Array.isArray(report.drops)
		? report.drops.slice(0, 8)
		: []) {
		if (!drop || !Object.hasOwn(reasons, drop.reason)) {
			continue;
		}
		paragraph(
			drop.tag
				? sprintf(
						/* translators: 1: screened original release tag, 2: fixed reason the release was skipped. */
						__('%1$s: %2$s', 'ran-booster'),
						drop.tag,
						reasons[drop.reason]
					)
				: reasons[drop.reason]
		);
	}
	if (report.truncated === true) {
		paragraph(
			__(
				'The skipped-release sample is incomplete. Counts include skips not shown here.',
				'ran-booster'
			)
		);
	}
};

const initializeManagedReleaseBrowser = (managedBrowser) => {
	const { __, sprintf } = wp.i18n;
	const branch = Boolean(managedBrowser.dataset.ranBoosterBranchStable);
	if (
		managedBrowser.dataset.ranBoosterManagedReleaseBrowserDisabled ===
			'true' ||
		!managedBrowser.dataset.ranBoosterManagedReleaseListNonce ||
		(!branch &&
			!managedBrowser.dataset.ranBoosterManagedReleaseInspectNonce)
	) {
		return;
	}
	if (managedReleaseBrowsers.has(managedBrowser)) {
		return;
	}
	managedReleaseBrowsers.add(managedBrowser);
	const observations = managedBrowser.querySelector(
		'[data-ran-booster-release-observations]'
	);
	const candidates = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-candidates]'
	);
	const candidateList = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-candidate-list]'
	);
	const placeholder = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-placeholder]'
	);
	const showPlaceholder = (visible) => {
		if (placeholder) {
			placeholder.hidden = !visible;
		}
	};
	const heading = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-heading]'
	);
	const message = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-message]'
	);
	const retry = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-retry]'
	);
	const review = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-review]'
	);
	const errorNotice = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-error]'
	);
	const errorMessage = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-error-message]'
	);
	let selected = null;
	let sequence = 0;
	const setStatus = (title, text) => {
		if (heading) {
			heading.textContent = title;
		}
		if (message) {
			message.textContent = text;
		}
	};
	const promptReview = () => {
		if (review && selected) {
			review.textContent = sprintf(
				/* translators: %s: selected release version. */
				__('Review release %s', 'ran-booster'),
				selected.candidate.version
			);
		}
		setStatus(__('Release candidates loaded', 'ran-booster'), '');
	};
	const canInspect = (relationship) =>
		relationship === 'newer' ||
		relationship === 'same' ||
		(relationship === 'older' &&
			managedBrowser.dataset.ranBoosterManagedReleaseOlder === 'true');
	const clearError = () => {
		if (errorNotice) {
			errorNotice.hidden = true;
		}
	};
	const showError = (text, hint = null) => {
		if (errorMessage) {
			errorMessage.textContent = text;
			appendLookupHint(errorMessage, hint);
		}
		if (errorNotice) {
			errorNotice.hidden = false;
		}
	};
	const setListBusy = (busy) => {
		managedBrowser.setAttribute('aria-busy', busy ? 'true' : 'false');
		if (candidates) {
			candidates.disabled = busy;
		}
		review?.setAttribute(
			'aria-disabled',
			busy || !selected ? 'true' : 'false'
		);
		if (retry) {
			retry.disabled = busy;
			retry.classList.toggle(
				'ran-booster-enhanced-mutation__submitter--busy',
				busy
			);
			retry.classList.toggle('ran-booster-update-is-active', busy);
		}
	};
	const request = async (operation, extra = {}) => {
		const data = new FormData();
		if (branch) {
			data.append('view_source', 'branch');
		}
		data.append(
			'action',
			operation === 'list'
				? 'ran_booster_managed_release_list_candidates'
				: 'ran_booster_managed_release_inspect'
		);
		data.append(
			'expected_type',
			managedBrowser.dataset.ranBoosterManagedReleaseType
		);
		data.append(
			'expected_identifier',
			managedBrowser.dataset.ranBoosterManagedReleaseIdentifier
		);
		data.append(
			'expected_source_revision',
			managedBrowser.dataset.ranBoosterManagedReleaseRevision
		);
		data.append(
			'release_channel',
			managedBrowser.dataset.ranBoosterManagedReleaseChannel
		);
		data.append(
			'_wpnonce',
			operation === 'list'
				? managedBrowser.dataset.ranBoosterManagedReleaseListNonce
				: managedBrowser.dataset.ranBoosterManagedReleaseInspectNonce
		);
		Object.entries(extra).forEach(([key, value]) =>
			data.append(key, value)
		);
		const ajaxUrl = managedBrowser.dataset.ranBoosterManagedReleaseAjaxUrl;
		let requestUrl = ajaxUrl;
		try {
			const parsedUrl = new URL(ajaxUrl);
			requestUrl = `${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`;
		} catch {
			// Relative AJAX URLs are already safe for same-origin requests.
		}
		const response = await window.fetch(requestUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		});
		if (!response.ok) {
			throw new Error('request_failed', { cause: response.status });
		}
		return response.json();
	};
	const chosenHost = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-chosen]'
	);
	let activeReview;
	const clearChosen = () => {
		activeReview?.teardown();
		activeReview = null;
		chosenHost?.replaceChildren();
	};
	const showChosen = (fields, relationship, version, body, footer) => {
		const form = document.createElement('form');
		form.className = 'ran-booster-switch-confirmation__form';
		form.id = 'ran-booster-managed-release-form-' + ++managedReleaseFormId;
		form.method = 'post';
		form.action = managedBrowser.dataset.ranBoosterManagedReleasePostUrl;
		const values = {
			action: 'ran_booster_release_install_chosen',
			expected_type: managedBrowser.dataset.ranBoosterManagedReleaseType,
			expected_identifier:
				managedBrowser.dataset.ranBoosterManagedReleaseIdentifier,
			expected_source_revision:
				managedBrowser.dataset.ranBoosterManagedReleaseRevision,
			release_channel:
				managedBrowser.dataset.ranBoosterManagedReleaseChannel,
			...fields,
		};
		for (const [name, value] of Object.entries(values)) {
			const input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = value;
			form.append(input);
		}
		const button = document.createElement('button');
		button.type = 'submit';
		button.className = review.className;
		button.classList.replace('is-outline', 'is-solid');
		button.setAttribute('form', form.id);
		if (relationship === 'older') {
			const warning = document.createElement('p');
			warning.className = 'ran-booster-switch-dialog__database';
			warning.textContent = __(
				'Installing the older release replaces the package files. Database changes will remain. Have a recent database backup ready.',
				'ran-booster'
			);
			const label = document.createElement('label');
			label.className = 'ran-booster-switch-dialog__consent';
			const input = document.createElement('input');
			input.type = 'checkbox';
			input.name = 'confirm_older';
			input.value = '1';
			input.required = true;
			button.disabled = true;
			input.addEventListener('change', () => {
				button.disabled = !input.checked;
			});
			label.append(
				input,
				__(
					"I understand the database won't be rolled back.",
					'ran-booster'
				)
			);
			form.append(warning, label);
		}
		if (relationship === 'same') {
			button.textContent = sprintf(
				/* translators: %s: selected release version. */
				wp.i18n._x(
					'Reinstall %s',
					'chosen release version',
					'ran-booster'
				),
				version
			);
		} else if (relationship === 'older') {
			button.textContent = sprintf(
				/* translators: %s: selected release version. */
				__('Downgrade to %s', 'ran-booster'),
				version
			);
		} else {
			button.textContent = sprintf(
				/* translators: %s: selected release version. */
				__('Upgrade to %s', 'ran-booster'),
				version
			);
		}
		const title = sprintf(
			/* translators: %s: versioned installation action, such as Upgrade to 2.0. */
			__('%s?', 'ran-booster'),
			button.textContent
		);
		body.append(form);
		footer.append(button);
		return title;
	};

	const inspect = async () => {
		if (
			activeReview?.isOpen ||
			!selected ||
			review?.getAttribute('aria-disabled') === 'true'
		) {
			return;
		}
		const { candidate, input, renderRelationship } = selected;
		clearChosen();
		clearError();
		const current = ++sequence;
		const body = document.createElement('div');
		body.className = 'ran-booster-switch-dialog';
		const outcome = document.createElement('p');
		outcome.setAttribute('role', 'status');
		outcome.textContent = sprintf(
			/* translators: %s: selected release version. */
			__('Checking release %s…', 'ran-booster'),
			candidate.version
		);
		body.append(outcome);
		const footer = document.createElement('div');
		footer.className = 'ran-booster-switch-dialog__actions';
		const cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = review.className;
		cancel.textContent = __('Cancel', 'ran-booster');
		footer.append(cancel);
		chosenHost.append(body, footer);
		const handle = window.ranBoosterOpenDialog?.(review, body, {
			/* translators: %s: selected release version. */
			title: sprintf(__('Review %s', 'ran-booster'), candidate.version),
			size: 'medium',
			footer,
		});
		if (!handle) {
			clearChosen();
			showError(
				__(
					'The release review could not open. Refresh the page and try again.',
					'ran-booster'
				)
			);
			return;
		}
		activeReview = handle;
		cancel.addEventListener('click', () => {
			++sequence;
			handle.close();
		});
		const isCurrent = () =>
			current === sequence &&
			handle.isOpen &&
			body.isConnected &&
			body.parentNode !== chosenHost;
		try {
			const response = await request('inspect', {
				release_id: candidate.release_id,
				release_tag: candidate.tag,
			});
			if (!isCurrent()) {
				return;
			}
			if (!response.successful || response.code !== 'release_ready') {
				const reason = response.data?.rejection_reason;
				if (
					response.code === 'unable_to_check' &&
					reason === 'rate_limited'
				) {
					const { host, content } =
						window.ranBoosterNotice.create('warning');
					const text = document.createElement('p');
					text.textContent = rateLimitMessage(
						response.data?.rate_limit
					);
					appendLookupHint(text, response.data?.lookup_hint);
					content.append(text);
					host.setAttribute('role', 'alert');
					body.replaceChildren(host);
					return;
				}
				let rejection;
				if (reason === 'digest_missing') {
					rejection = __(
						'The release ZIP has no published digest.',
						'ran-booster'
					);
				} else if (
					[
						'package_asset_missing',
						'package_asset_ambiguous',
					].includes(reason)
				) {
					rejection = __(
						'This release has no qualifying ZIP.',
						'ran-booster'
					);
				} else if (
					[
						'package_header_missing',
						'package_header_invalid',
						'package_header_ambiguous',
						'release_version_mismatch',
					].includes(reason)
				) {
					rejection = __(
						'The release ZIP has no matching package version header.',
						'ran-booster'
					);
				}
				if (rejection) {
					outcome.textContent = rejection;
					return;
				}
				throw new Error('inspection_failed');
			}
			const { version, version_relationship: relationship } =
				response.data;
			renderRelationship(relationship, version);
			if (input.disabled) {
				input.checked = false;
				selected = null;
			}
			const fields = response.data.chosen_install;
			if (!canInspect(relationship) || !version) {
				outcome.textContent =
					relationship === 'older'
						? __(
								'Downgrades are unavailable for this package.',
								'ran-booster'
							)
						: __('Version comparison unavailable', 'ran-booster');
				review.setAttribute(
					'aria-disabled',
					selected ? 'false' : 'true'
				);
				return;
			}
			outcome.textContent = sprintf(
				/* translators: 1: installed package version, 2: selected release version. */
				__('Installed: %1$s → Selected: %2$s', 'ran-booster'),
				response.data.installed_version,
				version
			);
			if (
				fields?._wpnonce &&
				fields.release_id === candidate.release_id &&
				fields.release_tag === candidate.tag &&
				fields.release_version === version &&
				fields.artifact_identity &&
				fields.artifact_sha256 &&
				fields.installed_version === response.data.installed_version
			) {
				handle.setTitle(
					showChosen(fields, relationship, version, body, footer)
				);
			} else {
				const reason = document.createElement('p');
				reason.textContent =
					relationship === 'same'
						? __(
								'This release matches the installed version. Reinstallation is unavailable.',
								'ran-booster'
							)
						: __(
								'This release passed inspection. Installation from this picker is unavailable.',
								'ran-booster'
							);
				body.append(reason);
				const screenUrl =
					managedBrowser.dataset.ranBoosterManagedReleaseWordpressUrl;
				if (relationship === 'newer' && screenUrl) {
					const link = document.createElement('a');
					link.href = screenUrl;
					link.textContent =
						managedBrowser.dataset.ranBoosterManagedReleaseType ===
						'theme'
							? __('Open WordPress Themes', 'ran-booster')
							: __('Open WordPress Plugins', 'ran-booster');
					body.append(link);
				}
			}
		} catch {
			if (isCurrent()) {
				outcome.textContent = __(
					'Booster could not check the selected release. Refresh releases and try again.',
					'ran-booster'
				);
			}
		}
	};
	const list = async () => {
		clearChosen();
		const current = ++sequence;
		renderListingObservations(observations);
		selected = null;
		if (review) {
			review.hidden = true;
		}
		clearError();
		setListBusy(true);
		if (candidateList) {
			if (window.htmx?.swap) {
				window.htmx.swap(candidateList, '', {
					swapStyle: 'innerHTML',
					swapDelay: 0,
					settleDelay: 0,
					transition: false,
					ignoreTitle: true,
				});
			} else {
				candidateList.replaceChildren();
			}
		}
		if (candidates) {
			candidates.hidden = true;
		}
		showPlaceholder(true);
		setStatus(
			wp.i18n.__('Checking published releases…', 'ran-booster'),
			wp.i18n.__(
				'Reading eligible candidates for this managed package.',
				'ran-booster'
			)
		);
		let lookupHint = null;
		let failureMessage;
		try {
			const response = await request('list');
			if (current !== sequence) {
				return;
			}
			if (response.successful === false) {
				if (response.code === 'unable_to_check') {
					lookupHint = response.data?.lookup_hint;
					failureMessage =
						lookupHint ||
						response.data?.rejection_reason === 'rate_limited'
							? rateLimitMessage(response.data?.rate_limit)
							: wp.i18n.__(
									'Booster could not list releases for this package. Try again.',
									'ran-booster'
								);
				} else if (
					response.code === 'source_changed' ||
					response.code === 'invalid_request'
				) {
					failureMessage = wp.i18n.__(
						'Refresh the package page and try again.',
						'ran-booster'
					);
				}
			}
			const releases = Array.isArray(response.data?.candidates)
				? response.data.candidates.slice(0, 8)
				: [];
			if (
				!response.successful ||
				!['release_candidates_available', 'no_releases'].includes(
					response.code
				) ||
				!candidateList
			) {
				throw new Error('no_releases');
			}
			if (response.code === 'no_releases') {
				const empty = document.createElement('p');
				empty.className = 'ran-booster-release-candidate';
				empty.textContent = wp.i18n.__(
					'No eligible releases were found in this search.',
					'ran-booster'
				);
				candidateList.append(empty);
				if (candidates) {
					candidates.hidden = false;
					showPlaceholder(false);
				}
				setStatus(
					wp.i18n.__('No eligible releases found', 'ran-booster'),
					empty.textContent
				);
				renderListingObservations(observations, response.data, branch);
				return;
			}
			if (releases.length === 0) {
				throw new Error('invalid_candidates');
			}
			renderListingObservations(observations, response.data, branch);
			const inspectable = releases.filter((candidate) =>
				canInspect(candidate.version_relationship)
			);
			const preferredCandidate = branch
				? releases.find(
						(candidate) => candidate.latest_eligible === true
					) || releases[0]
				: inspectable[0] || releases[0];
			const installedCandidate = releases.find(
				(candidate) => candidate.version_relationship === 'same'
			);
			const preselected = installedCandidate || inspectable[0];
			const visible = [preferredCandidate];
			if (installedCandidate && !visible.includes(installedCandidate)) {
				visible.push(installedCandidate);
			}
			const earlier = releases.filter(
				(candidate) => !visible.includes(candidate)
			);
			const appendCandidate = (candidate, target) => {
				const label = document.createElement('label');
				label.className = 'ran-booster-release-candidate';
				const input = document.createElement('input');
				input.type = 'radio';
				input.name = 'ran_booster_managed_release_candidate';
				input.dataset.releaseId = String(candidate.release_id);
				const description = document.createElement('span');
				const renderRelationship = (relationship, version) => {
					input.disabled = branch || !canInspect(relationship);
					const latest =
						candidate === preferredCandidate &&
						(!branch || candidate.latest_eligible === true) &&
						(relationship === 'older' || canInspect(relationship))
							? __('Latest eligible', 'ran-booster')
							: '';
					let marker;
					if (relationship === 'older') {
						marker = __('Older than installed', 'ran-booster');
					} else if (relationship === 'same') {
						marker = __('Installed/current', 'ran-booster');
					} else if (!canInspect(relationship)) {
						marker = __(
							'Version comparison unavailable',
							'ran-booster'
						);
					} else {
						marker = __('Newer than installed', 'ran-booster');
					}
					const tag =
						candidate.tag?.replace(/^v/i, '') === version
							? ''
							: candidate.tag;
					description.textContent = [
						version,
						tag,
						candidate.prerelease
							? __('Preview', 'ran-booster')
							: '',
						latest,
						marker,
					]
						.filter(Boolean)
						.join(' · ');
				};
				renderRelationship(
					candidate.version_relationship,
					candidate.version
				);
				const row = { candidate, input, renderRelationship };
				input.addEventListener('change', () => {
					if (
						!input.checked ||
						input.disabled ||
						candidates?.disabled ||
						selected === row
					) {
						return;
					}
					clearChosen();
					selected = row;
					review?.setAttribute('aria-disabled', 'false');
					clearError();
					promptReview();
				});
				if (!branch && candidate === preselected) {
					input.checked = true;
					selected = row;
				}
				label.append(input, description);
				target.append(label);
			};
			visible.forEach((candidate) =>
				appendCandidate(candidate, candidateList)
			);
			if (response.data.installed_version && !installedCandidate) {
				const installed = document.createElement('p');
				installed.className =
					'ran-booster-release-candidate ran-booster-release-installed-version';
				installed.textContent = wp.i18n.sprintf(
					/* translators: %s: installed package version. */
					wp.i18n.__('Installed version: %s', 'ran-booster'),
					response.data.installed_version
				);
				candidateList.append(installed);
			}
			if (earlier.length > 0) {
				const { host: disclosure, body } = earlierReleasesDisclosure(
					wp.i18n.sprintf(
						/* translators: %d: number of earlier releases. */
						wp.i18n._n(
							'Show %d earlier release',
							'Show %d earlier releases',
							earlier.length,
							'ran-booster'
						),
						earlier.length
					)
				);
				if (
					!canInspect('older') &&
					earlier.some(
						(candidate) =>
							candidate.version_relationship === 'older'
					)
				) {
					const warning = document.createElement('p');
					warning.textContent = wp.i18n.__(
						'Downgrades are unavailable because package data migrations may not be reversible. For recovery, follow the package-specific instructions or restore a backup.',
						'ran-booster'
					);
					body.append(warning);
				}
				earlier.forEach((candidate) =>
					appendCandidate(candidate, body)
				);
				candidateList.append(disclosure);
				mountDisclosure(disclosure);
			}
			if (candidates) {
				candidates.hidden = false;
				showPlaceholder(false);
			}
			if (branch) {
				setStatus(__('Release candidates loaded', 'ran-booster'), '');
			} else if (selected) {
				if (review) {
					review.hidden = false;
				}
				promptReview();
			} else if (
				releases.some(
					(candidate) => candidate.version_relationship !== 'older'
				)
			) {
				setStatus(
					__('Version comparison unavailable', 'ran-booster'),
					__(
						'Booster could not compare these release versions with the installed version.',
						'ran-booster'
					)
				);
			} else {
				if (
					managedBrowser.dataset.ranBoosterManagedReleaseChannel ===
					'prerelease'
				) {
					const { host: warning, content } =
						window.ranBoosterNotice.create('warning', {
							className: 'ran-booster-release-candidate__warning',
							scope: 'global',
						});
					const text = document.createElement('p');
					text.textContent = wp.i18n.__(
						'Preview track currently offers only versions older than installed. WordPress Updates will not downgrade this package.',
						'ran-booster'
					);
					content.append(text);
					candidateList.append(warning);
				}
				setStatus(
					wp.i18n.__(
						'No current or newer published release found',
						'ran-booster'
					),
					wp.i18n.__(
						'Every available release is older. WordPress Updates will not downgrade this package.',
						'ran-booster'
					)
				);
			}
		} catch (error) {
			if (current === sequence) {
				if (
					error?.message === 'request_failed' &&
					Number.isInteger(error.cause)
				) {
					failureMessage = sprintf(
						/* translators: %d: HTTP status returned by the site's release list endpoint. */
						__(
							'This site returned HTTP %d while loading releases. Try again. If this continues, check the site’s server logs.',
							'ran-booster'
						),
						error.cause
					);
				}
				showPlaceholder(false);
				failureMessage ??= wp.i18n.__(
					'Booster did not receive a usable response from this site. Refresh the page and try again.',
					'ran-booster'
				);
				showError(failureMessage, lookupHint);
				setStatus(
					wp.i18n.__(
						'Published releases could not be checked',
						'ran-booster'
					),
					failureMessage
				);
			}
		} finally {
			if (current === sequence) {
				setListBusy(false);
			}
		}
	};
	review?.addEventListener('click', inspect);
	retry?.addEventListener('click', list);
	const host = managedBrowser.closest('[data-ran-booster-ui-disclosure]');
	if (branch) {
		host?.addEventListener('change', (event) => {
			if (!event.target.matches('[data-ran-booster-release-channel]')) {
				return;
			}
			const channel = event.target.value;
			managedBrowser.dataset.ranBoosterManagedReleaseChannel = channel;
			managedBrowser.dataset.ranBoosterManagedReleaseListNonce =
				channel === 'stable'
					? managedBrowser.dataset.ranBoosterBranchStable
					: managedBrowser.dataset.ranBoosterBranchPreview;
			list();
		});
	}
	if (host && window.MutationObserver) {
		const loadWhenOpen = () => {
			const native = host.querySelector(':scope > details');
			const open = native
				? native.open
				: host
						.querySelector('[role="button"]')
						?.getAttribute('aria-expanded') === 'true';
			if (open) {
				observer.disconnect();
				if (!sequence) {
					list();
				}
			}
		};
		const observer = new window.MutationObserver(loadWhenOpen);
		observer.observe(host, {
			attributes: true,
			attributeFilter: ['open', 'aria-expanded'],
			childList: true,
			subtree: true,
		});
		loadWhenOpen();
		return;
	}
	list();
};
(() => {
	'use strict';

	// The source switch bar message follows the track radios and readiness.
	// Readiness is the ready flag on the Use releases button; the not-ready
	// message and tooltip are server-rendered so each is one translatable string.
	const syncUseReleasesState = () => {
		const button = document.querySelector(
			'[data-ran-booster-use-releases]'
		);
		const summary = document.querySelector(
			'[data-ran-booster-use-releases-summary]'
		);
		if (!button || !summary) {
			return;
		}
		const ready = button.dataset.ranBoosterUseReleasesReady === 'true';
		const template = summary.dataset.ranBoosterUseReleasesSummary;
		const unavailable =
			summary.dataset.ranBoosterUseReleasesSummaryUnavailable;
		const track = document
			.querySelector('[data-ran-booster-release-track-summary]')
			?.textContent?.trim();
		if (ready && template && track) {
			summary.textContent = template.replace('%s', track);
		} else if (!ready && unavailable) {
			summary.textContent = unavailable;
		}
		const wrapper = button.closest('.ran-booster-reason-tooltip');
		const reason = wrapper?.querySelector('[role="tooltip"]');
		if (ready) {
			wrapper?.removeAttribute('data-ran-booster-reason-tooltip');
			if (reason) {
				reason.hidden = true;
			}
			if (!button.disabled) {
				button.setAttribute('aria-disabled', 'false');
				if (
					reason &&
					button.getAttribute('aria-describedby') === reason.id
				) {
					button.removeAttribute('aria-describedby');
				}
			}
		} else {
			wrapper?.setAttribute('data-ran-booster-reason-tooltip', '');
			button.setAttribute('aria-disabled', 'true');
			if (reason) {
				button.setAttribute('aria-describedby', reason.id);
			}
		}
	};

	if (typeof window.MutationObserver === 'function') {
		new window.MutationObserver(syncUseReleasesState).observe(
			document.documentElement,
			{
				attributes: true,
				attributeFilter: ['data-ran-booster-use-releases-ready'],
				subtree: true,
			}
		);
	}

	const updateReleaseTrackSummary = (event) => {
		if (!event.target?.matches('[data-ran-booster-release-channel]')) {
			return;
		}
		const disclosure = event.target.closest(
			'#ran-booster-release-track-settings, [data-ran-booster-ui-disclosure="package-release-track"]'
		);
		const summary = disclosure?.querySelector(
			'[data-ran-booster-release-track-summary]'
		);
		const label = event.target.closest('label');
		const labelText = label?.querySelector('span');
		if (summary && labelText) {
			summary.textContent = labelText.textContent;
		}
		syncUseReleasesState();
	};

	document.addEventListener('change', updateReleaseTrackSummary);
	syncUseReleasesState();

	const initializeManagedReleaseBrowserAfterSwap = (event) => {
		if (
			!['wpbody-content', 'ran-booster-package-edit-region'].includes(
				event.detail?.target?.id
			)
		) {
			return;
		}
		const managedBrowser = document.querySelector(
			'[data-ran-booster-managed-release-browser]'
		);
		if (managedBrowser) {
			initializeManagedReleaseBrowser(managedBrowser);
		}
	};

	document.addEventListener(
		'htmx:afterSwap',
		initializeManagedReleaseBrowserAfterSwap
	);

	const boundSwitchDocuments = new WeakSet();

	function setHiddenInput(form, name, value) {
		let input = form.querySelector(
			'input[type="hidden"][name="' + name + '"]'
		);
		if (!input) {
			input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			form.appendChild(input);
		}
		input.value = value;
	}

	function removeHiddenInput(form, name) {
		const input = form.querySelector(
			'input[type="hidden"][name="' + name + '"]'
		);
		if (input) {
			input.remove();
		}
	}

	function clearSwitchConfirmation(form) {
		[
			'confirm_switch',
			'artifact_identity',
			'artifact_sha256',
			'confirmed_release_identity',
			'confirmed_release_tag',
			'confirmed_release_version',
			'confirmed_release_nonce',
			'confirmed_release_older',
			'confirmed_installed_version',
			'confirm_older_release',
			'confirmed_older_release_identity',
			'confirmed_older_release_tag',
			'confirmed_older_release_nonce',
		].forEach((name) => removeHiddenInput(form, name));
	}

	function submitSwitchForm(form, submitter, confirmed) {
		if (confirmed) {
			form.dataset.ranBoosterSwitchConfirmed = '1';
		} else {
			form.dataset.ranBoosterSwitchNativeFallback = '1';
			clearSwitchConfirmation(form);
		}
		if (typeof form.requestSubmit === 'function') {
			if (
				submitter &&
				(submitter.form === form || form.contains?.(submitter))
			) {
				form.requestSubmit(submitter);
			} else {
				form.requestSubmit();
			}
			return;
		}
		form.submit();
	}

	function appendPreflightErrorContent(target, data, fallbackMessage) {
		let message =
			typeof data?.message === 'string' && data.message
				? data.message
				: fallbackMessage;
		if (data?.rate_limit && typeof data.rate_limit === 'object') {
			message = rateLimitMessage(data.rate_limit);
			if (
				typeof data.message_suffix === 'string' &&
				data.message_suffix
			) {
				message += ` ${data.message_suffix}`;
			}
		}
		target.appendChild(document.createTextNode(message));
	}

	// The shared package error is reused: once this check rewrites or hides it,
	// an earlier Save failure's key and pointer no longer describe it.
	function releaseSharedNotice(sharedNotice) {
		sharedNotice.removeAttribute('data-feedback-key');
		sharedNotice.removeAttribute('data-ran-booster-feedback-taken');
		window.ranBoosterFeedback?.consume?.();
	}

	function hideSwitchPreflightError() {
		const sharedNotice = document.getElementById(
			'ran-booster-package-mutation-error'
		);
		if (sharedNotice) {
			const primary = sharedNotice.querySelector(
				'[data-ran-booster-ui-notice-primary]'
			);
			if (primary) {
				primary.replaceChildren();
			}
			sharedNotice.hidden = true;
			releaseSharedNotice(sharedNotice);
		}
		document
			.querySelectorAll('[data-ran-booster-switch-preflight-error]')
			.forEach(function (node) {
				node.remove();
			});
	}

	function showSwitchPreflightError(form, data) {
		const { __ } = wp.i18n;
		const fallback = __(
			'Published releases could not be checked. No package was installed and no package settings were changed.',
			'ran-booster'
		);
		const appendRetry = (target) => {
			if (typeof form.requestSubmit !== 'function') {
				return;
			}
			const button = document.createElement('button');
			button.type = 'button';
			button.className =
				'ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-neutral is-outline';
			button.setAttribute('data-ran-booster-switch-retry', '');
			button.textContent = __('Try again', 'ran-booster');
			button.addEventListener('click', function () {
				if (button.disabled) {
					return;
				}
				button.disabled = true;
				clearSwitchConfirmation(form);
				form.requestSubmit();
			});
			target.appendChild(button);
		};
		const sharedNotice = document.getElementById(
			'ran-booster-package-mutation-error'
		);
		if (sharedNotice) {
			const primary = sharedNotice.querySelector(
				'[data-ran-booster-ui-notice-primary]'
			);
			if (primary) {
				primary.replaceChildren();
				appendPreflightErrorContent(primary, data, fallback);
				appendRetry(primary);
				sharedNotice.hidden = false;
				releaseSharedNotice(sharedNotice);
				sharedNotice.scrollIntoView?.({ block: 'nearest' });
				sharedNotice.focus?.();
				return;
			}
		}

		const switchBar = document.querySelector(
			'[data-ran-booster-source-switch-bar="branch"]'
		);
		const host = switchBar?.parentNode || form.parentNode;
		if (!host) {
			return;
		}
		let notice;
		let target;
		if (typeof window.ranBoosterNotice?.create === 'function') {
			const created = window.ranBoosterNotice.create('error', {
				className: 'ran-booster-switch-preflight-error',
				scope: 'global',
			});
			notice = created.host;
			target = created.content;
		} else {
			notice = document.createElement('div');
			target = notice;
		}
		notice.setAttribute('role', 'alert');
		notice.setAttribute('tabindex', '-1');
		notice.setAttribute('data-ran-booster-switch-preflight-error', '');
		const paragraph = document.createElement('p');
		appendPreflightErrorContent(paragraph, data, fallback);
		target.appendChild(paragraph);
		appendRetry(target);
		if (switchBar && switchBar.parentNode === host) {
			switchBar.after(notice);
		} else {
			host.prepend(notice);
		}
		notice.focus?.();
	}

	function getReleaseChannelRadios(form) {
		const seen = new Set();
		const radios = [];
		const add = (radio) => {
			if (radio && !seen.has(radio)) {
				seen.add(radio);
				radios.push(radio);
			}
		};
		form.querySelectorAll?.('input[name="release_channel"]').forEach(add);
		if (form.id) {
			document
				.querySelectorAll?.(
					'input[name="release_channel"][form="' + form.id + '"]'
				)
				.forEach(add);
		}
		return radios;
	}

	function getSelectedReleaseChannel(form) {
		const checked =
			form.querySelector?.('input[name="release_channel"]:checked') ||
			(form.id
				? document.querySelector?.(
						'input[name="release_channel"][form="' +
							form.id +
							'"]:checked'
					)
				: null);
		return checked ? checked.value : 'stable';
	}

	function openSwitchConfirmationDialog(
		form,
		submitter,
		payload,
		expectedChannel,
		expectedChoice
	) {
		const { __, sprintf } = wp.i18n;
		const installedVersion =
			typeof payload?.installed_version === 'string'
				? payload.installed_version
				: '';
		const releaseVersion =
			typeof payload?.release_version === 'string'
				? payload.release_version
				: '';
		const relationship =
			typeof payload?.version_relationship === 'string'
				? payload.version_relationship
				: 'newer';
		const isOlder = relationship === 'older';
		const confirmedInstalledVersion =
			typeof payload?.confirmed_installed_version === 'string'
				? payload.confirmed_installed_version
				: '';
		const releaseIdentity =
			typeof payload?.release_identity === 'string'
				? payload.release_identity
				: '';
		const releaseTag =
			typeof payload?.release_tag === 'string' ? payload.release_tag : '';
		const releaseNonce =
			typeof payload?.confirmed_release_nonce === 'string'
				? payload.confirmed_release_nonce
				: '';
		if (
			!releaseIdentity ||
			!releaseTag ||
			!releaseVersion ||
			!releaseNonce ||
			(expectedChoice === 'installed' &&
				(!confirmedInstalledVersion ||
					confirmedInstalledVersion !== installedVersion))
		) {
			showSwitchPreflightError(form, null);
			return;
		}

		const body = document.createElement('div');
		body.className = 'ran-booster-switch-dialog';

		const versions = document.createElement('p');
		versions.className = 'ran-booster-switch-dialog__versions';
		if (installedVersion && releaseVersion) {
			versions.textContent = sprintf(
				/* translators: 1: currently installed version, 2: published release version to install. */
				__(
					'Installed: %1$s. Latest eligible release: %2$s.',
					'ran-booster'
				),
				installedVersion,
				releaseVersion
			);
		} else if (releaseVersion) {
			versions.textContent = sprintf(
				/* translators: %s: published release version to install. */
				__('Latest eligible release: %s.', 'ran-booster'),
				releaseVersion
			);
		} else {
			versions.textContent = __(
				'Branch currently active.',
				'ran-booster'
			);
		}
		body.appendChild(versions);

		if (
			typeof payload?.fallback_message === 'string' &&
			payload.fallback_message
		) {
			const fallbackNotice = document.createElement('p');
			fallbackNotice.className = 'ran-booster-switch-dialog__note';
			fallbackNotice.textContent = payload.fallback_message;
			body.appendChild(fallbackNotice);
		}

		const databaseWarning = document.createElement('p');
		databaseWarning.className = 'ran-booster-switch-dialog__database';
		databaseWarning.textContent = __(
			'Installs immediately. After switching, Updates will be set to Manual.',
			'ran-booster'
		);
		body.appendChild(databaseWarning);

		if (relationship === 'same' || payload?.matching_release) {
			const sameNotice = document.createElement('p');
			sameNotice.className = 'ran-booster-switch-dialog__note';
			sameNotice.textContent = __(
				'Same version: this replaces the installed files with the release.',
				'ran-booster'
			);
			body.appendChild(sameNotice);
		} else if (isOlder) {
			const olderNotice = document.createElement('p');
			olderNotice.className = 'ran-booster-switch-dialog__note';
			olderNotice.textContent = __(
				"This release is older than what's installed. Switching replaces the code but cannot roll back database changes. Have a recent database backup ready.",
				'ran-booster'
			);
			body.appendChild(olderNotice);
		}

		let olderCheckbox = null;
		if (isOlder) {
			const consentLabel = document.createElement('label');
			consentLabel.className = 'ran-booster-switch-dialog__consent';
			olderCheckbox = document.createElement('input');
			olderCheckbox.type = 'checkbox';
			olderCheckbox.setAttribute(
				'data-ran-booster-switch-older-consent',
				''
			);
			const consentText = document.createElement('span');
			consentText.textContent = __(
				"I understand the database won't be rolled back.",
				'ran-booster'
			);
			consentLabel.appendChild(olderCheckbox);
			consentLabel.appendChild(consentText);
			body.appendChild(consentLabel);
		}

		const footer = document.createElement('div');
		footer.className = 'ran-booster-switch-dialog__actions';

		const cancelButton = document.createElement('button');
		cancelButton.type = 'button';
		cancelButton.className =
			'ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-neutral is-outline';
		cancelButton.setAttribute('data-ran-booster-switch-cancel', '');
		cancelButton.textContent = __('Cancel', 'ran-booster');

		const confirmButton = document.createElement('button');
		confirmButton.type = 'button';
		confirmButton.className =
			'ran-wp-ui-reset-button ran-wp-ui-box-sizing ran-wp-ui-outset-ring--focus-except-active ran-wp-ui-button is-neutral is-outline';
		confirmButton.setAttribute('data-ran-booster-switch-confirm', '');
		if (relationship === 'same') {
			confirmButton.textContent = sprintf(
				/* translators: %s: release version to install. */
				__('Reinstall %s (current)', 'ran-booster'),
				releaseVersion
			);
		} else if (relationship === 'newer') {
			confirmButton.textContent = sprintf(
				/* translators: %s: release version to install. */
				__('Upgrade to %s (latest)', 'ran-booster'),
				releaseVersion
			);
		} else {
			confirmButton.textContent = sprintf(
				/* translators: %s: release version to install. */
				__('Install %s (latest)', 'ran-booster'),
				releaseVersion
			);
		}
		if (isOlder) {
			confirmButton.disabled = true;
		}
		footer.appendChild(cancelButton);
		const matching = payload?.matching_release;
		let matchingButton = null;
		if (
			matching?.release_identity &&
			matching?.release_tag &&
			matching?.release_version &&
			matching?.confirmed_release_nonce &&
			matching?.confirmed_installed_version === installedVersion &&
			matching?.version_relationship === 'same' &&
			installedVersion
		) {
			matchingButton = document.createElement('button');
			matchingButton.type = 'button';
			matchingButton.className = confirmButton.className;
			matchingButton.setAttribute(
				'data-ran-booster-switch-choice',
				'installed'
			);
			matchingButton.textContent = sprintf(
				/* translators: %s: release version to install. */
				__('Reinstall %s (current)', 'ran-booster'),
				matching.release_version
			);
			footer.appendChild(matchingButton);
		}
		footer.appendChild(confirmButton);

		let host = form.querySelector('[data-ran-booster-switch-dialog-host]');
		if (!host) {
			host = document.createElement('div');
			host.hidden = true;
			host.setAttribute('data-ran-booster-switch-dialog-host', '');
			form.appendChild(host);
		}
		host.replaceChildren(body, footer);

		const handle = window.ranBoosterOpenDialog(submitter || form, body, {
			title: __('Switch to releases?', 'ran-booster'),
			size: 'medium',
			footer,
		});
		if (!handle) {
			host.remove();
			submitSwitchForm(form, submitter, false);
			return;
		}

		if (olderCheckbox) {
			olderCheckbox.addEventListener('change', function () {
				confirmButton.disabled = !olderCheckbox.checked;
			});
		}

		cancelButton.addEventListener('click', function () {
			removeHiddenInput(form, 'switch_release_choice');
			if (typeof handle.close === 'function') {
				handle.close();
			} else {
				handle.teardown?.();
			}
		});

		function installChoice(candidate, choice) {
			const candidateOlder =
				(candidate.version_relationship || relationship) === 'older';
			if (candidateOlder && !olderCheckbox?.checked) {
				return;
			}
			if (getSelectedReleaseChannel(form) !== expectedChannel) {
				if (typeof handle.close === 'function') {
					handle.close();
				} else {
					handle.teardown?.();
				}
				host.remove();
				return;
			}
			confirmButton.disabled = true;
			if (matchingButton) {
				matchingButton.disabled = true;
			}
			cancelButton.disabled = true;
			handle.teardown?.();
			host.remove();
			setHiddenInput(form, 'switch_release_choice', choice);
			setHiddenInput(form, 'confirm_switch', '1');
			setHiddenInput(
				form,
				'confirmed_release_identity',
				candidate.release_identity
			);
			setHiddenInput(
				form,
				'confirmed_release_tag',
				candidate.release_tag
			);
			setHiddenInput(
				form,
				'confirmed_release_version',
				candidate.release_version
			);
			setHiddenInput(
				form,
				'confirmed_release_nonce',
				candidate.confirmed_release_nonce
			);
			setHiddenInput(
				form,
				'artifact_identity',
				candidate.artifact_identity
			);
			setHiddenInput(form, 'artifact_sha256', candidate.artifact_sha256);
			if (candidate.confirmed_installed_version) {
				setHiddenInput(
					form,
					'confirmed_installed_version',
					candidate.confirmed_installed_version
				);
			} else {
				removeHiddenInput(form, 'confirmed_installed_version');
			}
			setHiddenInput(
				form,
				'confirmed_release_older',
				candidateOlder ? '1' : '0'
			);
			if (candidateOlder) {
				setHiddenInput(form, 'confirm_older_release', '1');
			} else {
				removeHiddenInput(form, 'confirm_older_release');
				removeHiddenInput(form, 'confirmed_older_release_identity');
				removeHiddenInput(form, 'confirmed_older_release_tag');
				removeHiddenInput(form, 'confirmed_older_release_nonce');
			}
			submitSwitchForm(form, submitter, true);
		}
		confirmButton.addEventListener('click', () =>
			installChoice(payload, expectedChoice)
		);
		matchingButton?.addEventListener('click', () =>
			installChoice(matching, 'installed')
		);
	}

	async function handleReleaseSwitchSubmit(event) {
		if (event.defaultPrevented) {
			return;
		}
		const form = event.target;
		if (
			!form?.matches?.(
				'.ran-booster-release-switch-form[data-ran-booster-switch-preflight-url]'
			)
		) {
			return;
		}

		if (form.dataset.ranBoosterSwitchConfirmed === '1') {
			delete form.dataset.ranBoosterSwitchConfirmed;
			return;
		}
		if (form.dataset.ranBoosterSwitchNativeFallback === '1') {
			delete form.dataset.ranBoosterSwitchNativeFallback;
			return;
		}

		const submitter =
			event.submitter ||
			document.querySelector(
				'[data-ran-booster-use-releases][form="' + form.id + '"]'
			);
		if (form.dataset.ranBoosterSwitchChecking === '1') {
			event.preventDefault();
			event.stopPropagation();
			return;
		}
		if (
			submitter?.disabled ||
			submitter?.getAttribute('aria-disabled') === 'true'
		) {
			event.preventDefault();
			return;
		}
		const choice = 'latest';
		removeHiddenInput(form, 'switch_release_choice');
		if (
			typeof window.ranBoosterOpenDialog !== 'function' ||
			typeof window.fetch !== 'function'
		) {
			return;
		}

		const preflightUrl =
			form.getAttribute('data-ran-booster-switch-preflight-url') || '';
		const preflightAction =
			form.getAttribute('data-ran-booster-switch-preflight-action') ||
			'ran_booster_release_switch_preflight';
		let nonces = {};
		try {
			nonces =
				JSON.parse(
					form.getAttribute(
						'data-ran-booster-switch-preflight-nonces'
					) || '{}'
				) || {};
		} catch {
			nonces = {};
		}
		const channel = getSelectedReleaseChannel(form);
		const preflightNonce =
			typeof nonces[channel] === 'string' ? nonces[channel] : '';
		if (!preflightUrl || !preflightNonce) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		hideSwitchPreflightError();

		const type =
			form.querySelector('input[name="expected_type"]')?.value || '';
		const identifier =
			form.querySelector('input[name="expected_identifier"]')?.value ||
			'';
		const revision =
			form.querySelector('input[name="expected_source_revision"]')
				?.value || '';
		const body = new URLSearchParams({
			action: preflightAction,
			expected_type: type,
			expected_identifier: identifier,
			expected_source_revision: revision,
			release_channel: channel,
			switch_release_choice: choice,
			_wpnonce: preflightNonce,
		});

		const lockedRadios = getReleaseChannelRadios(form).filter(
			(radio) => !radio.disabled
		);
		lockedRadios.forEach((radio) => {
			radio.disabled = true;
		});
		form.dataset.ranBoosterSwitchChecking = '1';
		submitter?.setAttribute('aria-busy', 'true');

		let failed = false;
		let payload = null;
		try {
			const response = await window.fetch(preflightUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type':
						'application/x-www-form-urlencoded; charset=UTF-8',
				},
				body: body.toString(),
			});
			payload = response.ok ? await response.json() : null;
		} catch {
			failed = true;
		} finally {
			lockedRadios.forEach((radio) => {
				radio.disabled = false;
			});
			delete form.dataset.ranBoosterSwitchChecking;
			submitter?.removeAttribute('aria-busy');
		}

		if (failed || !payload || payload.successful !== true) {
			removeHiddenInput(form, 'switch_release_choice');
			showSwitchPreflightError(form, payload?.data || null);
			return;
		}
		const payloadChannel =
			typeof payload.data?.channel === 'string'
				? payload.data.channel
				: '';
		if (
			getSelectedReleaseChannel(form) !== channel ||
			(payloadChannel && payloadChannel !== channel)
		) {
			return;
		}
		openSwitchConfirmationDialog(
			form,
			submitter,
			payload.data || {},
			channel,
			choice
		);
	}

	function registerSwitchDialogHandler() {
		if (!document || boundSwitchDocuments.has(document)) {
			return;
		}
		boundSwitchDocuments.add(document);
		document.addEventListener('submit', handleReleaseSwitchSubmit, true);
	}

	registerSwitchDialogHandler();

	const managedBrowser = document.querySelector(
		'[data-ran-booster-managed-release-browser]'
	);
	if (managedBrowser) {
		initializeManagedReleaseBrowser(managedBrowser);
		return;
	}

	const form = document.querySelector('[data-ran-booster-package-create]');
	const releaseChoice = document.querySelector(
		'[data-ran-booster-source-choice="release_asset"]'
	);
	const branchChoice = document.querySelector(
		'[data-ran-booster-source-choice="branch"]'
	);
	const setup = document.querySelector('[data-ran-booster-release-setup]');
	const localized = window.ranBoosterReleaseManagement;
	const config =
		localized ||
		(setup
			? {
					ajaxUrl: setup.dataset.ranBoosterReleaseAjaxUrl,
					adminPostUrl: setup.dataset.ranBoosterReleaseAdminPostUrl,
					type: setup.dataset.ranBoosterReleaseType,
					supportedProviders: (
						setup.dataset.ranBoosterReleaseSupportedProviders || ''
					)
						.split(',')
						.filter(Boolean),
					actions: {
						listCandidates: 'ran_booster_release_list_candidates',
						inspect: 'ran_booster_release_inspect',
						install: 'ran_booster_release_install',
					},
					nonces: {
						listCandidates:
							setup.dataset.ranBoosterReleaseCandidatesNonce,
						inspect: setup.dataset.ranBoosterReleaseInspectNonce,
						install: setup.dataset.ranBoosterReleaseInstallNonce,
					},
				}
			: null);

	if (!config || !form || !releaseChoice || !branchChoice || !setup) {
		return;
	}

	const heading = setup.querySelector(
		'[data-ran-booster-release-status-heading]'
	);
	const status = setup.querySelector('[data-ran-booster-release-status]');
	const observations = setup.querySelector(
		'[data-ran-booster-release-observations]'
	);
	const message = setup.querySelector(
		'[data-ran-booster-release-status-message]'
	);
	const retry = setup.querySelector('[data-ran-booster-release-retry]');
	const install = setup.querySelector('[data-ran-booster-release-install]');
	const switchBranch = setup.querySelector(
		'[data-ran-booster-release-switch-branch]'
	);
	const details = setup.querySelector('[data-ran-booster-release-details]');
	const candidates = setup.querySelector(
		'[data-ran-booster-release-candidates]'
	);
	const candidateList = setup.querySelector(
		'[data-ran-booster-release-candidate-list]'
	);
	const choiceDescription = releaseChoice.querySelector(
		'[data-ran-booster-source-description]'
	);
	const staticDescription = choiceDescription?.textContent ?? '';
	const channelControl = setup.querySelector(
		'[data-ran-booster-release-channel-control]'
	);

	const advancedSummary = document.querySelector(
		'[data-ran-booster-advanced-source-summary]'
	);
	let requestSequence = 0;
	let selectedRelease = null;
	let releaseSelected = false;
	let discoveryTimer = null;
	const supportedProviders = new Set(
		Array.isArray(config.supportedProviders)
			? config.supportedProviders.filter(
					(provider) =>
						typeof provider === 'string' &&
						/^[a-z][a-z0-9_-]{0,31}$/.test(provider)
				)
			: []
	);
	const validFingerprint = (value) =>
		typeof value === 'string' && /^v2:[a-f0-9]{64}$/.test(value);

	const releaseChannel = () =>
		channelControl?.querySelector(
			'[data-ran-booster-release-channel]:checked'
		)?.value === 'prerelease'
			? 'prerelease'
			: 'stable';

	const includesPrereleases = () => releaseChannel() === 'prerelease';

	const selectedProvider = () =>
		form.querySelector('.ran-booster-provider-input')?.value || '';

	const providerSupported = () => supportedProviders.has(selectedProvider());

	const hasSubdirectory = () =>
		Boolean(
			form
				.querySelector('[name="ran_booster[subdirectory]"]')
				?.value?.trim()
		);

	const updateAdvancedSummary = () => {
		if (!advancedSummary) {
			return;
		}
		if (releaseSelected) {
			advancedSummary.textContent = wp.i18n.sprintf(
				/* translators: %s: selected release channel. */
				wp.i18n.__('Releases · %s', 'ran-booster'),
				includesPrereleases()
					? wp.i18n.__('Preview', 'ran-booster')
					: wp.i18n.__('Stable', 'ran-booster')
			);
			return;
		}
		const branch = form.querySelector('[name="ran_booster[branch]"]');
		advancedSummary.textContent = wp.i18n.sprintf(
			/* translators: %s is the repository branch name. */
			wp.i18n.__('Branch · %s', 'ran-booster'),
			branch?.value.trim() ||
				wp.i18n.__('repository default', 'ran-booster')
		);
	};

	const setText = (element, value) => {
		if (element) {
			element.textContent = value;
		}
	};

	const setHidden = (element, hidden) => {
		if (element) {
			element.hidden = hidden;
		}
	};

	const setStatus = (title, description, options = {}) => {
		setText(heading, title);
		setText(message, description);
		status?.classList.toggle(
			'screen-reader-text',
			options.screenReaderOnly === true
		);
		setup.classList.toggle('is-checking', options.checking === true);
		setup.setAttribute(
			'aria-busy',
			options.checking === true ? 'true' : 'false'
		);
		setHidden(retry, options.retry !== true);
		setHidden(switchBranch, options.switchBranch !== true);
	};

	const setChoiceState = (state) => {
		if (['waiting', 'unsupported', 'subdirectory'].includes(state)) {
			renderListingObservations(observations);
		}
		const disabled =
			state === 'waiting' ||
			state === 'unsupported' ||
			state === 'subdirectory';
		releaseChoice.classList.remove(
			'is-checking',
			'is-available',
			'is-unavailable'
		);
		releaseChoice.classList.toggle('is-disabled', disabled);
		releaseChoice.classList.toggle('is-checking', state === 'checking');
		releaseChoice.classList.toggle(
			'ran-booster-enhanced-mutation__submitter--busy',
			state === 'checking'
		);
		releaseChoice.classList.toggle(
			'ran-booster-update-is-active',
			state === 'checking'
		);
		releaseChoice.classList.toggle('is-available', state === 'available');
		releaseChoice.classList.toggle(
			'is-unavailable',
			state === 'unavailable' ||
				state === 'unsupported' ||
				state === 'subdirectory'
		);
		releaseChoice.disabled = false;
		releaseChoice.setAttribute(
			'aria-disabled',
			disabled ? 'true' : 'false'
		);
		releaseChoice.setAttribute('title', disabled ? staticDescription : '');
		releaseChoice.setAttribute(
			'aria-label',
			disabled
				? wp.i18n.sprintf(
						/* translators: %s: release availability description. */
						wp.i18n.__('Releases unavailable: %s', 'ran-booster'),
						staticDescription
					)
				: wp.i18n.__('Releases', 'ran-booster')
		);
		releaseChoice.setAttribute(
			'aria-busy',
			state === 'checking' ? 'true' : 'false'
		);
	};

	const showIdle = () => {
		renderListingObservations(observations);
		selectedRelease = null;
		setChoiceState('available');
		setStatus(
			wp.i18n.__('Release candidates appear here', 'ran-booster'),
			includesPrereleases()
				? wp.i18n.__(
						'Select Releases to load stable and preview candidates.',
						'ran-booster'
					)
				: wp.i18n.__(
						'Select Releases to load eligible stable candidates.',
						'ran-booster'
					)
		);
		setHidden(candidates, true);
		setHidden(details, true);
		setHidden(install, true);
		if (candidateList) {
			candidateList.replaceChildren();
		}
	};

	const showWaitingForRepository = () => {
		selectedRelease = null;
		setChoiceState('waiting');
		setStatus(
			wp.i18n.__('Release candidates appear here', 'ran-booster'),
			wp.i18n.__(
				'Choose a repository above to load eligible published releases.',
				'ran-booster'
			)
		);
		setHidden(candidates, true);
		setHidden(details, true);
		setHidden(install, true);
		if (candidateList) {
			candidateList.replaceChildren();
		}
	};

	const showUnsupportedProvider = () => {
		selectedRelease = null;
		releaseSelected = false;
		setChoiceState('unsupported');
		setStatus(
			wp.i18n.__('Published releases are unavailable', 'ran-booster'),
			wp.i18n.__(
				'This Git provider does not expose the complete published-release capability. Use Branch tracking for this repository.',
				'ran-booster'
			)
		);
		setHidden(candidates, true);
		setHidden(details, true);
		setHidden(install, true);
		if (candidateList) {
			candidateList.replaceChildren();
		}
		updateAdvancedSummary();
	};

	const showSubdirectoryUnsupported = () => {
		selectedRelease = null;
		releaseSelected = false;
		setChoiceState('subdirectory');
		setStatus(
			wp.i18n.__('Published releases are unavailable', 'ran-booster'),
			wp.i18n.__(
				'Published releases require the repository root. Branch supports the configured subdirectory.',
				'ran-booster'
			)
		);
		setHidden(candidates, true);
		setHidden(details, true);
		setHidden(install, true);
		if (candidateList) {
			candidateList.replaceChildren();
		}
		updateAdvancedSummary();
	};

	const forceBranchForUnsupportedProvider = () => {
		const releaseWasSelected =
			releaseChoice.getAttribute('aria-pressed') === 'true' ||
			releaseSelected;
		showUnsupportedProvider();
		if (releaseWasSelected) {
			branchChoice.focus();
			branchChoice.click();
		}
	};

	const forceBranchForSubdirectory = () => {
		const releaseWasSelected =
			releaseChoice.getAttribute('aria-pressed') === 'true' ||
			releaseSelected;
		showSubdirectoryUnsupported();
		if (releaseWasSelected) {
			branchChoice.focus();
			branchChoice.click();
		}
	};

	const setChecking = (checking, phase = 'discover') => {
		if (checking) {
			setChoiceState('checking');
			const inspecting = phase === 'inspect';
			setStatus(
				inspecting
					? wp.i18n.__('Validating published release…', 'ran-booster')
					: wp.i18n.__('Checking published releases…', 'ran-booster'),
				inspecting
					? wp.i18n.__(
							'Booster is downloading and validating the exact release ZIP for review. It will be discarded after inspection.',
							'ran-booster'
						)
					: wp.i18n.__(
							'Booster is checking release metadata without downloading a package.',
							'ran-booster'
						),
				{ checking: true }
			);
			setHidden(install, true);
			setHidden(details, true);
		}
	};

	const repositoryData = () => {
		const data = new FormData(form);
		data.delete('ran_booster[action]');
		data.delete('_wpnonce');
		data.delete('_wp_http_referer');
		return data;
	};

	const request = async (operation, extra = {}) => {
		const data = repositoryData();
		data.append('action', config.actions[operation]);
		data.append('expected_type', config.type);
		data.append('release_channel', releaseChannel());
		data.append('_wpnonce', config.nonces[operation]);
		Object.entries(extra).forEach(([key, value]) => {
			data.append(key, String(value));
		});
		const response = await window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		});
		if (!response.ok) {
			throw new Error('request_failed');
		}
		return response.json();
	};

	const showUnavailable = (code, hint = null, rateLimit = null) => {
		renderListingObservations(observations);
		if (code === 'unsupported_provider') {
			forceBranchForUnsupportedProvider();
			return;
		}
		const noRelease = code === 'no_releases' || code === 'release_invalid';
		let statusHeading = wp.i18n.__(
			'Published releases could not be checked',
			'ran-booster'
		);
		let statusMessage = wp.i18n.__(
			'Repository access or the Git provider may be temporarily unavailable.',
			'ran-booster'
		);
		if (noRelease && includesPrereleases()) {
			statusHeading = wp.i18n.__(
				'No eligible stable release or prerelease found',
				'ran-booster'
			);
			statusMessage = wp.i18n.__(
				'No eligible releases were found in this search.',
				'ran-booster'
			);
		} else if (noRelease) {
			statusHeading = wp.i18n.__(
				'No eligible stable published release found',
				'ran-booster'
			);
			statusMessage = wp.i18n.__(
				'No eligible releases were found in this search.',
				'ran-booster'
			);
		} else if (rateLimit && typeof rateLimit === 'object') {
			statusMessage = rateLimitMessage(rateLimit);
		}
		selectedRelease = null;
		setChoiceState('unavailable');
		setStatus(statusHeading, statusMessage, {
			retry: !noRelease,
			switchBranch: true,
		});
		if (!noRelease) {
			appendLookupHint(message, hint);
		}
		setHidden(install, true);
		setHidden(details, true);
		setHidden(candidates, true);
	};

	const showCandidateUnavailable = (code, data = null) => {
		selectedRelease = null;
		setChoiceState('unavailable');
		let description =
			code === 'release_invalid'
				? wp.i18n.__(
						'The selected release did not pass the package checks. Earlier releases remain available.',
						'ran-booster'
					)
				: wp.i18n.__(
						'The selected release could not be checked. Earlier releases remain available.',
						'ran-booster'
					);
		if (data?.rejection_reason === 'digest_missing') {
			description = wp.i18n.__(
				'The release ZIP has no published digest.',
				'ran-booster'
			);
		}
		setStatus(
			wp.i18n.__('Published release could not be used', 'ran-booster'),
			description,
			{ retry: true }
		);
		setHidden(install, true);
		setHidden(details, true);
		setHidden(candidates, false);
	};

	const showCandidates = (data) => {
		const releases = Array.isArray(data.candidates)
			? data.candidates.slice(0, 8)
			: [];
		if (!candidateList || releases.length === 0) {
			showUnavailable('no_releases');
			return;
		}
		selectedRelease = null;
		candidateList.replaceChildren();
		const candidateInputs = [];
		const appendCandidate = (candidate, target) => {
			const label = document.createElement('label');
			label.className = 'ran-booster-release-candidate';
			const input = document.createElement('input');
			input.type = 'radio';
			input.name = 'ran_booster_release_candidate';
			input.value = String(candidate.release_id);
			candidateInputs.push(input);
			input.addEventListener('change', () => {
				requestSequence += 1;
				selectedRelease = {
					id: candidate.release_id,
					tag: candidate.tag,
					version: candidate.version,
					channel: releaseChannel(),
				};
				setHidden(details, true);
				setHidden(install, true);
				inspectRelease();
			});
			const text = document.createElement('span');
			const track = candidate.prerelease
				? wp.i18n.__('Preview', 'ran-booster')
				: wp.i18n.__('Stable', 'ran-booster');
			text.textContent = wp.i18n.sprintf(
				/* translators: 1: release version, 2: release tag, 3: release channel label. */
				wp.i18n.__('%1$s (%2$s) · %3$s', 'ran-booster'),
				candidate.version,
				candidate.tag,
				track
			);
			label.append(input, text);
			target.append(label);
		};
		appendCandidate(releases[0], candidateList);
		if (releases.length > 1) {
			const earlier = releases.slice(1);
			const { host: disclosure, body } = earlierReleasesDisclosure(
				wp.i18n.sprintf(
					/* translators: %d: number of earlier releases. */
					wp.i18n._n(
						'Show %d earlier release',
						'Show %d earlier releases',
						earlier.length,
						'ran-booster'
					),
					earlier.length
				)
			);
			earlier.forEach((candidate) => appendCandidate(candidate, body));
			candidateList.append(disclosure);
			mountDisclosure(disclosure);
		}
		setChoiceState('available');
		setStatus(
			wp.i18n.__('Release candidates loaded', 'ran-booster'),
			wp.i18n.sprintf(
				/* translators: %d: number of eligible releases. */
				wp.i18n._n(
					'%d eligible release available. Choose one to run its pre-flight checks.',
					'%d eligible releases available. Choose one to run its pre-flight checks.',
					releases.length,
					'ran-booster'
				),
				releases.length
			),
			{ screenReaderOnly: true }
		);
		setHidden(candidates, false);
		const firstCandidate = candidateInputs[0];
		if (firstCandidate) {
			firstCandidate.checked = true;
			const event =
				typeof Event === 'function'
					? new Event('change', { bubbles: true })
					: { type: 'change' };
			firstCandidate.dispatchEvent(event);
		}
	};

	const listCandidates = async () => {
		renderListingObservations(observations);
		if (hasSubdirectory()) {
			forceBranchForSubdirectory();
			return;
		}
		if (!providerSupported()) {
			forceBranchForUnsupportedProvider();
			return;
		}
		const repository = form.querySelector(
			'[name="ran_booster[repository]"]'
		);
		if (!repository || !repository.value.trim()) {
			showWaitingForRepository();
			return;
		}
		const sequence = ++requestSequence;
		setChecking(true, 'list_candidates');
		try {
			const response = await request('listCandidates');
			if (sequence !== requestSequence) {
				return;
			}
			setChecking(false);
			if (
				response.successful &&
				response.code === 'release_candidates_available'
			) {
				showCandidates(response.data);
			} else {
				showUnavailable(
					response.code,
					response.data?.lookup_hint,
					response.data?.rate_limit
				);
			}
			if (
				(response.successful &&
					response.code === 'release_candidates_available') ||
				response.code === 'no_releases'
			) {
				renderListingObservations(observations, response.data);
			}
		} catch {
			if (sequence === requestSequence) {
				setChecking(false);
				showUnavailable('unable_to_check');
			}
		}
	};

	const showReady = (data) => {
		if (
			!selectedRelease ||
			data.release_id !== selectedRelease.id ||
			data.tag !== selectedRelease.tag ||
			releaseChannel() !== selectedRelease.channel ||
			!validFingerprint(data.fingerprint) ||
			typeof data.install_nonce !== 'string' ||
			!data.install_nonce
		) {
			showUnavailable('unable_to_check');
			return;
		}
		selectedRelease.fingerprint = data.fingerprint;
		selectedRelease.installNonce = data.install_nonce;
		setChoiceState('available');
		setStatus(
			wp.i18n.__('Published release inspected', 'ran-booster'),
			wp.i18n.__(
				'The selected release passed initial inspection. Review the pre-flight checks below before installing.',
				'ran-booster'
			),
			{ screenReaderOnly: true }
		);
		setHidden(details, false);
		setHidden(install, false);
		setText(
			setup.querySelector('[data-ran-booster-release-version]'),
			`${data.version} (${data.tag})`
		);
		setText(
			setup.querySelector('[data-ran-booster-release-package]'),
			config.type === 'plugin'
				? `${data.package_root}/${data.main_file}`
				: data.package_root
		);
	};

	const inspectRelease = async () => {
		if (!selectedRelease) {
			return;
		}
		const sequence = ++requestSequence;
		setChecking(true, 'inspect');
		setup.hidden = false;
		try {
			const response = await request('inspect', {
				release_id: selectedRelease.id,
				release_tag: selectedRelease.tag,
			});
			if (sequence !== requestSequence) {
				return;
			}
			setChecking(false);
			if (response.successful && response.code === 'release_ready') {
				showReady(response.data);
			} else {
				showCandidateUnavailable(response.code, response.data);
			}
		} catch {
			if (sequence === requestSequence) {
				setChecking(false);
				showCandidateUnavailable('unable_to_check');
			}
		}
	};

	const chooseRelease = () => {
		if (releaseChoice.getAttribute('aria-disabled') === 'true') {
			return;
		}
		releaseSelected = true;
		updateAdvancedSummary();
		listCandidates();
	};

	const chooseBranch = () => {
		renderListingObservations(observations);
		releaseSelected = false;
		requestSequence += 1;
		selectedRelease = null;
		updateAdvancedSummary();
	};

	const installRelease = (event) => {
		event.preventDefault();
		if (
			!selectedRelease ||
			!validFingerprint(selectedRelease.fingerprint)
		) {
			showUnavailable('unable_to_check');
			return;
		}
		const values = {
			release_id: selectedRelease.id,
			release_tag: selectedRelease.tag,
			release_fingerprint: selectedRelease.fingerprint,
			ran_booster_release_install_nonce: selectedRelease.installNonce,
			release_channel: selectedRelease.channel,
		};
		for (const [name, value] of Object.entries(values)) {
			form.elements.namedItem(name).value = String(value);
		}
		const branchAction = form.elements.namedItem('ran_booster[action]');
		const branchActionWasDisabled = branchAction?.disabled === true;
		const restoreBranchAction = () => {
			if (branchAction && 'disabled' in branchAction) {
				branchAction.disabled = branchActionWasDisabled;
			}
		};
		if (branchAction && 'disabled' in branchAction) {
			branchAction.disabled = true;
		}
		const createPostUrl = form.getAttribute('hx-post');
		form.setAttribute('hx-post', config.adminPostUrl);
		form.addEventListener(
			'htmx:afterRequest',
			() => {
				form.setAttribute('hx-post', createPostUrl);
				restoreBranchAction();
			},
			{ once: true }
		);
		form.requestSubmit(install);
	};

	const scheduleDiscovery = () => {
		renderListingObservations(observations);
		requestSequence += 1;
		selectedRelease = null;
		updateAdvancedSummary();
		setHidden(install, true);
		setHidden(details, true);
		if (discoveryTimer) {
			window.clearTimeout(discoveryTimer);
		}
		if (hasSubdirectory()) {
			forceBranchForSubdirectory();
			return;
		}
		if (!providerSupported()) {
			forceBranchForUnsupportedProvider();
			return;
		}
		const repository = form.querySelector(
			'[name="ran_booster[repository]"]'
		);
		if (!repository || !repository.value.trim()) {
			showWaitingForRepository();
			return;
		}
		setHidden(candidates, true);
		if (!releaseSelected) {
			showIdle();
			return;
		}
		setChecking(true, 'list_candidates');
		discoveryTimer = window.setTimeout(listCandidates, 100);
	};

	form.addEventListener('ran-booster:package-source-changed', (event) => {
		if (event.detail?.source === 'release_asset') {
			chooseRelease();
		} else if (event.detail?.source === 'branch') {
			chooseBranch();
		}
	});
	switchBranch?.addEventListener('click', () => {
		branchChoice.focus();
		branchChoice.click();
	});
	retry?.addEventListener('click', listCandidates);
	install?.addEventListener('click', installRelease);
	channelControl?.addEventListener('change', scheduleDiscovery);
	document.addEventListener(
		'ran-booster:repository-context-changed',
		scheduleDiscovery
	);
	const repositoryContextChanged = (event) => {
		if (
			event.target?.matches(
				'.ran-booster-provider-input, .ran-booster-credential-input, .ran-booster-repository-input, .ran-booster-branch-input, [name="ran_booster[subdirectory]"]'
			)
		) {
			scheduleDiscovery();
		}
	};
	form.addEventListener('input', repositoryContextChanged);
	form.addEventListener('change', repositoryContextChanged);

	scheduleDiscovery();
	updateAdvancedSummary();
})();
