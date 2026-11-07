import { Badge, Button, Card, Link, Notice, Switch } from '@wordpress/ui';
import { createElement } from 'react';
import { createNavigationLinks } from './ui-navigation.js';

window.ranBoosterUI = {
	version: '0.26.0',
	CardRoot: Card.Root,
	Badge,
	Button,
	Link,
	Switch,
	NavigationLinks: ({ items, provider }) =>
		createNavigationLinks(createElement, Link, items, provider),
	NoticeRoot: Notice.Root,
	NoticeDescription: Notice.Description,
};
