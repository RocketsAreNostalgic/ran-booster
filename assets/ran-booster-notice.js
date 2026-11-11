(function () {
	'use strict';

	// Changes the intent of a static Notice in place. The state class, core's
	// notice-* class, the intent attribute and the icon change together; the
	// icon is a clone from the one per-page template NoticeIcon prints, and
	// neutral has none. This only redraws: announcements stay with
	// ranBoosterAnnounce, and focus is left where it is.
	const intents = ['info', 'warning', 'success', 'error', 'neutral'];

	window.ranBoosterNotice = {
		// Builds a static Notice host the way the PHP hosts print it: the same
		// classes, intent attribute, description element and icon. The caller
		// fills `content`, places `host` and announces through
		// ranBoosterAnnounce; the notice has no live region of its own.
		create(intent, { className = '', scope = '' } = {}) {
			const doc = window.document;
			const host = doc.createElement('div');
			host.setAttribute(
				'class',
				'notice inline ran-wp-ui-notice ran-wp-ui-box-sizing' +
					(className ? ' ' + className : '')
			);
			host.setAttribute('data-ran-booster-ui-notice', '');
			if (scope) {
				host.setAttribute('data-ran-booster-ui-notice-scope', scope);
			}
			const content = doc.createElement('div');
			content.setAttribute(
				'class',
				'ran-wp-ui-text is-body-md ran-wp-ui-notice__description'
			);
			content.setAttribute('data-ran-booster-ui-notice-content', '');
			host.append(content);
			window.ranBoosterNotice.setIntent(host, intent);
			return { host, content };
		},
		setIntent(host, intent) {
			if (!host || !intents.includes(intent)) {
				return false;
			}
			intents.forEach((name) =>
				host.classList.remove('is-' + name, 'notice-' + name)
			);
			host.classList.add('is-' + intent);
			if (intent !== 'neutral') {
				host.classList.add('notice-' + intent);
			}
			host.setAttribute('data-ran-booster-ui-notice-intent', intent);
			host.querySelector(':scope > .ran-wp-ui-notice__icon')?.remove();
			const source = host.ownerDocument
				.getElementById('ran-booster-notice-icons')
				?.content.querySelector(
					'[data-ran-booster-notice-icon="' + intent + '"]'
				);
			if (source) {
				const icon = source.cloneNode(true);
				icon.removeAttribute('data-ran-booster-notice-icon');
				const description = host.querySelector(
					':scope > .ran-wp-ui-notice__description'
				);
				if (description) {
					description.after(icon);
				} else {
					host.append(icon);
				}
			}
			return true;
		},
	};
})();
