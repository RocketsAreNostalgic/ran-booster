import assert from 'node:assert/strict';
import test from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { Link } from '@wordpress/ui';
import { createNavigationLinks } from '../../scripts/ui-navigation.js';

const items = [
	{
		label: 'Status',
		href: '/status?one=1&two=2',
		current: true,
		className: 'provider-link',
		title: 'Provider status',
		target: '_self',
		rel: 'help',
		download: 'report',
		task: 'status',
		requestUrl: '/fragment?panel=status',
		controls: 'provider-panel',
	},
	{ label: 'Repositories', href: '/repositories', task: 'repositories' },
];

test('provider links forward only the fixed navigation props and keep native destinations', () => {
	const links = createNavigationLinks(React.createElement, Link, items, true);
	assert.equal(links.length, 3);
	assert.equal(links[2].type, 'li');
	assert.equal(links[2].props['aria-hidden'], true);
	assert.equal(links[2].props.className, 'ran-section-navigation__indicator');
	assert.equal(links[0].type, 'li');
	const first = links[0].props.children;
	assert.equal(first.type, Link);
	assert.deepEqual(first.props, {
		variant: 'unstyled',
		className:
			'provider-link current ran-booster-navigation__link ran-section-navigation__link',
		href: '/status?one=1&two=2',
		'aria-current': 'page',
		title: 'Provider status',
		target: '_self',
		rel: 'help',
		download: 'report',
		'hx-get': '/fragment?panel=status',
		'data-ran-booster-provider-task': 'status',
		'aria-controls': 'provider-panel',
		children: 'Status',
	});
	assert.equal(links[1].props.children.props['aria-current'], undefined);
	assert.equal(first.props.onClick, undefined);
	assert.equal(first.props.role, undefined);
	assert.equal(first.props.tabIndex, undefined);
});

test('provider current class follows committed selection and can be cleared', () => {
	const source = [
		{
			label: 'One',
			href: '/one',
			task: 'one',
			className: 'provider current',
		},
		{ label: 'Two', href: '/two', task: 'two', className: 'provider' },
	];
	for (const selected of ['one', 'two', null]) {
		const rendered = createNavigationLinks(
			React.createElement,
			Link,
			source.map((item) => ({
				...item,
				current: item.task === selected,
			})),
			true
		);
		for (const [index, item] of source.entries()) {
			const props = rendered[index].props.children.props;
			const classes = props.className.split(/\s+/);
			assert.equal(classes.includes('current'), item.task === selected);
			assert.equal(
				props['aria-current'],
				item.task === selected ? 'page' : undefined
			);
			assert.equal(classes.includes('provider'), true);
		}
	}
});

test('direct links keep independent instance state and stable distinct keys', () => {
	const first = createNavigationLinks(
		React.createElement,
		Link,
		items,
		false
	);
	const second = createNavigationLinks(
		React.createElement,
		Link,
		items.map((item) => ({ ...item, current: false, href: '/same' })),
		false
	);
	assert.equal(first[0].type, Link);
	assert.equal(first[0].props['aria-current'], 'page');
	assert.equal(second[0].props['aria-current'], undefined);
	assert.equal(second[1].props['aria-current'], undefined);
	assert.notEqual(second[0].key, second[1].key);
	const header = createNavigationLinks(
		React.createElement,
		Link,
		[
			{
				label: 'Header',
				href: '/header',
				className: 'current',
				current: false,
			},
		],
		false
	);
	assert.equal(
		header[0].props.className,
		'current ran-booster-navigation__link ran-section-navigation__link'
	);
	assert.deepEqual(
		createNavigationLinks(React.createElement, Link, [], false),
		[]
	);
});

test('actual public Link server output contains real anchors and current state', () => {
	const html = renderToStaticMarkup(
		React.createElement(
			'nav',
			null,
			createNavigationLinks(React.createElement, Link, items, false)
		)
	);
	assert.match(html, /<a\b/);
	assert.match(html, /href="\/status\?one=1&amp;two=2"/);
	assert.match(html, /aria-current="page"/);
	assert.match(html, /hx-get="\/fragment\?panel=status"/);
	assert.doesNotMatch(html, /role="tab"/);
});

test('public Link retains target, rel, download and label text with its new-tab context', () => {
	for (const target of ['_self', '_blank']) {
		const html = renderToStaticMarkup(
			React.createElement(
				'nav',
				null,
				createNavigationLinks(
					React.createElement,
					Link,
					[
						{
							label: 'Report <draft>',
							href: '/report?one=1&two=2',
							target,
							rel: 'noopener noreferrer',
							download: 'report.txt',
						},
					],
					false
				)
			)
		);
		assert.match(html, /href="\/report\?one=1&amp;two=2"/);
		assert.ok(html.includes(`target="${target}"`));
		assert.match(html, /rel="noopener noreferrer"/);
		assert.match(html, /download="report.txt"/);
		assert.match(html, /Report &lt;draft&gt;/);
		if (target === '_blank') {
			assert.match(html, /aria-label="\(opens in a new tab\)"/);
		} else {
			assert.doesNotMatch(html, /aria-label=/);
		}
	}
});
