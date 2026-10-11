// A dump of a model holding other models, folded out: the post with its author and comments, coloured, classes linked.
const { expandRow } = require('./_helpers.cjs');

module.exports = {
    name: '47-lens-dumps-nested',
    // The same capture as the dumps lens picture, so both come from one run of the demo.
    capture: {
        key: 'GET /demo/dump',
        query: 'uri=demo%2Fdump',
        seed: (page, { base }) => page.goto(`${base}/demo/dump`, { waitUntil: 'networkidle0' }),
    },
    tab: 'dumps',
    prepare: async (page) => {
        await expandRow(page, 'Models\\Post');

        // The dump box scrolls on its own; bring the loaded relations (the nesting) into view.
        await page.evaluate(() => {
            const box = [...document.querySelectorAll('main pre')].find((pre) => pre.offsetParent !== null && pre.textContent.includes('relations'));

            if (! box) {
                return;
            }

            const walker = document.createTreeWalker(box, NodeFilter.SHOW_TEXT);

            while (walker.nextNode()) {
                if (walker.currentNode.textContent.includes('relations')) {
                    box.scrollTop = walker.currentNode.parentElement.offsetTop - box.offsetTop - 8;

                    return;
                }
            }
        });
    },
};
