// The capture list searched with the palette's syntax: requests with Livewire, or anything that failed with a 404.
const captures = require('./_captures.cjs');

module.exports = {
    name: '25-list-search',
    capture: captures.mixed,
    tab: 'overview',
    prepare: async (page, { sleep }) => {
        await page.type('#monocle-search', '$live | 404');
        // The field waits a moment before it searches.
        await sleep(500);
    },
};
