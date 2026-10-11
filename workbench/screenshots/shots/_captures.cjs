// The captures the shots look at, and how to make each one.
// Not a shot itself: files starting with _ are helpers.
module.exports = {
    showcase: {
        key: 'showcase',
        query: 'uri=showcase',
        seed: (page, { base }) => page.goto(`${base}/demo/showcase`),
    },
    countdown: {
        key: 'countdown',
        query: 'uri=countdown',
        seed: async (page, { base }) => {
            await page.goto(`${base}/demo/queue/countdown`);
            await page.goto(`${base}/demo/queue/work`);
        },
    },
    livewire: {
        key: 'livewire',
        query: 'uri=livewire',
        seed: async (page, { base, sleep }) => {
            await page.goto(`${base}/demo/livewire`, { waitUntil: 'networkidle0' });
            await page.click('[wire\\:click]');
            await sleep(800);
        },
    },
    // A list with a bit of everything, for the command palette and the list search: the showcase
    // (whose jobs then run), a dump, a Livewire page and a page that does not exist.
    mixed: {
        key: 'mixed',
        query: 'uri=showcase',
        seed: async (page, { base }) => {
            for (const path of ['/demo/missing-page', '/demo/livewire', '/demo/dump', '/demo/showcase', '/demo/queue/work']) {
                await page.goto(`${base}${path}`);
            }
        },
    },
};
