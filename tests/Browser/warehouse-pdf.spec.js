import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});

test('warehouse PDF preserves applied filters, validates real PDF and supports blind count and reset', async ({ page }) => {
    await page.goto('/warehouses/1?stock=all&search=Alpha&sort_by=quantity&sort_dir=desc&per_page=25');
    const trigger = page.getByRole('button', { name: 'PDF-leltárív', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Raktári leltárív előnézete', exact: true });
    await expect(dialog).toBeVisible();
    const frame = dialog.locator('iframe');
    await expect(frame).toHaveAttribute('src', /preview=1/);
    const url = new URL(await frame.getAttribute('src'));
    expect(url.pathname).toBe('/warehouses/1/inventory.pdf');
    for (const [key, value] of Object.entries({ search: 'Alpha', stock: 'all', sort_by: 'quantity', sort_dir: 'desc' })) expect(url.searchParams.get(key)).toBe(value);
    for (const key of ['page', 'per_page', 'context']) expect(url.searchParams.has(key)).toBe(false);
    await dialog.getByRole('button', { name: 'Fekvő (A4)', exact: true }).click();
    await expect(frame).toHaveAttribute('src', /orientation=landscape/);
    await dialog.getByLabel('Vakleltár – mennyiségek elrejtése', { exact: true }).check();
    await expect(frame).toHaveAttribute('src', /blind_mode=1/);
    await expect(dialog.getByLabel('Számolt mennyiség – üres oszlop', { exact: true })).toBeChecked();
    await expect(dialog.getByLabel('Számolt mennyiség – üres oszlop', { exact: true })).toBeDisabled();
    const response = await page.request.get(await dialog.getByRole('link', { name: 'PDF letöltése', exact: true }).getAttribute('href'));
    expect(response.ok()).toBe(true);
    expect(response.headers()['content-type']).toContain('application/pdf');
    expect(response.headers()['content-disposition']).toContain('attachment');
    expect((await response.body()).subarray(0, 5).toString()).toBe('%PDF-');
    await dialog.getByRole('button', { name: 'Alapbeállítások visszaállítása', exact: true }).click();
    await expect(frame).toHaveAttribute('src', /orientation=portrait/);
    await expect(dialog.getByLabel('Vakleltár – mennyiségek elrejtése', { exact: true })).not.toBeChecked();
    await expect(dialog.getByLabel('Számolt mennyiség – üres oszlop', { exact: true })).toBeEnabled();
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await expect(frame).toHaveCount(0);
});

test('mobile PDF settings collapse releases preview space and dialog fits viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/warehouses/1');
    await page.getByRole('button', { name: 'PDF-leltárív', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Raktári leltárív előnézete', exact: true });
    const frame = dialog.locator('iframe');
    const before = await frame.boundingBox();
    await dialog.getByRole('button', { name: 'Beállítások elrejtése', exact: true }).click();
    await expect(dialog.locator('aside')).not.toBeVisible();
    expect((await frame.boundingBox()).height).toBeGreaterThan(before.height + 100);
    const box = await dialog.boundingBox();
    expect(box.width).toBeLessThanOrEqual(390);
    expect(box.height).toBeLessThanOrEqual(844);
    expect(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
    await dialog.getByRole('button', { name: 'Előnézet bezárása', exact: true }).click();
    await expect(dialog).not.toBeVisible();
});
