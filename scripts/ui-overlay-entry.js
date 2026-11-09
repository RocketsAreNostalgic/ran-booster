import './ui-runtime-entry.js';
import { Dialog, Input } from '@wordpress/ui';

Object.assign(window.ranBoosterUI, {
	Dialog: {
		Root: Dialog.Root,
		Popup: Dialog.Popup,
		Header: Dialog.Header,
		Title: Dialog.Title,
		Description: Dialog.Description,
		Content: Dialog.Content,
		CloseIconButton: Dialog.CloseIconButton,
		Portal: Dialog.Portal,
	},
	Input,
});
