const managedReleaseBrowsers = new WeakSet();

const initializeManagedReleaseBrowser = (managedBrowser) => {
	const { __, sprintf } = wp.i18n;
	if (
		managedBrowser.dataset.ranBoosterManagedReleaseBrowserDisabled ===
			'true' ||
		!managedBrowser.dataset.ranBoosterManagedReleaseListNonce ||
		!managedBrowser.dataset.ranBoosterManagedReleaseInspectNonce
	) {
		return;
	}
	if (managedReleaseBrowsers.has(managedBrowser)) {
		return;
	}
	managedReleaseBrowsers.add(managedBrowser);
	const candidates = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-candidates]'
	);
	const candidateList = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-candidate-list]'
	);
	const heading = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-heading]'
	);
	const message = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-message]'
	);
	const retry = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-retry]'
	);
	const nativeUpdate = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-native-update]'
	);
	const errorNotice = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-error]'
	);
	const errorMessage = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-error-message]'
	);
	let selected = null;
	let selectedOutcome = null;
	const candidateElements = new Map();
	let sequence = 0;
	const setStatus = (title, text) => {
		if (heading) {
			heading.textContent = title;
		}
		if (message) {
			message.textContent = text;
		}
	};
	const clearError = () => {
		if (errorNotice) {
			errorNotice.hidden = true;
		}
	};
	const showError = (text) => {
		if (errorMessage) {
			errorMessage.textContent = text;
		}
		if (errorNotice) {
			errorNotice.hidden = false;
		}
	};
	const setListBusy = (busy) => {
		managedBrowser.setAttribute('aria-busy', busy ? 'true' : 'false');
		if (retry) {
			retry.disabled = busy;
			retry.classList.toggle(
				'ran-booster-enhanced-mutation__submitter--busy',
				busy
			);
			retry.classList.toggle('ran-booster-update-is-active', busy);
		}
	};
	const disableNativeUpdate = () => {
		if (!nativeUpdate) {
			return;
		}
		nativeUpdate.removeAttribute('href');
		nativeUpdate.setAttribute('aria-disabled', 'true');
		nativeUpdate.setAttribute('tabindex', '-1');
		nativeUpdate.classList.add('disabled');
		nativeUpdate.textContent = __('Install now', 'ran-booster');
	};
	const enableNativeUpdate = (version) => {
		const url =
			managedBrowser.dataset.ranBoosterManagedReleaseNativeUpdateUrl;
		if (!nativeUpdate || !url) {
			return false;
		}
		nativeUpdate.setAttribute('href', url);
		nativeUpdate.setAttribute('aria-disabled', 'false');
		nativeUpdate.removeAttribute('tabindex');
		nativeUpdate.classList.remove('disabled');
		/* translators: %s: release version. */
		nativeUpdate.textContent = sprintf(
			/* translators: %s: release version. */
			__('Install %s now', 'ran-booster'),
			version
		);
		return true;
	};
	const isNativeUpdateOffer = (candidate) =>
		candidate.version_relationship === 'newer' &&
		candidate.version ===
			managedBrowser.dataset
				.ranBoosterManagedReleaseNativeUpdateVersion &&
		String(candidate.release_id) ===
			managedBrowser.dataset
				.ranBoosterManagedReleaseNativeUpdateReleaseId &&
		Boolean(managedBrowser.dataset.ranBoosterManagedReleaseNativeUpdateUrl);
	const isCurrentNativeUpdateOffer = (candidate, nativeOffer) =>
		nativeOffer?.available === true &&
		candidate.version_relationship === 'newer' &&
		candidate.version === nativeOffer.version &&
		String(candidate.release_id) === String(nativeOffer.release_id) &&
		Boolean(managedBrowser.dataset.ranBoosterManagedReleaseNativeUpdateUrl);
	const request = async (operation, extra = {}) => {
		const data = new FormData();
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
			throw new Error('request_failed');
		}
		return response.json();
	};
	const inspect = async () => {
		if (!selected) {
			return;
		}
		const current = ++sequence;
		clearError();
		setListBusy(true);
		setStatus(
			__('Inspecting published release…', 'ran-booster'),
			__(
				'Booster is validating the selected release without changing the installed package.',
				'ran-booster'
			)
		);
		try {
			const response = await request('inspect', {
				release_id: selected.release_id,
				release_tag: selected.tag,
			});
			if (current !== sequence) {
				return;
			}
			if (!response.successful || response.code !== 'release_ready') {
				throw new Error('inspection_failed');
			}
			const relationship = response.data.version_relationship;
			const nativeOffer = isCurrentNativeUpdateOffer(
				{
					...response.data,
					release_id: selected.release_id,
				},
				response.data.native_offer
			);
			/* translators: %s: release version. */
			let outcomeMessage = sprintf(
				/* translators: %s: release version. */
				__('Version %s matches the installed version.', 'ran-booster'),
				response.data.version
			);
			let statusMessage = outcomeMessage;
			if (relationship === 'newer') {
				outcomeMessage = nativeOffer
					? sprintf(
							/* translators: %s: release version. */
							__(
								'Version %s is ready to install.',
								'ran-booster'
							),
							response.data.version
						)
					: sprintf(
							/* translators: %s: release version. */
							__(
								'Version %s is newer, but it is not the current WordPress update offer.',
								'ran-booster'
							),
							response.data.version
						);
				statusMessage = nativeOffer
					? outcomeMessage
					: sprintf(
							/* translators: %s: inspected release status. */
							__(
								'%s Refresh releases before installing.',
								'ran-booster'
							),
							outcomeMessage
						);
			} else if (relationship === 'older') {
				outcomeMessage = sprintf(
					/* translators: %s: release version. */
					__(
						'Version %s is older than the installed version.',
						'ran-booster'
					),
					response.data.version
				);
				statusMessage = outcomeMessage;
			}
			setStatus(
				wp.i18n.__('Release checked', 'ran-booster'),
				statusMessage
			);
			if (selectedOutcome) {
				selectedOutcome.textContent = outcomeMessage;
				selectedOutcome.hidden = relationship !== 'newer';
			}
			if (nativeOffer) {
				enableNativeUpdate(response.data.native_offer.version);
			} else {
				disableNativeUpdate();
			}
		} catch {
			if (current === sequence) {
				showError(
					wp.i18n.__(
						'Booster could not check the selected release. Refresh releases and try again.',
						'ran-booster'
					)
				);
				setStatus(
					wp.i18n.__(
						'Published release could not be inspected',
						'ran-booster'
					),
					wp.i18n.__(
						'The saved package or release may have changed. Refresh and try again.',
						'ran-booster'
					)
				);
			}
		} finally {
			if (current === sequence) {
				setListBusy(false);
			}
		}
	};
	const list = async () => {
		const current = ++sequence;
		selected = null;
		clearError();
		setListBusy(true);
		disableNativeUpdate();
		if (candidateList) {
			candidateList.replaceChildren();
		}
		if (candidates) {
			candidates.hidden = true;
		}
		setStatus(
			wp.i18n.__('Checking published releases…', 'ran-booster'),
			wp.i18n.__(
				'Reading eligible candidates for this managed package.',
				'ran-booster'
			)
		);
		try {
			const response = await request('list');
			if (current !== sequence) {
				return;
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
			candidateList.replaceChildren();
			if (response.code === 'no_releases') {
				const empty = document.createElement('p');
				empty.className = 'ran-booster-release-candidate';
				empty.textContent =
					managedBrowser.dataset.ranBoosterManagedReleaseChannel ===
					'prerelease'
						? wp.i18n.__(
								'No Preview releases have been published for this package yet.',
								'ran-booster'
							)
						: wp.i18n.__(
								'No Stable releases have been published for this package yet.',
								'ran-booster'
							);
				candidateList.append(empty);
				if (candidates) {
					candidates.hidden = false;
				}
				setStatus(
					wp.i18n.__('No published releases found', 'ran-booster'),
					empty.textContent
				);
				return;
			}
			if (releases.length === 0) {
				throw new Error('invalid_candidates');
			}
			const nativeOffer = releases.find(isNativeUpdateOffer);
			const inspectable = releases.filter(
				(candidate) => candidate.version_relationship !== 'older'
			);
			const preferredCandidate =
				nativeOffer || inspectable[0] || releases[0];
			const installedCandidate = releases.find(
				(candidate) => candidate.version_relationship === 'same'
			);
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
				const older = candidate.version_relationship === 'older';
				input.disabled = older;
				input.addEventListener('change', () => {
					disableNativeUpdate();
					if (selectedOutcome) {
						selectedOutcome.hidden = true;
					}
					selected = candidate;
					selectedOutcome = outcome;
					inspect();
				});
				let marker =
					candidate === preferredCandidate
						? wp.i18n.__('· Latest eligible', 'ran-booster')
						: '';
				if (older) {
					marker += wp.i18n.__(
						'· Older than installed',
						'ran-booster'
					);
				} else if (candidate.version_relationship === 'same') {
					marker += wp.i18n.__('· Installed/current', 'ran-booster');
				} else {
					marker += wp.i18n.__(
						'· Newer than installed',
						'ran-booster'
					);
				}
				const outcome = document.createElement('p');
				outcome.className = 'ran-booster-release-candidate__outcome';
				outcome.hidden = true;
				label.append(
					input,
					document.createTextNode(
						wp.i18n.sprintf(
							/* translators: 1: release version, 2: release tag, 3: release channel label, 4: release relationship marker. */
							wp.i18n.__('%1$s (%2$s) · %3$s%4$s', 'ran-booster'),
							candidate.version,
							candidate.tag,
							candidate.prerelease
								? wp.i18n.__('Preview', 'ran-booster')
								: wp.i18n.__('Stable', 'ran-booster'),
							marker
						)
					),
					outcome
				);
				candidateElements.set(candidate, { input, outcome });
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
				const disclosure = document.createElement('details');
				disclosure.className =
					'ran-booster-release-settings-disclosure';
				const summary = document.createElement('summary');
				summary.textContent = wp.i18n.sprintf(
					/* translators: %d: number of earlier releases. */
					wp.i18n._n(
						'Show %d earlier release',
						'Show %d earlier releases',
						earlier.length,
						'ran-booster'
					),
					earlier.length
				);
				disclosure.append(summary);
				if (
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
					disclosure.append(warning);
				}
				earlier.forEach((candidate) =>
					appendCandidate(candidate, disclosure)
				);
				candidateList.append(disclosure);
			}
			if (candidates) {
				candidates.hidden = false;
			}
			if (inspectable.length > 0) {
				setStatus(
					wp.i18n.__('Release candidates loaded', 'ran-booster'),
					wp.i18n.sprintf(
						/* translators: %d: number of eligible releases. */
						wp.i18n._n(
							'%d eligible release found. Inspecting the preferred available release.',
							'%d eligible releases found. Inspecting the preferred available release.',
							releases.length,
							'ran-booster'
						),
						releases.length
					)
				);
				selected = nativeOffer || inspectable[0];
				const selectedCandidate = candidateElements.get(selected);
				selectedOutcome = selectedCandidate?.outcome || null;
				const input = selectedCandidate?.input;
				if (input) {
					input.checked = true;
				}
				await inspect();
			} else {
				if (
					managedBrowser.dataset.ranBoosterManagedReleaseChannel ===
					'prerelease'
				) {
					const warning = document.createElement('p');
					warning.className =
						'notice notice-warning inline ran-booster-release-candidate__warning';
					warning.textContent = wp.i18n.__(
						'Preview track currently offers only versions older than installed. WordPress Updates will not downgrade this package.',
						'ran-booster'
					);
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
		} catch {
			if (current === sequence) {
				showError(
					wp.i18n.__(
						'Booster could not load published releases. The repository provider may be temporarily unavailable or rate-limited. Try again later.',
						'ran-booster'
					)
				);
				setStatus(
					wp.i18n.__(
						'Published releases could not be checked',
						'ran-booster'
					),
					wp.i18n.__(
						'Booster could not read eligible releases for the saved package.',
						'ran-booster'
					)
				);
			}
		} finally {
			if (current === sequence) {
				setListBusy(false);
			}
		}
	};
	nativeUpdate?.addEventListener('click', (event) => {
		if ('true' === nativeUpdate.getAttribute('aria-disabled')) {
			event.preventDefault();
		}
	});
	retry?.addEventListener('click', list);
	list();
};
(() => {
	'use strict';

	const updateReleaseTrackSummary = (event) => {
		if (!event.target?.matches('[data-ran-booster-release-channel]')) {
			return;
		}
		const disclosure = event.target.closest(
			'#ran-booster-release-track-settings'
		);
		const summary = disclosure?.querySelector(
			'[data-ran-booster-release-track-summary]'
		);
		const label = event.target.closest('label');
		const labelText = label?.querySelector('span');
		if (summary && labelText) {
			summary.textContent = labelText.textContent;
		}
	};

	document.addEventListener('change', updateReleaseTrackSummary);

	const initializeManagedReleaseBrowserAfterSwap = (event) => {
		if (event.detail?.target?.id !== 'wpbody-content') {
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
	const choiceHeading = releaseChoice.querySelector(
		'[data-ran-booster-source-heading]'
	);
	const choiceMeta = releaseChoice.querySelector(
		'[data-ran-booster-source-meta]'
	);
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
		typeof value === 'string' && /^v1:[a-f0-9]{64}$/.test(value);

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
				wp.i18n.__('provider default', 'ran-booster')
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

	const setChoiceState = (state, description, meta) => {
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
		releaseChoice.setAttribute('title', disabled ? description : '');
		releaseChoice.setAttribute(
			'aria-label',
			disabled
				? wp.i18n.sprintf(
						/* translators: %s: release availability description. */
						wp.i18n.__('Releases unavailable: %s', 'ran-booster'),
						description
					)
				: wp.i18n.__('Releases', 'ran-booster')
		);
		releaseChoice.setAttribute(
			'aria-busy',
			state === 'checking' ? 'true' : 'false'
		);
		setText(choiceHeading, wp.i18n.__('Releases', 'ran-booster'));
		setText(choiceDescription, description);
		setText(choiceMeta, meta);
	};

	const showIdle = () => {
		selectedRelease = null;
		setChoiceState(
			'available',
			wp.i18n.__(
				'View eligible published releases from this repository.',
				'ran-booster'
			),
			wp.i18n.__('Stable by default', 'ran-booster')
		);
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
		setChoiceState(
			'waiting',
			wp.i18n.__(
				'Choose a repository before selecting this source.',
				'ran-booster'
			),
			wp.i18n.__('Choose repository first', 'ran-booster')
		);
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
		setChoiceState(
			'unsupported',
			wp.i18n.__(
				'Published releases are not available for this repository provider.',
				'ran-booster'
			),
			wp.i18n.__('Provider capability unavailable', 'ran-booster')
		);
		setStatus(
			wp.i18n.__('Published releases are unavailable', 'ran-booster'),
			wp.i18n.__(
				'This provider does not expose the complete published-release capability. Use Branch tracking for this repository.',
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
		setChoiceState(
			'subdirectory',
			wp.i18n.__(
				'Published releases require the repository root. Branch supports the configured subdirectory.',
				'ran-booster'
			),
			wp.i18n.__('Repository root required', 'ran-booster')
		);
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
			setChoiceState(
				'checking',
				includesPrereleases()
					? wp.i18n.__(
							'Checking the selected repository for stable releases and prereleases…',
							'ran-booster'
						)
					: wp.i18n.__(
							'Checking the selected repository for a stable published release…',
							'ran-booster'
						),
				wp.i18n.__('Checking repository…', 'ran-booster')
			);
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

	const showUnavailable = (code) => {
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
			'Repository access or the provider may be temporarily unavailable.',
			'ran-booster'
		);
		let choiceMessage = wp.i18n.__(
			'Booster could not confirm release availability.',
			'ran-booster'
		);
		if (noRelease && includesPrereleases()) {
			statusHeading = wp.i18n.__(
				'No eligible stable release or prerelease found',
				'ran-booster'
			);
			statusMessage = wp.i18n.__(
				'This repository does not publish an eligible stable or preview release.',
				'ran-booster'
			);
			choiceMessage = wp.i18n.__(
				'No eligible stable release or prerelease is currently available.',
				'ran-booster'
			);
		} else if (noRelease) {
			statusHeading = wp.i18n.__(
				'No eligible stable published release found',
				'ran-booster'
			);
			statusMessage = wp.i18n.__(
				'This repository does not publish an eligible stable release.',
				'ran-booster'
			);
			choiceMessage = wp.i18n.__(
				'No eligible stable published release is currently available.',
				'ran-booster'
			);
		}
		selectedRelease = null;
		setChoiceState(
			'unavailable',
			choiceMessage,
			noRelease
				? wp.i18n.__('Branch only', 'ran-booster')
				: wp.i18n.__('Check failed', 'ran-booster')
		);
		setStatus(statusHeading, statusMessage, {
			retry: !noRelease,
			switchBranch: true,
		});
		setHidden(install, true);
		setHidden(details, true);
		setHidden(candidates, true);
	};

	const showCandidateUnavailable = (code) => {
		selectedRelease = null;
		setChoiceState(
			'unavailable',
			code === 'release_invalid'
				? wp.i18n.__(
						'The selected release is not an eligible WordPress package. Choose another release.',
						'ran-booster'
					)
				: wp.i18n.__(
						'The selected release could not be checked. Choose another release or retry.',
						'ran-booster'
					),
			wp.i18n.__('Choose another', 'ran-booster')
		);
		setStatus(
			wp.i18n.__('Published release could not be used', 'ran-booster'),
			code === 'release_invalid'
				? wp.i18n.__(
						'The selected release did not pass the package checks. Earlier releases remain available.',
						'ran-booster'
					)
				: wp.i18n.__(
						'The selected release could not be checked. Earlier releases remain available.',
						'ran-booster'
					),
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
			const disclosure = document.createElement('details');
			disclosure.className = 'ran-booster-release-settings-disclosure';
			const summary = document.createElement('summary');
			const earlier = releases.slice(1);
			summary.textContent = wp.i18n.sprintf(
				/* translators: %d: number of earlier releases. */
				wp.i18n._n(
					'Show %d earlier release',
					'Show %d earlier releases',
					earlier.length,
					'ran-booster'
				),
				earlier.length
			);
			disclosure.append(summary);
			earlier.forEach((candidate) =>
				appendCandidate(candidate, disclosure)
			);
			candidateList.append(disclosure);
		}
		setChoiceState(
			'available',
			wp.i18n.sprintf(
				/* translators: %d: number of eligible published releases. */
				wp.i18n._n(
					'%d eligible published release found.',
					'%d eligible published releases found.',
					releases.length,
					'ran-booster'
				),
				releases.length
			),
			wp.i18n.sprintf(
				/* translators: %d: number of available releases. */
				wp.i18n._n(
					'%d available',
					'%d available',
					releases.length,
					'ran-booster'
				),
				releases.length
			)
		);
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
				showUnavailable(response.code);
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
			!validFingerprint(data.fingerprint)
		) {
			showUnavailable('unable_to_check');
			return;
		}
		selectedRelease.fingerprint = data.fingerprint;
		setChoiceState(
			'available',
			wp.i18n.sprintf(
				/* translators: %s: inspected release version. */
				wp.i18n.__(
					'Release %s was inspected and is ready for final acquisition.',
					'ran-booster'
				),
				data.version
			),
			wp.i18n.__('Inspected', 'ran-booster')
		);
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
				showCandidateUnavailable(response.code);
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
