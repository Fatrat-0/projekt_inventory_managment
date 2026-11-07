import { test, expect } from '@playwright/test';

const defaults = ['tasks', 'stock_summary', 'product_alerts', 'category_alerts', 'pending_transfers', 'recent_movements', 'activity', 'warehouse_stocks', 'replenishment'];
const rows = page => page.locator('ul[aria-label="Vezérlőpult blokkjainak sorrendje"] > [data-layout-row]:not([aria-hidden="true"])');
const order = page => rows(page).evaluateAll(elements => elements.map(element => element.dataset.layoutRow));
const row = (page, id) => rows(page).filter({ has: page.locator(`input[name="order[]"][value="${id}"]`) });

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}
async function editor(page) {
    await page.goto('/dashboard/customize');
    await page.getByRole('button', { name: 'Alapértelmezett elrendezés', exact: true }).click();
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/dashboard');
    await page.getByRole('link', { name: 'Testreszabás', exact: true }).click();
    await expect(row(page, 'activity').locator('[data-layout-handle]')).toBeVisible();
}
async function beginMouseDrag(page, sourceId, targetId) {
    const grip = row(page, sourceId).locator('[data-layout-handle]');
    await expect.poll(() => row(page, sourceId).evaluate(element => getComputedStyle(element).transitionDuration)).toBe('0s');
    await grip.scrollIntoViewIfNeeded();
    await grip.click({ trial: true });
    const from = await grip.boundingBox();
    const target = await row(page, targetId).boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2 + 6, from.y + from.height / 2 + 6, { steps: 3 });
    const y = target.y + (defaults.indexOf(sourceId) > defaults.indexOf(targetId) ? 8 : target.height - 8);
    await page.mouse.move(from.x + from.width / 2, y, { steps: 25 });
}

test('mouse dragging preserves checkbox choices, updates real order and persists only on Save', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1100 });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await editor(page);
    await page.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true }).uncheck();
    let saves = 0;
    page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/dashboard/layout') saves++; });
    await beginMouseDrag(page, 'activity', 'tasks');
    await expect.poll(async () => (await order(page))[0]).toBe('activity');
    await expect(page.getByRole('button', { name: 'Mentés', exact: true })).toBeDisabled();
    await page.mouse.up();
    await expect(page.getByRole('button', { name: 'Mentés', exact: true })).toBeEnabled();
    await expect(page.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true })).not.toBeChecked();
    await expect(row(page, 'activity').getByRole('button', { name: /feljebb$/ })).toBeDisabled();
    expect(saves).toBe(0);
    expect(await page.locator('input[name="order[]"]').evaluateAll(elements => elements.map(element => element.value))).toEqual(await order(page));
    await page.screenshot({ path: 'tests/Browser/.runtime/dashboard-drag-desktop.png' });
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/dashboard');
    expect(saves).toBe(1);
    expect(await page.locator('[data-dashboard-panel]').first().getAttribute('data-dashboard-panel')).toBe('activity');
    await expect(page.locator('[data-dashboard-panel="product_alerts"]')).toHaveCount(0);
    await page.reload();
    expect(await page.locator('[data-dashboard-panel]').first().getAttribute('data-dashboard-panel')).toBe('activity');
    await editor(page);
    expect(errors).toEqual([]);
});

test('Escape cancels dragging, a grip click does not reorder, and Cancel discards a dragged draft', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1100 });
    await login(page);
    await editor(page);
    await row(page, 'tasks').locator('[data-layout-handle]').click();
    await expect(page.getByRole('button', { name: 'Mentés', exact: true })).toBeEnabled();
    expect(await order(page)).toEqual(defaults);
    await beginMouseDrag(page, 'activity', 'tasks');
    await expect.poll(async () => (await order(page))[0]).toBe('activity');
    await page.keyboard.press('Escape');
    await page.mouse.up();
    expect(await order(page)).toEqual(defaults);
    await expect(page.locator('.sortable-fallback')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Mentés', exact: true })).toBeEnabled();
    await beginMouseDrag(page, 'activity', 'tasks');
    await expect.poll(async () => (await order(page))[0]).toBe('activity');
    await page.mouse.up();
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/dashboard');
    expect(await page.locator('[data-dashboard-panel]').evaluateAll(elements => elements.map(element => element.dataset.dashboardPanel))).toEqual(defaults);
});

test('touch drag autoscrolls, cancels cleanly and regular swiping still scrolls the page', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 740 }, isMobile: true, hasTouch: true });
    try {
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await login(page);
        await editor(page);
        const cdp = await context.newCDPSession(page);
        const touch = (type, x, y) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' || type === 'touchCancel' ? [] : [{ x, y, id: 1 }] });
        const frame = () => page.evaluate(() => new Promise(requestAnimationFrame));
        await page.evaluate(() => window.scrollTo(0, 0));
        const grip = await row(page, 'tasks').locator('[data-layout-handle]').boundingBox();
        const x = grip.x + grip.width / 2;
        const y = grip.y + grip.height / 2;
        const edge = page.viewportSize().height - 8;
        await touch('touchStart', x, y);
        for (let step = 1; step <= 18; step++) { await touch('touchMove', x, y + (edge - y) * step / 18); await frame(); }
        // Hold at the viewport edge as a user would; do not depend on one throttled move frame.
        for (let step = 0; step < 60 && await page.evaluate(() => window.scrollY) < 80; step++) {
            await touch('touchMove', x, edge - step % 2); await frame();
        }
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(60);
        await expect.poll(async () => (await order(page))[0]).not.toBe('tasks');
        await touch('touchCancel');
        expect(await order(page)).toEqual(defaults);
        await expect(page.locator('.sortable-fallback')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Mentés', exact: true })).toBeEnabled();
        await page.evaluate(() => window.scrollTo(0, 0));
        await touch('touchStart', 200, 600);
        for (let step = 1; step <= 10; step++) { await touch('touchMove', 200, 600 - step * 30); await frame(); }
        await touch('touchEnd');
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(60);
        expect(await order(page)).toEqual(defaults);
        await expect.poll(() => row(page, 'tasks').evaluate(element => getComputedStyle(element).transitionDuration)).toBe('0s');
        await row(page, 'tasks').locator('[data-layout-handle]').evaluate(element => element.scrollIntoView({ block: 'center', behavior: 'instant' }));
        await row(page, 'tasks').locator('[data-layout-handle]').click({ trial: true });
        const source = await row(page, 'tasks').locator('[data-layout-handle]').boundingBox();
        const target = await row(page, 'category_alerts').boundingBox();
        const start = source.y + source.height / 2;
        await touch('touchStart', x, start);
        for (let step = 1; step <= 20; step++) { await touch('touchMove', x, start + (target.y + target.height - 8 - start) * step / 20); await frame(); }
        await expect.poll(async () => (await order(page))[0]).not.toBe('tasks');
        await touch('touchEnd');
        expect(new Set(await order(page)).size).toBe(defaults.length);
        await page.getByRole('button', { name: 'Mentés', exact: true }).click();
        await expect(page).toHaveURL(url => url.pathname === '/dashboard');
        expect(await page.locator('[data-dashboard-panel]').evaluateAll(elements => elements.map(element => element.dataset.dashboardPanel))).not.toEqual(defaults);
        await editor(page);
        expect(errors).toEqual([]);
    } finally { await context.close(); }
});
