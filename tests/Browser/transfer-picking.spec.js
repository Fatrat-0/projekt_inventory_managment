import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function choose(page, label, query, option) {
    await page.getByRole('combobox', { name: label, exact: true }).fill(query);
    await page.getByRole('option', { name: option, exact: true }).click();
}

test('a transfer picks partial quantities from multiple bins and unassigned stock', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);

    const headers = {
        'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content'),
        Accept: 'application/json',
    };
    const post = async (path, data) => {
        const result = await page.request.post(path, { headers, data, maxRedirects: 0 });
        expect(result.status(), await result.text()).toBe(302);
        return result.headers().location;
    };
    const warehouse = async name => {
        await page.goto(await post('/warehouses', { warehouse_name: name, location: 'Teszt' }));
        const link = await page.getByRole('link', { name, exact: true }).first().getAttribute('href');
        return new URL(link, page.url()).pathname;
    };
    const sourceName = 'Kitárolási próbaraktár';
    const targetName = 'Átvételi próbaraktár';
    const sourcePath = await warehouse(sourceName);
    await warehouse(targetName);
    const sourceWarehouse = Number(sourcePath.split('/').at(-1));

    const productName = 'Rekeszes próbaáru';
    const sku = 'PICK-' + randomUUID().slice(0, 8);
    await post('/products', { product_name: productName, sku, category_id: 1, price: 1 });
    const products = await (await page.request.get('/products?search=' + sku)).text();
    const productId = Number(products.match(/href="[^"]*\/products\/(\d+)\/edit/)[1]);
    await post('/movements', { request_key: randomUUID(), product_id: productId, warehouse_id: sourceWarehouse, quantity: 40, type: 'in' });

    for (const [code, quantity] of [['PICK-A', 20], ['PICK-B', 15]]) {
        const location = await post(sourcePath + '/locations', { code, name: code + ' rekesz', kind: 'shelf', max_weight_kg: 100 });
        await post(new URL(location).pathname + '/placements', {
            request_key: randomUUID(), product_id: productId, quantity, cargo_weight_kg: quantity,
        });
    }

    await page.setViewportSize({ width: 320, height: 740 });
    await page.goto('/transfers/create');
    await choose(page, 'Termék', sku, `${productName} (${sku})`);
    await choose(page, 'Forrásraktár', sourceName, `${sourceName} · Teszt`);
    await choose(page, 'Célraktár', targetName, `${targetName} · Teszt`);

    const section = page.getByRole('region', { name: 'Forráshelyek – kitárolás' });
    const binA = section.getByRole('textbox', { name: /Kitárolandó mennyiség \(db\) – PICK-A/ });
    const binB = section.getByRole('textbox', { name: /Kitárolandó mennyiség \(db\) – PICK-B/ });
    const loose = section.getByRole('textbox', { name: 'Kitárolandó mennyiség (db) – Tárhely nélküli készlet' });
    const start = page.getByRole('button', { name: 'Átmozgatás indítása', exact: true });
    const summary = page.locator('[data-transfer-summary-picking]');
    const quantityInput = page.getByLabel('Mennyiség (db)', { exact: true });
    await quantityInput.fill('');
    await expect(section).toBeHidden();
    await quantityInput.fill('25');
    await expect(section).toContainText('20 db tárolt');
    await expect(section).toContainText('0 db foglalt');
    await expect(section).toContainText('5 db kiadható');
    await expect(start).toBeDisabled();
    await section.getByRole('button', { name: 'Kitárolási javaslat alkalmazása' }).click();
    await expect(loose).toHaveValue('5');
    await expect(binA).toHaveValue('20');
    await expect(binB).toHaveValue('');
    await expect(summary).toHaveText('1 rekesz + tárhely nélkül · 25 db');

    await binA.fill('21');
    await expect(binA).toHaveAttribute('aria-invalid', 'true');
    await expect(start).toBeDisabled();
    await binA.fill('16');
    await expect(section).toContainText('21 / 25 db kitárolásra');
    await expect(start).toBeDisabled();
    await binB.fill('4');
    await expect(summary).toHaveText('2 rekesz + tárhely nélkül · 25 db');
    await expect(start).toBeEnabled();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);

    await start.click();
    await expect(page.getByRole('status')).toContainText('Átmozgatás elindítva');
    const details = page.getByRole('link', { name: productName, exact: true }).first();
    await details.click();
    const history = page.getByRole('region', { name: 'Kitárolási forráshelyek' });
    await expect(history.getByText('16 db', { exact: true })).toBeVisible();
    await expect(history.getByText('4 db', { exact: true })).toBeVisible();
    await expect(history.getByText('5 db', { exact: true })).toBeVisible();

    const snapshot = await page.request.get(`/transfers/picking-sources?from_warehouse_id=${sourceWarehouse}&product_id=${productId}`);
    expect(snapshot.ok()).toBe(true);
    const state = await snapshot.json();
    expect(state.available_quantity).toBe(15);
    expect(state.sources.find(row => row.code === 'PICK-A').stored_quantity).toBe(4);
    expect(state.sources.find(row => row.code === 'PICK-B').stored_quantity).toBe(11);
    expect(pageErrors).toEqual([]);
});
