import { test, expect } from '@playwright/test';

async function login(page, admin = false) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(admin ? 'warehouse-admin@example.test' : 'browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}
const rows = page => page.locator('[data-stock-row]:visible');
const cards = page => page.getByRole('button', { name: 'Kártyás nézet', exact: true });
const table = page => page.getByRole('button', { name: 'Táblázatos nézet', exact: true });
async function editMinimum(page) {
    await rows(page).getByRole('button', { name: /^Műveletek:/ }).click();
    await rows(page).getByRole('menuitem', { name: 'Helyi minimum módosítása', exact: true }).click();
}

test('desktop view buttons preserve filters and unsent search, render a real card grid and remember the selection', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await login(page);
    await page.goto('/stock?warehouse_id=1&sort_by=quantity&sort_dir=desc&per_page=10');
    const url = page.url();
    const ids = await rows(page).evaluateAll(elements => elements.map(element => element.dataset.stockRow));
    await expect(table(page)).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('combobox', { name: 'Készlet keresése', exact: true }).fill('Még el nem küldött');
    await cards(page).focus(); await page.keyboard.press('Space');
    await expect(cards(page)).toHaveAttribute('aria-pressed', 'true'); await expect(table(page)).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('[data-stock-table]')).toBeHidden(); await expect(page.locator('[data-stock-cards]')).toBeVisible();
    expect(await rows(page).evaluateAll(elements => elements.map(element => element.dataset.stockRow))).toEqual(ids);
    const first = await rows(page).nth(0).boundingBox(); const second = await rows(page).nth(1).boundingBox();
    expect(second.y).toBe(first.y); expect(second.x).toBeGreaterThan(first.x);
    await expect(page.getByRole('combobox', { name: 'Készlet keresése', exact: true })).toHaveValue('Még el nem küldött');
    await expect(page).toHaveURL(url);
    await page.locator('[data-stock-card-sort] summary').click();
    await expect(page.locator('[data-stock-card-sort]').getByRole('link', { name: 'Kiadható', exact: false })).toBeVisible();
    await page.screenshot({ path: 'tests/Browser/.runtime/stock-desktop-cards.png', fullPage: true });
    await page.reload(); await expect(cards(page)).toHaveAttribute('aria-pressed', 'true');
    expect(await page.evaluate(() => localStorage.getItem('inventory.stock.view'))).toBe('cards');
    await page.goto('/products'); await page.goto(url); await expect(cards(page)).toHaveAttribute('aria-pressed', 'true');
    await rows(page).first().getByRole('button', { name: /^Műveletek:/ }).click();
    await expect(rows(page).first().getByRole('menu', { name: 'Készletsor műveletei', exact: true })).toBeVisible();
    await table(page).click(); await expect(table(page)).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-stock-cards] [popover]:popover-open')).toHaveCount(0);
    await expect(page.locator('[data-stock-card-sort]')).toBeHidden();
});

test('minimum drafts survive switching both views and mobile resizing and Cancel clears the shared draft', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.setViewportSize({ width: 1440, height: 1000 });
    await login(page, true); await page.goto('/stock?product_id=1&warehouse_id=1');
    let writes = 0; page.on('request', request => { if (request.method() === 'POST') writes++; });
    await editMinimum(page); await rows(page).getByRole('spinbutton').fill('13');
    await cards(page).click(); await expect(rows(page).getByRole('spinbutton')).toHaveValue('13');
    await rows(page).getByRole('spinbutton').fill('17'); await table(page).click();
    await expect(rows(page).getByRole('spinbutton')).toHaveValue('17');
    await page.setViewportSize({ width: 390, height: 800 });
    await expect(page.getByRole('group', { name: 'Készlet megjelenítése', exact: true })).toBeHidden();
    await expect(page.locator('[data-stock-table]')).toBeHidden(); await expect(page.locator('[data-stock-cards]')).toBeVisible();
    await expect(rows(page).getByRole('spinbutton')).toHaveValue('17');
    expect(await page.evaluate(() => localStorage.getItem('inventory.stock.view'))).toBe('table');
    await page.setViewportSize({ width: 1440, height: 1000 }); await expect(page.locator('[data-stock-table]')).toBeVisible();
    await expect(rows(page).getByRole('spinbutton')).toHaveValue('17');
    await rows(page).getByRole('link', { name: 'Mégse', exact: true }).click();
    await cards(page).click(); await expect(rows(page).getByRole('spinbutton')).toBeHidden();
    await editMinimum(page); await expect(rows(page).getByRole('spinbutton')).toHaveValue('5');
    expect(writes).toBe(0); expect(errors).toEqual([]);
});

test('invalid and unavailable storage do not break either view', async ({ browser, page }) => {
    await login(page); await page.goto('/stock');
    await page.evaluate(() => localStorage.setItem('inventory.stock.view', 'broken')); await page.reload();
    await expect(table(page)).toHaveAttribute('aria-pressed', 'true');
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    try {
        await context.addInitScript(() => Object.defineProperty(window, 'localStorage', { get() { throw new Error('Unavailable storage'); } }));
        const restricted = await context.newPage(); const errors = []; restricted.on('pageerror', error => errors.push(error.message));
        await login(restricted); await restricted.goto('/stock'); await cards(restricted).click();
        await expect(restricted.locator('[data-stock-cards]')).toBeVisible();
        await table(restricted).click(); await expect(restricted.locator('[data-stock-table]')).toBeVisible();
        expect(errors).toEqual([]);
    } finally { await context.close(); }
});

test('without JavaScript the responsive table and cards remain usable without dead toggle buttons', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 900 } });
    try {
        const page = await context.newPage(); await login(page); await page.goto('/stock');
        await expect(page.getByRole('group', { name: 'Készlet megjelenítése', exact: true })).toBeHidden();
        await expect(page.locator('[data-stock-table]')).toBeVisible(); await expect(page.locator('[data-stock-cards]')).toBeHidden();
        await page.setViewportSize({ width: 390, height: 800 });
        await expect(page.locator('[data-stock-cards]')).toBeVisible(); await expect(page.locator('[data-stock-table]')).toBeHidden();
        await expect(page.locator('[data-stock-card-sort]')).toBeVisible();
    } finally { await context.close(); }
});
