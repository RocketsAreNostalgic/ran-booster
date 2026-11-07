/**
 * Optional geometry and focus behavior for the shared native-link navigation.
 * The consumer owns destination/current state and calls update after it commits.
 *
 * @param {HTMLElement} host Opted-in native-link track.
 */
export function createSectionNavigation(host) {
	if (!host?.matches('.ran-section-navigation')) {
		return null;
	}
	const view = host.ownerDocument.defaultView;
	let indicator = host.querySelector('.ran-section-navigation__indicator');
	const owned = !indicator;
	if (owned) {
		indicator = host.ownerDocument.createElement(
			host.matches('ul, ol') ? 'li' : 'span'
		);
		indicator.className = 'ran-section-navigation__indicator';
		indicator.setAttribute('aria-hidden', 'true');
		host.appendChild(indicator);
	}
	const links = () =>
		Array.from(host.querySelectorAll(':scope > a, :scope > li > a'));
	let frame;
	let disposed = false;
	const position = (left, top, width, animate) => {
		host.classList.toggle('ran-section-navigation--moving', animate);
		host.style.setProperty('--ran-section-indicator-x', `${left}px`);
		host.style.setProperty('--ran-section-indicator-top', `${top}px`);
		host.style.setProperty('--ran-section-indicator-width', `${width}px`);
		host.classList.add('ran-section-navigation--ready');
	};
	const measure = (animate = false) => {
		if (disposed || !host.isConnected) {
			return;
		}
		const current = links().find(
			(link) => link.getAttribute('aria-current') === 'page'
		);
		if (
			!current ||
			!host.querySelector('.ran-section-navigation__indicator')
		) {
			host.classList.remove('ran-section-navigation--ready');
			return;
		}
		const track = host.getBoundingClientRect();
		const link = current.getBoundingClientRect();
		const size =
			parseFloat(
				view
					.getComputedStyle(host)
					.getPropertyValue('--ran-section-indicator-size')
			) || 2;
		position(
			link.left - track.left + host.scrollLeft - host.clientLeft,
			link.bottom - track.top + host.scrollTop - host.clientTop - size,
			link.width,
			animate
		);
	};
	const cancel = () => {
		if (frame !== undefined) {
			view.cancelAnimationFrame(frame);
			frame = undefined;
		}
	};
	const update = ({ animate = false, afterPaint = false } = {}) => {
		cancel();
		if (disposed) {
			return;
		}
		if (afterPaint) {
			// Retain the previous endpoint through a consumer's layout move.
			frame = view.requestAnimationFrame(() => {
				frame = view.requestAnimationFrame(() => {
					frame = undefined;
					measure(animate);
				});
			});
		} else {
			measure(animate);
		}
	};
	const focus = (event) => {
		if (links().includes(event.target)) {
			event.target.scrollIntoView({
				block: 'nearest',
				inline: 'nearest',
			});
		}
	};
	const observer = view.ResizeObserver
		? new view.ResizeObserver(() => {
				if (frame === undefined) {
					measure();
				}
			})
		: null;
	observer?.observe(host);
	links().forEach((link) => observer?.observe(link));
	host.addEventListener('focusin', focus);
	update();
	return {
		update,
		snapshot() {
			const { left, top, width } = indicator.getBoundingClientRect();
			return { left, top, width };
		},
		transfer(previous) {
			if (disposed || !previous) {
				return;
			}
			const track = host.getBoundingClientRect();
			position(
				previous.left - track.left + host.scrollLeft - host.clientLeft,
				previous.top - track.top + host.scrollTop - host.clientTop,
				previous.width,
				false
			);
			update({ animate: true, afterPaint: true });
		},
		destroy() {
			disposed = true;
			cancel();
			observer?.disconnect();
			host.removeEventListener('focusin', focus);
			host.classList.remove(
				'ran-section-navigation--ready',
				'ran-section-navigation--moving'
			);
			for (const property of ['x', 'top', 'width']) {
				host.style.removeProperty(
					`--ran-section-indicator-${property}`
				);
			}
			if (owned) {
				indicator.remove();
			}
		},
	};
}
