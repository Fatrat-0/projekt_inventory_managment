import { test, expect } from '@playwright/test';

test('warehouse statistics filter charts and preview and download a customized PDF for the selected warehouse', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);

    await page.goto('/warehouses?search=Teszt');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const location = await page.request.post('/warehouses/3/locations', {
        headers: { 'X-CSRF-TOKEN': csrf },
        form: { code: 'STAT-01', name: 'Statisztika próbahely', kind: 'pallet', max_weight_kg: '80', max_pallet_slots: '4' },
        maxRedirects: 0,
    });
    expect(location.headers().location).toMatch(/\/warehouses\/3\/locations\/\d+$/);

    const navigation = page.getByRole('navigation', { name: 'Raktármodul nézetei' });
    const list = navigation.getByRole('link', { name: 'Raktárlista' });
    const statistics = navigation.getByRole('link', { name: 'Statisztika' });
    await expect(list).toHaveAttribute('aria-current', 'page');
    await statistics.click();
    await expect(statistics).toHaveAttribute('aria-current', 'page');

    const weight = page.getByRole('region', { name: 'Terhelési kapacitás' });
    const pallets = page.getByRole('region', { name: 'Raklapférőhely' });
    const stock = page.getByRole('region', { name: 'Helyi készlet összetétele' });
    await expect(page.locator('[data-statistics-charts] svg')).toHaveCount(3);
    await expect(weight).toContainText(/raktár adatai/);
    await expect(weight).toContainText('Teljes kapacitás');
    await expect(pallets).toContainText('Teljes kapacitás');
    const warehouseFilter = page.getByRole('combobox', { name: 'Raktár', exact: true });
    await expect(page.getByRole('button', { name: 'Megjelenítés' })).toHaveCount(0);
    await warehouseFilter.selectOption('3');
    await expect(page.getByRole('link', { name: 'Raktár adatlapja', exact: true })).toHaveAttribute('href', /\/warehouses\/3\b/);
    await expect(weight.locator('[data-statistics-ring] strong')).toHaveText('0%');
    await expect(weight.locator('dd').last()).toHaveText('80 kg');
    await expect(pallets.locator('dd').last()).toHaveText('4 hely');
    await expect(weight.locator('circle[stroke-dasharray]')).toHaveAttribute('stroke-dasharray', '100,100');

    await warehouseFilter.selectOption('1');
    await expect(weight.locator('[data-statistics-ring]')).toHaveText('Nincs adat');
    await expect(weight).toContainText('Nincs aktív tárhelyhez beállított terhelési kapacitás.');
    await expect(pallets).toContainText('Nincs aktív raklaphely.');
    await expect(stock).toContainText('Tárhely nélkül kiadható');

    await page.setViewportSize({ width: 320, height: 760 });
    await expect(statistics).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);

    await warehouseFilter.selectOption('3');
    const pdf = page.getByRole('button', { name: 'Statisztika PDF', exact: true });
    await pdf.click();
    const dialog = page.getByRole('dialog', { name: 'Raktári statisztika PDF-előnézete' });
    const frame = dialog.locator('iframe');
    await expect(dialog).toBeVisible();
    await expect(frame).toHaveAttribute('src', /warehouse_id=3/);
    await expect(frame).toHaveAttribute('src', /preview=1/);
    expect(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
    await dialog.getByRole('button', { name: 'Fekvő (A4)', exact: true }).click();
    await dialog.getByLabel('Raklapférőhely', { exact: true }).uncheck();
    await expect(frame).toHaveAttribute('src', /orientation=landscape/);
    await expect(frame).toHaveAttribute('src', /show_pallets=0/);
    const download = await dialog.getByRole('link', { name: 'PDF letöltése' }).getAttribute('href');
    const url = new URL(download);
    expect(Object.fromEntries(url.searchParams)).toMatchObject({ warehouse_id: '3', orientation: 'landscape', show_pallets: '0' });
    expect(url.searchParams.has('preview')).toBe(false);
    const response = await page.request.get(download);
    expect(response.ok()).toBe(true);
    expect(response.headers()['content-disposition']).toMatch(/^attachment;/);
    expect((await response.body()).subarray(0, 5).toString()).toBe('%PDF-');
    await dialog.getByRole('button', { name: 'Alapbeállítások visszaállítása', exact: true }).click();
    await expect(frame).toHaveAttribute('src', /show_pallets=1/);
    await expect(frame).toHaveAttribute('src', /orientation=portrait/);
    await dialog.getByRole('button', { name: 'Előnézet bezárása' }).click();
    await expect(pdf).toBeFocused();

    await warehouseFilter.selectOption('');
    await expect(weight).toContainText(/raktár adatai/);
    await list.click();
    await expect(page).toHaveURL(url => url.pathname === '/warehouses' && url.searchParams.get('search') === 'Teszt');
});
