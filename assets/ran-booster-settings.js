(() => {
	let form;
	let notice;
	let initial;
	let submitting = false;
	const fingerprint = () =>
		JSON.stringify(
			[...new FormData(form)].filter(([name]) =>
				name.startsWith('ran_booster[preferences][')
			)
		);
	const changed = () => Boolean(form && fingerprint() !== initial);
	const bind = (preserveInitial = false) => {
		form = document.querySelector('[data-ran-booster-general-form]');
		notice = document.querySelector('[data-ran-booster-unsaved]');
		if (!form || !notice) {
			return;
		}
		if (!preserveInitial || initial === undefined) {
			initial = fingerprint();
		}
		submitting = false;
		const update = () => {
			notice.hidden = !changed();
		};
		form.addEventListener('input', update);
		form.addEventListener('change', update);
		form.addEventListener('submit', () => {
			submitting = true;
		});
		update();
	};
	bind();
	document.body.addEventListener('htmx:afterSwap', (event) => {
		if (event.detail.target?.id !== 'ran-booster-general-region') {
			return;
		}
		const region = document.querySelector('#ran-booster-general-region');
		const saved = region?.hasAttribute('data-ran-booster-general-saved');
		bind(!saved);
		if (!saved) {
			region
				?.querySelector(
					'#ran-booster-general-error[role="alert"]:not([hidden])'
				)
				?.focus();
		}
	});
	document.body.addEventListener('htmx:afterRequest', (event) => {
		if (event.detail.elt === form) {
			submitting = false;
		}
	});
	window.addEventListener('beforeunload', (event) => {
		if (!submitting && changed()) {
			event.preventDefault();
			event.returnValue = '';
		}
	});
})();
