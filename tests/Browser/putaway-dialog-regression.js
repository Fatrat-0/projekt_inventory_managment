import { expect } from '@playwright/test';

export async function verifyPutawayDialogIsolation(page, { routePattern, code, review, dialog, save, physical, site }) {
    const mode = page.getByRole('group', { name: 'Célrekesz választási módja' });
    const manual = mode.getByRole('button', { name: 'Kézi rekeszkeresés' });
    const search = page.getByRole('searchbox', { name: 'Rekesz keresése' });
    const getSummary = () => dialog.locator('dl').first().innerText();
    let manualSearches = 0;
    let explicitSearches = 0;
    const waitForDebounce = () => page.evaluate(() => new Promise(resolve => setTimeout(resolve, 520)));
    let markHeld, release, markFinished;
    const held = new Promise(resolve => { markHeld = resolve; });
    const gate = new Promise(resolve => { release = resolve; });
    const finished = new Promise(resolve => { markFinished = resolve; });
    await page.route(routePattern, async route => {
        const body = route.request().postDataJSON();
        if (!body || body.to_location_id || (body.mode && body.mode !== 'search')) return route.continue();
        manualSearches++;
        if (body.search !== code || ++explicitSearches !== 1) return route.continue();
        try {
            const reply = await route.fetch();
            expect(reply.ok()).toBe(true);
            markHeld();
            await gate;
            await route.fulfill({ response: reply }).catch(() => {}); // The browser may have aborted the old search.
        } finally {
            markFinished();
        }
    });

    try {
        // Switch and open in the same turn so the automatic search is still queued.
        await manual.evaluate((element, reviewText) => {
            element.click();
            element.previousElementSibling.click();
            element.click();
            [...document.querySelectorAll('button')].find(button => button.textContent.trim() === reviewText).click();
        }, (await review.innerText()).trim());
        await expect(dialog).toBeVisible();
        await waitForDebounce();
        expect(manualSearches).toBe(0);
        await dialog.press('Escape');
        await expect(dialog).toBeHidden();

        await search.fill(code);
        await held;
        await review.press('Enter');
        await expect(dialog).toBeVisible();
        const summary = await getSummary();
        expect(summary).toContain(code);
        if (site) await site.check();
        await physical.check();
        await expect(save).toBeEnabled();

        release();
        await finished;
        // Observe a full debounce period to catch a rescheduled search while the dialog is open.
        await waitForDebounce();
        expect(explicitSearches).toBe(1);
        await expect(physical).toBeChecked();
        if (site) await expect(site).toBeChecked();
        await expect(save).toBeEnabled();
        expect(await getSummary()).toBe(summary);
        await dialog.press('Escape');
        await expect(dialog).toBeHidden();
        await expect.poll(() => explicitSearches).toBe(2);
    } finally {
        release();
    }
}
