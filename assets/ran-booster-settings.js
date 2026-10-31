(() => {
	const form = document.querySelector('[data-ran-booster-general-form]');
	const notice = document.querySelector('[data-ran-booster-unsaved]');
	if (!form || !notice) {
		return;
	}
	const fingerprint = () => JSON.stringify([...new FormData(form)]);
	const initial = fingerprint();
	let submitting = false;
	const changed = () => fingerprint() !== initial;
	form.addEventListener('input', () => {
		notice.hidden = !changed();
	});
	form.addEventListener('change', () => {
		notice.hidden = !changed();
	});
	form.addEventListener('submit', () => {
		submitting = true;
	});
	window.addEventListener('beforeunload', (event) => {
		if (!submitting && changed()) {
			event.preventDefault();
			event.returnValue = '';
		}
	});
})();
