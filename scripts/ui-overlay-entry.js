import './ui-runtime-entry.js';
import { Dialog, Input, Tabs } from '@wordpress/ui';

Object.assign(window.ranBoosterUI, {
	Dialog: {
		Root: Dialog.Root,
		Popup: Dialog.Popup,
		Header: Dialog.Header,
		Title: Dialog.Title,
		Description: Dialog.Description,
		Content: Dialog.Content,
		Footer: Dialog.Footer,
		CloseIconButton: Dialog.CloseIconButton,
		Portal: Dialog.Portal,
	},
	Input,
	Tabs: {
		Root: Tabs.Root,
		List: Tabs.List,
		Tab: Tabs.Tab,
		Panel: Tabs.Panel,
	},
});
