(function () {
	'use strict';

	if (window.ranBoosterExtensionCardInitialized) {
		return;
	}
	window.ranBoosterExtensionCardInitialized = true;

	const selector = '[data-ran-booster-card-preview="true"]';
	const roots = new Map();
	const parts = {
		name: '.plugin-card-top .name.column-name',
		description: '.plugin-card-top .desc.column-description',
		metadata: '.plugin-card-bottom .ran-booster-extension-card__metadata',
		compatibility: '.plugin-card-bottom .column-compatibility',
		actions: '.plugin-card-top .action-links',
	};

	function restore(card) {
		const entry = roots.get(card);
		if (!entry) {
			return;
		}
		entry.nodes.forEach(({ node, marker }) => {
			marker.replaceWith(node);
		});
		card.classList.remove('ran-booster-extension-card--wordpress');
		entry.root.unmount();
		entry.host.remove();
		roots.delete(card);
	}

	function mount(card) {
		const css = document.getElementById('wp-components-css');
		const element = window.wp && window.wp.element;
		const components = window.wp && window.wp.components;
		let hasStyles = false;
		try {
			hasStyles =
				Boolean(css?.sheet?.cssRules.length) &&
				!css.disabled &&
				!css.sheet.disabled;
		} catch {
			// Failed or unreadable styles keep the native server-rendered card.
		}
		if (
			roots.has(card) ||
			!hasStyles ||
			!element ||
			!element.createRoot ||
			!element.createElement ||
			!element.flushSync ||
			!components ||
			!components.Card ||
			!components.CardHeader ||
			!components.CardBody ||
			!components.CardFooter
		) {
			return;
		}
		const nodes = Object.entries(parts).map(([name, query]) => ({
			name,
			node: card.querySelector(query),
		}));
		if (nodes.some((part) => !part.node)) {
			return;
		}

		const host = document.createElement('div');
		host.className = 'ran-booster-extension-card__mount';
		card.appendChild(host);
		let root;
		try {
			const h = element.createElement;
			const slot = (name) =>
				h('div', { 'data-ran-booster-card-slot': name });
			root = element.createRoot(host);
			element.flushSync(() => {
				root.render(
					h(
						components.Card,
						null,
						h(components.CardHeader, null, slot('name')),
						h(
							components.CardBody,
							null,
							slot('description'),
							slot('metadata'),
							slot('compatibility')
						),
						h(components.CardFooter, null, slot('actions'))
					)
				);
			});
			nodes.forEach((part) => {
				part.slot = host.querySelector(
					`[data-ran-booster-card-slot="${part.name}"]`
				);
				if (!part.slot) {
					throw new Error('Missing extension card slot');
				}
			});
			// React owns the shell; these empty slots retain their native PHP nodes.
			nodes.forEach((part) => {
				part.marker = document.createComment('extension card slot');
				part.node.before(part.marker);
				part.slot.appendChild(part.node);
			});
			roots.set(card, { root, host, nodes });
			card.classList.add('ran-booster-extension-card--wordpress');
		} catch {
			nodes.forEach(({ node, marker }) => {
				if (marker) {
					marker.replaceWith(node);
				}
			});
			if (root) {
				root.unmount();
			}
			host.remove();
		}
	}

	function initialize(scope) {
		if (scope.matches && scope.matches(selector)) {
			mount(scope);
		}
		if (scope.querySelectorAll) {
			scope.querySelectorAll(selector).forEach(mount);
		}
	}

	document.addEventListener('htmx:beforeCleanupElement', (event) => {
		const target = event.detail.elt;
		roots.forEach((entry, card) => {
			if (target === card || target.contains(card)) {
				restore(card);
			}
		});
	});
	document.addEventListener('htmx:beforeHistorySave', () => {
		roots.forEach((entry, card) => restore(card));
		queueMicrotask(() => initialize(document));
	});
	document.addEventListener('htmx:load', (event) =>
		initialize(event.detail.elt || event.target)
	);
	document.addEventListener('htmx:historyRestore', () =>
		initialize(document)
	);
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', () =>
			initialize(document)
		);
	} else {
		initialize(document);
	}
})();
