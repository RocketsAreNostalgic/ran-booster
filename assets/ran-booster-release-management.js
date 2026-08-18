(() => {
	'use strict';

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
		const disabled = state === 'waiting' || state === 'unsupported';
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
			state === 'unavailable' || state === 'unsupported'
		);
		releaseChoice.disabled = disabled;
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
			? data.candidates.slice(0, 8)
			: [];
		if (!candidateList || releases.length === 0) {
			showUnavailable('no_releases');
			return;
		}
		selectedRelease = null;
		candidateList.replaceChildren();
		releases.forEach((candidate) => {
			const label = document.createElement('label');
			label.className = 'ran-booster-release-candidate';
			const input = document.createElement('input');
			input.type = 'radio';
			input.name = 'ran_booster_release_candidate';
			input.value = String(candidate.release_id);
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
			candidateList.append(label);
		});
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
		if (releaseChoice.disabled) {
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
				'.ran-booster-provider-input, .ran-booster-credential-input, .ran-booster-repository-input, .ran-booster-branch-input'
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
