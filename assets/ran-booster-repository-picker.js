(function () {
	'use strict';

	function onDomReady(callback) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', callback, {
				once: true,
			});
			return;
		}

		callback();
	}

	onDomReady(initRepositoryPicker);

	document.addEventListener('htmx:beforeSwap', function (event) {
		if (event.detail?.target?.id !== 'wpbody-content') {
			return;
		}

		document
			.querySelectorAll('.ran-booster-repository-picker')
			.forEach(function (modal) {
				modal.remove();
			});
		document.body.classList.remove('ran-booster-repository-picker-open');
	});

	document.addEventListener('htmx:afterSwap', function (event) {
		if (event.detail?.target?.id === 'wpbody-content') {
			initRepositoryPicker();
		}
	});

	function updateDeploymentPolicyAvailability(form, provider) {
		const select = form.querySelector(
			'.ran-booster-deployment-policy-input, select[name="ran_booster[deployment_policy]"]'
		);

		if (!select) {
			return;
		}

		const automatic = Array.from(select.options).find(function (option) {
			return option.value === 'automatic';
		});

		if (!automatic) {
			return;
		}

		const supported = Boolean(provider && provider.webhooks);
		automatic.disabled = !supported;
		if (!supported && select.value === 'automatic') {
			select.value = 'manual';
		}
	}

	function updateRepositoryPickerAvailability(form, provider) {
		const button = form.querySelector(
			'.ran-booster-open-repository-picker'
		);

		if (!button) {
			return;
		}

		const supported = Boolean(provider && provider.browse);

		button.disabled = !supported;
		button.hidden = !supported;
		button.title = supported
			? ''
			: 'Repository browsing is not available for this provider.';
	}

	function repositoryResultStatus(count, partialMessage) {
		const countMessage =
			count + (count === 1 ? ' repository' : ' repositories');

		return partialMessage
			? countMessage + '. ' + partialMessage
			: countMessage;
	}

	function setRepositoryPickerLoading(list, isLoading) {
		const checking = isLoading === true;

		list.classList.toggle('is-checking', checking);
		list.setAttribute('aria-busy', checking ? 'true' : 'false');
	}

	function emptyRepositoryPickerState() {
		return {
			version: 2,
			lastProvider: '',
			providers: {},
		};
	}

	function normalizeRepositoryPickerState(value) {
		const state = emptyRepositoryPickerState();

		if (!value || typeof value !== 'object' || value.version !== 2) {
			return state;
		}

		if (
			typeof value.lastProvider === 'string' &&
			/^[a-z0-9][a-z0-9_-]{0,63}$/i.test(value.lastProvider)
		) {
			state.lastProvider = value.lastProvider;
		}

		if (!value.providers || typeof value.providers !== 'object') {
			return state;
		}

		Object.keys(value.providers).forEach(function (providerCode) {
			if (
				!/^[a-z0-9][a-z0-9_-]{0,63}$/i.test(providerCode) ||
				!value.providers[providerCode] ||
				typeof value.providers[providerCode] !== 'object'
			) {
				return;
			}

			const provider = value.providers[providerCode];
			const accessible =
				provider.accessible && typeof provider.accessible === 'object'
					? provider.accessible
					: {};
			const publicLookup =
				provider.public && typeof provider.public === 'object'
					? provider.public
					: {};
			const publicIdentity = ['anonymous', 'default', 'profile'].includes(
				publicLookup.identity
			)
				? publicLookup.identity
				: 'anonymous';

			state.providers[providerCode] = {
				mode: provider.mode === 'accessible' ? 'accessible' : 'public',
				accessible: {
					credentialId:
						typeof accessible.credentialId === 'string'
							? accessible.credentialId.slice(0, 191)
							: '',
					filter:
						typeof accessible.filter === 'string'
							? accessible.filter.slice(0, 2048)
							: '',
				},
				public: {
					identity: publicIdentity,
					profileId:
						publicIdentity === 'profile' &&
						typeof publicLookup.profileId === 'string'
							? publicLookup.profileId.slice(0, 191)
							: '',
					owner:
						typeof publicLookup.owner === 'string'
							? publicLookup.owner.slice(0, 255)
							: '',
					filter:
						typeof publicLookup.filter === 'string'
							? publicLookup.filter.slice(0, 2048)
							: '',
				},
			};
		});

		return state;
	}

	function readRepositoryPickerState(storage) {
		if (!storage) {
			return emptyRepositoryPickerState();
		}

		try {
			const value = storage.getItem('ran-booster-repository-picker');

			return value
				? normalizeRepositoryPickerState(JSON.parse(value))
				: emptyRepositoryPickerState();
		} catch {
			return emptyRepositoryPickerState();
		}
	}

	function writeRepositoryPickerState(storage, state) {
		if (!storage) {
			return;
		}

		try {
			storage.setItem(
				'ran-booster-repository-picker',
				JSON.stringify(normalizeRepositoryPickerState(state))
			);
		} catch {
			// Storage can be disabled by browser privacy policy.
		}
	}

	function repositoryPickerStorage(targetWindow) {
		try {
			return targetWindow.sessionStorage;
		} catch {
			return null;
		}
	}

	function repositoryPickerProviderState(state, providerCode) {
		if (
			!Object.prototype.hasOwnProperty.call(state.providers, providerCode)
		) {
			state.providers[providerCode] = {
				mode: 'public',
				accessible: {
					credentialId: '',
					filter: '',
				},
				public: {
					identity: 'default',
					profileId: '',
					owner: '',
					filter: '',
				},
			};
		}

		return state.providers[providerCode];
	}

	function restoreRepositoryPickerProvider(form, state, providers) {
		if (
			!form ||
			form.getAttribute('data-ran-booster-package-create') !== '1' ||
			form.getAttribute('data-ran-booster-explicit-provider') === '1'
		) {
			return false;
		}

		const provider = providers[state.lastProvider];
		const input = form.querySelector('.ran-booster-provider-input');
		const available =
			input &&
			provider &&
			provider.browse &&
			Array.from(input.options).some(function (option) {
				return option.value === state.lastProvider;
			});

		if (!available) {
			return false;
		}

		input.value = state.lastProvider;
		return true;
	}

	function autoOpenRepositoryPicker(form) {
		if (
			!form ||
			form.getAttribute('data-ran-booster-package-create') !== '1' ||
			form.getAttribute('data-ran-booster-open-picker') !== '1'
		) {
			return false;
		}

		const button = form.querySelector(
			'.ran-booster-open-repository-picker'
		);

		if (!button || button.disabled || button.hidden) {
			return false;
		}

		button.click();
		return true;
	}

	function repositoryPickerPublicProfileValue(
		lookup,
		savedLookup,
		availableProfileIds
	) {
		const available = Array.isArray(availableProfileIds)
			? availableProfileIds
			: [];

		if (!savedLookup || savedLookup.identity === 'anonymous') {
			return '';
		}

		if (
			savedLookup.identity === 'profile' &&
			available.includes(savedLookup.profileId)
		) {
			return savedLookup.profileId;
		}

		if (
			savedLookup.identity === 'default' &&
			lookup &&
			!lookup.stale &&
			available.includes(lookup.configured_id)
		) {
			return lookup.configured_id;
		}

		return '';
	}

	function repositoryPickerPublicProfiles(lookup, profiles) {
		const configured = Array.isArray(profiles)
			? profiles.filter(function (profile) {
					return profile && profile.configured !== false;
				})
			: [];

		if (!lookup || !lookup.supports_default) {
			return configured;
		}

		return configured.filter(function (profile) {
			return profile.id === lookup.configured_id;
		});
	}

	function repositoryPickerShowsCredentialsNotice(
		lookup,
		configuredProfiles,
		credentialsUrl
	) {
		return Boolean(
			lookup &&
			Array.isArray(configuredProfiles) &&
			configuredProfiles.length === 0 &&
			credentialsUrl
		);
	}

	function dispatchRepositoryContextChanged(form, reason) {
		if (!form || typeof window.CustomEvent !== 'function') {
			return;
		}

		form.dispatchEvent(
			new window.CustomEvent('ran-booster:repository-context-changed', {
				bubbles: true,
				detail: {
					reason,
				},
			})
		);
	}

	function initRepositoryPicker() {
		const settings = window.ranBoosterRepoPicker || {};
		const providerList = Array.isArray(settings.providers)
			? settings.providers
			: [];
		const providers = {};

		providerList.forEach(function (provider) {
			if (provider && typeof provider.code === 'string') {
				providers[provider.code] = Object.assign({}, provider, {
					label: provider.label || 'Repository provider',
					owner_label: provider.owner_label || 'owner',
					credential_profiles: Array.isArray(
						provider.credential_profiles
					)
						? provider.credential_profiles
						: [],
				});
			}
		});

		const pickerStorage = repositoryPickerStorage(window);
		const pickerState = readRepositoryPickerState(pickerStorage);
		const pickerButtons = document.querySelectorAll(
			'.ran-booster-open-repository-picker'
		);

		document
			.querySelectorAll('.ran-booster-provider-input')
			.forEach(function (providerInput) {
				const form = providerInput.form;

				if (!form) {
					return;
				}

				restoreRepositoryPickerProvider(form, pickerState, providers);
				updateProviderForm(form, false);
				providerInput.addEventListener('change', function () {
					updateProviderForm(form, true);
					dispatchRepositoryContextChanged(form, 'provider');
					const provider = getFormProvider(form);
					if (provider && provider.browse) {
						pickerState.lastProvider = provider.code;
						writeRepositoryPickerState(pickerStorage, pickerState);
					}
				});
			});

		document
			.querySelectorAll(
				'.ran-booster-credential-input, .ran-booster-branch-input'
			)
			.forEach(function (input) {
				input.addEventListener('change', function () {
					dispatchRepositoryContextChanged(
						input.form,
						input.classList.contains('ran-booster-credential-input')
							? 'credential'
							: 'branch'
					);
				});
			});

		if (!pickerButtons.length) {
			return;
		}

		let loadedRepositories = null;
		let activeMode = 'public';
		let activeProvider = null;
		let requestSequence = 0;
		let activeButton = null;
		let activeForm = null;
		let partialResultMessage = '';
		let loadedPublicLookupProfileId = '';
		const modal = createPickerModal();
		const dialog = modal.querySelector(
			'.ran-booster-repository-picker__dialog'
		);
		const title = modal.querySelector(
			'.ran-booster-repository-picker__title'
		);
		const modeButtons = modal.querySelectorAll(
			'.ran-booster-repository-picker__mode'
		);
		const accessibleModeButton = modal.querySelector(
			'.ran-booster-repository-picker__mode[data-mode="accessible"]'
		);
		const accessibleOptions = modal.querySelector(
			'.ran-booster-repository-picker__accessible-options'
		);
		const credentialSelect = modal.querySelector(
			'.ran-booster-repository-picker__credential'
		);
		const publicSearch = modal.querySelector(
			'.ran-booster-repository-picker__public-search'
		);
		const publicProfileSelect = modal.querySelector(
			'.ran-booster-repository-picker__public-profile'
		);
		const publicLimitNotice = modal.querySelector(
			'.ran-booster-repository-picker__public-limit-notice'
		);
		const publicLimitLink = modal.querySelector(
			'.ran-booster-repository-picker__public-limit-link'
		);
		const ownerInput = modal.querySelector(
			'.ran-booster-repository-picker__owner'
		);
		const search = modal.querySelector(
			'.ran-booster-repository-picker__search'
		);
		const status = modal.querySelector(
			'.ran-booster-repository-picker__status'
		);
		const list = modal.querySelector(
			'.ran-booster-repository-picker__list'
		);

		document.body.appendChild(modal);

		document
			.querySelectorAll('.ran-booster-repository-input')
			.forEach(function (input) {
				input.addEventListener('input', function () {
					const form = input.form;
					const identityInput = form
						? input.form.querySelector(
								'.ran-booster-provider-repository-id-input'
							)
						: null;
					const identitySourceInput = form
						? form.querySelector(
								'.ran-booster-provider-repository-identity-source-input'
							)
						: null;
					const publicLookupProfileInput = form
						? form.querySelector(
								'.ran-booster-public-lookup-profile-input'
							)
						: null;

					if (identityInput) {
						identityInput.value = '';
					}
					if (identitySourceInput) {
						identitySourceInput.value = 'manual';
					}
					if (publicLookupProfileInput) {
						publicLookupProfileInput.value = '';
					}
				});
				input.addEventListener('change', function () {
					dispatchRepositoryContextChanged(input.form, 'repository');
				});
			});

		pickerButtons.forEach(function (pickerButton) {
			pickerButton.addEventListener('click', function () {
				activeButton = this;
				activeForm = this.form;
				activeProvider = getFormProvider(activeForm);

				if (!activeProvider || !activeProvider.browse) {
					return;
				}

				pickerState.lastProvider = activeProvider.code;
				writeRepositoryPickerState(pickerStorage, pickerState);
				configurePicker(activeProvider);
				title.textContent =
					'Pick a ' +
					(this.getAttribute('data-package-type') === 'theme'
						? 'theme'
						: 'plugin') +
					' repository from ' +
					activeProvider.label;
				modal.removeAttribute('hidden');
				document.body.classList.add(
					'ran-booster-repository-picker-open'
				);
				const providerState = currentProviderState();
				activeMode =
					providerState.mode === 'accessible' &&
					!accessibleModeButton.disabled
						? 'accessible'
						: 'public';
				showMode(activeMode, true);
			});
		});

		modeButtons.forEach(function (button) {
			button.addEventListener('click', function () {
				showMode(button.getAttribute('data-mode'), true);
			});
		});

		credentialSelect.addEventListener('change', function () {
			currentProviderState().accessible.credentialId =
				credentialSelect.value;
			persistPickerState();
			showAccessibleRepositories();
		});
		publicProfileSelect.addEventListener('change', function () {
			persistPublicLookupSelection();
			resetPublicResults();
		});
		ownerInput.addEventListener('input', function () {
			if (!activeProvider) {
				return;
			}
			currentProviderState().public.owner = ownerInput.value;
			persistPickerState();
		});

		publicSearch.addEventListener('submit', function (event) {
			event.preventDefault();
			const owner = ownerInput.value.trim();

			if (!owner) {
				setRepositoryPickerLoading(list, false);
				loadedRepositories = null;
				search.disabled = true;
				list.replaceChildren();
				setStatus(
					'Enter the ' +
						activeProvider.owner_label.toLowerCase() +
						' to search.'
				);
				ownerInput.focus();
				return;
			}

			ownerInput.value = owner;
			currentProviderState().public.owner = owner;
			persistPublicLookupSelection();
			const lookup = publicLookupSelection();
			loadRepositories(
				'public',
				owner,
				'',
				lookup.identity,
				lookup.profileId
			);
		});

		modal.addEventListener('click', function (event) {
			if (
				event.target === modal ||
				event.target.closest('.ran-booster-repository-picker__close')
			) {
				closePicker();
			}
		});

		modal.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				closePicker();
				return;
			}

			trapFocus(event, dialog);
		});

		search.addEventListener('input', function () {
			if (activeProvider) {
				currentProviderState()[activeMode].filter = search.value;
				persistPickerState();
			}
			if (loadedRepositories !== null) {
				renderRepositories(loadedRepositories, search.value);
			}
		});

		document
			.querySelectorAll(
				'form[data-ran-booster-package-create="1"][data-ran-booster-open-picker="1"]'
			)
			.forEach(autoOpenRepositoryPicker);

		function createPickerModal() {
			const overlay = document.createElement('div');

			overlay.className =
				'ran-booster-repository-picker ran-booster-dialog';
			overlay.setAttribute('hidden', 'hidden');
			overlay.innerHTML =
				'<div class="ran-booster-repository-picker__dialog ran-booster-dialog__surface" role="dialog" aria-modal="true" aria-labelledby="ran-booster-repository-picker-title">' +
				'<div class="ran-booster-repository-picker__header ran-booster-dialog__header">' +
				'<h2 id="ran-booster-repository-picker-title" class="ran-booster-repository-picker__title ran-booster-dialog__title">Pick a repository</h2>' +
				'<button type="button" class="ran-booster-repository-picker__close ran-booster-dialog__close" aria-label="Close repository picker"><span aria-hidden="true">&times;</span></button>' +
				'</div>' +
				'<div class="ran-booster-repository-picker__body">' +
				'<div class="ran-booster-repository-picker__modes" role="group" aria-label="Repository source">' +
				'<button type="button" class="ran-booster-source-choice ran-booster-repository-picker__mode" data-mode="public" aria-pressed="false">' +
				'<span class="ran-booster-source-choice__radio" aria-hidden="true"></span>' +
				'<span><strong>Public repositories</strong><small>Find public repositories by owner using anonymous or saved access.</small><span class="ran-booster-source-choice__meta">Public only</span></span>' +
				'</button>' +
				'<button type="button" class="ran-booster-source-choice ran-booster-repository-picker__mode" data-mode="accessible" aria-pressed="false">' +
				'<span class="ran-booster-source-choice__radio" aria-hidden="true"></span>' +
				'<span><strong>Accessible repositories</strong><small>Browse repositories available to a saved access profile.</small><span class="ran-booster-source-choice__meta">Saved access required</span></span>' +
				'</button>' +
				'</div>' +
				'<div class="ran-booster-repository-picker__profile-options ran-booster-repository-picker__accessible-options" hidden>' +
				'<label for="ran-booster-repository-picker-credential">Repository access profile</label>' +
				'<select id="ran-booster-repository-picker-credential" class="ran-booster-repository-picker__credential"></select>' +
				'</div>' +
				'<form class="ran-booster-repository-picker__public-search" hidden>' +
				'<div class="ran-booster-repository-picker__profile-options">' +
				'<label for="ran-booster-repository-picker-public-profile">Repository access profile</label>' +
				'<select id="ran-booster-repository-picker-public-profile" class="ran-booster-repository-picker__public-profile"></select>' +
				'</div>' +
				'<div class="notice notice-info inline ran-booster-repository-picker__public-limit-notice" hidden>' +
				'<p>Anonymous API requests have lower rate limits. <a class="ran-booster-repository-picker__public-limit-link" href="#">Manage credentials</a> to add a search access token for more reliable repository lookup.</p>' +
				'</div>' +
				'<label for="ran-booster-repository-picker-owner" class="ran-booster-repository-picker__owner-label">Repository owner</label>' +
				'<div class="ran-booster-repository-picker__public-search-fields">' +
				'<input id="ran-booster-repository-picker-owner" class="regular-text ran-booster-repository-picker__owner" type="text" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="e.g. organisation-or-user">' +
				'<button type="submit" class="button button-primary">Search</button>' +
				'</div>' +
				'</form>' +
				'<div class="ran-booster-repository-picker__filter">' +
				'<label for="ran-booster-repository-picker-search" class="screen-reader-text">Filter repository results</label>' +
				'<input id="ran-booster-repository-picker-search" class="regular-text ran-booster-repository-picker__search" type="search" placeholder="Filter repository results&hellip;" autocomplete="off">' +
				'</div>' +
				'<p class="ran-booster-repository-picker__status" role="status" aria-live="polite"></p>' +
				'<div class="ran-booster-repository-picker__list"></div>' +
				'</div>' +
				'</div>';
			return overlay;
		}

		function currentProviderState() {
			return repositoryPickerProviderState(
				pickerState,
				activeProvider.code
			);
		}

		function persistPickerState() {
			writeRepositoryPickerState(pickerStorage, pickerState);
		}

		function persistPublicLookupSelection() {
			if (!activeProvider) {
				return;
			}

			const selection = publicLookupSelection();
			const publicState = currentProviderState().public;
			publicState.identity = selection.identity;
			publicState.profileId = selection.profileId;
			publicState.owner = ownerInput.value;
			persistPickerState();
		}

		function configurePicker(provider) {
			const profiles = Array.isArray(provider.credential_profiles)
				? provider.credential_profiles
				: [];
			const accessibleProfiles = profiles.filter(function (profile) {
				return profile.configured !== false;
			});
			const providerState = currentProviderState();
			const ownerLabel = modal.querySelector(
				'.ran-booster-repository-picker__owner-label'
			);

			credentialSelect.replaceChildren();
			accessibleProfiles.forEach(function (profile) {
				const details = [
					profile.label,
					profile.kind_label,
					profile.detail,
				]
					.filter(Boolean)
					.join(' — ');
				credentialSelect.appendChild(
					new window.Option(
						details || 'Repository credential',
						profile.id || ''
					)
				);
			});
			const formCredential = activeForm
				? activeForm.querySelector('.ran-booster-credential-input')
				: null;
			if (
				formCredential &&
				formCredential.value &&
				Array.from(credentialSelect.options).some(function (option) {
					return option.value === formCredential.value;
				})
			) {
				credentialSelect.value = formCredential.value;
			} else if (
				Array.from(credentialSelect.options).some(function (option) {
					return (
						option.value === providerState.accessible.credentialId
					);
				})
			) {
				credentialSelect.value = providerState.accessible.credentialId;
			}
			providerState.accessible.credentialId = credentialSelect.value;
			accessibleModeButton.disabled = accessibleProfiles.length === 0;
			accessibleModeButton.classList.toggle(
				'is-disabled',
				accessibleProfiles.length === 0
			);
			ownerLabel.textContent =
				provider.label + ' ' + provider.owner_label;
			ownerInput.value = providerState.public.owner;
			configurePublicLookup(provider, profiles);
			persistPickerState();
		}

		function configurePublicLookup(provider, profiles) {
			const savedLookup = currentProviderState().public;
			const lookup =
				provider.public_lookup &&
				typeof provider.public_lookup === 'object'
					? provider.public_lookup
					: null;
			publicProfileSelect.replaceChildren(
				new window.Option('Anonymous', '')
			);

			const configuredProfiles = repositoryPickerPublicProfiles(
				lookup,
				profiles
			);
			configuredProfiles.forEach(function (profile) {
				const details = [
					profile.label,
					profile.kind_label,
					profile.detail,
				]
					.filter(Boolean)
					.join(' — ');
				publicProfileSelect.appendChild(
					new window.Option(
						details || 'Repository credential',
						profile.id || ''
					)
				);
			});
			const credentialsUrl =
				typeof provider.credentials_url === 'string'
					? provider.credentials_url
					: '';
			const showCredentialsNotice =
				repositoryPickerShowsCredentialsNotice(
					lookup,
					configuredProfiles,
					credentialsUrl
				);
			publicLimitNotice.toggleAttribute('hidden', !showCredentialsNotice);
			if (showCredentialsNotice) {
				publicLimitLink.href = credentialsUrl || '#';
			}
			publicProfileSelect.value = repositoryPickerPublicProfileValue(
				lookup,
				savedLookup,
				configuredProfiles.map(function (profile) {
					return profile.id;
				})
			);
			persistPublicLookupSelection();
		}

		function publicLookupSelection() {
			return publicProfileSelect.value
				? {
						identity: 'profile',
						profileId: publicProfileSelect.value,
					}
				: { identity: 'anonymous', profileId: '' };
		}

		function showMode(mode, moveFocus) {
			setRepositoryPickerLoading(list, false);
			activeMode =
				mode === 'accessible' && !accessibleModeButton.disabled
					? 'accessible'
					: 'public';
			const providerState = currentProviderState();
			providerState.mode = activeMode;
			loadedRepositories = null;
			partialResultMessage = '';
			loadedPublicLookupProfileId = '';
			search.value = providerState[activeMode].filter;
			list.replaceChildren();
			persistPickerState();

			modeButtons.forEach(function (button) {
				const isActive =
					button.getAttribute('data-mode') === activeMode;
				button.setAttribute(
					'aria-pressed',
					isActive ? 'true' : 'false'
				);
				button.classList.toggle('is-selected', isActive);
			});

			if (activeMode === 'public') {
				accessibleOptions.setAttribute('hidden', 'hidden');
				publicSearch.removeAttribute('hidden');

				search.disabled = true;
				const owner = ownerInput.value.trim();
				if (owner) {
					const lookup = publicLookupSelection();
					loadRepositories(
						'public',
						owner,
						'',
						lookup.identity,
						lookup.profileId
					);
				} else {
					setStatus(
						'Enter the ' +
							activeProvider.owner_label.toLowerCase() +
							' to find public repositories on ' +
							activeProvider.label +
							'.'
					);
				}

				if (moveFocus) {
					ownerInput.focus();
				}
				return;
			}

			publicSearch.setAttribute('hidden', 'hidden');
			accessibleOptions.removeAttribute('hidden');

			if (!activeProvider.credential_profiles.length) {
				search.disabled = true;
				setStatus(
					'Add a repository access credential for ' +
						activeProvider.label +
						' to browse accessible repositories.'
				);
				if (moveFocus) {
					modeButtons[0].focus();
				}
				return;
			}

			showAccessibleRepositories();
			if (moveFocus) {
				credentialSelect.focus();
			}
		}

		function showAccessibleRepositories() {
			const credentialId = credentialSelect.value;
			loadRepositories('accessible', '', credentialId);
		}

		function loadRepositories(
			mode,
			owner,
			credentialId,
			publicLookupIdentity = 'anonymous',
			publicLookupProfileId = ''
		) {
			if (!activeProvider || !activeProvider.browse) {
				showError(
					'This repository provider does not support browsing.'
				);
				return;
			}

			const sequence = ++requestSequence;
			const providerCode = activeProvider.code;
			partialResultMessage = '';
			setRepositoryPickerLoading(list, true);
			setStatus('Loading repositories…');
			list.replaceChildren();
			search.disabled = true;

			requestRepositoryList(
				{
					action: settings.action || 'ran_booster_list_repositories',
					nonce: settings.nonce || '',
					mode,
					owner: owner || '',
					credential_id: credentialId || '',
					public_lookup_identity: publicLookupIdentity,
					public_lookup_profile_id: publicLookupProfileId,
					provider: providerCode,
				},
				function (response) {
					if (
						sequence !== requestSequence ||
						mode !== activeMode ||
						!activeProvider ||
						providerCode !== activeProvider.code
					) {
						return;
					}

					setRepositoryPickerLoading(list, false);

					if (!response || response.success !== true) {
						showError(getErrorMessage(response));
						return;
					}

					const data = response.data || {};
					let result = Array.isArray(data) ? data : data.repositories;
					result = Array.isArray(result) ? result : [];

					if (
						result.some(function (repository) {
							return (
								!repository ||
								repository.provider !== providerCode
							);
						})
					) {
						showError(
							'The repository provider returned invalid results.'
						);
						return;
					}

					loadedRepositories = result;
					loadedPublicLookupProfileId =
						mode === 'public' &&
						typeof data.public_lookup_profile_id === 'string'
							? data.public_lookup_profile_id
							: '';
					partialResultMessage =
						data.partial === true &&
						typeof data.message === 'string'
							? data.message
							: '';
					search.disabled = false;
					renderRepositories(loadedRepositories, search.value);
				},
				function (response) {
					if (
						sequence !== requestSequence ||
						mode !== activeMode ||
						!activeProvider ||
						providerCode !== activeProvider.code
					) {
						return;
					}
					setRepositoryPickerLoading(list, false);
					showError(getErrorMessage(response));
				}
			);
		}

		function requestRepositoryList(data, onSuccess, onFailure) {
			const payload = new URLSearchParams(data);

			window
				.fetch(settings.ajaxUrl || window.ajaxurl, {
					body: payload,
					credentials: 'same-origin',
					headers: { Accept: 'application/json' },
					method: 'POST',
				})
				.then(function (response) {
					return response
						.json()
						.catch(function () {
							return null;
						})
						.then(function (responseData) {
							return { data: responseData, ok: response.ok };
						});
				})
				.then(function (result) {
					if (result.ok) {
						onSuccess(result.data);
						return;
					}

					onFailure(result.data);
				})
				.catch(function () {
					onFailure(null);
				});
		}

		function renderRepositories(items, query) {
			const needle = query.trim().toLowerCase();
			const filtered = items.filter(function (repository) {
				return (
					repository &&
					typeof repository.locator === 'string' &&
					repository.locator.toLowerCase().indexOf(needle) !== -1
				);
			});

			list.replaceChildren();

			if (!filtered.length) {
				let emptyMessage =
					'No repositories are available to the selected access profile.';

				if (needle) {
					emptyMessage = 'No repositories match your filter.';
				} else if (activeMode === 'public') {
					emptyMessage =
						'No public repositories were found for this ' +
						activeProvider.owner_label.toLowerCase() +
						'.';
				}

				setStatus(
					partialResultMessage
						? emptyMessage + ' ' + partialResultMessage
						: emptyMessage
				);
				return;
			}

			setStatus(
				repositoryResultStatus(filtered.length, partialResultMessage)
			);
			filtered.forEach(function (repository) {
				const button = document.createElement('button');
				const name = document.createElement('span');
				const details = document.createElement('span');

				button.type = 'button';
				button.className = 'ran-booster-repository-picker__repository';
				name.className =
					'ran-booster-repository-picker__repository-name';
				details.className =
					'ran-booster-repository-picker__repository-details';
				name.textContent = repository.locator;
				details.textContent =
					(repository.private ? 'Private' : 'Public') +
					(repository.default_branch
						? ' · ' + repository.default_branch
						: '') +
					(repository.credential_label
						? ' · ' + repository.credential_label
						: '');
				button.appendChild(name);
				button.appendChild(details);
				button.addEventListener('click', function () {
					selectRepository(repository);
				});
				list.appendChild(button);
			});
		}

		function selectRepository(repository) {
			if (!activeForm) {
				return;
			}

			const repositoryInput = activeForm.querySelector(
				'.ran-booster-repository-input'
			);
			const branchInput = activeForm.querySelector(
				'.ran-booster-branch-input'
			);
			let credentialInput = activeForm.querySelector(
				'.ran-booster-credential-input'
			);
			const providerInput = activeForm.querySelector(
				'.ran-booster-provider-input'
			);
			const providerRepositoryIdInput = activeForm.querySelector(
				'.ran-booster-provider-repository-id-input'
			);
			const providerRepositoryIdentitySourceInput =
				activeForm.querySelector(
					'.ran-booster-provider-repository-identity-source-input'
				);
			const publicLookupProfileInput = activeForm.querySelector(
				'.ran-booster-public-lookup-profile-input'
			);

			if (!credentialInput) {
				credentialInput = document.createElement('input');
				credentialInput.type = 'hidden';
				credentialInput.name = 'ran_booster[credential_id]';
				credentialInput.className = 'ran-booster-credential-input';
				activeForm.appendChild(credentialInput);
			}

			repositoryInput.value = repository.locator || '';
			if (providerInput) {
				providerInput.value = repository.provider;
				updateProviderForm(activeForm, false);
			}
			if (providerRepositoryIdInput) {
				providerRepositoryIdInput.value =
					repository.provider_repository_id || '';
			}
			if (providerRepositoryIdentitySourceInput) {
				providerRepositoryIdentitySourceInput.value = 'picker';
			}
			if (publicLookupProfileInput) {
				publicLookupProfileInput.value =
					activeMode === 'public' ? loadedPublicLookupProfileId : '';
			}
			branchInput.value = repository.default_branch || '';
			selectCredential(
				credentialInput,
				repository.provider,
				activeMode === 'accessible'
					? repository.credential_id || credentialSelect.value || ''
					: ''
			);
			dispatchRepositoryContextChanged(activeForm, 'picker');
			closePicker();
			repositoryInput.focus();
		}

		function closePicker() {
			if (modal.hasAttribute('hidden')) {
				return;
			}
			setRepositoryPickerLoading(list, false);
			modal.setAttribute('hidden', 'hidden');
			requestSequence += 1;
			document.body.classList.remove(
				'ran-booster-repository-picker-open'
			);
			if (activeButton) {
				activeButton.focus();
			}
		}

		function setStatus(message) {
			status.textContent = message;
			status.classList.remove(
				'ran-booster-repository-picker__status--error'
			);
		}

		function showError(message) {
			setRepositoryPickerLoading(list, false);
			loadedRepositories = null;
			loadedPublicLookupProfileId = '';
			partialResultMessage = '';
			search.disabled = true;
			list.replaceChildren();
			status.textContent = message || 'Repositories could not be loaded.';
			status.classList.add(
				'ran-booster-repository-picker__status--error'
			);
		}

		function resetPublicResults() {
			setRepositoryPickerLoading(list, false);
			loadedRepositories = null;
			loadedPublicLookupProfileId = '';
			partialResultMessage = '';
			search.disabled = true;
			list.replaceChildren();
			setStatus(
				'Enter the ' +
					activeProvider.owner_label.toLowerCase() +
					' to find public repositories on ' +
					activeProvider.label +
					'.'
			);
		}

		function getErrorMessage(response) {
			const fallback =
				activeMode === 'public'
					? 'Public repositories could not be loaded. Check the owner name and try again.'
					: 'Repositories could not be loaded. Check the selected access profile and try again.';

			if (!response || !response.data) {
				return fallback;
			}

			if (typeof response.data === 'string') {
				return response.data;
			}

			return response.data.message || fallback;
		}

		function getFormProvider(form) {
			if (!form) {
				return null;
			}

			const input = form.querySelector('.ran-booster-provider-input');
			const code = input
				? input.value
				: settings.defaultProvider ||
					(providerList.length ? providerList[0].code : '');
			return providers[code] || null;
		}

		function updateProviderForm(form, clearSelection) {
			const provider = getFormProvider(form);
			const credentialInput = form.querySelector(
				'.ran-booster-credential-input'
			);
			const publicLookupProfileInput = form.querySelector(
				'.ran-booster-public-lookup-profile-input'
			);
			const repositoryLink = form.parentElement
				? form.parentElement.querySelector(
						'.ran-booster-repository-link'
					)
				: null;

			if (credentialInput) {
				credentialInput
					.querySelectorAll('option')
					.forEach(function (option) {
						const optionProvider =
							option.getAttribute('data-provider');
						const available =
							!optionProvider ||
							(provider && optionProvider === provider.code);
						option.disabled = !available;
						option.hidden = !available;
					});

				const selectedOption =
					credentialInput.options[credentialInput.selectedIndex];
				if (
					clearSelection ||
					(selectedOption && selectedOption.disabled)
				) {
					credentialInput.value = '';
				}
			}

			updateRepositoryPickerAvailability(form, provider);

			updateDeploymentPolicyAvailability(form, provider);

			if (repositoryLink && provider) {
				const repositoryInput = form.querySelector(
					'.ran-booster-repository-input'
				);
				const repository = repositoryInput ? repositoryInput.value : '';
				repositoryLink.setAttribute(
					'data-repository-base',
					provider.repository_url_base || ''
				);
				repositoryLink.href =
					(provider.repository_url_base || '') +
					repository.replace(/^\/+/, '');
			}

			if (clearSelection) {
				const providerRepositoryIdInput = form.querySelector(
					'.ran-booster-provider-repository-id-input'
				);
				const identitySourceInput = form.querySelector(
					'.ran-booster-provider-repository-identity-source-input'
				);

				if (providerRepositoryIdInput) {
					providerRepositoryIdInput.value = '';
				}
				if (identitySourceInput) {
					identitySourceInput.value = 'manual';
				}
				if (publicLookupProfileInput) {
					publicLookupProfileInput.value = '';
				}
			}
		}

		function selectCredential(select, providerCode, credentialId) {
			const match = Array.from(select.options).find(function (option) {
				return (
					option.value === credentialId &&
					(!credentialId ||
						option.getAttribute('data-provider') === providerCode)
				);
			});

			select.selectedIndex = match ? match.index : 0;
		}
	}

	function trapFocus(event, container) {
		if (event.key !== 'Tab') {
			return;
		}

		const focusable = Array.from(
			container.querySelectorAll(
				'a[href], button:not(:disabled), input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"]):not([disabled])'
			)
		).filter(function (element) {
			if (
				('disabled' in element && element.disabled) ||
				element.tabIndex < 0 ||
				(element.tagName === 'INPUT' && element.type === 'hidden') ||
				!element.getClientRects().length
			) {
				return false;
			}

			let current = element;
			while (current) {
				if (
					current.hidden ||
					current.getAttribute('aria-hidden') === 'true'
				) {
					return false;
				}

				const style =
					current.ownerDocument.defaultView.getComputedStyle(current);
				if (
					style.display === 'none' ||
					style.visibility === 'hidden' ||
					style.visibility === 'collapse'
				) {
					return false;
				}

				current = current.parentElement;
			}

			return true;
		});
		const first = focusable[0];
		const last = focusable[focusable.length - 1];
		const activeElement = container.ownerDocument.activeElement;

		if (!first || !last) {
			return;
		}

		if (event.shiftKey && activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}
})();
