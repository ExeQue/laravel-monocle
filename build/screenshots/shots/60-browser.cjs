// Browser tracing: the Monocle button on a traced page, its list, and the trace it builds.
const captures = require('./_captures.cjs');

// A small screen, so the button is not lost in an empty page.
const viewport = { width: 800, height: 450 };

// Clicks each Livewire button once, so the page has a few recorded calls.
async function makeCalls(page, { sleep }) {
    for (const button of await page.$$('[wire\\:click]')) {
        await button.click();
        await sleep(700);
    }
}

module.exports = [
    {
        name: '60-browser-button',
        capture: captures.livewire,
        url: ({ base }) => `${base}/demo/livewire`,
        viewport,
        prepare: makeCalls,
    },
    {
        name: '61-browser-button-list',
        capture: captures.livewire,
        url: ({ base }) => `${base}/demo/livewire`,
        viewport,
        prepare: async (page, kit) => {
            await makeCalls(page, kit);
            await page.evaluate(() => document.querySelector('monocle-button').shadowRoot.querySelector('.dot').click());
        },
    },
    {
        name: '62-browser-trace',
        capture: captures.livewire,
        tab: 'trace',
    },
];
