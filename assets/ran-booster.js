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

	onDomReady(function () {
		initDocumentationDeepLinks();
		initTroubleshootingCredentialChoices();
		initProviderRepositoryFilter();
		initProviderTaskNavigation();
	});

	function initProviderTaskNavigation() {
		const taskRegion = document.getElementById(
			'ran-booster-provider-tasks'
		);
		if (!taskRegion) {
			return;
		}

		function currentTaskRegion() {
			return document.getElementById('ran-booster-provider-tasks');
		}

		function handlesTaskRegion(event) {
			const target = event.detail?.target;
			const requestElement = event.detail?.elt;

			return (
				target?.id === 'ran-booster-provider-task-panel' ||
				target?.id === 'ran-booster-provider-tasks' ||
				Boolean(
					requestElement?.closest?.('#ran-booster-provider-tasks')
				)
			);
		}

		function setBusy(isBusy) {
			const region = currentTaskRegion();
			if (!region) {
				return;
			}

			const progress = region.querySelector(
				'[data-ran-booster-provider-task-progress]'
			);
			const error = region.querySelector(
				'[data-ran-booster-provider-task-error]'
			);

			region.toggleAttribute('aria-busy', isBusy);
			if (progress) {
				progress.hidden = !isBusy;
			}
			if (isBusy && error) {
				error.hidden = true;
			}
		}

		function showFailure(event) {
			if (!handlesTaskRegion(event)) {
				return;
			}

			setBusy(false);
			const error = currentTaskRegion()?.querySelector(
				'[data-ran-booster-provider-task-error]'
			);
			if (error) {
				error.hidden = false;
				error.focus?.({ preventScroll: true });
			}
		}

		document.addEventListener('htmx:beforeRequest', function (event) {
			if (handlesTaskRegion(event)) {
				setBusy(true);
			}
		});

		document.addEventListener('htmx:afterSwap', function (event) {
			if (!handlesTaskRegion(event)) {
				return;
			}

			setBusy(false);
			const region = currentTaskRegion();
			if (!region) {
				return;
			}

			initProviderRepositoryFilter(region);
			const panel = region.querySelector(
				'#ran-booster-provider-task-panel'
			);
			const currentTask = panel?.dataset.ranBoosterProviderTask || '';
			let currentTab = null;

			region
				.querySelectorAll('.ran-booster-provider-task-tab')
				.forEach(function (tab) {
					const isCurrent =
						tab.dataset.ranBoosterProviderTask === currentTask;
					if (isCurrent) {
						tab.setAttribute('aria-current', 'page');
						currentTab = tab;
					} else {
						tab.removeAttribute('aria-current');
					}
				});
			currentTab?.focus({ preventScroll: true });

			region.dispatchEvent(
				new CustomEvent('ran-booster:provider-tasks-ready', {
					bubbles: true,
					detail: { panel, root: region },
				})
			);
		});

		[
			'htmx:responseError',
			'htmx:sendError',
			'htmx:swapError',
			'htmx:targetError',
			'htmx:timeout',
		].forEach(function (eventName) {
			document.addEventListener(eventName, showFailure);
		});
	}

	function initDocumentationDeepLinks() {
		if (!document.querySelector('.ran-booster-documentation')) {
			return;
		}

		openDetailsForHash(document, window.location.hash);
		window.addEventListener('hashchange', function () {
			openDetailsForHash(document, window.location.hash);
		});
	}

	function initTroubleshootingCredentialChoices() {
		const form = document.querySelector(
			'.ran-booster-troubleshooting__form'
		);
		if (!form) {
			return;
		}

		const provider = form.querySelector(
			'.ran-booster-troubleshooting__provider'
		);
		const credential = form.querySelector(
			'.ran-booster-troubleshooting__credential'
		);
		if (!provider || !credential) {
			return;
		}

		function updateCredentialChoices() {
			credential
				.querySelectorAll('option[data-provider]')
				.forEach(function (option) {
					const available =
						option.dataset.provider === provider.value;
					option.disabled = !available;
					option.hidden = !available;
				});

			const selected = credential.options[credential.selectedIndex];
			if (selected && selected.disabled) {
				credential.value = '';
			}
		}

		provider.addEventListener('change', updateCredentialChoices);
		updateCredentialChoices();
	}

	function initProviderRepositoryFilter(root = document) {
		const input = root.querySelector(
			'[data-ran-booster-provider-repository-filter]'
		);
		const count = root.querySelector(
			'[data-ran-booster-provider-repository-count]'
		);
		const rows = Array.from(
			root.querySelectorAll('[data-ran-booster-provider-repository]')
		);

		if (
			!input ||
			!count ||
			!rows.length ||
			input.dataset.ranBoosterRepositoryFilterBound === 'true'
		) {
			return;
		}
		input.dataset.ranBoosterRepositoryFilterBound = 'true';

		function update() {
			const query = input.value.trim().toLowerCase();
			let visible = 0;

			rows.forEach(function (row) {
				const matches = (
					row.getAttribute('data-repository-search') || ''
				).includes(query);
				row.hidden = !matches;
				if (matches) {
					visible += 1;
				}
			});

			const template =
				visible === 1 ? count.dataset.singular : count.dataset.plural;
			count.textContent = (template || '%d').replace('%d', visible);
		}

		input.addEventListener('input', update);
	}

	function openDetailsForHash(root, hash) {
		if (
			typeof hash !== 'string' ||
			hash.length < 2 ||
			hash.length > 513 ||
			hash.charAt(0) !== '#'
		) {
			return false;
		}

		let id;
		try {
			id = decodeURIComponent(hash.slice(1));
		} catch {
			return false;
		}

		if (!id || id.length > 512) {
			return false;
		}

		const target = root.getElementById(id);
		const details =
			target && typeof target.closest === 'function'
				? target.closest('.ran-booster-documentation details')
				: null;

		if (!details) {
			return false;
		}

		details.open = true;

		return true;
	}
})();
