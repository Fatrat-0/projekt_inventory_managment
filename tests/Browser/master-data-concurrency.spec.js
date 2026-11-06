import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email').fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
}

const resources = [
    ['products', 'Terméknév', 'Frissítés', { product_name: 'Concurrent product', sku: 'CONCURRENT-UI', category_id: '1', price: '100' }],
    ['warehouses', 'Raktár neve', 'Raktár mentése', { warehouse_name: 'Concurrent warehouse', location: 'Cím' }],
    ['categories', 'Kategória neve', 'Kategória mentése', { category_name: 'Concurrent category', default_item_min_stock: '0' }],
    ['partners', 'Név / Cégnév', 'Partner mentése', { name: 'Concurrent partner', type: 'customer', phone: '0', notes: 'N'.repeat(2000) }],
];

for (const [resource, label, save, fixture] of resources) {
    test(`${resource}: independent forms preserve stale input, require review, and reject an outdated review`, async ({ page, context }) => {
        await login(page);
        const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const created = await page.request.post('/'+resource, { headers: { 'X-CSRF-TOKEN': token }, form: fixture, maxRedirects: 0 });
        expect(created.status()).toBe(302);
        await page.goto('/'+resource+'?search='+encodeURIComponent(Object.values(fixture)[0]));
        const editUrl = await page.locator(`a[href*="/${resource}/"][href*="/edit"]`).first().getAttribute('href');
        expect(editUrl).toBeTruthy();
        await page.goto(editUrl);
        const other = await context.newPage();
        await other.goto(editUrl);
        const ownName = 'Saját-'+resource+'-á';
        await page.getByLabel(label, { exact: true }).fill(ownName);
        await other.getByLabel(label, { exact: true }).fill('W'.repeat(100));
        await other.getByRole('button', { name: save, exact: true }).click();
        await expect(other).toHaveURL(url => url.pathname === '/'+resource);
        await page.getByRole('button', { name: save, exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Az aktuális adatok átnézése szükséges' })).toBeVisible();
        await expect(page.getByLabel(label, { exact: true })).toHaveValue(ownName);
        await expect(page.locator('input[name="lock_version"]')).toHaveValue('1');
        await expect(page.locator('input[name="review_version"]')).toHaveValue('2');
        await expect(page.locator('dl')).toContainText('W'.repeat(100));
        // A checked review still cannot overwrite a later third edit.
        await page.getByRole('checkbox', { name: /Átnéztem az aktuális adatokat/ }).check();
        await other.goto(editUrl);
        await other.getByLabel(label, { exact: true }).fill('Harmadik mentett változat');
        await other.getByRole('button', { name: save, exact: true }).click();
        await expect(other).toHaveURL(url => url.pathname === '/'+resource);
        await page.getByRole('button', { name: save, exact: true }).click();
        await expect(page.locator('input[name="review_version"]')).toHaveValue('3');
        await expect(page.getByRole('checkbox', { name: /Átnéztem az aktuális adatokat/ })).not.toBeChecked();
        await expect(page.getByLabel(label, { exact: true })).toHaveValue(ownName);
        for (const width of [320, 375, 767, 768, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        }
        if (resource === 'partners') await page.screenshot({ path: 'tests/Browser/.runtime/master-data-conflict-mobile.png', fullPage: true });
        await page.getByRole('checkbox', { name: /Átnéztem az aktuális adatokat/ }).check();
        await page.getByRole('button', { name: save, exact: true }).click();
        await expect(page).toHaveURL(url => url.pathname === '/'+resource);
        await page.goto(editUrl);
        await expect(page.getByLabel(label, { exact: true })).toHaveValue(ownName);
        await expect(page.locator('input[name="lock_version"]')).toHaveValue('4');
        await other.close();
    });
}

test('conflict review and reloading current data work without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    try {
        await login(page);
        // This test edits its own warehouse; later specs still need the shared picker fixture.
        const name = 'NoJS-'+randomUUID();
        const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const created = await page.request.post('/warehouses', { headers: { 'X-CSRF-TOKEN': token }, form: { warehouse_name: name, location: 'Próbahely' }, maxRedirects: 0 });
        expect(created.status()).toBe(302);
        const listing = await page.request.get('/warehouses?search='+encodeURIComponent(name));
        const match = (await listing.text()).match(/data-warehouse-id="(\d+)"/);
        expect(match).not.toBeNull();
        const editUrl = '/warehouses/'+match[1]+'/edit';
        await page.goto(editUrl);
        const other = await context.newPage();
        await other.goto(editUrl);
        await other.getByLabel('Raktár neve', { exact: true }).fill('NoJS current');
        await other.getByRole('button', { name: 'Raktár mentése', exact: true }).click();
        await expect(other).toHaveURL(/\/warehouses$/);
        await page.getByLabel('Raktár neve', { exact: true }).fill('NoJS own');
        await page.getByRole('button', { name: 'Raktár mentése', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Az aktuális adatok átnézése szükséges' })).toBeVisible();
        await expect(page.getByLabel('Raktár neve', { exact: true })).toHaveValue('NoJS own');
        await page.getByRole('link', { name: 'Aktuális adatok betöltése a saját módosításaim helyett' }).click();
        await expect(page.getByLabel('Raktár neve', { exact: true })).toHaveValue('NoJS current');
        await expect(page.locator('input[name="review_version"]')).toHaveCount(0);
        await page.getByLabel('Raktár neve', { exact: true }).fill('NoJS reviewed');
        await page.getByRole('button', { name: 'Raktár mentése', exact: true }).click();
        await expect(page).toHaveURL(/\/warehouses$/);
    } finally { await context.close(); }
});
