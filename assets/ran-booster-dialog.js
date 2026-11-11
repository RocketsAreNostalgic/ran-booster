(function () {
	'use strict';

	// Present existing server-rendered nodes inside the public WordPress UI
	// Dialog. Nodes are moved, not cloned, and return to their original place
	// when the dialog closes, so the page markup survives every open.
	// ranBoosterOpenDialog returns a handle with close() and teardown(), or
	// null when the overlay is unavailable; callers then keep their native
	// behaviour. Pointer dismissal stays enabled by default for read-only
	// content; a form dialog opts out with disablePointerDismissal.
	//
	// The body is either one content node or a list of sections
	// ({ id, label, content }) shown as public WordPress UI Tabs; a single
	// section is still a tab list with one selected tab. Optional banner,
	// aside and footer nodes fill a banner header behind the title, a column
	// beside the body and a Dialog footer.

	const parts = [
		'Root',
		'Popup',
		'Header',
		'Title',
		'Description',
		'Content',
		'CloseIconButton',
		'Portal',
	];
	const tabParts = ['Root', 'List', 'Tab', 'Panel'];
	let active = null;

	function components() {
		const ui = window.ranBoosterUI;
		const dialog = ui?.Dialog;
		const element = window.wp?.element;

		if (
			ui?.version !== '0.26.0' ||
			!dialog ||
			!parts.every((part) => dialog[part]) ||
			typeof element?.createElement !== 'function' ||
			typeof element.createRoot !== 'function' ||
			typeof element.flushSync !== 'function'
		) {
			return null;
		}

		return { dialog, element, tabs: ui.Tabs };
	}

	function validSections(sections, tabs) {
		const ids = new Set();

		return (
			sections.length > 0 &&
			tabParts.every((part) => tabs?.[part]) &&
			sections.every(function (section) {
				const fresh =
					typeof section?.id === 'string' &&
					section.id !== '' &&
					!ids.has(section.id);
				ids.add(section?.id);
				return (
					fresh &&
					typeof section.label === 'string' &&
					section.label !== '' &&
					!!section.content
				);
			})
		);
	}

	function openDialog(trigger, content, options = {}) {
		const available = components();
		const sections = Array.isArray(options.sections)
			? options.sections
			: null;
		const { banner, aside, footer } = options;
		const moved = [
			content,
			...(sections || []).map((section) => section?.content),
			banner,
			aside,
			footer,
		].filter(Boolean);
		const doc = moved[0]?.ownerDocument;

		if (
			!available ||
			!doc?.body ||
			typeof options.title !== 'string' ||
			options.title === '' ||
			(sections
				? content || !validSections(sections, available.tabs)
				: !content) ||
			(footer && !available.dialog.Footer) ||
			!moved.every((node) => node.parentNode)
		) {
			return null;
		}

		active?.teardown();

		const { dialog, tabs } = available;
		const { createElement, createRoot, flushSync } = available.element;
		const listeners = new AbortController();
		const markers = moved.map(function (node) {
			const marker = doc.createComment('ran-booster-dialog');
			node.parentNode.insertBefore(marker, node);
			return { node, marker };
		});
		const host = doc.createElement('div');
		const portal = doc.createElement('div');
		let open = true;
		let finished = false;

		portal.className = 'ran-booster-ui-dialog-portal';
		doc.body.append(host, portal);
		const root = createRoot(host);
		// Everything else on the page is inert while the dialog is open,
		// including skip links and other siblings of the admin wrapper that
		// the library does not cover. Only nodes made inert here are restored.
		const inerted = Array.from(doc.body.children).filter(function (node) {
			if (
				node === host ||
				node === portal ||
				node.hasAttribute('inert')
			) {
				return false;
			}
			node.setAttribute('inert', '');
			return true;
		});

		function tabStops() {
			return Array.from(
				portal.querySelectorAll(
					'a[href], button, input, select, textarea, summary, [tabindex]'
				)
			).filter(
				(node) =>
					!node.disabled &&
					node.getAttribute('tabindex') !== '-1' &&
					node.getAttribute('aria-hidden') !== 'true' &&
					!node.hasAttribute('data-base-ui-focus-guard') &&
					!node.closest('[hidden], [inert]')
			);
		}

		// Tab wraps inside the dialog: the last control goes to the first,
		// Shift+Tab from the first goes to the last, and focus that has
		// reached a focus guard or left the portal comes back in.
		function wrapTab(event) {
			if (
				event.key !== 'Tab' ||
				event.ctrlKey ||
				event.altKey ||
				event.metaKey ||
				!open
			) {
				return;
			}

			const stops = tabStops();
			const current = doc.activeElement;
			const index = stops.indexOf(current);
			const stray =
				index < 0 &&
				(!portal.contains(current) ||
					current.hasAttribute('data-base-ui-focus-guard'));
			const first = stops[0];
			const last = stops[stops.length - 1];

			if (!first) {
				event.preventDefault();
				return;
			}
			if (event.shiftKey ? stray || current === first : false) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && (stray || current === last)) {
				event.preventDefault();
				first.focus();
			}
		}

		function closeButton() {
			return (
				portal.querySelector('[data-wp-ui-dialog-close-icon]') || true
			);
		}

		function finalFocus() {
			const focused = doc.activeElement;

			if (
				open ||
				!trigger?.isConnected ||
				(focused !== doc.body &&
					focused !== trigger &&
					!portal.contains(focused))
			) {
				return false;
			}

			return trigger;
		}

		// React owns each slot; the caller owns the node moved into it.
		function slot(className, node, key) {
			return createElement('div', {
				key,
				className,
				ref(element) {
					if (element && node.parentNode !== element) {
						element.appendChild(node);
					}
				},
			});
		}

		function body() {
			const main = sections
				? createElement(
						'div',
						{ className: 'ran-booster-ui-dialog__panels' },
						sections.map((section) =>
							createElement(
								tabs.Panel,
								{
									key: section.id,
									value: section.id,
									keepMounted: true,
									className: 'ran-booster-ui-dialog__panel',
								},
								slot(
									'ran-booster-ui-dialog__section',
									section.content
								)
							)
						)
					)
				: slot('ran-booster-ui-dialog__body', content);
			const contentElement = createElement(
				dialog.Content,
				null,
				options.description
					? createElement(
							dialog.Description,
							{ className: 'ran-booster-ui-dialog__description' },
							options.description
						)
					: null,
				aside
					? createElement(
							'div',
							{ className: 'ran-booster-ui-dialog__layout' },
							main,
							slot('ran-booster-ui-dialog__aside', aside)
						)
					: main
			);

			if (!sections) {
				return contentElement;
			}

			// The tab row stays above the scrolling content, so Tabs.Root
			// wraps both the list and the Content region holding the panels.
			return createElement(
				tabs.Root,
				{
					className: 'ran-booster-ui-dialog__tabs',
					defaultValue: sections[0].id,
				},
				createElement(
					tabs.List,
					{
						className: 'ran-booster-ui-dialog__tablist',
						activateOnFocus: true,
					},
					sections.map((section) =>
						createElement(
							tabs.Tab,
							{ key: section.id, value: section.id },
							section.label
						)
					)
				),
				contentElement
			);
		}

		function render() {
			flushSync(function () {
				root.render(
					createElement(
						dialog.Root,
						{
							open,
							disablePointerDismissal:
								options.disablePointerDismissal === true,
							onOpenChange(next) {
								if (!next) {
									handle.close();
								}
							},
							onOpenChangeComplete(next) {
								if (!next && !open) {
									handle.teardown();
								}
							},
						},
						createElement(
							dialog.Popup,
							{
								className: options.className,
								size: options.size || 'medium',
								'aria-modal': 'true',
								portal: createElement(dialog.Portal, {
									container: portal,
								}),
								initialFocus:
									options.initialFocus ?? closeButton,
								finalFocus,
							},
							createElement(
								dialog.Header,
								banner
									? {
											className:
												'ran-booster-ui-dialog__banner',
										}
									: null,
								banner
									? slot(
											'ran-booster-ui-dialog__banner-media',
											banner
										)
									: null,
								createElement(
									dialog.Title,
									banner
										? {
												className:
													'ran-booster-ui-dialog__banner-title',
											}
										: null,
									options.title
								),
								createElement(
									dialog.CloseIconButton,
									options.closeLabel
										? { label: options.closeLabel }
										: null
								)
							),
							body(),
							footer
								? createElement(
										dialog.Footer,
										null,
										slot(
											'ran-booster-ui-dialog__actions',
											footer
										)
									)
								: null
						)
					)
				);
			});
		}

		const handle = {
			close() {
				if (!open || finished) {
					return;
				}
				open = false;
				render();
			},
			teardown() {
				if (finished) {
					return;
				}
				finished = true;
				open = false;
				listeners.abort();
				flushSync(function () {
					root.unmount();
				});
				markers.forEach(function ({ node, marker }) {
					if (marker.parentNode) {
						marker.replaceWith(node);
					} else {
						node.remove();
					}
				});
				host.remove();
				portal.remove();
				inerted.forEach((node) => node.removeAttribute('inert'));
				if (active === handle) {
					active = null;
				}
			},
		};

		doc.addEventListener('keydown', wrapTab, {
			capture: true,
			signal: listeners.signal,
		});
		window.addEventListener('pagehide', handle.teardown, {
			signal: listeners.signal,
		});
		doc.addEventListener(
			'htmx:beforeSwap',
			function (event) {
				if (
					event.detail?.target?.id === 'wpbody-content' &&
					event.detail.shouldSwap
				) {
					handle.teardown();
				}
			},
			{ signal: listeners.signal }
		);

		active = handle;
		try {
			render();
		} catch {
			handle.teardown();
			return null;
		}

		return handle;
	}

	window.ranBoosterOpenDialog = openDialog;
})();
