(function () {
	'use strict';

	// More Details links point at a hidden, server-rendered details section,
	// which :target reveals in place without JavaScript. With the shared
	// Dialog available, the section's banner, tab sections and sidebar open
	// in a Dialog instead, with the card's own action in its footer, and all
	// go back to the page when it closes.
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

		const extension = link.getAttribute(
			'data-ran-booster-extension-details'
		);
		const id = 'ran-booster-extension-details-' + extension;
		const section = document.getElementById(id);
		// Look every region up by ID: during a closing transition they are
		// still inside the previous Dialog, which the helper restores first.
		const sections = (
			section?.getAttribute('data-ran-booster-dialog-sections') || ''
		)
			.split(' ')
			.filter(Boolean)
			.map(function (key) {
				const content = document.getElementById(id + '-section-' + key);

				return {
					id: key,
					label:
						content?.getAttribute(
							'data-ran-booster-dialog-section-label'
						) || '',
					content,
				};
			});
		const handle =
			section &&
			window.ranBoosterOpenDialog(link, null, {
				title: link.getAttribute('data-title') || '',
				description:
					section.querySelector(
						'.ran-booster-extension-details-host__description'
					)?.textContent || '',
				size: 'large',
				sections,
				banner: document.getElementById(id + '-banner'),
				aside: document.getElementById(id + '-aside'),
				footer: document.getElementById(
					'ran-booster-extension-action-' + extension
				),
			});

		if (handle) {
			event.preventDefault();
		}
	});

	// Install and Activate submit once, show their pending label and
	// announce it through the shared helper.
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
		if (button) {
			button.disabled = true;
		}
		if (button && pending) {
			button.setAttribute('aria-label', pending);
			button.textContent = pending;
		}
		if (pending && typeof window.ranBoosterAnnounce === 'function') {
			window.ranBoosterAnnounce(pending, 'polite');
		}
	});
})();
