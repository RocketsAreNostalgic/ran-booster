/* global DOMParser, htmx */
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
		initActivityHistoryAppend();
	});

	function initActivityHistoryAppend() {
		const root = document.querySelector('[data-ran-booster-activity]');
		const list = root?.querySelector('[data-ran-booster-activity-list]');
		const loadOlder = root?.querySelector(
			'[data-ran-booster-activity-load-older]'
		);
		if (
			!root ||
			!list ||
			!loadOlder ||
			!root.dataset.ranBoosterActivityFilter ||
			typeof htmx === 'undefined' ||
			typeof htmx.ajax !== 'function'
		) {
			return;
		}

		const progress = root.querySelector(
			'[data-ran-booster-activity-progress]'
		);
		const error = root.querySelector('[data-ran-booster-activity-error]');
		const announcement = root.querySelector(
			'[data-ran-booster-activity-announcement]'
		);
		const { __ } = wp.i18n;
		let inFlight = false;

		function selection() {
			return {
				filter: root.dataset.ranBoosterActivityFilter || '',
				package:
					root.querySelector('[name="activity_package"]')?.value ||
					'',
				provider:
					root.querySelector('[name="activity_provider"]')?.value ||
					'',
			};
		}

		function showFailure() {
			if (error) {
				error.hidden = false;
				error.focus?.({ preventScroll: true });
			}
		}

		function setBusy(isBusy) {
			root.setAttribute('aria-busy', isBusy ? 'true' : 'false');
			loadOlder.setAttribute('aria-busy', isBusy ? 'true' : 'false');
			loadOlder.setAttribute('aria-disabled', isBusy ? 'true' : 'false');
			if (progress) {
				progress.hidden = !isBusy;
			}
			if (isBusy && error) {
				error.hidden = true;
			}
		}

		function activityUrl(value, filter) {
			try {
				const url = new URL(value, window.location.href);
				const cursor = (url.searchParams.get('before') || '').match(
					/^v1\|([a-f0-9]{64})\|\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\|(activity|deployment)\|[1-9][0-9]*$/
				);
				return url.origin === window.location.origin &&
					url.pathname === window.location.pathname &&
					url.searchParams.get('page') === 'ran-booster' &&
					url.searchParams.get('tab') === 'troubleshooting' &&
					url.searchParams.get('panel') === 'activity' &&
					cursor?.[1] === filter
					? url
					: null;
			} catch {
				return null;
			}
		}

		loadOlder.dataset.ranBoosterActivityAppendBound = 'true';
		loadOlder.textContent = __('Load older activity', 'ran-booster');
		loadOlder.addEventListener('click', function (event) {
			if (
				event.button === 1 ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey
			) {
				return;
			}
			if (inFlight) {
				event.preventDefault();
				return;
			}
			const request = activityUrl(
				loadOlder.getAttribute('href'),
				root.dataset.ranBoosterActivityFilter
			);
			if (!request) {
				return;
			}
			event.preventDefault();
			inFlight = true;
			setBusy(true);
			const snapshot = selection();
			const existing = new Set(
				Array.from(
					list.querySelectorAll('[data-ran-booster-activity-row]')
				).map(function (row) {
					return row.getAttribute('data-ran-booster-activity-row');
				})
			);
			htmx.ajax('GET', request.href, {
				source: loadOlder,
				target: root,
				handler(_element, responseInfo) {
					try {
						const response = responseInfo.xhr;
						const responseUrl = activityUrl(
							response.responseURL,
							snapshot.filter
						);
						const responseDocument =
							new DOMParser().parseFromString(
								response.responseText,
								'text/html'
							);
						const responseRoots = responseDocument.querySelectorAll(
							'[data-ran-booster-activity]'
						);
						const responseRoot =
							1 === responseRoots.length
								? responseRoots[0]
								: null;
						const responseList = responseRoot?.querySelector(
							'[data-ran-booster-activity-list]'
						);
						const current = selection();
						const incomingRows = responseList
							? Array.from(
									responseList.querySelectorAll(
										'[data-ran-booster-activity-row]'
									)
								)
							: [];
						const incoming = new Set();
						const next = responseRoot?.querySelector(
							'[data-ran-booster-activity-load-older]'
						);
						const nextUrl = next
							? activityUrl(
									next.getAttribute('href'),
									snapshot.filter
								)
							: null;
						const exhausted = Boolean(
							responseRoot?.querySelector(
								'[data-ran-booster-activity-exhausted]'
							)
						);
						const validRows = exhausted
							? !responseList && !next
							: !exhausted &&
								0 < incomingRows.length &&
								25 >= incomingRows.length &&
								incomingRows.every(function (row) {
									const id = row.getAttribute(
										'data-ran-booster-activity-row'
									);
									if (
										!/^[1-9][0-9]*$/.test(id || '') ||
										existing.has(id)
									) {
										return false;
									}
									incoming.add(id);
									return (
										incoming.size ===
										incomingRows.indexOf(row) + 1
									);
								});
						if (
							200 !== response.status ||
							!root.isConnected ||
							!responseUrl ||
							request.href !== responseUrl.href ||
							!responseRoot ||
							'0' !==
								responseRoot.dataset
									.ranBoosterActivityUnavailable ||
							'1' !==
								responseRoot.dataset
									.ranBoosterActivityHasCursor ||
							responseRoot.dataset.ranBoosterActivityFilter !==
								snapshot.filter ||
							responseRoot.dataset.ranBoosterActivityProvider !==
								snapshot.provider ||
							responseRoot.dataset.ranBoosterActivityPackage !==
								snapshot.package ||
							current.filter !== snapshot.filter ||
							current.provider !== snapshot.provider ||
							current.package !== snapshot.package ||
							!validRows ||
							(next &&
								(!nextUrl ||
									nextUrl.searchParams.get('before') ===
										request.searchParams.get('before') ||
									nextUrl.searchParams.get(
										'activity_provider'
									) !==
										request.searchParams.get(
											'activity_provider'
										) ||
									nextUrl.searchParams.get(
										'activity_package'
									) !==
										request.searchParams.get(
											'activity_package'
										)))
						) {
							showFailure();
							return;
						}
						const hadFocus =
							loadOlder.ownerDocument?.activeElement ===
							loadOlder;
						const responseRunner = responseRoot.querySelector(
							'[data-ran-booster-activity-runner]'
						);
						if (
							!root.querySelector(
								'[data-ran-booster-activity-runner]'
							) &&
							responseRunner
						) {
							root.querySelector(
								'.ran-booster-attempt-table'
							)?.before(responseRunner);
						}
						if (!exhausted) {
							list.append(...incomingRows);
						}
						incomingRows.forEach(function (row) {
							htmx.process?.(row);
						});
						if (next) {
							loadOlder.setAttribute(
								'href',
								next.getAttribute('href')
							);
						} else {
							loadOlder.parentElement?.remove();
						}
						if (announcement) {
							announcement.textContent = exhausted
								? __(
										'No older activity is available.',
										'ran-booster'
									)
								: __('Older activity loaded.', 'ran-booster');
							announcement.hidden = false;
							if (hadFocus) {
								announcement.focus?.({ preventScroll: true });
							}
						}
					} catch {
						showFailure();
					}
				},
			})
				.catch(showFailure)
				.finally(function () {
					inFlight = false;
					setBusy(false);
				});
		});
	}

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
			const requestElement =
				event.detail?.requestConfig?.elt || event.detail?.elt;

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

		let activeRequest = null;

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

			if (isBusy) {
				region.setAttribute('aria-busy', 'true');
			} else {
				region.removeAttribute('aria-busy');
			}
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

			if (event.detail?.xhr !== activeRequest) {
				return;
			}
			activeRequest = null;
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
				activeRequest = event.detail.xhr;
				setBusy(true);
			}
		});

		document.addEventListener('htmx:afterSwap', function (event) {
			if (!handlesTaskRegion(event)) {
				return;
			}

			if (event.detail?.xhr !== activeRequest) {
				return;
			}
			activeRequest = null;
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
					tab.classList.toggle('current', isCurrent);
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

		document.addEventListener('htmx:afterRequest', function (event) {
			if (
				handlesTaskRegion(event) &&
				event.detail?.xhr === activeRequest
			) {
				setBusy(false);
			}
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

			count.textContent = wp.i18n.sprintf(
				/* translators: %d is the number of repositories shown. */
				wp.i18n._nx(
					'%d repository shown',
					'%d repositories shown',
					visible,
					'Provider table repository count',
					'ran-booster'
				),
				visible
			);
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
					return [
						detail,
						{
							open: detail.open,
							name: detail.getAttribute('name'),
						},
					];
				})
			);
			details.forEach(function (detail) {
				detail.removeAttribute('name');
				detail.open = true;
			});
		});
		window.addEventListener('afterprint', function () {
			if (!openStates) {
				return;
			}

			openStates.forEach(function (state, detail) {
				detail.open = state.open;
			});
			openStates.forEach(function (state, detail) {
				if (state.name !== null) {
					detail.setAttribute('name', state.name);
				}
			});
			openStates = null;
		});
	}
})();
