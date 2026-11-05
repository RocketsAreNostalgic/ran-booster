// Read-only measurements for the rendered Overview Activity card.
export async function measureOverviewActivity(tab) {
	return tab.playwright.evaluate(() => {
		const number = (value) => Number.parseFloat(value) || 0;
		const color = (value) => {
			const parts = value.match(/[\d.]+/g)?.map(Number) || [];
			return [parts[0] || 0, parts[1] || 0, parts[2] || 0, parts[3] ?? 1];
		};
		const blend = (front, back) => {
			const alpha = front[3] + back[3] * (1 - front[3]);
			return [
				...front
					.slice(0, 3)
					.map((channel, index) =>
						alpha
							? (channel * front[3] +
									back[index] * back[3] * (1 - front[3])) /
								alpha
							: 0
					),
				alpha,
			];
		};
		const background = (element) => {
			let result = [0, 0, 0, 0];
			for (
				let current = element;
				current;
				current = current.parentElement
			) {
				result = blend(
					result,
					color(getComputedStyle(current).backgroundColor)
				);
			}
			return blend(result, [255, 255, 255, 1]);
		};
		const luminance = (rgb) => {
			const channels = rgb.slice(0, 3).map((value) => {
				const channel = value / 255;
				return channel <= 0.04045
					? channel / 12.92
					: ((channel + 0.055) / 1.055) ** 2.4;
			});
			return (
				channels[0] * 0.2126 +
				channels[1] * 0.7152 +
				channels[2] * 0.0722
			);
		};
		const card = document.querySelector(
			'[aria-labelledby="ran-booster-overview-activity"]'
		);
		const list = card?.querySelector(
			'.ran-booster-overview-activity__list'
		);
		const rows = [
			...(list?.querySelectorAll('.ran-booster-overview-activity__row') ||
				[]),
		];
		return {
			cardPresent: Boolean(card),
			listPresent: Boolean(list),
			rows: rows.map((row, index) => {
				const style = getComputedStyle(row);
				const rect = row.getBoundingClientRect();
				const content = row.querySelector(':scope > div');
				const badge = row.querySelector(':scope > .ran-booster-badge');
				if (!content || !badge)
					throw new Error(
						`Activity row ${index + 1} is missing content or badge.`
					);
				const contentRect = content.getBoundingClientRect();
				const badgeRect = badge.getBoundingClientRect();
				const badgeStyle = getComputedStyle(badge);
				const range = document.createRange();
				range.selectNodeContents(badge);
				const lines = [...range.getClientRects()].filter(
					(item) => item.width && item.height
				).length;
				const bg = background(badge);
				const foreground = blend(color(badgeStyle.color), bg);
				const light = [luminance(foreground), luminance(bg)].sort(
					(a, b) => b - a
				);
				const previous = rows[index - 1]?.getBoundingClientRect();
				return {
					height: rect.height,
					marginTop: number(style.marginTop),
					marginBottom: number(style.marginBottom),
					paddingTop: number(style.paddingTop),
					paddingBottom: number(style.paddingBottom),
					borderTop: number(style.borderTopWidth),
					borderBottom: number(style.borderBottomWidth),
					contentInsetTop:
						Math.min(contentRect.top, badgeRect.top) -
						rect.top -
						number(style.borderTopWidth),
					contentInsetBottom:
						rect.bottom -
						number(style.borderBottomWidth) -
						Math.max(contentRect.bottom, badgeRect.bottom),
					gapFromPreviousDivider: previous
						? rect.top - previous.bottom
						: null,
					overlap:
						Math.min(contentRect.right, badgeRect.right) -
							Math.max(contentRect.left, badgeRect.left) >
							0.5 &&
						Math.min(contentRect.bottom, badgeRect.bottom) -
							Math.max(contentRect.top, badgeRect.top) >
							0.5,
					overflow:
						row.scrollWidth > row.clientWidth + 1 ||
						content.scrollWidth > content.clientWidth + 1 ||
						content.scrollHeight > content.clientHeight + 1 ||
						contentRect.left < rect.left - 1 ||
						contentRect.right > rect.right + 1 ||
						badgeRect.left < rect.left - 1 ||
						badgeRect.right > rect.right + 1,
					badge: {
						text: badge.textContent.trim(),
						tone:
							[...badge.classList].find((name) =>
								name.startsWith('ran-booster-badge--')
							) || '',
						height: badgeRect.height,
						width: badgeRect.width,
						lines,
						fontSize: number(badgeStyle.fontSize),
						lineHeight: badgeStyle.lineHeight,
						fontWeight: badgeStyle.fontWeight,
						paddingTop: number(badgeStyle.paddingTop),
						paddingBottom: number(badgeStyle.paddingBottom),
						paddingLeft: number(badgeStyle.paddingLeft),
						paddingRight: number(badgeStyle.paddingRight),
						contrast: (light[0] + 0.05) / (light[1] + 0.05),
						foreground: badgeStyle.color,
						background: bg,
						overflow:
							badge.scrollWidth > badge.clientWidth + 1 ||
							badge.scrollHeight > badge.clientHeight + 1,
					},
				};
			}),
		};
	});
}

export async function assertOverviewActivity(
	tab,
	{ expectedRows, compact = true } = {}
) {
	const result = await measureOverviewActivity(tab);
	const assert = (condition, message) => {
		if (!condition) throw new Error(message);
	};
	assert(result.cardPresent, 'Overview Activity card is missing.');
	if (expectedRows !== undefined)
		assert(
			result.rows.length === expectedRows,
			`Expected ${expectedRows} Activity rows, found ${result.rows.length}.`
		);
	result.rows.forEach((row, index) => {
		const label = `Activity row ${index + 1}`;
		assert(
			row.marginTop === 0 && row.marginBottom === 0,
			`${label} has nonzero margins.`
		);
		assert(
			Math.abs(row.contentInsetTop - row.contentInsetBottom) <= 0.5,
			`${label} has unbalanced content spacing.`
		);
		assert(
			row.gapFromPreviousDivider === null ||
				Math.abs(row.gapFromPreviousDivider) <= 0.5,
			`${label} has a gap from the previous divider.`
		);
		assert(!row.overlap, `${label} content overlaps its badge.`);
		assert(!row.overflow && !row.badge.overflow, `${label} overflows.`);
		assert(row.badge.fontSize >= 12, `${label} badge font is below 12px.`);
		assert(
			row.badge.contrast >= 4.5,
			`${label} badge contrast is below 4.5:1.`
		);
		if (compact && row.badge.lines <= 1)
			assert(
				row.badge.height <= 22,
				`${label} one-line badge exceeds 22px.`
			);
	});
	return result;
}
