import { createSectionNavigation } from '../assets/ran-section-navigation.js';

// Native section-navigation bridge for the repository detail tabs, which are
// replaced by HTMX swaps; the indicator moves from the old track to the new one.
export function createRepositoryNavigationBridge(roots) {
	const selector =
		'.ran-booster-repository-detail__tabs > .ran-section-navigation';
	const mount = (host) => {
		if (!host || !host.isConnected || roots.has(host)) {
			return;
		}
		roots.set(host, {
			navigation: true,
			sectionNavigation: createSectionNavigation(host),
		});
	};
	return {
		selector,
		mount,
		snapshot(region) {
			return roots
				.get(region?.querySelector(selector))
				?.sectionNavigation?.snapshot();
		},
		transfer(region, previous) {
			const host = region?.querySelector(selector);
			mount(host);
			roots.get(host)?.sectionNavigation?.transfer(previous);
		},
	};
}
