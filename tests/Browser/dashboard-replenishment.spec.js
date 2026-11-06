import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}
async function post(page, path, data) {
    return page.request.post(path, { headers: { 'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content') }, data, maxRedirects: 0 });
}
async function scenario(page) {
    const sku = 'REPL-'+randomUUID();
    expect((await post(page, '/products', { product_name: 'Utánpótlás teszt '+sku, sku, category_id: 1, price: 1, min_stock_override: 0 })).status()).toBe(302);
    const html = await (await page.request.get('/products?search='+sku)).text();
    const product = Number(html.match(/href="[^"]*\/products\/(\d+)\/edit/)[1]);
    for (const [warehouse_id, quantity] of [[1, 20], [2, 1]]) {
        expect((await post(page, '/movements', { request_key: randomUUID(), product_id: product, warehouse_id, quantity, type: 'in' })).status()).toBe(302);
    }
    await page.goto('/stock?warehouse_id=2');
    const row = page.locator('[data-stock-row]:visible').filter({ hasText: sku });
    const target = await row.locator('[data-stock-minimum]').getAttribute('data-stock-minimum');
    await row.locator('summary').click();
    await row.getByRole('spinbutton').fill('10');
    await row.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('A helyi minimum frissítve');
    await page.goto('/dashboard/customize');
    for (const checkbox of await page.getByRole('checkbox').all()) {
        await checkbox.setChecked(['warehouse_stocks', 'replenishment'].includes(await checkbox.getAttribute('value')));
    }
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    return { sku, product, target };
}
async function cleanup(page, target, sku) {
    if (sku) {
        const html = await (await page.request.get('/transfers?status=pending&search='+sku)).text();
        const match = html.match(/href="[^"]*\/transfers\/(\d+)(?:\?|\"|\/pdf)/);
        if (match) expect((await post(page, '/transfers/'+match[1]+'/cancel', { _method: 'PATCH' })).status()).toBe(302);
    }
    if (target) await post(page, '/stock/'+target+'/minimum', { _method: 'PATCH', min_quantity: 0, expected_minimum: 10 });
    await page.goto('/dashboard/customize');
    await page.getByRole('button', { name: 'Alapértelmezett elrendezés', exact: true }).click();
    // Without JS the reset already saves and returns to the editor; saving is harmless there too.
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
}

test('warehouse chart links and replenishment preparation work on mobile and save one guarded transfer', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    let target, sku;
    try {
        const state = await scenario(page); target = state.target; sku = state.sku;
        const panel = page.locator('[data-dashboard-panel="replenishment"]');
        const proposal = panel.locator(`[data-replenishment="${target}"]:visible`);
        await expect(proposal).toContainText('Még hiányzik: 9 db');
        await expect(proposal).toContainText('Teszt raktár → Északi tesztraktár');
        for (const width of [320, 390, 768, 1440]) {
            await page.setViewportSize({ width, height: 800 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        }
        await page.setViewportSize({ width: 390, height: 800 });
        await page.screenshot({ path: 'tests/Browser/.runtime/dashboard-replenishment-mobile.png', fullPage: true });
        const warehouse = page.locator('[data-warehouse-stock="2"]:visible');
        await warehouse.getByRole('link', { name: 'Északi tesztraktár', exact: true }).click();
        await expect(page).toHaveURL(/stock\?warehouse_id=2/);
        await expect(page.locator('[data-stock-row]:visible').filter({ hasText: state.sku })).toBeVisible();
        await page.goto('/dashboard');
        await proposal.getByRole('link', { name: 'Átmozgatás előkészítése', exact: false }).click();
        await expect(page.getByRole('combobox', { name: 'Termék', exact: true })).toHaveValue('Utánpótlás teszt '+state.sku+' ('+state.sku+')');
        await expect(page.getByRole('combobox', { name: 'Forrásraktár', exact: true })).toHaveValue('Teszt raktár · Test');
        await expect(page.getByRole('combobox', { name: 'Célraktár', exact: true })).toHaveValue('Északi tesztraktár · Bemutató célállomás');
        await expect(page.getByRole('spinbutton', { name: 'Mennyiség (db)', exact: true })).toHaveValue('9');
        const before = await (await page.request.get('/movements/stock?product_id='+state.product+'&warehouse_id=1')).json();
        expect(before.quantity).toBe(20);
        await page.getByRole('button', { name: 'Átmozgatás indítása', exact: true }).click();
        await expect(page.getByRole('status')).toContainText('Átmozgatás elindítva');
        await page.goto('/dashboard');
        await expect(page.locator(`[data-replenishment="${target}"]`)).toHaveCount(0);
        const after = await (await page.request.get('/movements/stock?product_id='+state.product+'&warehouse_id=1')).json();
        expect(after.quantity).toBe(11);
        expect(errors).toEqual([]);
    } finally { await cleanup(page, target, sku); }
});

test('new panels, minimum editing and prepared transfers remain usable without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 320, height: 740 } });
    const page = await context.newPage();
    let target, sku;
    try {
        await login(page);
        const state = await scenario(page); target = state.target; sku = state.sku;
        const proposal = page.locator(`[data-replenishment="${target}"]:visible`);
        await expect(proposal).toContainText('Még hiányzik: 9 db');
        await proposal.getByRole('link', { name: /Átmozgatás előkészítése/ }).click();
        await expect(page.locator('input[name="product_id"]')).toHaveValue(String(state.product));
        await expect(page.locator('input[name="from_warehouse_id"]')).toHaveValue('1');
        await expect(page.locator('input[name="to_warehouse_id"]')).toHaveValue('2');
        await expect(page.getByRole('combobox', { name: 'Forrásraktár', exact: true })).toHaveValue('Teszt raktár · Test');
        await expect(page.getByRole('spinbutton', { name: 'Mennyiség (db)', exact: true })).toHaveValue('9');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.getByRole('button', { name: 'Átmozgatás indítása', exact: true }).click();
        await expect(page.getByRole('status')).toContainText('Átmozgatás elindítva');
    } finally { await cleanup(page, target, sku); await context.close(); }
});
