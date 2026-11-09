(function () {
	'use strict';

	// Present an existing server-rendered node inside the public WordPress UI
	// Dialog. The node is moved, not cloned, and returns to its original place
	// when the dialog closes, so the page markup survives every open.
	// ranBoosterOpenDialog returns a handle with close() and teardown(), or
	// null when the overlay is unavailable; callers then keep their native
	// behaviour. Pointer dismissal stays enabled by default for read-only
	// content; a form dialog opts out with disablePointerDismissal.

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

		return { dialog, element };
	}

	function openDialog(trigger, content, options = {}) {
		const available = components();
		const doc = content?.ownerDocument;

		if (
			!available ||
			!doc?.body ||
			!content.parentNode ||
			typeof options.title !== 'string' ||
			options.title === ''
		) {
			return null;
		}

		active?.teardown();

		const { dialog } = available;
		const { createElement, createRoot, flushSync } = available.element;
		const listeners = new AbortController();
		const marker = doc.createComment('ran-booster-dialog');
		const host = doc.createElement('div');
		const portal = doc.createElement('div');
		let open = true;
		let finished = false;

		content.parentNode.insertBefore(marker, content);
		portal.className = 'ran-booster-ui-dialog-portal';
		doc.body.append(host, portal);
		const root = createRoot(host);

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
								portal: createElement(dialog.Portal, {
									container: portal,
								}),
								initialFocus:
									options.initialFocus ?? closeButton,
								finalFocus,
							},
							createElement(
								dialog.Header,
								null,
								createElement(
									dialog.Title,
									null,
									options.title
								),
								createElement(
									dialog.CloseIconButton,
									options.closeLabel
										? { label: options.closeLabel }
										: null
								)
							),
							createElement(
								dialog.Content,
								null,
								options.description
									? createElement(
											dialog.Description,
											null,
											options.description
										)
									: null,
								// React owns this container; the caller owns the moved node.
								createElement('div', {
									className: 'ran-booster-ui-dialog__body',
									ref(node) {
										if (
											node &&
											content.parentNode !== node
										) {
											node.appendChild(content);
										}
									},
								})
							)
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
				if (marker.parentNode) {
					marker.replaceWith(content);
				} else {
					content.remove();
				}
				host.remove();
				portal.remove();
				if (active === handle) {
					active = null;
				}
			},
		};

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
