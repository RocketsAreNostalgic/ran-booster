const managedReleaseBrowsers = new WeakSet();

const initializeManagedReleaseBrowser = (managedBrowser) => {
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
	const updates = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-updates]'
	);
	const errorNotice = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-error]'
	);
	const errorMessage = managedBrowser.querySelector(
		'[data-ran-booster-managed-release-error-message]'
	);
	let selected = null;
	let selectedOutcome = null;
	let selectedLabel = null;
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
		setStatus(
			'Inspecting published release…',
			'Booster is validating the selected release without changing the installed package.'
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
			setStatus(
				'Release checked',
				relationship === 'newer'
					? `Version ${response.data.version} is available in WordPress Updates.`
					: `Version ${response.data.version} is installed.`
			);
			if (selectedOutcome) {
				selectedOutcome.textContent =
					relationship === 'newer'
						? `Version ${response.data.version} is available in WordPress Updates.`
						: `Version ${response.data.version} is installed.`;
				selectedOutcome.hidden = relationship !== 'newer';
			}
			if (updates) {
				updates.hidden = relationship !== 'newer';
				if (relationship === 'newer' && selectedLabel) {
					selectedLabel.append(updates);
				}
			}
		} catch {
			if (current === sequence) {
				showError(
					'Booster could not check the selected release. Refresh releases and try again.'
				);
				setStatus(
					'Published release could not be inspected',
					'The saved package or release may have changed. Refresh and try again.'
				);
			}
		}
	};
	const list = async () => {
		const current = ++sequence;
		selected = null;
		clearError();
		setListBusy(true);
		if (updates) {
			updates.hidden = true;
		}
		setStatus(
			'Checking published releases…',
			'Reading eligible candidates for this managed package.'
		);
		try {
			const response = await request('list');
			if (current !== sequence) {
				return;
			}
			const releases = Array.isArray(response.data?.candidates)
				? response.data.candidates
						.slice()
						.sort(
							(left, right) =>
								Date.parse(right.published_at) -
								Date.parse(left.published_at)
						)
						.slice(0, 8)
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
						? 'No Preview releases have been published for this package yet.'
						: 'No Stable releases have been published for this package yet.';
				candidateList.append(empty);
				if (candidates) {
					candidates.hidden = false;
				}
				setStatus('No published releases found', empty.textContent);
				return;
			}
			if (releases.length === 0) {
				throw new Error('invalid_candidates');
			}
			const installedCandidate = releases.find(
				(candidate) => candidate.version_relationship === 'same'
			);
			const visible = [releases[0]];
			if (installedCandidate && installedCandidate !== releases[0]) {
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
					if (selectedOutcome) {
						selectedOutcome.hidden = true;
					}
					selected = candidate;
					selectedLabel = label;
					selectedOutcome = outcome;
					inspect();
				});
				let marker =
					candidate === releases[0] ? ' · Latest eligible' : '';
				if (older) {
					marker += ' · Older than installed';
				} else if (candidate.version_relationship === 'same') {
					marker += ' · Installed/current';
				} else {
					marker += ' · Newer than installed';
				}
				const outcome = document.createElement('p');
				outcome.className = 'ran-booster-release-candidate__outcome';
				outcome.hidden = true;
				label.append(
					input,
					document.createTextNode(
						`${candidate.version} (${candidate.tag}) · ${candidate.prerelease ? 'Preview' : 'Stable'}${marker}`
					),
					outcome
				);
				candidateElements.set(candidate, { label, outcome });
				target.append(label);
			};
			visible.forEach((candidate) =>
				appendCandidate(candidate, candidateList)
			);
			if (response.data.installed_version && !installedCandidate) {
				const installed = document.createElement('p');
				installed.className =
					'ran-booster-release-candidate ran-booster-release-installed-version';
				installed.textContent = `Installed version: ${response.data.installed_version}`;
				candidateList.append(installed);
			}
			if (earlier.length > 0) {
				const disclosure = document.createElement('details');
				disclosure.className =
					'ran-booster-release-settings-disclosure';
				const summary = document.createElement('summary');
				summary.textContent = `Show ${earlier.length} earlier release${earlier.length === 1 ? '' : 's'}`;
				disclosure.append(summary);
				if (
					earlier.some(
						(candidate) =>
							candidate.version_relationship === 'older'
					)
				) {
					const warning = document.createElement('p');
					warning.textContent =
						'Downgrades are unavailable because package data migrations may not be reversible. For recovery, follow the package-specific instructions or restore a backup.';
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
			const inspectable = releases.filter(
				(candidate) => candidate.version_relationship !== 'older'
			);
			if (inspectable.length > 0) {
				setStatus(
					'Release candidates loaded',
					`${releases.length} eligible release${releases.length === 1 ? '' : 's'} found. Inspecting the newest available release.`
				);
				selected = inspectable[0];
				selectedLabel = candidateElements.get(selected)?.label || null;
				selectedOutcome =
					candidateElements.get(selected)?.outcome || null;
				const input = candidateList.querySelector(
					`input[data-release-id="${selected.release_id}"]`
				);
				if (input) {
					input.checked = true;
				}
				setListBusy(false);
				inspect();
			} else {
				setStatus(
					'No current or newer published release found',
					'Every available release is older. WordPress Updates will not downgrade this package.'
				);
			}
		} catch {
			if (current === sequence) {
				showError(
					'Booster could not load published releases. The repository provider may be temporarily unavailable or rate-limited. Try again later.'
				);
				setStatus(
					'Published releases could not be checked',
					'Booster could not read eligible releases for the saved package.'
				);
			}
		} finally {
			if (current === sequence) {
				setListBusy(false);
			}
		}
	};
	retry?.addEventListener('click', list);
	list();
};

(() => {
	'use strict';

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
				?.value.trim()
		);

	const updateAdvancedSummary = () => {
		if (!advancedSummary) {
			return;
		}
		if (releaseSelected) {
			advancedSummary.textContent = `Published releases · ${
				includesPrereleases() ? 'Preview' : 'Stable'
			}`;
			return;
		}
		const branch = form.querySelector('[name="ran_booster[branch]"]');
		advancedSummary.textContent = `Branch · ${
			branch?.value.trim() || 'provider default'
		}`;
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
				? `Published releases unavailable: ${description}`
				: 'Published releases'
		);
		releaseChoice.setAttribute(
			'aria-busy',
			state === 'checking' ? 'true' : 'false'
		);
		setText(choiceHeading, 'Published releases');
		setText(choiceDescription, description);
		setText(choiceMeta, meta);
	};

	const showIdle = () => {
		selectedRelease = null;
		setChoiceState(
			'available',
			'View eligible published releases from this repository.',
			'Stable by default'
		);
		setStatus(
			'Release candidates appear here',
			includesPrereleases()
				? 'Select Published releases to load stable and preview candidates.'
				: 'Select Published releases to load eligible stable candidates.'
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
			'Choose a repository before selecting this source.',
			'Choose repository first'
		);
		setStatus(
			'Release candidates appear here',
			'Choose a repository above to load eligible published releases.'
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
			'Published releases are not available for this repository provider.',
			'Provider capability unavailable'
		);
		setStatus(
			'Published releases are unavailable',
			'This provider does not expose the complete published-release capability. Use Branch tracking for this repository.'
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
			'Published releases require the repository root. Branch supports the configured subdirectory.',
			'Repository root required'
		);
		setStatus(
			'Published releases are unavailable',
			'Published releases require the repository root. Branch supports the configured subdirectory.'
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
					? 'Checking the selected repository for stable releases and prereleases…'
					: 'Checking the selected repository for a stable published release…',
				'Checking repository…'
			);
			const inspecting = phase === 'inspect';
			setStatus(
				inspecting
					? 'Validating published release…'
					: 'Checking published releases…',
				inspecting
					? 'Booster is downloading and validating the exact release ZIP for review. It will be discarded after inspection.'
					: 'Booster is checking release metadata without downloading a package.',
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
		let statusHeading = 'Published releases could not be checked';
		let statusMessage =
			'Repository access or the provider may be temporarily unavailable.';
		let choiceMessage = 'Booster could not confirm release availability.';
		if (noRelease && includesPrereleases()) {
			statusHeading = 'No eligible stable release or prerelease found';
			statusMessage =
				'This repository does not publish an eligible stable or preview release.';
			choiceMessage =
				'No eligible stable release or prerelease is currently available.';
		} else if (noRelease) {
			statusHeading = 'No eligible stable published release found';
			statusMessage =
				'This repository does not publish an eligible stable release.';
			choiceMessage =
				'No eligible stable published release is currently available.';
		}
		selectedRelease = null;
		setChoiceState(
			'unavailable',
			choiceMessage,
			noRelease ? 'Branch only' : 'Check failed'
		);
		setStatus(statusHeading, statusMessage, {
			retry: !noRelease,
			switchBranch: true,
		});
		setHidden(install, true);
		setHidden(details, true);
		setHidden(candidates, true);
	};

	const showCandidates = (data) => {
		const releases = Array.isArray(data.candidates)
			? data.candidates
					.slice()
					.sort(
						(left, right) =>
							Date.parse(right.published_at) -
							Date.parse(left.published_at)
					)
					.slice(0, 8)
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
			const track = candidate.prerelease ? 'Preview' : 'Stable';
			text.textContent = `${candidate.version} (${candidate.tag}) · ${track}`;
			label.append(input, text);
			target.append(label);
		};
		appendCandidate(releases[0], candidateList);
		if (releases.length > 1) {
			const disclosure = document.createElement('details');
			disclosure.className = 'ran-booster-release-settings-disclosure';
			const summary = document.createElement('summary');
			const earlier = releases.slice(1);
			summary.textContent = `Show ${earlier.length} earlier release${earlier.length === 1 ? '' : 's'}`;
			disclosure.append(summary);
			earlier.forEach((candidate) =>
				appendCandidate(candidate, disclosure)
			);
			candidateList.append(disclosure);
		}
		const firstCandidate = candidateInputs[0];
		if (firstCandidate) {
			firstCandidate.checked = true;
			const event =
				typeof Event === 'function'
					? new Event('change', { bubbles: true })
					: { type: 'change' };
			firstCandidate.dispatchEvent(event);
		}
		setChoiceState(
			'available',
			`${releases.length} eligible published release${releases.length === 1 ? '' : 's'} found.`,
			`${releases.length} available`
		);
		setStatus(
			'Release candidates loaded',
			`${releases.length} eligible release${releases.length === 1 ? '' : 's'} available. Choose one to run its pre-flight checks.`,
			{ screenReaderOnly: true }
		);
		setHidden(candidates, false);
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
			`Release ${data.version} was inspected and is ready for final acquisition.`,
			'Inspected'
		);
		setStatus(
			'Published release inspected',
			'The selected release passed initial inspection. Review the pre-flight checks below before installing.',
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
				showUnavailable(response.code);
			}
		} catch {
			if (sequence === requestSequence) {
				setChecking(false);
				showUnavailable('unable_to_check');
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
