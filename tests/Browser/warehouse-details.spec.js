import { test, expect } from '@playwright/test';

async function post(page, path, data) {
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const response = await page.request.post(path, { headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, form: data });
    expect(response.ok(), await response.text()).toBe(true);
    return response;
}
async function warehouse(page, name) {
    await page.goto('/warehouses?search='+encodeURIComponent(name));
    await post(page, '/warehouses', { warehouse_name: name, location: 'Teszt cím' });
    await page.reload();
    return (await page.locator('tr[data-warehouse-id]').first().getAttribute('data-warehouse-id'));
}
async function movement(page, warehouseId, product, quantity, type = 'in') {
    await post(page, '/movements', { request_key: crypto.randomUUID(), product_id: product, warehouse_id: warehouseId, type, quantity, reference: 'DETAILS-BROWSER' });
}
async function transfer(page, from, to, product, quantity) {
    await post(page, '/transfers', { request_key: crypto.randomUUID(), product_id: product, from_warehouse_id: from, to_warehouse_id: to, quantity });
}

test.beforeEach(async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});

test('warehouse details display local stock and separate incoming and already-deducted outgoing transfers', async ({ page }) => {
    const local = await warehouse(page, 'DETAILS-LOCAL-A');
    const other = await warehouse(page, 'DETAILS-LOCAL-B');
    await movement(page, local, '1', 3);
    await movement(page, other, '1', 9);
    await movement(page, other, '2', 6);
    await transfer(page, other, local, '2', 2);
    await transfer(page, local, other, '1', 1);
    await page.goto('/warehouses?search=DETAILS-LOCAL-A&per_page=25');
    await page.getByRole('link', { name: 'DETAILS-LOCAL-A', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/warehouses/'+local);
    await expect(page.locator('[data-summary="local"]')).toContainText('2 db');
    await expect(page.locator('[data-summary="products"]')).toHaveText('1');
    await expect(page.locator('[data-summary="incoming"]')).toContainText('2 db');
    await expect(page.locator('[data-summary="outgoing"]')).toContainText('1 db');
    await expect(page.locator('tr[data-stock-product-id="1"]')).toContainText('2 db');
    await expect(page.locator('tr[data-stock-product-id="2"]')).toHaveCount(0);
    const incoming = page.getByRole('region', { name: 'Beérkező átmozgatások', exact: true });
    await expect(incoming).toContainText('Zeta teszttermék');
    await expect(incoming).toContainText('DETAILS-LOCAL-B');
    await page.screenshot({ path: 'tests/Browser/.runtime/warehouse-details-desktop.png', fullPage: true });
    await incoming.getByRole('link', { name: 'Összes (1)', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/transfers' && url.searchParams.get('to_warehouse_id') === local && url.searchParams.get('status') === 'pending');
});

test('local suggestions, automatic stock filters and editing preserve detail and warehouse list state', async ({ page }) => {
    const local = await warehouse(page, 'DETAILS-FILTER-A');
    await movement(page, local, '1', 3);
    await movement(page, local, '2', 1);
    await movement(page, local, '2', 1, 'out');
    await page.goto('/warehouses?search=DETAILS-FILTER&sort_by=location&sort_dir=desc&per_page=25');
    await page.getByRole('link', { name: 'DETAILS-FILTER-A', exact: true }).click();
    const stocks = page.getByRole('region', { name: /^Helyi készlet/ });
    await stocks.getByLabel('Készlet', { exact: true }).selectOption('zero');
    await expect(page.locator('tr[data-stock-product-id="2"]')).toContainText('0 db');
    await expect(page.locator('tr[data-stock-product-id="1"]')).toHaveCount(0);
    await expect(page.locator('[data-summary="local"]')).toContainText('3 db');
    await stocks.getByLabel('Készlet', { exact: true }).selectOption('all');
    await expect(page.locator('tr[data-stock-product-id]')).toHaveCount(2);
    const search = page.getByRole('combobox', { name: 'Helyi termékek keresése', exact: true });
    await search.fill('zeta');
    await page.getByRole('listbox', { name: 'Helyi termékjavaslatok', exact: true }).getByRole('option', { name: 'Zeta teszttermék ZETA-002', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('product_id') === '2');
    await page.getByLabel('Helyi találatok oldalanként', { exact: true }).selectOption('25');
    await expect(page).toHaveURL(url => url.searchParams.get('per_page') === '25');
    await page.getByRole('link', { name: 'Raktár szerkesztése', exact: true }).click();
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/warehouses/'+local && url.searchParams.get('product_id') === '2');
    await page.getByRole('link', { name: 'Raktár szerkesztése', exact: true }).click();
    await page.getByLabel('Raktár neve', { exact: true }).fill('DETAILS-FILTER-A frissített');
    await page.getByRole('button', { name: 'Raktár mentése', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'DETAILS-FILTER-A frissített', exact: true })).toBeVisible();
    await expect(page.locator('tr[data-stock-product-id]')).toHaveCount(1);
    await expect(stocks.getByLabel('Készlet', { exact: true })).toHaveValue('all');
    await expect(page.getByLabel('Helyi találatok oldalanként', { exact: true })).toHaveValue('25');
    await page.getByRole('link', { name: 'Készletszűrők törlése', exact: true }).click();
    await expect(page).toHaveURL(url => !url.searchParams.has('product_id'));
    await expect(stocks.getByLabel('Készlet', { exact: true })).toHaveValue('positive');
    await expect(page.getByLabel('Helyi találatok oldalanként', { exact: true })).toHaveValue('25');
    await page.getByRole('link', { name: '← Raktárak', exact: true }).click();
    await expect(page.getByRole('combobox', { name: 'Raktárak keresése', exact: true })).toHaveValue('DETAILS-FILTER');
    await expect(page.getByLabel('Találatok oldalanként', { exact: true })).toHaveValue('25');
    expect(new URL(page.url()).searchParams.get('sort_dir')).toBe('desc');
});

test('empty warehouse details fit mobile and malformed local filters recover without a redirect loop', async ({ page }) => {
    const local = await warehouse(page, 'DETAILS-EMPTY');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/warehouses/'+local);
    await expect(page.locator('[data-summary="local"]')).toContainText('0 db');
    await expect(page.locator('[data-summary="incoming"]')).toContainText('0 db');
    const stocks = page.getByRole('region', { name: /^Helyi készlet/ });
    await expect(stocks.getByText('Nincs a szűrésnek megfelelő helyi készletsor.', { exact: true }).last()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    await page.screenshot({ path: 'tests/Browser/.runtime/warehouse-details-mobile.png', fullPage: true });
    await page.goto('/warehouses/'+local+'?product_id=999999');
    await expect(page).toHaveURL(url => !url.searchParams.has('product_id'));
    await expect(page.getByRole('alert')).toContainText('A készletszűrés egy része már nem érvényes');
});

test('shipment-managed incoming items open the shipment workflow', async ({ page }) => {
    const local = await warehouse(page, 'DETAILS-SHIPMENT-A');
    const other = await warehouse(page, 'DETAILS-SHIPMENT-B');
    await movement(page, other, '1', 4);
    await page.goto('/shipments/create');
    await page.getByLabel('Szállítmány hivatkozása', { exact: true }).fill('DETAILS-SHIPMENT');
    await page.getByRole('combobox', { name: 'Induló raktár', exact: true }).fill('DETAILS-SHIPMENT-B');
    await page.getByRole('listbox', { name: 'Raktártalálatok', exact: true }).getByRole('option', { name: 'DETAILS-SHIPMENT-B', exact: true }).click();
    const stop = page.locator('fieldset').first();
    await stop.getByLabel('Célraktár', { exact: true }).selectOption(local);
    await stop.getByLabel('Termék', { exact: true }).selectOption('1');
    await stop.getByLabel('Mennyiség (db)', { exact: true }).fill('2');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await page.getByRole('button', { name: 'Szállítmány indítása', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Megálló átvétele', exact: true })).toBeVisible();
    const shipmentUrl = page.url();
    await page.goto('/warehouses/'+local);
    await expect(page.locator('[data-summary="incoming"]')).toContainText('2 db');
    const incoming = page.getByRole('region', { name: 'Beérkező átmozgatások', exact: true });
    await incoming.getByRole('link', { name: /ATM-.*SZALL-/ }).click();
    await expect(page).toHaveURL(shipmentUrl);
});

test('local stock headers toggle each sort, preserve current filter edits and keep dropdowns only on mobile', async ({ page }) => {
    const local = await warehouse(page, 'DETAILS-SORT');
    await movement(page, local, '1', 3);
    await movement(page, local, '2', 1);
    await page.goto('/warehouses?search=DETAILS-SORT&per_page=25');
    await page.getByRole('link', { name: 'DETAILS-SORT', exact: true }).click();
    const context = new URL(page.url()).searchParams.get('context');
    const url = new URL(page.url());
    for (const [key,value] of Object.entries({ search: 'teszt', stock: 'all', per_page: '25', page: '2' })) url.searchParams.set(key,value);
    await page.goto(url.toString());
    await expect(page.getByLabel('Rendezés', { exact: true })).not.toBeVisible();
    await expect(page.getByLabel('Irány', { exact: true })).not.toBeVisible();
    for (const [label,column,firstAsc] of [['Termék','product_name','1'],['Cikkszám','sku','1'],['Helyi készlet','quantity','2']]) {
        const header = page.getByRole('columnheader', { name: label, exact: true });
        await header.getByRole('link').click();
        const firstDirection = column === 'product_name' ? 'desc' : 'asc';
        await expect(header).toHaveAttribute('aria-sort', firstDirection === 'asc' ? 'ascending' : 'descending');
        expect(new URL(page.url()).searchParams.has('page')).toBe(false);
        expect(new URL(page.url()).searchParams.get('context')).toBe(context);
        expect(new URL(page.url()).searchParams.get('per_page')).toBe('25');
        const firstId = firstDirection === 'asc' ? firstAsc : '2';
        await expect(page.locator('tr[data-stock-product-id]').first()).toHaveAttribute('data-stock-product-id', firstId);
        await header.getByRole('link').click();
        await expect(header).toHaveAttribute('aria-sort', firstDirection === 'asc' ? 'descending' : 'ascending');
    }
    await page.getByRole('combobox', { name: 'Helyi termékek keresése', exact: true }).fill('alpha');
    await page.getByRole('columnheader', { name: 'Helyi készlet', exact: true }).getByRole('link').click();
    await expect(page).toHaveURL(current => current.searchParams.get('search') === 'alpha' && current.searchParams.get('sort_by') === 'quantity');
    await expect(page.locator('tr[data-stock-product-id]')).toHaveCount(1);
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByLabel('Rendezés', { exact: true })).toBeVisible();
    await page.getByLabel('Rendezés', { exact: true }).selectOption('sku');
    await expect(page).toHaveURL(current => current.searchParams.get('sort_by') === 'sku');
    await expect(page.getByRole('article')).toHaveCount(1);
});

test('an out-of-range local stock page recovers and retains filters instead of showing a misleading empty list', async ({ page }) => {
    const local = await warehouse(page, 'DETAILS-STALE-PAGE');
    await movement(page, local, '1', 2);
    await page.goto('/warehouses?search=DETAILS-STALE-PAGE&per_page=25');
    await page.getByRole('link', { name: 'DETAILS-STALE-PAGE', exact: true }).click();
    const context = new URL(page.url()).searchParams.get('context');
    const url = new URL(page.url());
    for (const [key,value] of Object.entries({ search: 'alpha', stock: 'all', sort_by: 'quantity', sort_dir: 'desc', per_page: '25', page: '999' })) url.searchParams.set(key,value);
    await page.goto(url.toString());
    await expect(page).toHaveURL(current => !current.searchParams.has('page') && current.searchParams.get('context') === context);
    await expect(page.locator('tr[data-stock-product-id="1"]')).toContainText('2 db');
    await expect(page.getByRole('combobox', { name: 'Helyi termékek keresése', exact: true })).toHaveValue('alpha');
    await expect(page.getByLabel('Helyi találatok oldalanként', { exact: true })).toHaveValue('25');
    await expect(page.getByRole('columnheader', { name: 'Helyi készlet', exact: true })).toHaveAttribute('aria-sort', 'descending');
});
