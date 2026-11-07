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
    const navigation = page.getByRole('navigation', { name: 'Raktári nézetek' });
    const trigger = navigation.getByRole('link', { name: 'PDF-leltárív', exact: true });
    await trigger.click();
    await expect(page).toHaveURL(/\/warehouses\/1\/inventory-preview/);
    await expect(trigger).toHaveAttribute('aria-current', 'page');
    const preview = page.getByRole('region', { name: 'Raktári leltárív előnézete', exact: true });
    await expect(preview).toBeVisible();
    const frame = preview.locator('iframe');
    await expect(frame).toHaveAttribute('src', /preview=1/);
    const url = new URL(await frame.getAttribute('src'));
    expect(url.pathname).toBe('/warehouses/1/inventory.pdf');
    for (const [key, value] of Object.entries({ search: 'Alpha', stock: 'all', sort_by: 'quantity', sort_dir: 'desc' })) expect(url.searchParams.get(key)).toBe(value);
    for (const key of ['page', 'per_page', 'context']) expect(url.searchParams.has(key)).toBe(false);
    await preview.getByRole('button', { name: 'Fekvő (A4)', exact: true }).click();
    await expect(frame).toHaveAttribute('src', /orientation=landscape/);
    await preview.getByLabel('Vakleltár – mennyiségek elrejtése', { exact: true }).check();
    await expect(frame).toHaveAttribute('src', /blind_mode=1/);
    await expect(preview.getByLabel('Számolt mennyiség – üres oszlop', { exact: true })).toBeChecked();
    await expect(preview.getByLabel('Számolt mennyiség – üres oszlop', { exact: true })).toBeDisabled();
    const response = await page.request.get(await preview.getByRole('link', { name: 'PDF letöltése', exact: true }).getAttribute('href'));
    expect(response.ok()).toBe(true);
    expect(response.headers()['content-type']).toContain('application/pdf');
    expect(response.headers()['content-disposition']).toContain('attachment');
    expect((await response.body()).subarray(0, 5).toString()).toBe('%PDF-');
    await preview.getByRole('button', { name: 'Alapbeállítások visszaállítása', exact: true }).click();
    await expect(frame).toHaveAttribute('src', /orientation=portrait/);
    await expect(preview.getByLabel('Vakleltár – mennyiségek elrejtése', { exact: true })).not.toBeChecked();
    await expect(preview.getByLabel('Számolt mennyiség – üres oszlop', { exact: true })).toBeEnabled();

    const locations = navigation.getByRole('link', { name: 'Tárhelyek és kapacitás', exact: true });
    await locations.click();
    await expect(locations).toHaveAttribute('aria-current', 'page');
    await trigger.click();
    await expect(frame).toHaveAttribute('src', /search=Alpha/);
    await navigation.getByRole('link', { name: 'Raktári áttekintés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/warehouses/1' && url.searchParams.get('search') === 'Alpha' && url.searchParams.get('per_page') === '25');
});

test('mobile PDF settings collapse releases preview space while the navigation remains available', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/warehouses/1');
    const navigation = page.getByRole('navigation', { name: 'Raktári nézetek' });
    await navigation.getByRole('link', { name: 'PDF-leltárív', exact: true }).click();
    const preview = page.getByRole('region', { name: 'Raktári leltárív előnézete', exact: true });
    const frame = preview.locator('iframe');
    const before = await frame.boundingBox();
    await preview.locator('aside').getByRole('button', { name: 'Beállítások elrejtése', exact: true }).click();
    await expect(preview.locator('aside')).not.toBeVisible();
    await expect(preview.locator('header').getByRole('button', { name: 'PDF testreszabása', exact: true })).toBeFocused();
    expect((await frame.boundingBox()).height).toBeGreaterThan(before.height + 100);
    const box = await preview.boundingBox();
    expect(box.width).toBeLessThanOrEqual(390);
    expect(box.height).toBeLessThanOrEqual(844);
    expect(await preview.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
    await navigation.getByRole('link', { name: 'Tárhelyek és kapacitás', exact: true }).click();
    await expect(page).toHaveURL(/\/warehouses\/1\/locations/);
});
