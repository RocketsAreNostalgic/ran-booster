// Run in a synthetic browser page with the production credential form, assets,
// and real HTMX 2.0.10. saveUrl returns the Core HX-Redirect success contract;
// failureUrl returns a 422. This does not qualify installed WordPress behavior.
export async function verifyCredentialRedirects(saveUrl, failureUrl) {
	const form = document.querySelector('#ran-booster-credential-editor form');
	const evidence = [];
	const location = htmx.location;
	let redirected = null;
	const warned = () => {
		const event = new Event('beforeunload', { cancelable: true });
		window.dispatchEvent(event);
		return event.defaultPrevented;
	};
	const check = (condition, message) => {
		if (!condition) {
			throw new Error(message);
		}
		evidence.push(message);
	};
	const submit = async (url) => {
		form.setAttribute('hx-post', url);
		htmx.process(form);
		await new Promise((resolve) => {
			form.addEventListener('htmx:afterRequest', resolve, { once: true });
			form.requestSubmit();
		});
	};
	// htmx's supported location seam observes its real response-handler branch
	// without unloading the fixture between negative controls.
	htmx.location = {
		set href(url) {
			redirected = { url, warned: warned() };
		},
	};
	try {
		check(htmx.version === '2.0.10', 'locked htmx 2.0.10');
		form.noValidate = true;
		form.dispatchEvent(new Event('input', { bubbles: true }));
		await submit(failureUrl);
		check(redirected === null && warned(), '422 retains dirty protection');
		await htmx.ajax('POST', saveUrl, {
			source: document.querySelector('#open'),
			target: '#ran-booster-provider-profile-region',
		});
		check(
			redirected?.warned === true,
			'unrelated response retains protection'
		);
		redirected = null;
		const veto = (event) => event.preventDefault();
		document.addEventListener('htmx:beforeOnLoad', veto, { once: true });
		await submit(saveUrl);
		check(
			redirected === null && warned(),
			'late response veto retains protection'
		);
		let swaps = 0;
		const swap = () => swaps++;
		document.addEventListener('htmx:beforeSwap', swap);
		await submit(saveUrl);
		document.removeEventListener('htmx:beforeSwap', swap);
		check(
			redirected?.warned === false && swaps === 0,
			'confirmed HX-Redirect bypasses warning without a swap'
		);
		await new Promise((resolve) => setTimeout(resolve, 0));
		check(
			warned(),
			'navigation grant expires when fixture intercepts redirect'
		);
		return evidence;
	} finally {
		htmx.location = location;
	}
}
