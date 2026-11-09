(function () {
	'use strict';

	// More Details links point at a hidden, server-rendered details section,
	// which :target reveals in place without JavaScript. With the shared
	// Dialog available, the same section opens in a Dialog instead and goes
	// back to the page when it closes.
	document.addEventListener('click', function (event) {
		const link = event.target.closest?.(
			'a[data-ran-booster-extension-details]'
		);

		if (
			!link ||
			event.defaultPrevented ||
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey ||
			typeof window.ranBoosterOpenDialog !== 'function'
		) {
			return;
		}

		const id =
			'ran-booster-extension-details-' +
			link.getAttribute('data-ran-booster-extension-details');
		const section = document.getElementById(id);
		// Look the content up by ID: during a closing transition it is still
		// inside the previous Dialog, which the helper restores before reopening.
		const content = document.getElementById(id + '-content');
		const handle =
			section &&
			content &&
			window.ranBoosterOpenDialog(link, content, {
				title: link.getAttribute('data-title') || '',
				description:
					section.querySelector(
						'.ran-booster-extension-details-host__description'
					)?.textContent || '',
				size: 'large',
			});

		if (handle) {
			event.preventDefault();
		}
	});

	// Install and Activate submit once and show their pending label.
	document.addEventListener('submit', function (event) {
		const form = event.target;

		if (!form.matches?.('.ran-booster-extension-action')) {
			return;
		}
		if (form.hasAttribute('aria-busy')) {
			event.preventDefault();
			return;
		}

		const button = form.querySelector('button[type="submit"]');
		const pending = button?.getAttribute('data-pending');

		form.setAttribute('aria-busy', 'true');
		form.setAttribute('role', 'status');
		form.setAttribute('aria-live', 'polite');
		if (button) {
			button.disabled = true;
		}
		if (button && pending) {
			button.setAttribute('aria-label', pending);
			button.textContent = pending;
		}
	});
})();
