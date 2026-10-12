// Takes the dashboard screenshots for the docs, in light and dark.
// Each file in shots/ describes one picture; this script only runs them.
//
//   build/screenshots/run.sh              every shot
//   build/screenshots/run.sh 17 lens-ai   only shots whose name starts with or contains these
//
// Captures are cleared before each demo runs, so every picture shows only
// what its own demo recorded; the pictures of one demo are taken in parallel.
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');
const { scrollToTabs } = require('./shots/_helpers.cjs');

const base = process.env.MONOCLE_URL ?? 'http://localhost:8000';
const out = path.resolve(__dirname, '../../docs/images');
const themes = ['light', 'dark'];
// A 1920x1080 (16:9) desktop screen.
const defaultViewport = { width: 1920, height: 1080, deviceScaleFactor: 1 };

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Helpers a shot gets: where the app is, and small waits.
const kit = { base, sleep };

function loadShots(only) {
    const dir = path.join(__dirname, 'shots');

    return fs.readdirSync(dir)
        .filter((file) => file.endsWith('.cjs') && ! file.startsWith('_'))
        .sort()
        // A shot file exports one shot, or a list of them.
        .flatMap((file) => [require(path.join(dir, file))].flat())
        // `17` or `http` picks every shot whose name starts with or contains it.
        .filter((shot) => only.length === 0 || only.some((pick) => shot.name.startsWith(pick) || shot.name.includes(pick)));
}

// Removes every capture the way the dashboard's Clear button does, which sends its CSRF token.
async function clearCaptures(page) {
    await page.goto(`${base}/_monocle`, { waitUntil: 'networkidle0' });
    await page.evaluate(async () => {
        await window.Alpine.$data(document.querySelector('[x-data^="monocle("]')).clearAll();
    });
}

// Runs a demo and returns the id of the capture it made.
async function seedCapture(page, capture) {
    await capture.seed(page, kit);
    await sleep(500);

    const url = `${base}/_monocle/api/captures?limit=1&${capture.query}`;
    const list = await page.evaluate(async (u) => (await fetch(u)).json(), url);

    return list.rows[0]?.id ?? null;
}

async function take(browser, shot, id, theme) {
    const page = await browser.newPage();
    await page.setViewport({ ...defaultViewport, ...(shot.viewport ?? {}) });
    await page.evaluateOnNewDocument((value) => localStorage.setItem('monocle.theme', value), theme);
    // Parts that follow the system theme (the Monocle button on traced pages) get it too.
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: theme }]);
    // A shot with a url looks at a page of the app; the rest at the capture in the dashboard.
    await page.goto(shot.url ? shot.url(kit) : `${base}/_monocle#${id}/${shot.tab ?? 'overview'}`, { waitUntil: 'networkidle0' });
    await sleep(600);

    // On a tab, scroll past the capture's header so the tab's content fills the picture.
    if (! shot.url && (shot.tab ?? 'overview') !== 'overview' && shot.scroll !== false) {
        await scrollToTabs(page);
        await sleep(200);
    }

    if (shot.prepare) {
        await shot.prepare(page, kit);
        await sleep(400);
    }

    // WebP keeps the docs sharp without bloating the repository.
    const file = `${out}/${shot.name}-${theme}.webp`;
    await page.screenshot({ path: file, type: 'webp', quality: 90 });
    await page.close();
    console.log(path.relative(process.cwd(), file));
}

// Runs the jobs `width` at a time.
async function pool(jobs, width) {
    const queue = [...jobs];
    const workers = Array.from({ length: width }, async () => {
        while (queue.length > 0) {
            await queue.shift()();
        }
    });

    await Promise.all(workers);
}

// Old pictures go first: all of them on a full run, else those of the chosen shots,
// so nothing stale is left behind when a shot is renamed or removed.
function removeOldPictures(shots, everything) {
    const names = new Set(shots.map((shot) => shot.name));

    for (const file of fs.readdirSync(out)) {
        if (! file.endsWith('.webp')) {
            continue;
        }

        const shot = file.replace(/-(light|dark)\.webp$/, '');

        if (everything || names.has(shot)) {
            fs.unlinkSync(path.join(out, file));
        }
    }
}

async function main() {
    const shots = loadShots(process.argv.slice(2));
    removeOldPictures(shots, process.argv.length <= 2);

    const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
    const seeder = await browser.newPage();

    // Shots that look at the same demo form a group. Per group: clear, run the
    // demo once, take every picture of it in parallel, then the next group.
    const groups = new Map();

    for (const shot of shots) {
        groups.set(shot.capture.key, [...(groups.get(shot.capture.key) ?? []), shot]);
    }

    for (const group of groups.values()) {
        await clearCaptures(seeder);

        const capture = group[0].capture;
        const id = await seedCapture(seeder, capture);

        if (id === null) {
            console.warn(`skipped ${group.map((shot) => shot.name).join(', ')}: no capture for ${capture.key}`);
            continue;
        }

        const jobs = group.flatMap((shot) => themes.map((theme) => () => take(browser, shot, id, theme)));
        await pool(jobs, Number(process.env.SHOTS_PARALLEL ?? 4));
    }

    await clearCaptures(seeder);
    await browser.close();
}

main();
