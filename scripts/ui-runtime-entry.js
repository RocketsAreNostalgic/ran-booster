import {
	Button,
	Card,
	CollapsibleCard,
	Link,
	Notice,
	Switch,
} from '@wordpress/ui';
import { createElement } from 'react';
import {
	createNavigationLinks,
	renderNavigationLinks,
	navigationContract,
	createRepositoryNavigationBridge,
} from './ui-navigation.js';
import { createSectionNavigation } from '../assets/ran-section-navigation.js';

window.ranBoosterUI = {
	version: '0.26.0',
	createSectionNavigation,
	renderNavigationLinks,
	navigationContract,
	createRepositoryNavigationBridge,
	CardRoot: Card.Root,
	CollapsibleCard: {
		Root: CollapsibleCard.Root,
		Header: CollapsibleCard.Header,
		Content: CollapsibleCard.Content,
	},
	Button,
	Link,
	Switch,
	NavigationLinks: ({ items, provider }) =>
		createNavigationLinks(createElement, Link, items, provider),
	NoticeRoot: Notice.Root,
	NoticeDescription: Notice.Description,
};
