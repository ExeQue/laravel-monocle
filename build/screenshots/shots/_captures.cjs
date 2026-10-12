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
    // A Lighthouse operation with a variable, a directive and a fragment, whose second field throws.
    graphql: {
        key: 'graphql',
        query: 'uri=graphql',
        seed: async (page, { base }) => {
            await page.goto(`${base}/`, { waitUntil: 'networkidle0' });
            await page.evaluate(async () => {
                await fetch('/graphql', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        query: 'query Feed($name: String, $loud: Boolean = true) { hello(name: $name) @include(if: $loud) ...Extra } fragment Extra on Query { boom }',
                        variables: { name: 'Ada' },
                    }),
                });
            });
        },
    },
    // An account opened and paid into through an aggregate root, then rebuilt from its events.
    eventSourcing: {
        key: 'event-sourcing',
        query: 'uri=event-sourcing',
        seed: (page, { base }) => page.goto(`${base}/demo/event-sourcing`, { waitUntil: 'networkidle0' }),
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
