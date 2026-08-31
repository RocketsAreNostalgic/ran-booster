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
		const detailRegion = document.getElementById(
			'ran-booster-provider-profile-region'
		);
		if (
			!taskRegion &&
			!detailRegion?.querySelector?.('.ran-booster-repository-detail')
		) {
			return;
		}

		function currentTaskRegion() {
			return (
				document.getElementById('ran-booster-provider-tasks') ||
				document.getElementById('ran-booster-provider-profile-region')
			);
		}

		function handlesTaskRegion(event) {
			const target = event.detail?.target;
			const requestElement = event.detail?.elt;

			return (
				target?.id === 'ran-booster-provider-task-panel' ||
				target?.id === 'ran-booster-provider-tasks' ||
				(target?.id === 'ran-booster-provider-profile-region' &&
					Boolean(
						requestElement?.closest?.(
							'.ran-booster-repository-detail__tabs'
						)
					)) ||
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
					if (!tab.dataset.ranBoosterProviderTask) {
						return;
					}
					const isCurrent =
						tab.dataset.ranBoosterProviderTask === currentTask;
					if (isCurrent) {
						tab.setAttribute('aria-current', 'page');
						currentTab = tab;
					} else {
						tab.removeAttribute('aria-current');
					}
				});
			const currentRepositoryTab = region.querySelector(
				'.ran-booster-repository-detail__tabs [aria-current="page"]'
			);
			(currentRepositoryTab || currentTab)?.focus({
				preventScroll: true,
			});

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
		const root = document.querySelector('.ran-booster-documentation');
		if (!root) {
			return;
		}
		if (root.dataset.ranBoosterDocumentationDeepLinksBound === 'true') {
			return;
		}
		const sections = Array.from(
			root.querySelectorAll('.ran-booster-documentation__section[id]')
		);
		if (!sections.length) {
			return;
		}
		const links = Array.from(
			root.querySelectorAll(
				'.ran-booster-documentation__index a[href^="#"]'
			)
		);
		root.dataset.ranBoosterDocumentationDeepLinksBound = 'true';

		function activateHash(hash, useFirstSection) {
			const section = openDetailsForHash(root, hash);
			if (section) {
				setDocumentationActiveLink(root, links, section);
			} else if (useFirstSection) {
				setDocumentationActiveLink(root, links, sections[0]);
			}
		}

		activateHash(window.location.hash, true);
		window.addEventListener('hashchange', function () {
			activateHash(window.location.hash, false);
		});
		links.forEach(function (link) {
			link.addEventListener('click', function () {
				activateHash(link.getAttribute('href') || '', false);
			});
		});
		initDocumentationSectionObserver(root, links, sections);
		initDocumentationPrintDetails(root);
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
		const section = getDocumentationSectionForHash(root, hash);
		if (!section) {
			return null;
		}

		section.open = true;

		return section;
	}

	function getDocumentationSectionForHash(root, hash) {
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

		const documentRoot = root.ownerDocument || root;
		const target = documentRoot.getElementById(id);
		if (!target || typeof target.closest !== 'function') {
			return null;
		}

		const section = target.closest('.ran-booster-documentation__section');
		if (
			!section ||
			(typeof root.contains === 'function' && !root.contains(section))
		) {
			return null;
		}

		return section;
	}

	function setDocumentationActiveLink(root, links, section) {
		links.forEach(function (link) {
			const isCurrent =
				getDocumentationSectionForHash(
					root,
					link.getAttribute('href') || ''
				) === section;
			if (isCurrent) {
				link.setAttribute('aria-current', 'location');
			} else {
				link.removeAttribute('aria-current');
			}
		});
	}

	function initDocumentationSectionObserver(root, links, sections) {
		if (typeof window.IntersectionObserver !== 'function') {
			return;
		}

		const intersecting = new Map();
		const observer = new window.IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					intersecting.set(entry.target, entry);
				});
				const activeEntries = sections
					.map(function (section, index) {
						return {
							entry: intersecting.get(section),
							index,
							section,
						};
					})
					.filter(function (candidate) {
						return candidate.entry?.isIntersecting;
					})
					.sort(function (first, second) {
						const firstDistance = Math.abs(
							first.entry.boundingClientRect.top - 70
						);
						const secondDistance = Math.abs(
							second.entry.boundingClientRect.top - 70
						);
						return (
							firstDistance - secondDistance ||
							first.index - second.index
						);
					});

				if (activeEntries.length) {
					setDocumentationActiveLink(
						root,
						links,
						activeEntries[0].section
					);
				}
			},
			{ rootMargin: '-70px 0px -65% 0px', threshold: 0 }
		);

		sections.forEach(function (section) {
			observer.observe(section);
		});
	}

	function initDocumentationPrintDetails(root) {
		const details = Array.from(root.querySelectorAll('details'));
		if (!details.length) {
			return;
		}

		let openStates = null;
		window.addEventListener('beforeprint', function () {
			if (openStates) {
				return;
			}

			openStates = new Map(
				details.map(function (detail) {
					return [detail, detail.open];
				})
			);
			details.forEach(function (detail) {
				detail.open = true;
			});
		});
		window.addEventListener('afterprint', function () {
			if (!openStates) {
				return;
			}

			openStates.forEach(function (wasOpen, detail) {
				detail.open = wasOpen;
			});
			openStates = null;
		});
	}
})();
