import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
});

const csrf = page => page.locator('meta[name="csrf-token"]').getAttribute('content');
const quality = page => page.getByRole('region', { name: 'Visszáru minősítése', exact: true });
async function stock(page) {
    const response = await page.request.get('/movements/stock?product_id=1&warehouse_id=1');
    expect(response.ok()).toBe(true);
    return response.json();
}
async function post(page, url, data, json = false) {
    return page.request.post(url, {
        headers: { 'X-CSRF-TOKEN': await csrf(page), ...(json ? { Accept: 'application/json' } : {}) },
        form: data, maxRedirects: 0,
    });
}
async function quarantinedReturn(page, quantity = 6) {
    const before = await stock(page);
    // Fund this test's original issue so each test is independent of other stock consumers.
    const common = { product_id: '1', warehouse_id: '1', quantity: String(quantity) };
    expect((await post(page, '/movements', { ...common, request_key: randomUUID(), type: 'in' })).status()).toBe(302);
    const reference = 'QUALITY-BROWSER-'+randomUUID();
    expect((await post(page, '/movements', { ...common, request_key: randomUUID(), type: 'out', reference })).status()).toBe(302);
    await page.goto('/movements?search='+reference);
    await page.locator('tbody').getByRole('link', { name: /^MOZ-/ }).click();
    const original = new URL(page.url()).pathname;
    const response = await post(page, original+'/return', {
        request_key: randomUUID(), quantity: String(quantity), return_reason: 'Minősítésre beérkezett visszáru',
    });
    expect(response.status()).toBe(302);
    const path = new URL(response.headers().location).pathname;
    await page.goto(path);
    return { before, path, original };
}
async function fillQuality(page, action, quantity, reason) {
    const section = quality(page);
    await section.getByLabel('Művelet', { exact: true }).selectOption(action);
    await section.getByLabel('Mennyiség', { exact: true }).fill(String(quantity));
    await section.getByLabel('Minősítés indoka', { exact: true }).fill(reason);
}
async function submitQuality(page) {
    await quality(page).getByRole('button', { name: 'Minősítés rögzítése', exact: true }).click();
}
function expectStock(actual, before, physicalDelta, quarantinedDelta) {
    expect(actual.physical_quantity).toBe(before.physical_quantity + physicalDelta);
    expect(actual.quarantined_quantity).toBe(before.quarantined_quantity + quarantinedDelta);
    expect(actual.quantity).toBe(actual.physical_quantity - actual.quarantined_quantity);
}

test('customer return is unavailable until inspection, then partial release and scrap keep separate balances', async ({ page }) => {
    const source = await quarantinedReturn(page);
    expectStock(await stock(page), source.before, 6, 6);
    expect((await stock(page)).quantity).toBe(source.before.quantity);
    await expect(quality(page).getByLabel('Mennyiség', { exact: true })).toHaveAttribute('max', '6');
    await fillQuality(page, 'release', 2, 'Két bontatlan termék ellenőrizve');
    await submitQuality(page);
    expectStock(await stock(page), source.before, 6, 4);
    await expect(quality(page)).toContainText('Két bontatlan termék ellenőrizve');
    await expect(quality(page).getByLabel('Mennyiség', { exact: true })).toHaveAttribute('max', '4');
    await fillQuality(page, 'scrap', 4, 'Négy sérült termék selejtezve');
    await submitQuality(page);
    expectStock(await stock(page), source.before, 2, 0);
    await expect(quality(page)).toContainText('Két bontatlan termék ellenőrizve');
    await expect(quality(page)).toContainText('Négy sérült termék selejtezve');
    await expect(quality(page).getByRole('button', { name: 'Minősítés rögzítése', exact: true })).toHaveCount(0);
    const scrapLink = quality(page).getByRole('link', { name: /^MOZ-/ });
    await expect(scrapLink).toHaveCount(1);
    const scrapPath = new URL(await scrapLink.getAttribute('href')).pathname;
    await scrapLink.click();
    await expect(page.getByRole('link', { name: 'Visszáru rögzítése', exact: true })).toHaveCount(0);
    expect((await page.request.get(scrapPath+'/return')).status()).toBe(404);
});

test('an unavailable customer return cannot be issued by bypassing the form', async ({ page }) => {
    const source = await quarantinedReturn(page, 3);
    const current = await stock(page);
    const response = await post(page, '/movements', {
        request_key: randomUUID(), product_id: '1', warehouse_id: '1', type: 'out',
        quantity: String(current.quantity + 1), reference: 'Blocked stock must not leave',
    }, true);
    expect(response.status()).toBe(422);
    expect((await response.json()).errors.quantity).toBeTruthy();
    expectStock(await stock(page), source.before, 3, 3);
    await fillQuality(page, 'release', 3, 'Kiadás előtt ellenőrizve');
    await submitQuality(page);
    expectStock(await stock(page), source.before, 3, 0);
});

test('replayed inspection form records one action and rejects a changed action under the same key', async ({ page }) => {
    const source = await quarantinedReturn(page, 4);
    const key = await quality(page).locator('input[name="request_key"]').inputValue();
    const data = { request_key: key, action: 'release', quantity: '4', reason: 'Azonos beküldés ismétlése' };
    expect((await post(page, source.path+'/quality', data)).status()).toBe(302);
    await fillQuality(page, 'release', 4, data.reason);
    await submitQuality(page);
    expectStock(await stock(page), source.before, 4, 0);
    await expect(quality(page).getByText(data.reason, { exact: true })).toHaveCount(1);
    const conflict = await post(page, source.path+'/quality', { ...data, action: 'scrap' }, true);
    expect(conflict.status()).toBe(422);
    expect((await conflict.json()).errors.request_key).toBeTruthy();
    expectStock(await stock(page), source.before, 4, 0);
});

test('stale inspection form reports the current remainder and preserves the submitted reason and key', async ({ page }) => {
    const source = await quarantinedReturn(page);
    const key = await quality(page).locator('input[name="request_key"]').inputValue();
    await fillQuality(page, 'scrap', 3, 'Megőrzendő minősítési indok');
    expect((await post(page, source.path+'/quality', {
        request_key: randomUUID(), action: 'release', quantity: '4', reason: 'Másik raktáros ellenőrzése',
    })).status()).toBe(302);
    await submitQuality(page);
    await expect(page.getByRole('alert')).toContainText('2 db');
    await expect(quality(page).getByLabel('Minősítés indoka', { exact: true })).toHaveValue('Megőrzendő minősítési indok');
    await expect(quality(page).locator('input[name="request_key"]')).toHaveValue(key);
    expectStock(await stock(page), source.before, 6, 2);
    await fillQuality(page, 'scrap', 2, 'Megőrzendő minősítési indok');
    await submitQuality(page);
    expectStock(await stock(page), source.before, 4, 0);
});

test('inspection works without JavaScript on mobile and escapes its history reason', async ({ page, browser }) => {
    const source = await quarantinedReturn(page, 2);
    const context = await browser.newContext({
        javaScriptEnabled: false, storageState: await page.context().storageState(),
        baseURL: new URL(page.url()).origin, viewport: { width: 320, height: 800 },
    });
    try {
        const plain = await context.newPage();
        await plain.goto(source.path);
        const reason = '<script>window.qualityInjected=1</script> '+ 'W'.repeat(450);
        await fillQuality(plain, 'scrap', 2, reason);
        expect(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await submitQuality(plain);
        await expect(quality(plain)).toContainText(reason);
        expect(await plain.evaluate(() => window.qualityInjected)).toBeUndefined();
        expect(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await plain.screenshot({ path: 'tests/Browser/.runtime/return-quality-mobile.png', fullPage: true });
        expectStock(await stock(plain), source.before, 0, 0);
    } finally { await context.close(); }
});
