import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { shipmentCommand as command } from './shipment-fixtures';

const button = (page, name) => page.getByRole('button', { name, exact: true });
const fixture = key => JSON.parse(execFileSync('php', ['tests/Browser/manual-receipt-fixture.php', key], { encoding: 'utf8' }));
const list = '/raktari-feladatok/betarolas';
const stock = async (page, data) => (await page.request.get('/movements/stock?' + new URLSearchParams({
    product_id: data.product_id, warehouse_id: data.warehouse_id, details: '1',
}))).json();

async function open(page, data, prefill = true) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await button(page, 'Log in').click();
    await expect(page).toHaveURL(/dashboard/);
    const query = prefill ? '?' + new URLSearchParams({ product_id: data.product_id, warehouse_id: data.warehouse_id }) : '';
    await page.goto('/movements/create' + query);
}

async function widths(page, { left, summary, dialog } = {}) {
    for (const width of [1440, 1024, 768, 375, 320]) {
        const height = width < 400 ? 600 : 900;
        await page.setViewportSize({ width, height });
        expect(await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth))).toBeLessThanOrEqual(width);
        if (summary) {
            const main = await left.boundingBox();
            const side = await summary.boundingBox();
            expect(side.x + side.width).toBeLessThanOrEqual(width);
            if (width >= 1024) expect(side.x).toBeGreaterThan(main.x + main.width);
            else expect(side.y).toBeGreaterThan(main.y);
        }
        if (dialog) {
            const box = await dialog.boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(0);
            expect(box.y).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
            expect(box.y + box.height).toBeLessThanOrEqual(height + 1);
        }
    }
}

async function cargo(page, quantity, weight) {
    await page.locator('[data-putaway-quantity]').fill(String(quantity));
    if (weight !== null) await page.locator('[data-putaway-weight]').fill(String(weight));
    await page.locator('[data-putaway-pallets]').fill('0');
}

async function confirmPutaway(page, site = false, responsive = false) {
    const confirmation = page.getByRole('dialog', { name: 'Betárolás megerősítése' });
    await expect(confirmation).toBeVisible();
    if (responsive) await widths(page, { dialog: confirmation });
    if (site) await confirmation.locator('[data-putaway-site-check]').check();
    await confirmation.getByLabel('Ellenőriztem, hogy a megadott mennyiséget ténylegesen ebbe a rekeszbe helyezték.').check();
    await button(confirmation, 'Betárolás rögzítése').click();
}

test('manual receipt from zero moves through the common queue, partial and complete placement', async ({ page }) => {
    test.setTimeout(65_000);
    const data = fixture('FLOW');
    await open(page, data, false);
    await page.getByRole('combobox', { name: 'Termék', exact: true }).fill(data.sku);
    await page.getByRole('option', { name: new RegExp(data.sku) }).click();
    await page.getByRole('combobox', { name: 'Raktár', exact: true }).fill('Bevételezési raktár FLOW');
    await page.getByRole('option', { name: /Bevételezési raktár FLOW/ }).click();
    await page.getByLabel('Mennyiség (db)', { exact: true }).fill('200');
    await button(page, 'Doboz').click();
    await expect(page.getByLabel('Mennyiség (doboz)', { exact: true })).toHaveValue('20');
    const summary = page.getByRole('complementary', { name: 'Bevételezés összegzése' });
    await expect(summary).toContainText('Várható fizikai készlet200 db');
    await expect(summary).toContainText(data.sku);
    await widths(page, { left: page.locator('form fieldset').filter({ has: page.locator('#movement-product') }), summary });
    await button(page, 'Bevételezés ellenőrzése').click();
    const review = page.getByRole('dialog', { name: 'Bevételezés megerősítése' });
    await expect(review).toContainText('0 db → 200 db');
    await widths(page, { dialog: review });
    await page.keyboard.press('Escape');
    await expect(button(page, 'Bevételezés ellenőrzése')).toBeFocused();
    await button(page, 'Bevételezés ellenőrzése').click();
    await button(page, 'Bevételezés véglegesítése').click();
    await expect(page.getByText('200 db sikeresen bevételezve.')).toBeVisible();
    const work = page.getByRole('link', { name: 'Betárolás megkezdése' });
    await expect(work).toHaveAttribute('href', /\/raktari-feladatok\/betarolas\/kezi\/\d+$/);
    await work.click();
    await expect(page.locator('[data-app-topbar]').getByRole('link', { name: 'Betárolás', exact: true }))
        .toHaveAttribute('aria-current', 'page');
    await expect(page.locator('[data-putaway-remaining]')).toHaveText('200 db');
    await expect.poll(() => stock(page, data)).toMatchObject({ physical_quantity: 200, pending_putaway_quantity: 200, pickable_quantity: 0 });
    await widths(page);
    const task = await page.getByRole('link', { name: /^MOZ-.* bevételezés/ }).textContent();
    await page.goto(list + '?' + new URLSearchParams({ origin: 'manual', search: task.trim().split(' ')[0] }));
    const row = page.locator('[data-putaway-task-row]');
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('Kézi bevételezés');
    await row.getByRole('link').filter({ hasText: 'Kézi bevételezési próba' }).click();

    await cargo(page, 120, null);
    await page.getByLabel(/A részlet egész, bontatlan csomagokból áll/).check();
    await button(page, 'Ajánlások keresése').click();
    const first = page.locator('[data-putaway-location="A-01"]');
    await expect(first).toContainText('Feltételes');
    await expect(first.locator('[data-putaway-stock="bin"]')).toHaveText('Rekeszkészlet: 0 → 120 db');
    await first.getByText('Részletek', { exact: true }).click();
    await expect(first.getByRole('link', { name: /Megtekintés az alaprajzon/ })).toBeVisible();
    await expect(first.getByRole('link', { name: /Megtekintés az alaprajzon/ })).toHaveAttribute('href', /\/alaprajz\/\d+$/);
    await button(first, 'Kiválasztás').click();
    await page.getByText('Külső méretek (cm)', { exact: true }).click();
    await page.getByLabel('Szélesség (cm)').fill('101');
    await button(page, 'Betárolás ellenőrzése').click();
    await expect(page.getByText(/meghaladja a tárhely méretét/).filter({ visible: true })).toBeVisible();
    await expect(page.getByText('Kiválasztott rekesz A-01')).toBeVisible();
    await page.getByLabel('Szélesség (cm)').fill('25');
    await button(page, 'Betárolás ellenőrzése').click();
    await confirmPutaway(page, true, true);
    await expect(page.locator('[data-putaway-success]')).toContainText('Hátralévő: 80 db.');
    await expect.poll(() => stock(page, data)).toMatchObject({ physical_quantity: 200, assigned_quantity: 120, pending_putaway_quantity: 80 });
    await button(page, 'További betárolás').click();
    await cargo(page, 80, 8);
    await button(page, 'Kézi rekeszkeresés').click();
    await page.getByRole('searchbox', { name: 'Rekesz keresése' }).fill('A-02');
    await button(page, 'Keresés').click();
    const second = page.locator('[data-putaway-location="A-02"]');
    await expect(second.locator('[data-putaway-stock="bin"] strong')).toHaveText('0 → 80 db');
    await button(second, 'Kiválasztás').click();
    await button(page, 'Betárolás ellenőrzése').click();
    await confirmPutaway(page, true);
    await expect(page.locator('[data-putaway-success]')).toContainText('A bevételezés teljes mennyisége betárolva.');
    await expect(page.locator('[data-putaway-history] li')).toHaveCount(2);
    await expect(page.locator('[data-putaway-quantity]')).toBeHidden();
    await expect.poll(() => stock(page, data)).toMatchObject({ physical_quantity: 200, assigned_quantity: 200, pending_putaway_quantity: 0 });
    await page.getByRole('link', { name: /^MOZ-.* bevételezés/ }).click();
    const progress = page.getByRole('region', { name: 'A bevételezés betárolása' });
    await expect(progress).toContainText('Teljesen betárolva');
    await expect(progress).toContainText('A-01 · 120 db');
    await expect(progress).toContainText('A-02 · 80 db');
    await expect(progress.getByRole('link', { name: 'Betárolás folytatása' })).toHaveCount(0);
    await progress.getByRole('link', { name: 'Betárolási előzmények' }).click();
    expect(new URL(page.url()).hash).toBe('#putaway-history-title');
});

test('receipt retry books once and a stale putaway draft refreshes without silently moving the target', async ({ page }) => {
    test.setTimeout(60_000);
    const data = fixture('RETRY');
    await page.setViewportSize({ width: 375, height: 667 });
    await open(page, data);
    await page.getByLabel('Mennyiség (db)', { exact: true }).fill('20');
    await expect(page.getByRole('complementary', { name: 'Bevételezés összegzése' })).toContainText('Várható fizikai készlet70 db');
    const keys = [];
    await page.route('**/movements', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        keys.push(route.request().postDataJSON().request_key);
        if (keys.length > 1) return route.continue();
        await route.fetch();
        await route.abort();
    });
    await button(page, 'Bevételezés ellenőrzése').click();
    await button(page, 'Bevételezés véglegesítése').evaluate(button => { button.click(); button.click(); });
    await button(page, 'Mentés újrapróbálása').click();
    await expect(page.getByText('20 db sikeresen bevételezve.')).toBeVisible();
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
    await page.getByRole('link', { name: 'Később tárolom be' }).click();
    await expect(page.getByRole('region', { name: 'A bevételezés betárolása' })).toContainText('Betárolásra vár20 db');
    const resume = page.getByRole('link', { name: 'Betárolás folytatása' });
    await expect(resume).toHaveAttribute('href', /\/raktari-feladatok\/betarolas\/kezi\/\d+$/);
    await resume.click();
    await cargo(page, 5, 1);
    await button(page, 'Ajánlások keresése').click();
    await button(page.locator('[data-putaway-location="A-01"]'), 'Kiválasztás').click();
    const id = new URL(page.url()).pathname.split('/').at(-1);
    await command(page, 'POST', '/movements/in/receipts/' + id + '/putaway', {
        confirmed: true, version: 1, operation_key: randomUUID(), quantity: 5, to_location_id: data.bins['A-02'],
        cargo_weight_kg: 1, pallet_slots: 0, site_verified: true,
    }, 201);
    await button(page, 'Betárolás ellenőrzése').click();
    await button(page, 'Tétel frissítése').click();
    await expect(page.locator('[data-putaway-remaining]')).toHaveText('15 db');
    await expect(page.getByText('Kiválasztott rekesz A-01')).toBeVisible();
    await expect(page.getByRole('dialog', { name: 'Betárolás megerősítése' })).toBeHidden();
    await expect.poll(() => stock(page, data)).toMatchObject({ physical_quantity: 70, assigned_quantity: 5, pending_putaway_quantity: 15 });
});
