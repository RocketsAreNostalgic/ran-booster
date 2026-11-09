// Read-only measurement of the shared page frame (see "Page frame geometry" in
// docs/admin-composition-contract.md). Private runner helper, not CI.
export async function measurePageFrame(tab) {
	return tab.playwright.evaluate(() => {
		const rect = (element) => element?.getBoundingClientRect();
		const left = (element) =>
			element ? Math.round(rect(element).left) : null;
		const wrap = document.querySelector('.ran-booster-admin');
		const shell = document.querySelector('.ran-admin-shell');
		const title = wrap?.querySelector(
			'.ran-booster-page-heading__title, h1, h2'
		);
		if (!wrap || !shell || !title) {
			return null;
		}
		const description = wrap.querySelector(
			'.ran-booster-page-heading__description'
		);
		const notices = [
			...wrap.querySelectorAll(
				':scope > .notice, :scope > [data-ran-booster-ui-notice]'
			),
		]
			.filter((notice) => notice.offsetParent)
			.reduce((sum, notice) => sum + rect(notice).height, 0);
		const frames = [
			...wrap.querySelectorAll(
				'.ran-booster-card__surface, .ran-booster-settings-section, .ran-booster-panel'
			),
		].filter(
			(element) =>
				element.offsetParent &&
				rect(element).top >= rect(title).bottom - 1
		);
		const first = frames[0];
		const after = description
			? rect(description).bottom
			: rect(title).bottom;
		const cards = [
			...wrap.querySelectorAll('.ran-booster-card__surface'),
		].filter(
			(card) =>
				card.offsetParent &&
				!card.parentElement.closest('.ran-booster-card__surface')
		);
		return {
			shellToTitle: Math.round(
				rect(title).top - rect(shell).bottom - notices
			),
			titleLeft: left(title),
			descriptionLeft: left(description),
			firstFrameLeft: left(first),
			tabsLeft: left(
				wrap.querySelector('.nav-tab-wrapper, [role="tablist"]')
			),
			headerToFirstFrame: first
				? Math.round(rect(first).top - after)
				: null,
			cardGaps: cards
				.slice(1)
				.map((card, index) =>
					Math.round(rect(card).top - rect(cards[index]).bottom)
				),
			cardHeadingInsets: cards.map((card) => {
				const heading = card.querySelector('h2, h3, h4');
				return heading
					? Math.round(rect(heading).top - rect(card).top)
					: null;
			}),
		};
	});
}

// Page-level checks that hold on every menu page.
export async function assertPageFrame(tab) {
	const frame = await measurePageFrame(tab);
	if (!frame) {
		throw new Error('The page has no Booster wrap, shell or title.');
	}
	const problems = [];
	if (frame.shellToTitle !== 24) {
		problems.push(`Shell to title is ${frame.shellToTitle}px, not 24px.`);
	}
	for (const key of ['descriptionLeft', 'firstFrameLeft', 'tabsLeft']) {
		if (frame[key] !== null && frame[key] !== frame.titleLeft) {
			problems.push(
				`${key} is ${frame[key]}px but the title is at ${frame.titleLeft}px.`
			);
		}
	}
	if (frame.cardGaps.some((gap) => gap !== 24)) {
		problems.push(
			`Card gaps are ${frame.cardGaps.join(', ')}px, not 24px.`
		);
	}
	if (
		frame.cardHeadingInsets.some((inset) => inset !== null && inset !== 17)
	) {
		problems.push(
			`Card heading insets are ${frame.cardHeadingInsets.join(', ')}px, not 17px.`
		);
	}
	if (problems.length) {
		throw new Error(problems.join(' '));
	}
	return frame;
}
