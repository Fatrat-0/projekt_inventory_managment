import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

const listings = [
    ['products', 'product-id'], ['stock', 'stock-row'], ['movements', 'movement-id'],
    ['transfers', 'transfer-id'], ['shipments', 'shipment-id'], ['partners', 'partner-id'],
    ['categories', 'category-id'], ['warehouses', 'warehouse-id'], ['quality-returns', 'quality-return-id'], ['users', 'user-id'],
];
async function login(page, admin = true) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(admin ? 'warehouse-admin@example.test' : 'browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}
const toggle = (page, mode) => page.getByRole('button', { name: mode === 'cards' ? 'Kártyás nézet' : 'Táblázatos nézet', exact: true });
const records = (page, attr) => page.locator(`[data-${attr}]:visible`);
const ids = (page, attr) => records(page, attr).evaluateAll((elements, attribute) => elements.map(element => element.getAttribute('data-'+attribute)), attr);
let fixtureTransfer;
async function post(page, url, data) {
    const response = await page.request.post(url, { headers: { 'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content') }, data, maxRedirects: 0 });
    expect(response.status(), url).toBe(302);
    return response;
}

test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext(); const page = await context.newPage();
    try {
        await login(page);
        const marker = 'LIST-VIEW-'+randomUUID();
        await post(page, '/products', { product_name: marker, sku: marker, price: 1, category_id: 1 });
        const html = await (await page.request.get('/products?search='+marker)).text();
        const product = Number(html.match(/href="[^"]*\/products\/(\d+)\/edit/)[1]);
        await post(page, '/movements', { request_key: randomUUID(), product_id: product, warehouse_id: 1, type: 'in', quantity: 10, reference: marker });
        await post(page, '/transfers', { request_key: randomUUID(), product_id: product, from_warehouse_id: 1, to_warehouse_id: 2, quantity: 1 });
        const transferHtml = await (await page.request.get('/transfers?search='+marker)).text();
        fixtureTransfer = transferHtml.match(/data-transfer-id="(\d+)"/)[1];
        await post(page, '/shipments', { reference: marker, origin_warehouse_id: 1, stops: [{ warehouse_id: 2, items: [{ product_id: product, quantity: 1 }] }] });
        await post(page, '/partners', { name: marker, type: 'both' });
        await post(page, '/movements', { request_key: randomUUID(), product_id: product, warehouse_id: 1, type: 'out', quantity: 1, reference: marker+'-OUT' });
        const issues = await (await page.request.get('/movements?search='+marker+'-OUT')).text();
        const issue = issues.match(/href="[^"]*\/movements\/(\d+)(?:\?|")/)[1];
        await post(page, '/movements/'+issue+'/return', { request_key: randomUUID(), quantity: 1, return_reason: marker });
    } finally { await context.close(); }
});

for (const [path, attr] of listings) {
    test(path+' uses the shared desktop toggle, remembers its own choice and keeps mobile cards and record order', async ({ page }) => {
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        await page.setViewportSize({ width: 1440, height: 950 }); await login(page);
        await page.goto('/'+path); await expect(page.locator('[data-listing-toggle]')).toBeVisible();
        await expect(toggle(page, path === 'shipments' ? 'cards' : 'table')).toHaveAttribute('aria-pressed', 'true');
        await toggle(page, 'table').click();
        const before = await ids(page, attr); expect(before.length).toBeGreaterThan(0);
        if (path === 'shipments') await page.screenshot({ path: 'tests/Browser/.runtime/listing-shipments-table.png', fullPage: true });
        const url = page.url(); let navigations = 0;
        page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) navigations++; });
        await toggle(page, 'cards').focus(); await page.keyboard.press('Space');
        await expect(toggle(page, 'cards')).toHaveAttribute('aria-pressed', 'true');
        await expect(page.locator('[data-listing-table]')).toBeHidden();
        await expect(page.locator('[data-listing-cards]')).toBeVisible();
        expect(await ids(page, attr)).toEqual(before); await expect(page).toHaveURL(url); expect(navigations).toBe(0);
        await page.screenshot({ path: 'tests/Browser/.runtime/listing-'+path+'-cards.png', fullPage: true });
        await page.reload(); await expect(toggle(page, 'cards')).toHaveAttribute('aria-pressed', 'true');
        await page.goto('/dashboard'); await page.goto(url); await expect(toggle(page, 'cards')).toHaveAttribute('aria-pressed', 'true');
        await toggle(page, 'table').click();
        await page.setViewportSize({ width: 320, height: 800 });
        await expect(page.locator('[data-listing-toggle]')).toBeHidden();
        await expect(page.locator('[data-listing-cards]')).toBeVisible(); await expect(page.locator('[data-listing-table]')).toBeHidden();
        expect(await ids(page, attr)).toEqual(before);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), path+' must fit mobile').toBe(true);
        expect(await page.evaluate(key => localStorage.getItem('inventory.'+key+'.view'), path)).toBe('table');
        await page.setViewportSize({ width: 1440, height: 950 }); await expect(toggle(page, 'table')).toHaveAttribute('aria-pressed', 'true');
        await expect(page.locator('[data-listing-table]')).toBeVisible();
        expect(errors).toEqual([]);
    });
}

test('preferences are independent and the old product preference is migrated without changing column choices', async ({ page }) => {
    await login(page);
    await page.evaluate(() => {
        localStorage.setItem('productViewMode', 'kanban');
        localStorage.setItem('productColumns', JSON.stringify({ sku: false }));
        localStorage.setItem('inventory.partners.view', 'invalid');
    });
    await page.goto('/products'); await expect(toggle(page, 'cards')).toHaveAttribute('aria-pressed', 'true');
    await toggle(page, 'table').click(); await expect(page.getByRole('columnheader', { name: /Cikkszám/ })).toBeHidden();
    await page.goto('/stock'); await expect(toggle(page, 'table')).toHaveAttribute('aria-pressed', 'true'); await toggle(page, 'cards').click();
    await page.goto('/partners'); await expect(toggle(page, 'table')).toHaveAttribute('aria-pressed', 'true');
    await page.goto('/products'); await expect(toggle(page, 'table')).toHaveAttribute('aria-pressed', 'true');
    await page.goto('/stock'); await expect(toggle(page, 'cards')).toHaveAttribute('aria-pressed', 'true');
});

test('card sorting on movements, transfers and shipments submits draft search and preserves the selected view', async ({ page }) => {
    await login(page);
    for (const [path, sort, label, input] of [
        ['movements', 'quantity', 'Mennyiség', 'Mozgások keresése'],
        ['transfers', 'quantity', 'Mennyiség', 'Átmozgatások keresése'],
        ['shipments', 'reference', 'Hivatkozás', 'Szállítmányok keresése'],
    ]) {
        await page.goto('/'+path+'?sort_by=created_at&sort_dir=desc&per_page=25'); await toggle(page, 'cards').click();
        const search = page.getByRole('combobox', { name: input, exact: true });
        await search.fill('LIST-VIEW'); await search.press('Escape');
        const sortPanel = page.locator('[data-listing-card-sort]'); await expect(sortPanel).toBeVisible();
        if (path === 'shipments') await page.getByLabel('Rendezés', { exact: true }).selectOption(sort);
        else { await sortPanel.locator('summary').click(); await sortPanel.getByRole('link', { name: label, exact: false }).click(); }
        await expect(page).toHaveURL(url => url.searchParams.get('search') === 'LIST-VIEW' && url.searchParams.get('sort_by') === sort && url.searchParams.get('per_page') === '25');
        await expect(toggle(page, 'cards')).toHaveAttribute('aria-pressed', 'true');
        if (path === 'shipments') {
            await toggle(page, 'table').click(); await page.getByRole('columnheader', { name: /Állapot/ }).getByRole('link').click();
            await expect(page).toHaveURL(url => url.searchParams.get('sort_by') === 'status' && url.searchParams.get('search') === 'LIST-VIEW');
        }
    }
});

test('card actions preserve transfer context and receipt behavior and protect the current user', async ({ page }) => {
    await login(page);
    await page.goto('/transfers?search=LIST-VIEW&sort_by=quantity&sort_dir=asc&per_page=25');
    await toggle(page, 'cards').click();
    const card = page.locator('[data-transfer-id="'+fixtureTransfer+'"]:visible');
    await expect(card.getByRole('link', { name: 'Szállítólevél (PDF)', exact: true })).toBeVisible();
    let confirmation = false; page.once('dialog', async dialog => { confirmation = true; await dialog.accept(); });
    await card.getByRole('button', { name: 'Átvétel', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Az átvétel rögzítve');
    expect(confirmation).toBe(true);
    await expect(card).toContainText('Megérkezett');
    await expect(card.getByRole('button', { name: 'Átvétel', exact: true })).toHaveCount(0);
    await expect(page).toHaveURL(url => url.searchParams.get('search') === 'LIST-VIEW' && url.searchParams.get('sort_by') === 'quantity' && url.searchParams.get('per_page') === '25');
    await expect(toggle(page, 'cards')).toHaveAttribute('aria-pressed', 'true');
    await page.goto('/users'); await toggle(page, 'cards').click();
    const self = page.locator('[data-listing-cards] article').filter({ hasText: 'warehouse-admin@example.test' });
    await expect(self).toContainText('Te vagy az'); await expect(self.getByRole('button', { name: 'Törlés', exact: true })).toHaveCount(0);
    await expect(page.locator('[data-listing-cards] article').filter({ hasText: 'browser@example.test' }).getByRole('button', { name: 'Törlés', exact: true })).toBeVisible();
});

test('all shared views work when local storage is unavailable', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Blocked', 'SecurityError'); } }));
    await login(page);
    for (const [path] of listings) {
        await page.goto('/'+path); await toggle(page, 'cards').click(); await expect(page.locator('[data-listing-cards]')).toBeVisible();
        await toggle(page, 'table').click(); await expect(page.locator('[data-listing-table]')).toBeVisible();
    }
    expect(errors).toEqual([]);
});

test('empty filtered lists stay readable in both views and on narrow screens', async ({ page }) => {
    await login(page);
    const missing = 'NO-LIST-'+randomUUID();
    for (const [path, attr] of listings.filter(([path]) => path !== 'users')) {
        await page.setViewportSize({ width: 1440, height: 900 }); await page.goto('/'+path+'?search='+missing);
        for (const mode of ['table', 'cards']) {
            await toggle(page, mode).click();
            await expect(page.getByRole('status').filter({ hasText: 'Nincs' })).toBeVisible();
            await expect(records(page, attr)).toHaveCount(0);
        }
        await page.setViewportSize({ width: 320, height: 740 });
        await expect(page.getByRole('status').filter({ hasText: 'Nincs' })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), path).toBe(true);
    }
});

test('card layouts fit tablet and narrow screens with enlarged text', async ({ page }) => {
    await login(page);
    for (const [path] of listings) {
        await page.setViewportSize({ width: 1440, height: 900 }); await page.goto('/'+path); await toggle(page, 'cards').click();
        await page.evaluate(() => { document.documentElement.style.fontSize = '24px'; });
        for (const width of [1440, 768, 320]) {
            await page.setViewportSize({ width, height: 740 });
            const sizing = await page.evaluate(() => ({ width: document.documentElement.scrollWidth, viewport: innerWidth,
                overflow: [...document.querySelectorAll('main *')].filter(element => element.getBoundingClientRect().right > innerWidth + 1).slice(0, 8).map(element => ({ tag: element.tagName, classes: element.className })) }));
            expect(sizing.width, path+' at '+width+'px with 150% text: '+JSON.stringify(sizing.overflow)).toBeLessThanOrEqual(sizing.viewport);
        }
    }
});

test('all pages retain a usable default and mobile cards without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1440, height: 900 } });
    try {
        const page = await context.newPage(); await login(page);
        for (const [path, attr] of listings) {
            await page.setViewportSize({ width: 1440, height: 900 }); await page.goto('/'+path);
            await expect(page.locator('[data-listing-toggle]')).toBeHidden();
            await expect(page.locator(path === 'shipments' ? '[data-listing-cards]' : '[data-listing-table]')).toBeVisible();
            const before = await ids(page, attr); expect(before.length).toBeGreaterThan(0);
            await page.setViewportSize({ width: 320, height: 800 });
            await expect(page.locator('[data-listing-cards]')).toBeVisible(); await expect(page.locator('[data-listing-table]')).toBeHidden();
            expect(await ids(page, attr)).toEqual(before);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), path).toBe(true);
        }
    } finally { await context.close(); }
});
