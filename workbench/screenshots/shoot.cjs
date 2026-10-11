// Takes the dashboard screenshots for the docs, at 2x in light and dark.
// Each file in shots/ describes one picture; this script only runs them.
//
//   composer screenshots              every shot
//   composer screenshots -- trace ai  only these
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

const base = process.env.MONOCLE_URL ?? 'http://localhost:8000';
const out = path.resolve(__dirname, '../../docs/images');
const themes = ['light', 'dark'];
const defaultViewport = { width: 1440, height: 900, deviceScaleFactor: 2 };

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function loadShots(only) {
    const dir = path.join(__dirname, 'shots');

    return fs.readdirSync(dir)
        .filter((file) => file.endsWith('.cjs') && ! file.startsWith('_'))
        .sort()
        .map((file) => require(path.join(dir, file)))
        .filter((shot) => only.length === 0 || only.includes(shot.name));
}

// Helpers a shot gets: where the app is, and small waits.
const kit = { base, sleep };

async function seedCaptures(page, shots) {
    const ids = {};

    // A demo several shots use is run once.
    for (const shot of shots) {
        if (ids[shot.capture.key] !== undefined) {
            continue;
        }

        await shot.capture.seed(page, kit);
        await sleep(500);

        const url = `${base}/_monocle/api/captures?limit=1&${shot.capture.query}`;
        const list = await page.evaluate(async (u) => (await fetch(u)).json(), url);
        ids[shot.capture.key] = list.rows[0]?.id ?? null;
    }

    return ids;
}

async function take(browser, shot, id, theme) {
    const page = await browser.newPage();
    await page.setViewport({ ...defaultViewport, ...(shot.viewport ?? {}) });
    await page.evaluateOnNewDocument((value) => localStorage.setItem('monocle.theme', value), theme);
    await page.goto(`${base}/_monocle#${id}/${shot.tab ?? 'overview'}`, { waitUntil: 'networkidle0' });
    await sleep(600);

    if (shot.prepare) {
        await shot.prepare(page, kit);
        await sleep(400);
    }

    const file = `${out}/${shot.name}-${theme}.png`;
    await page.screenshot({ path: file });
    await page.close();
    console.log(path.relative(process.cwd(), file));
}

(async () => {
    const shots = loadShots(process.argv.slice(2));
    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const seeder = await browser.newPage();
    await seeder.goto(`${base}/_monocle`);

    const ids = await seedCaptures(seeder, shots);

    for (const shot of shots) {
        const id = ids[shot.capture.key];

        if (id === null) {
            console.warn(`skipped ${shot.name}: no capture for ${shot.capture.key}`);
            continue;
        }

        for (const theme of themes) {
            await take(browser, shot, id, theme);
        }
    }

    await browser.close();
})();
