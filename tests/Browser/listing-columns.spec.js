import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

const pages = ['products', 'stock', 'movements', 'transfers', 'shipments', 'partners', 'categories', 'warehouses', 'quality-returns', 'users'];
const table = page => page.locator('[data-listing-table]');
const picker = page => page.getByRole('button', { name: 'Táblázat oszlopai', exact: true });
const panel = page => page.getByRole('dialog', { name: 'Táblázat oszlopai', exact: true });
const cells = (page, key) => table(page).locator('[data-listing-column="'+key+'"]:visible');
async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click(); await expect(page).toHaveURL(/dashboard/);
}
async function post(page, url, data) {
    const response = await page.request.post(url, { headers: { 'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content') }, data, maxRedirects: 0 });
    expect(response.status()).toBe(302); return response;
}
test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext(); const page = await context.newPage();
    try {
        await login(page); const marker = 'COLS-'+randomUUID();
        await post(page, '/products', { product_name: marker, sku: marker, price: 1, category_id: 1 });
        const productHtml = await (await page.request.get('/products?search='+marker)).text();
        const product = Number(productHtml.match(/href="[^"]*\/products\/(\d+)\/edit/)[1]);
        await post(page, '/movements', { request_key: randomUUID(), product_id: product, warehouse_id: 1, type: 'in', quantity: 10 });
        await post(page, '/transfers', { request_key: randomUUID(), product_id: product, from_warehouse_id: 1, to_warehouse_id: 2, quantity: 1 });
        await post(page, '/shipments', { reference: marker, origin_warehouse_id: 1, stops: [{ warehouse_id: 2, items: [{ product_id: product, quantity: 1 }] }] });
        await post(page, '/partners', { name: marker, type: 'both' });
        await post(page, '/movements', { request_key: randomUUID(), product_id: product, warehouse_id: 1, type: 'out', quantity: 1, reference: marker });
        const issues = await (await page.request.get('/movements?search='+marker)).text();
        const issue = issues.match(/href="[^"]*\/movements\/(\d+)(?:\?|")/)[1];
        await post(page, '/movements/'+issue+'/return', { request_key: randomUUID(), quantity: 1, return_reason: marker });
    } finally { await context.close(); }
});

for (const path of pages) {
    test(path+' toggles matching header and row columns, protects identity, remembers choices and restores defaults', async ({ page }) => {
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        await page.setViewportSize({ width: 1440, height: 1000 }); await login(page); await page.goto('/'+path);
        await page.getByRole('button', { name: 'Táblázatos nézet', exact: true }).click();
        const headingKey = path === 'movements' ? 'product_name' : path === 'shipments' ? 'reference' : 'actions';
        await expect(table(page).locator('thead [data-listing-column="'+headingKey+'"]').getByRole('button', { name: 'Táblázat oszlopai', exact: true })).toBeVisible();
        await expect(page.locator('[data-page-toolbar]').getByRole('button', { name: 'Táblázat oszlopai', exact: true })).toHaveCount(0);
        await picker(page).focus(); await page.keyboard.press('Enter'); await expect(panel(page)).toBeVisible();
        await expect(picker(page)).toHaveAttribute('aria-expanded', 'true');
        const choices = await panel(page).locator('input').evaluateAll(inputs => inputs.map(input => ({ key: input.dataset.columnKey, required: input.disabled })));
        const keys = choices.map(choice => choice.key);
        expect(await table(page).locator('thead th').evaluateAll(headers => headers.map(header => header.dataset.listingColumn))).toEqual(keys);
        const body = table(page).locator('tbody tr').filter({ has: page.locator('[data-listing-column]') });
        expect(await body.count()).toBeGreaterThan(0);
        for (const row of await body.all()) expect(await row.locator('td').evaluateAll(cells => cells.map(cell => cell.dataset.listingColumn))).toEqual(keys);
        const optional = choices.filter(choice => !choice.required); const required = choices.filter(choice => choice.required);
        await expect(panel(page).locator('input[data-column-key="'+optional[0].key+'"]')).toBeFocused();
        const url = page.url(); let requests = 0; page.on('request', request => { if (request.method() !== 'GET') requests++; });
        for (const { key } of optional) {
            await panel(page).locator('input[data-column-key="'+key+'"]').uncheck();
            await expect(cells(page, key)).toHaveCount(0);
        }
        await expect(table(page).locator('thead th:visible')).toHaveCount(required.length);
        if (path === 'products') await expect(table(page).locator('tfoot')).toContainText('Oldalösszesítő');
        for (const row of await body.all()) await expect(row.locator('td:visible')).toHaveCount(required.length);
        for (const { key } of required) await expect(panel(page).locator('input[data-column-key="'+key+'"]')).toBeChecked();
        await page.keyboard.press('Escape'); await expect(panel(page)).toBeHidden(); await expect(picker(page)).toBeFocused();
        await page.reload(); await expect(table(page).locator('thead th:visible')).toHaveCount(required.length); await expect(page).toHaveURL(url);
        await picker(page).click(); await panel(page).getByRole('button', { name: 'Alapbeállítások visszaállítása', exact: true }).click();
        await expect(table(page).locator('thead th:visible')).toHaveCount(keys.length);
        for (const row of await body.all()) await expect(row.locator('td:visible')).toHaveCount(keys.length);
        await expect.poll(async () => {
            const anchor = await picker(page).boundingBox(); const region = await table(page).boundingBox();
            return anchor.x >= Math.max(region.x, 0) && anchor.x + anchor.width <= Math.min(region.x + region.width, 1440);
        }).toBe(true);
        const anchor = await picker(page).boundingBox(); const region = await table(page).boundingBox();
        expect(anchor.x).toBeGreaterThanOrEqual(Math.max(region.x, 0));
        expect(anchor.x + anchor.width).toBeLessThanOrEqual(Math.min(region.x + region.width, 1440));
        const popup = await panel(page).boundingBox();
        expect(popup.x).toBeGreaterThanOrEqual(0); expect(popup.x + popup.width).toBeLessThanOrEqual(1440);
        if (path === 'stock') await page.screenshot({ path: 'tests/Browser/.runtime/stock-columns-header.png', fullPage: true });
        await panel(page).locator('input[data-column-key="'+optional[0].key+'"]').uncheck();
        await page.keyboard.press('Escape');
        await page.getByRole('button', { name: 'Kártyás nézet', exact: true }).click(); await expect(picker(page)).toBeHidden();
        await expect(page.locator('[data-listing-cards]')).toBeVisible();
        await page.getByRole('button', { name: 'Táblázatos nézet', exact: true }).click(); await expect(cells(page, optional[0].key)).toHaveCount(0);
        await page.setViewportSize({ width: 390, height: 800 }); await expect(picker(page)).toBeHidden();
        await page.setViewportSize({ width: 1440, height: 1000 }); await expect(picker(page)).toBeVisible();
        await expect(cells(page, optional[0].key)).toHaveCount(0);
        expect(requests).toBe(0); expect(errors).toEqual([]);
    });
}

test('product column migration, corrupt preferences, fixed columns and per-page isolation are safe', async ({ page }) => {
    await login(page);
    await page.evaluate(() => {
        localStorage.setItem('productColumns', JSON.stringify({ sku: false, price: false, product_name: false, actions: false, alien: false }));
        localStorage.setItem('inventory.stock.columns', '{broken');
        localStorage.setItem('inventory.users.columns', JSON.stringify({ name: false, actions: false, email: 'false', created_at: null }));
        localStorage.setItem('inventory.categories.columns', '[]');
    });
    await page.goto('/products'); await expect(cells(page, 'sku')).toHaveCount(0); await expect(cells(page, 'price')).toHaveCount(0);
    await expect(table(page).locator('thead [data-listing-column="product_name"]')).toBeVisible();
    await expect(table(page).locator('thead [data-listing-column="actions"]')).toBeVisible();
    await picker(page).click(); await panel(page).getByRole('button', { name: 'Alapbeállítások visszaállítása', exact: true }).click();
    await page.keyboard.press('Escape'); await page.reload(); await expect(table(page).locator('thead th:visible')).toHaveCount(7);
    for (const [path, count] of [['stock', 8], ['users', 4], ['categories', 6]]) {
        await page.goto('/'+path); await expect(table(page).locator('thead th:visible')).toHaveCount(count);
    }
    await page.goto('/stock'); await picker(page).click(); await panel(page).getByRole('checkbox', { name: 'Cikkszám (SKU) oszlop megjelenítése', exact: true }).uncheck(); await page.keyboard.press('Escape');
    await page.goto('/products'); await expect(table(page).locator('thead [data-listing-column="sku"]')).toBeVisible();
    await page.goto('/stock'); await expect(cells(page, 'sku')).toHaveCount(0);
});

test('hiding the minimum column preserves its unsaved edit and sorted hidden columns keep their ordering', async ({ page }) => {
    await login(page); await page.goto('/stock?product_id=1&warehouse_id=1&sort_by=quantity&sort_dir=desc&per_page=10');
    const row = table(page).locator('[data-stock-row]').first();
    await row.getByRole('button', { name: /^Műveletek:/ }).click();
    await row.getByRole('menuitem', { name: 'Helyi minimum módosítása', exact: true }).click();
    await row.getByRole('spinbutton').fill('13');
    await picker(page).click(); await panel(page).getByRole('checkbox', { name: 'Helyi minimum oszlop megjelenítése', exact: true }).uncheck();
    await expect(row.getByRole('spinbutton')).toBeHidden();
    await panel(page).getByRole('checkbox', { name: 'Fizikai készlet oszlop megjelenítése', exact: true }).uncheck();
    await expect(page).toHaveURL(url => url.searchParams.get('sort_by') === 'quantity' && url.searchParams.get('sort_dir') === 'desc');
    await panel(page).getByRole('checkbox', { name: 'Helyi minimum oszlop megjelenítése', exact: true }).check(); await page.keyboard.press('Escape');
    await expect(row.getByRole('spinbutton')).toHaveValue('13');
    await page.getByRole('button', { name: 'Kártyás nézet', exact: true }).click();
    await expect(page.locator('[data-listing-cards]').getByRole('spinbutton')).toHaveValue('13');
});

test('the column panel stays inside a narrow desktop viewport with large text, supports reset and closes on resize', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 500 }); await login(page); await page.goto('/quality-returns');
    await page.evaluate(() => { document.documentElement.style.fontSize = '24px'; });
    await picker(page).click(); await expect(panel(page)).toBeVisible();
    const bounds = await panel(page).boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0); expect(bounds.x+bounds.width).toBeLessThanOrEqual(768);
    expect(bounds.y).toBeGreaterThanOrEqual(0); expect(bounds.y+bounds.height).toBeLessThanOrEqual(500);
    await panel(page).getByRole('checkbox', { name: 'Hátralévő oszlop megjelenítése', exact: true }).uncheck();
    await panel(page).getByRole('button', { name: 'Alapbeállítások visszaállítása', exact: true }).click();
    await expect(panel(page).getByRole('checkbox', { name: 'Hátralévő oszlop megjelenítése', exact: true })).toBeChecked();
    await page.screenshot({ path: 'tests/Browser/.runtime/listing-columns-large-text.png' });
    await page.setViewportSize({ width: 390, height: 800 }); await expect(panel(page)).toBeHidden();
    await page.setViewportSize({ width: 1440, height: 900 }); await picker(page).click();
    await page.getByRole('heading', { name: 'Minősítési munkalista', exact: true }).click(); await expect(panel(page)).toBeHidden();
});

test('column changes work with blocked storage and default columns remain available without JavaScript', async ({ browser, page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Blocked', 'SecurityError'); } }));
    await login(page); await page.goto('/stock'); await picker(page).click();
    await panel(page).getByRole('checkbox', { name: 'Cikkszám (SKU) oszlop megjelenítése', exact: true }).uncheck(); await expect(cells(page, 'sku')).toHaveCount(0);
    await panel(page).getByRole('button', { name: 'Alapbeállítások visszaállítása', exact: true }).click();
    await expect(table(page).locator('thead [data-listing-column="sku"]')).toBeVisible(); expect(errors).toEqual([]);
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 900 } });
    try {
        const fallback = await context.newPage(); await login(fallback);
        for (const path of pages.filter(path => path !== 'shipments')) {
            await fallback.goto('/'+path); await expect(picker(fallback)).toBeHidden();
            const headers = await table(fallback).locator('thead th').count();
            await expect(table(fallback).locator('thead th:visible')).toHaveCount(headers);
        }
    } finally { await context.close(); }
});

test('clicks on panel padding, title, help and fixed labels keep it open while outside clicks and keyboard dismissal still work', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 }); await login(page); await page.goto('/stock');
    await picker(page).click(); await expect(panel(page)).toBeVisible();
    const initial = await panel(page).locator('input').evaluateAll(inputs => inputs.map(input => input.checked));
    const sku = panel(page).getByRole('checkbox', { name: 'Cikkszám (SKU) oszlop megjelenítése', exact: true });
    for (const click of [
        () => panel(page).click({ position: { x: 4, y: 4 } }),
        () => panel(page).getByRole('heading', { name: 'Táblázat oszlopai', exact: true }).click(),
        () => panel(page).locator('p').click(),
        async () => {
            const bounds = await panel(page).locator('label').filter({ has: page.locator('input:disabled') }).first().boundingBox();
            // A real pointer can hit a disabled label even though Playwright's input actionability check rejects it.
            await page.mouse.click(bounds.x + bounds.width - 8, bounds.y + bounds.height / 2);
        },
    ]) {
        // Repeat after focusing an input: this is the focus-out path that used to close the panel.
        await sku.focus(); await click(); await expect(panel(page)).toBeVisible();
        expect(await panel(page).locator('input').evaluateAll(inputs => inputs.map(input => input.checked))).toEqual(initial);
    }
    await sku.uncheck(); await expect(panel(page)).toBeVisible(); await expect(cells(page, 'sku')).toHaveCount(0);
    await panel(page).click({ position: { x: 4, y: 4 } }); await expect(panel(page)).toBeVisible();
    await panel(page).getByRole('button', { name: 'Alapbeállítások visszaállítása', exact: true }).click();
    await expect(sku).toBeChecked(); await page.keyboard.press('Escape');
    await expect(panel(page)).toBeHidden(); await expect(picker(page)).toBeFocused();
    await picker(page).click(); await page.getByRole('heading', { name: /^Készlet/ }).click(); await expect(panel(page)).toBeHidden();
    await picker(page).click(); await panel(page).getByRole('button', { name: 'Alapbeállítások visszaállítása', exact: true }).focus();
    await page.keyboard.press('Tab'); await expect(panel(page)).toBeHidden();
});

test('empty result rows span exactly the selected columns', async ({ page }) => {
    await login(page);
    for (const path of ['products', 'shipments', 'partners', 'categories', 'warehouses', 'quality-returns']) {
        await page.goto('/'+path); await page.getByRole('button', { name: 'Táblázatos nézet', exact: true }).click(); await picker(page).click();
        for (const input of await panel(page).locator('input:not(:disabled)').all()) await input.uncheck();
        await page.keyboard.press('Escape');
        const expected = await table(page).locator('thead th:visible').count();
        await page.goto('/'+path+'?search=NO-COLS-'+randomUUID());
        await expect(table(page).getByRole('status')).toHaveAttribute('colspan', String(expected));
        if (path === 'products') await expect(table(page).locator('tfoot')).toHaveCount(0);
    }
});
