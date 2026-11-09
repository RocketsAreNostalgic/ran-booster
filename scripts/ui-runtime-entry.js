import { Button, Card, CollapsibleCard, Notice, Switch } from '@wordpress/ui';
import { createRepositoryNavigationBridge } from './ui-navigation.js';
import { createSectionNavigation } from '../assets/ran-section-navigation.js';

window.ranBoosterUI = {
	version: '0.26.0',
	createSectionNavigation,
	createRepositoryNavigationBridge,
	CardRoot: Card.Root,
	CollapsibleCard: {
		Root: CollapsibleCard.Root,
		Header: CollapsibleCard.Header,
		Content: CollapsibleCard.Content,
	},
	Button,
	Switch,
	NoticeRoot: Notice.Root,
	NoticeDescription: Notice.Description,
};
