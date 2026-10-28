import { test, expect } from '@playwright/test';
import { writeFile } from 'node:fs/promises';

async function quantity(page, product = 1, warehouse = 1) {
    const response = await page.request.get(`/movements/stock?product_id=${product}&warehouse_id=${warehouse}`);
    expect(response.ok()).toBe(true);
    return (await response.json()).quantity;
}
async function draft(page, reference, excessive = false) {
    await page.goto('/shipments/create');
    await page.getByLabel('Szállítmány hivatkozása', { exact: true }).fill(reference);
    await page.getByLabel('Jármű / rendszám (opcionális)').fill('E2E-123');
    await page.getByRole('combobox', { name: 'Induló raktár', exact: true }).fill('Teszt raktár');
    await page.getByRole('listbox', { name: 'Raktártalálatok', exact: true }).getByRole('option', { name: 'Teszt raktár', exact: true }).click();
    for (const [index, warehouse, product] of [[0, '2', '1'], [1, '3', '2'], [2, '4', '1']]) {
        if (index) await page.getByRole('button', { name: '+ Megálló hozzáadása', exact: true }).click();
        const stop = page.locator('fieldset').nth(index);
        await stop.getByLabel('Célraktár', { exact: true }).selectOption(warehouse);
        await stop.getByLabel('Termék', { exact: true }).selectOption(product);
        await stop.getByLabel('Mennyiség (db)', { exact: true }).fill(excessive ? '999' : '1');
    }
    return page.getByRole('region', { name: 'Rakodási sorrend előnézete' });
}
test.beforeEach(async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});

test('shipment builder reorders stops and produces reverse loading order without touching stock', async ({ page }) => {
    const before = await quantity(page);
    const preview = await draft(page, 'E2E-Rakodási sorrend');
    await expect(preview.getByRole('listitem')).toHaveText([
        /1\. felrakodás: Nyugati tesztraktár.*3\. lerakodási megálló/,
        /2\. felrakodás: Déli tesztraktár.*2\. lerakodási megálló/,
        /3\. felrakodás: Északi tesztraktár.*1\. lerakodási megálló/,
    ]);
    await page.getByRole('button', { name: '1. megálló hátrébb', exact: true }).click();
    await expect(page.locator('fieldset').nth(0).getByLabel('Célraktár', { exact: true })).toHaveValue('3');
    await expect(page.locator('fieldset').nth(0).getByLabel('Termék', { exact: true })).toHaveValue('2');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await expect(page).toHaveURL(/\/shipments\/\d+$/);
    const loading = page.getByRole('list', { name: 'Felrakodási sorrend' });
    await expect(loading.getByRole('listitem').filter({ has: page.locator('strong') })).toHaveCount(3);
    await expect(loading.locator(':scope > li').nth(2)).toContainText('Déli tesztraktár');
    expect(await quantity(page)).toBe(before);
    await page.screenshot({ path: 'tests/Browser/.runtime/shipment-loading-plan.png', fullPage: true });
    // Check the actual PDF through this authenticated browser session. The desktop
    // sandbox may cancel Chrome's native download manager independently of the response.
    const pdf = await page.request.get(await page.getByRole('link', { name: 'Rakodólista (PDF)', exact: true }).getAttribute('href'));
    expect(pdf.ok()).toBe(true);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect(pdf.headers()['content-disposition']).toMatch(/attachment;.*SZALL-\d+-rakodasi-terv\.pdf/);
    const bytes = await pdf.body();
    expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
    await writeFile('tests/Browser/.runtime/loading-plan.pdf', bytes);
    await page.getByRole('link', { name: 'Terv szerkesztése', exact: true }).click();
    await expect(page.locator('fieldset').nth(0).getByLabel('Célraktár', { exact: true })).toHaveValue('3');
    await expect(page.locator('fieldset').nth(0).getByLabel('Termék', { exact: true })).toHaveValue('2');
});

test('shipment can start once and be received stop by stop', async ({ page }) => {
    const before = await quantity(page);
    const targetBefore = await quantity(page, 1, 2);
    await draft(page, 'E2E-Teljesített útvonal');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    const path = new URL(page.url()).pathname;
    const csrf = await page.getByRole('main').locator('input[name="_token"]').first().inputValue();
    await page.getByRole('button', { name: 'Szállítmány indítása', exact: true }).click();
    await expect(page.getByText('Úton', { exact: true })).toBeVisible();
    expect(await quantity(page)).toBe(before - 2);
    const replay = await page.request.post(path+'/start', { form: { _token: csrf, version: '1' } });
    expect(replay.ok()).toBe(true);
    expect(await quantity(page)).toBe(before - 2);
    await expect(page.getByRole('link', { name: 'Terv szerkesztése', exact: true })).toHaveCount(0);
    for (let i = 0; i < 3; i++) await page.getByRole('button', { name: 'Megálló átvétele', exact: true }).click();
    await expect(page.getByText('Teljesítve', { exact: true })).toBeVisible();
    expect(await quantity(page, 1, 2)).toBe(targetBefore + 1);
    await expect(page.getByRole('button', { name: 'Megálló átvétele', exact: true })).toHaveCount(0);
});

test('insufficient shipment stock keeps the entire shipment as a draft', async ({ page }) => {
    const before = await quantity(page);
    await draft(page, 'E2E-Nincs elegendő készlet', true);
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await page.getByRole('button', { name: 'Szállítmány indítása', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Nincs elegendő készlet');
    await expect(page.getByText('Tervezet', { exact: true })).toBeVisible();
    expect(await quantity(page)).toBe(before);
    await expect(page.getByRole('link', { name: 'Terv szerkesztése', exact: true })).toBeVisible();
});

test('partial cancellation returns the remaining cargo and leaves received stock at the destination', async ({ page }) => {
    const before = await quantity(page);
    const targetBefore = await quantity(page, 1, 2);
    const zetaBefore = await quantity(page, 2, 1);
    await draft(page, 'E2E-Részleges visszavonás');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await page.getByRole('button', { name: 'Szállítmány indítása', exact: true }).click();
    await page.getByRole('button', { name: 'Megálló átvétele', exact: true }).click();
    await page.getByText('Szállítmány visszavonása', { exact: true }).click();
    await page.getByRole('button', { name: 'Visszavonás rögzítése', exact: true }).click();
    await expect(page.getByText('Visszavonva', { exact: true })).toBeVisible();
    expect(await quantity(page)).toBe(before - 1);
    expect(await quantity(page, 1, 2)).toBe(targetBefore + 1);
    expect(await quantity(page, 2, 1)).toBe(zetaBefore);
});

test('shipment form preserves rows on duplicate destination validation and fits mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await draft(page, 'E2E-Hibás útvonal');
    await page.locator('fieldset').nth(1).getByLabel('Célraktár', { exact: true }).selectOption('2');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.locator('fieldset')).toHaveCount(3);
    await expect(page.getByLabel('Szállítmány hivatkozása', { exact: true })).toHaveValue('E2E-Hibás útvonal');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/shipment-builder-mobile.png', fullPage: true });
});

test('editing product rows and changing origin keep the remaining route and quantities consistent', async ({ page }) => {
    await draft(page, 'E2E-Terméksorok és forrásváltás');
    const first = page.locator('fieldset').nth(0);
    await first.getByRole('button', { name: '+ Termék hozzáadása', exact: true }).click();
    await first.getByLabel('Termék', { exact: true }).nth(1).selectOption('2');
    await first.getByLabel('Mennyiség (db)', { exact: true }).nth(1).fill('7');
    await first.getByRole('button', { name: '1. termék törlése a(z) 1. megállóból', exact: true }).click();
    await expect(first.getByLabel('Termék', { exact: true })).toHaveValue('2');
    await expect(first.getByLabel('Mennyiség (db)', { exact: true })).toHaveValue('7');

    await page.getByRole('combobox', { name: 'Induló raktár', exact: true }).fill('Északi');
    await page.getByRole('listbox', { name: 'Raktártalálatok', exact: true }).getByRole('option', { name: 'Északi tesztraktár', exact: true }).click();
    await expect(first.getByLabel('Célraktár', { exact: true })).toHaveValue('');
    await expect(first.getByLabel('Célraktár', { exact: true }).locator('option[value="2"]')).toBeDisabled();
    await first.getByLabel('Célraktár', { exact: true }).selectOption('1');
    await page.getByRole('button', { name: '2. megálló törlése', exact: true }).click();
    await expect(page.locator('fieldset')).toHaveCount(2);
    await expect(page.locator('fieldset').nth(1).getByLabel('Célraktár', { exact: true })).toHaveValue('4');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await expect(page).toHaveURL(/\/shipments\/\d+$/);
    await page.getByRole('link', { name: 'Terv szerkesztése', exact: true }).click();
    await expect(page.locator('fieldset')).toHaveCount(2);
    await expect(page.getByRole('combobox', { name: 'Induló raktár', exact: true })).toHaveValue('Északi tesztraktár');
    await expect(page.locator('fieldset').nth(0).getByLabel('Termék', { exact: true })).toHaveValue('2');
    await expect(page.locator('fieldset').nth(0).getByLabel('Mennyiség (db)', { exact: true })).toHaveValue('7');
});
