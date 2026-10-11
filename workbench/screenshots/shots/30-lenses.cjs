// One picture per built-in lens: its tab on a capture from its own demo.
// These are the pictures the per-lens pages in docs/lenses/ use.
const { sleep } = { sleep: (ms) => new Promise((resolve) => setTimeout(resolve, ms)) };

// A GET demo, or a POST demo sent with the page's CSRF token.
function demo(path, { method = 'GET', query = `uri=${encodeURIComponent(path.split('?')[0].replace(/^\//, ''))}`, times = 1 } = {}) {
    return {
        key: `${method} ${path}`,
        query,
        seed: async (page, { base }) => {
            for (let run = 0; run < times; run++) {
                if (method === 'GET') {
                    await page.goto(`${base}${path}`, { waitUntil: 'networkidle0' });
                    continue;
                }

                await page.goto(`${base}/`, { waitUntil: 'networkidle0' });
                await page.evaluate(async (url) => {
                    const token = document.querySelector('meta[name="csrf-token"]').content;
                    await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'text/html' }, redirect: 'manual' });
                }, path);
            }
        },
    };
}

const lenses = [
    ['request', '/demo/showcase', 'request'],
    ['session', '/demo/session', 'session'],
    ['middleware', '/demo/middleware', 'middleware'],
    ['database', '/demo/database/n-plus-one', 'queries'],
    ['eloquent', '/demo/eloquent', 'models'],
    ['cache', '/demo/cache', 'cache'],
    ['events', '/demo/events', 'events'],
    ['log', '/demo/log', 'log'],
    ['exceptions', '/demo/exceptions/reported', 'exceptions'],
    ['views', '/demo/views', 'views'],
    ['mail', '/demo/mail', 'mail'],
    ['http-client', '/demo/http', 'http'],
    ['queue', '/demo/queue/chain', 'jobs'],
    ['commands', '/demo/artisan', 'commands'],
    ['processes', '/demo/process', 'processes'],
    ['schedule', '/demo/alarms/failed-scheduled-task', 'scheduled_tasks', { query: 'type=command&uri=schedule' }],
    ['filesystem', '/demo/filesystem', 'filesystem'],
    ['dumps', '/demo/dump', 'dumps'],
    ['auth', '/demo/auth/failed', 'auth', { method: 'POST' }],
    ['authorization', '/demo/authorization/denied', 'authorization'],
    ['validation', '/demo/validation/form-request', 'validation', { method: 'POST' }],
    ['spans', '/demo/spans', 'timeline'],
    ['inertia', '/demo/inertia', 'inertia'],
    ['pennant', '/demo/pennant', 'features'],
    ['response-cache', '/demo/response-cache', 'response_cache', { times: 2 }],
    ['media-library', '/demo/media', 'media'],
    ['ai', '/demo/alarms/ai-tool-failed', 'ai'],
    ['prism', '/demo/prism', 'prism'],
];

module.exports = lenses.map(([name, path, tab, options = {}], index) => ({
    name: `${30 + index}-lens-${name}`,
    capture: demo(path, options),
    tab,
}));
