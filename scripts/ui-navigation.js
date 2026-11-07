// Create the two consumer navigation shapes with the supplied public Link.
export function createNavigationLinks(h, Link, items, provider) {
	return items.map((item, index) => {
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
					...linkClasses,
					'ran-booster-navigation__link',
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
			item.label
		);
		return provider ? h('li', { key }, link) : link;
	});
}
