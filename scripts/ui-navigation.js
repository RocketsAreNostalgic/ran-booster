import { createSectionNavigation } from '../assets/ran-section-navigation.js';

// Create the two consumer navigation shapes with the supplied public Link.
export function createNavigationLinks(h, Link, items, provider) {
	const links = items.map((item, index) => {
		const key = `${item.task || 'link'}:${index}`;
		const linkClasses = provider
			? (item.className || '')
					.split(/\s+/)
					.filter((name) => name && name !== 'current')
			: [item.className].filter(Boolean);
		if (provider && item.current) {
			linkClasses.push('current');
		}
		const link = h(
			Link,
			{
				key,
				variant: 'unstyled',
				className: [
					...new Set([
						...linkClasses,
						'ran-booster-navigation__link',
						'ran-section-navigation__link',
					]),
				].join(' '),
				href: item.href,
				'aria-current': item.current ? 'page' : undefined,
				title: item.title,
				target: item.target,
				rel: item.rel,
				download: item.download,
				'hx-get': item.requestUrl,
				'data-ran-booster-provider-task': item.task,
				'aria-controls': item.controls,
			},
			!provider && item.current
				? [
						item.label,
						h('span', {
							key: 'active-indicator',
							className: 'ran-section-navigation__indicator',
							'aria-hidden': true,
						}),
					]
				: item.label
		);
		return provider ? h('li', { key }, link) : link;
	});
	if (provider && links.length) {
		links.push(
			h(provider ? 'li' : 'span', {
				key: 'active-indicator',
				className: 'ran-section-navigation__indicator',
				'aria-hidden': true,
			})
		);
	}
	return links;
}

const navigationAttributes = [
	'class',
	'href',
	'title',
	'rel',
	'target',
	'download',
	'aria-current',
	'aria-controls',
	'hx-get',
	'data-ran-booster-provider-task',
];

function navigationItems(links) {
	return links.map((link) => ({
		label: link.textContent,
		href: link.getAttribute('href'),
		current: link.getAttribute('aria-current') === 'page',
		className: link.getAttribute('class') || '',
		title: link.getAttribute('title') ?? undefined,
		target: link.getAttribute('target') ?? undefined,
		rel: link.getAttribute('rel') ?? undefined,
		download: link.getAttribute('download') ?? undefined,
		task: link.getAttribute('data-ran-booster-provider-task') ?? undefined,
		requestUrl: link.getAttribute('hx-get') ?? undefined,
		controls: link.getAttribute('aria-controls') ?? undefined,
	}));
}

function hasNavigationText(node) {
	return Array.from(node.childNodes).some(
		(child) => child.nodeType === 3 && child.textContent.trim()
	);
}

function hasHeaderIndicator(link) {
	const [decoration] = link.children;
	return (
		link.childElementCount === 1 &&
		link.getAttribute('aria-current') === 'page' &&
		decoration.tagName === 'SPAN' &&
		decoration.getAttribute('class') ===
			'ran-section-navigation__indicator' &&
		decoration.getAttribute('aria-hidden') === 'true' &&
		decoration.attributes.length === 2 &&
		decoration.childNodes.length === 0
	);
}

// Core owns these fixed DOM/attribute contracts; Shell owns only geometry.
export const navigationContract = {
	links: navigationLinks,
	items: navigationItems,
	allows: allowsNavigation,
};

export function createRepositoryNavigationBridge(roots) {
	const selector =
		'.ran-booster-repository-detail__tabs > .ran-section-navigation';
	const mount = (host) => {
		if (!host || !host.isConnected || roots.has(host)) {
			return;
		}
		roots.set(host, {
			navigation: true,
			nativeNavigation: true,
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

function allowsNavigation(host, provider, links, htmx) {
	if (
		!links.length ||
		hasNavigationText(host) ||
		host.children.length !== links.length ||
		links.some(
			(link) =>
				!link.getAttribute('href') ||
				(!provider && link.hasAttribute('hx-get')) ||
				(link.childElementCount &&
					(provider || !hasHeaderIndicator(link))) ||
				![null, 'page'].includes(link.getAttribute('aria-current')) ||
				/^_blank$/i.test(link.getAttribute('target') || '') ||
				Array.from(link.attributes).some(
					(attribute) =>
						!navigationAttributes.includes(attribute.name)
				)
		) ||
		(provider &&
			links.some(
				(link) =>
					link.parentElement.tagName !== 'LI' ||
					hasNavigationText(link.parentElement) ||
					link.parentElement.children.length !== 1 ||
					link.parentElement.attributes.length
			))
	) {
		return false;
	}
	if (provider) {
		const region = host.closest('#ran-booster-provider-tasks');
		const nav = host.parentElement;
		const panel = region?.querySelector('#ran-booster-provider-task-panel');
		const overrides = [
			'hx-target',
			'hx-select',
			'hx-swap',
			'hx-push-url',
			'hx-history',
			'hx-sync',
		];
		if (
			!htmx?.process ||
			htmx.config?.disableInheritance ||
			[
				region,
				nav,
				host,
				...links.map((link) => link.parentElement),
			].some(
				(node) =>
					node?.hasAttribute('hx-disinherit') ||
					node?.hasAttribute('data-hx-disinherit')
			) ||
			nav?.getAttribute('hx-boost') !== 'true' ||
			nav.hasAttribute('data-hx-boost') ||
			nav.parentElement !== region ||
			overrides.some(
				(attribute) =>
					region?.hasAttribute(`data-${attribute}`) ||
					[
						nav,
						host,
						...links.map((link) => link.parentElement),
					].some(
						(node) =>
							node.hasAttribute(attribute) ||
							node.hasAttribute(`data-${attribute}`)
					)
			) ||
			!region ||
			region.getAttribute('hx-target') !==
				'#ran-booster-provider-task-panel' ||
			region.getAttribute('hx-select') !==
				'#ran-booster-provider-task-panel' ||
			region.getAttribute('hx-swap') !==
				'outerHTML transition:false show:none' ||
			region.getAttribute('hx-push-url') !== 'true' ||
			region.getAttribute('hx-history') !== 'false' ||
			region.getAttribute('hx-sync') !== 'this:replace' ||
			!panel ||
			host.contains(panel) ||
			host.ownerDocument.getElementById(
				'ran-booster-provider-task-panel'
			) !== panel ||
			links.some(
				(link) =>
					!link.getAttribute('hx-get') ||
					!link.getAttribute('data-ran-booster-provider-task')
			)
		) {
			return false;
		}
	}
	return true;
}

function navigationLinks(host, provider) {
	return Array.from(
		host.querySelectorAll(provider ? ':scope > li > a' : ':scope > a')
	);
}

export function renderNavigationLinks(host, entry, element, NavigationLinks) {
	element.flushSync(() =>
		entry.root.render(
			element.createElement(
				entry.boundary,
				null,
				element.createElement(NavigationLinks, {
					items: navigationItems(entry.links),
					provider: entry.provider,
				})
			)
		)
	);
	const visible = navigationLinks(host, entry.provider);
	if (
		visible.length !== entry.links.length ||
		visible.some(
			(link, index) =>
				link.tagName !== 'A' ||
				link.getAttribute('href') !==
					entry.links[index].getAttribute('href')
		)
	) {
		throw new Error('Navigation did not render its destinations');
	}
}
