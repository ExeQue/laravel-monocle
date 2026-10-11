// Monocle's own time per lens, in the popover on the Monocle total.
const captures = require('./_captures.cjs');

module.exports = {
    name: 'monocle-time',
    capture: captures.showcase,
    tab: 'overview',
    prepare: async (page) => {
        // A real click, so the popover opens next to the number.
        const total = await page.evaluateHandle(() => [...document.querySelectorAll('button')].find((b) => b.offsetParent && b.getAttribute('@click')?.includes('totalPopover')));
        await total.asElement()?.click();
    },
};
