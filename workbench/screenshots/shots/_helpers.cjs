// Helpers shots share. Not a shot itself: files starting with _ are helpers.

// Scrolls the capture's pane (`main`, the page itself never scrolls) so `element` sits
// at the top of the picture, under the sticky tab bar. Instant, so the picture is not taken mid-scroll.
function scrollPaneTo(element) {
    const pane = document.querySelector('main');

    if (! element || ! pane) {
        return;
    }

    // The tab bar is sticky, so the element goes just below it rather than behind it.
    const tabs = element.closest('[role="tablist"]') ? 0 : document.querySelector('[role="tablist"]')?.offsetHeight ?? 0;
    const top = element.getBoundingClientRect().top - pane.getBoundingClientRect().top + pane.scrollTop - tabs - 12;
    pane.scrollTo({ top, behavior: 'instant' });
}

// Folds out the first row on the open tab whose text contains `text`, waits for its
// details (they are fetched when the row opens) and scrolls the row to the top.
async function expandRow(page, text) {
    const findRow = (needle) => [...document.querySelectorAll('main tbody tr, main li')]
        .find((row) => row.offsetParent !== null && row.textContent.includes(needle) && row.querySelector('[aria-expanded="false"]'));

    // A tab loads its rows after it opens.
    await page.waitForFunction(findRow, { timeout: 8000 }, text).catch(() => console.warn(`no row with "${text}"`));

    await page.evaluate((needle, find) => {
        const row = new Function(`return (${find})`)()(needle);
        row?.setAttribute('data-shot-row', '');
        row?.querySelector('[aria-expanded="false"]')?.click();
    }, text, findRow.toString());

    await page.waitForFunction(() => {
        const details = document.querySelector('[data-shot-row]')?.nextElementSibling;

        // Drawn, not just present: the row stays a pixel high until its content is in.
        return (details?.offsetHeight ?? 0) > 40;
    }, { timeout: 8000 }).catch(() => console.warn(`no details for "${text}"`));

    // The row folds out with an animation; scroll once the pane has stopped growing.
    await settle(page);
    await page.evaluate(`(${scrollPaneTo})(document.querySelector('[data-shot-row]'))`);
}

// Waits until the capture's pane keeps the same height for a moment.
async function settle(page) {
    let last = -1;

    for (let tries = 0; tries < 20; tries++) {
        const height = await page.evaluate(() => document.querySelector('main')?.scrollHeight ?? 0);

        if (height === last) {
            return;
        }

        last = height;
        await new Promise((resolve) => setTimeout(resolve, 150));
    }
}

// Scrolls the capture's pane so the tab bar is at its top, past the capture's header.
async function scrollToTabs(page) {
    await page.evaluate(`(${scrollPaneTo})(document.querySelector('[role="tablist"]'))`);
}

// Clicks the first visible element on the open tab whose own text is `text`, with a real
// mouse click so a popover opens next to it.
async function clickText(page, text) {
    const findElement = (needle) => [...document.querySelectorAll('main *')]
        .find((element) => element.offsetParent !== null && element.childElementCount === 0 && element.textContent.trim() === needle);

    await page.waitForFunction(findElement, { timeout: 8000 }, text).catch(() => console.warn(`no element "${text}"`));

    const handle = await page.evaluateHandle(findElement, text);
    await handle.asElement()?.click();
}

// Opens the command palette with its shortcut and, optionally, types a search into it.
async function openPalette(page, search = '') {
    await page.keyboard.down('Control');
    await page.keyboard.press('k');
    await page.keyboard.up('Control');
    await page.waitForSelector('#monocle-palette-input', { visible: true });

    if (search !== '') {
        await page.type('#monocle-palette-input', search);
    }
}

module.exports = { expandRow, clickText, scrollToTabs, openPalette };
