import { test, expect } from '@playwright/test';

async function createWarehouse(page, name, location = 'Budapest, Teszt utca 1.') {
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const response = await page.request.post('/warehouses', {
        headers: { 'X-CSRF-TOKEN': token }, form: { warehouse_name: name, location },
    });
    expect(response.ok()).toBe(true);
}

test.beforeEach(async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});

test('warehouse search combines words across name and address, preserves literal characters and supports stock filtering', async ({ page }) => {
    await page.goto('/warehouses');
    await createWarehouse(page, 'Literal 100%_! raktár', 'Győr');
    await page.getByLabel('Raktárak keresése', { exact: true }).fill('100%_! Győr');
    await page.getByRole('button', { name: 'Keresés', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody')).toContainText('Literal 100%_! raktár');
    await page.getByLabel('Készlet', { exact: true }).selectOption('stocked');
    await expect(page).toHaveURL(url => url.searchParams.get('stock') === 'stocked');
    await expect(page.getByRole('status')).toContainText('Nincs a feltételeknek megfelelő raktár');
    await page.getByRole('link', { name: 'Szűrők törlése', exact: true }).click();
    await page.getByLabel('Raktárak keresése', { exact: true }).fill('Teszt Test');
    await page.getByRole('button', { name: 'Keresés', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    const row = page.locator('tr[data-warehouse-id="1"]');
    await row.getByRole('button', { name: /^Műveletek:/ }).click();
    await expect(row.getByRole('menuitem', { name: 'Törlés', exact: true })).toBeDisabled();
    await expect(row.getByRole('menuitem', { name: 'Mozgások', exact: true })).toHaveAttribute('href', /warehouse_id=1/);
    await row.getByRole('menuitem', { name: 'Mozgások', exact: true }).click();
    await expect(page).toHaveURL(/\/movements\?.*warehouse_id=1/);
});

test('creating, editing and cancelling a warehouse preserve list filters and page size', async ({ page }) => {
    await page.goto('/warehouses?search=UI-raktar&sort_by=location&sort_dir=desc&per_page=25');
    await page.getByRole('link', { name: '+ Új raktár', exact: true }).click();
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    await expect(page.getByLabel('Raktárak keresése', { exact: true })).toHaveValue('UI-raktar');
    await page.getByRole('link', { name: '+ Új raktár', exact: true }).click();
    await page.getByLabel('Raktár neve', { exact: true }).fill('UI-raktar eredeti');
    await page.getByLabel('Cím / lokáció', { exact: true }).fill('Eredeti cím');
    await page.getByRole('button', { name: 'Raktár mentése', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Raktár sikeresen hozzáadva');
    await expect(page.getByLabel('Találatok oldalanként', { exact: true })).toHaveValue('25');
    await expect(page.getByLabel('Raktárak keresése', { exact: true })).toHaveValue('UI-raktar');
    await page.getByRole('button', { name: /^Műveletek:/ }).click();
    await page.getByRole('menuitem', { name: 'Szerkesztés', exact: true }).click();
    await expect(page.getByLabel('Raktár neve', { exact: true })).toHaveValue('UI-raktar eredeti');
    await page.getByLabel('Raktár neve', { exact: true }).fill('UI-raktar módosított');
    await page.getByLabel('Cím / lokáció', { exact: true }).fill('Módosított cím');
    await page.getByRole('button', { name: 'Raktár mentése', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Raktár adatai frissítve');
    await expect(page.locator('tbody')).toContainText('UI-raktar módosított');
    await expect(page.locator('tbody')).toContainText('Módosított cím');
    expect(new URL(page.url()).searchParams.get('sort_dir')).toBe('desc');
});

test('warehouse validation links field errors and retains input, then permits correction', async ({ page }) => {
    await page.goto('/warehouses/create');
    await page.getByLabel('Raktár neve', { exact: true }).fill('Hibajavítás');
    await page.getByLabel('Cím / lokáció', { exact: true }).fill('Megmaradó cím');
    await page.locator('form').filter({ has: page.locator('#warehouse-name') }).evaluate(form => { form.noValidate = true; });
    await page.getByLabel('Raktár neve', { exact: true }).fill('');
    await page.getByRole('button', { name: 'Raktár mentése', exact: true }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByLabel('Raktár neve', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#warehouse-name-error')).not.toBeEmpty();
    await expect(page.getByLabel('Cím / lokáció', { exact: true })).toHaveValue('Megmaradó cím');
    await page.getByLabel('Raktár neve', { exact: true }).fill('Javított raktár');
    await page.getByRole('button', { name: 'Raktár mentése', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Raktár sikeresen hozzáadva');
});

test('unused warehouses can be deleted, and forged deletion of a used warehouse is rejected', async ({ page }) => {
    await page.goto('/warehouses');
    await createWarehouse(page, 'Torolheto-raktar');
    await page.goto('/warehouses?search=Torolheto-raktar');
    await page.getByRole('button', { name: /^Műveletek:/ }).click();
    await page.getByRole('menuitem', { name: 'Törlés', exact: true }).click();
    await expect(page.getByRole('status').first()).toContainText('Raktár törölve');
    await expect(page.locator('tbody')).toContainText('Nincs a feltételeknek megfelelő raktár');
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const denied = await page.request.delete('/warehouses/1', { headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } });
    expect(denied.status()).toBe(422);
    expect((await denied.json()).errors.warehouse).toBeTruthy();
    await page.goto('/warehouses?search=Teszt Test');
    await page.getByRole('button', { name: /^Műveletek:/ }).click();
    await expect(page.getByRole('menuitem', { name: 'Törlés', exact: true })).toBeDisabled();
});

test('warehouse pagination and column sorting retain filters and fit mobile', async ({ page }) => {
    await page.goto('/warehouses');
    for (let i = 0; i < 16; i++) await createWarehouse(page, `Lapozas-${String(i).padStart(2, '0')}`);
    await page.goto('/warehouses?search=Lapozas&stock=empty&per_page=15');
    await expect(page.locator('tbody tr')).toHaveCount(15);
    await page.getByRole('link', { name: 'Következő', exact: true }).last().click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await page.getByRole('columnheader', { name: /Raktár neve/ }).getByRole('link').click();
    expect(new URL(page.url()).searchParams.has('page')).toBe(false);
    expect(new URL(page.url()).searchParams.get('stock')).toBe('empty');
    await expect(page.getByRole('columnheader', { name: /Raktár neve/ })).toHaveAttribute('aria-sort', 'descending');
    await page.getByLabel('Találatok oldalanként', { exact: true }).selectOption('25');
    await expect(page.locator('tbody tr')).toHaveCount(16);
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    await expect(page.getByRole('article')).toHaveCount(16);
    await expect(page.getByRole('article').first().getByRole('button', { name: /^Műveletek:/ })).toBeVisible();
    await page.getByLabel('Rendezés', { exact: true }).selectOption('total_quantity');
    await expect(page).toHaveURL(url => url.searchParams.get('sort_by') === 'total_quantity');
    await expect(page.getByLabel('Készlet', { exact: true })).toHaveValue('empty');
    await page.screenshot({ path: 'tests/Browser/.runtime/warehouses-mobile.png', fullPage: true });
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.screenshot({ path: 'tests/Browser/.runtime/warehouses-desktop.png', fullPage: true });
});

test('warehouse action menus support keyboard navigation, Escape, Tab and outside dismissal', async ({ page }) => {
    await page.goto('/warehouses');
    await createWarehouse(page, 'Menu-teszt A');
    await createWarehouse(page, 'Menu-teszt B');
    await page.goto('/warehouses?search=Menu-teszt');
    const triggers = page.getByRole('button', { name: /^Műveletek:/ });
    await triggers.first().focus();
    await triggers.first().press('ArrowDown');
    const menu = page.getByRole('menu');
    await expect(menu.getByRole('menuitem', { name: 'Szerkesztés', exact: true })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menu.getByRole('menuitem', { name: 'Termékek', exact: true })).toBeFocused();
    await page.keyboard.press('End');
    await expect(menu.getByRole('menuitem', { name: 'Törlés', exact: true })).toBeFocused();
    await page.keyboard.press('Home');
    await expect(menu.getByRole('menuitem', { name: 'Szerkesztés', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    await expect(triggers.first()).toBeFocused();
    await triggers.first().press('ArrowUp');
    await expect(menu.getByRole('menuitem', { name: 'Törlés', exact: true })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(menu).toHaveCount(0);
    await triggers.first().click();
    await page.getByRole('heading', { name: /^Raktárak/ }).click();
    await expect(menu).toHaveCount(0);
    await triggers.last().click();
    await expect(menu).toHaveCount(1);
    await expect(menu).toHaveAttribute('aria-label', 'Menu-teszt B műveletei');
    await page.getByRole('heading', { name: /^Raktárak/ }).click();
    await expect(menu).toHaveCount(0);
});

test('warehouse menus stay within the viewport above clipped tables and on mobile, and deletion can be cancelled', async ({ page }) => {
    await page.goto('/warehouses');
    await createWarehouse(page, 'Menu-Edge');
    await page.setViewportSize({ width: 1000, height: 420 });
    await page.goto('/warehouses?search=Menu-Edge');
    const trigger = page.getByRole('button', { name: 'Műveletek: Menu-Edge', exact: true });
    await trigger.click();
    const menu = page.getByRole('menu');
    let bounds = await menu.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.y).toBeGreaterThanOrEqual(0);
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(420);
    expect(await menu.evaluate(element => element.contains(document.elementFromPoint(element.getBoundingClientRect().x + 15, element.getBoundingClientRect().y + 15)))).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/warehouse-menu-desktop.png' });
    page.removeAllListeners('dialog');
    page.on('dialog', dialog => dialog.dismiss());
    await menu.getByRole('menuitem', { name: 'Törlés', exact: true }).click();
    await expect(page.locator('tr[data-warehouse-id]')).toHaveCount(1);
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 390, height: 640 });
    await trigger.click();
    bounds = await menu.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(640);
    await page.screenshot({ path: 'tests/Browser/.runtime/warehouse-menu-mobile.png' });
    await menu.getByRole('menuitem', { name: 'Szerkesztés', exact: true }).click();
    await expect(page).toHaveURL(/\/warehouses\/\d+\/edit/);
});

test('warehouse suggestions distinguish names by address, select exact IDs and clear the old selection when typing', async ({ page }) => {
    await page.goto('/warehouses');
    await createWarehouse(page, 'Dupla-ajánlat', 'Győr');
    await createWarehouse(page, 'Dupla-ajánlat', 'Debrecen');
    await page.goto('/warehouses?search=old&page=2&per_page=25&sort_by=location&sort_dir=desc');
    await page.getByLabel('Készlet', { exact: true }).selectOption('empty');
    await expect(page).toHaveURL(url => url.searchParams.get('stock') === 'empty');
    const input = page.getByRole('combobox', { name: 'Raktárak keresése', exact: true });
    await input.fill('dupla gyor');
    const suggestions = page.getByRole('listbox', { name: 'Kereső raktárjavaslatai', exact: true });
    await expect(suggestions.getByRole('option')).toHaveCount(1);
    await suggestions.getByRole('option', { name: 'Dupla-ajánlat Győr', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody')).toContainText('Győr');
    let params = new URL(page.url()).searchParams;
    expect(params.get('warehouse_id')).toBeTruthy();
    expect(params.get('stock')).toBe('empty');
    expect(params.get('per_page')).toBe('25');
    expect(params.get('sort_dir')).toBe('desc');
    expect(params.has('search')).toBe(false);
    expect(params.has('page')).toBe(false);
    await expect(page.getByRole('link', { name: 'Kiválasztott raktár szűrésének törlése', exact: true })).toContainText('Dupla-ajánlat');
    await input.fill('Dupla-ajánlat');
    await input.press('Enter');
    await expect(page.locator('tbody tr')).toHaveCount(2);
    params = new URL(page.url()).searchParams;
    expect(params.get('warehouse_id') || '').toBe('');
    expect(params.get('search')).toBe('Dupla-ajánlat');
});

test('warehouse suggestions support multiword accents, keyboard choice, Escape, Tab and mobile layout', async ({ page }) => {
    await page.goto('/warehouses');
    for (let i = 1; i <= 10; i++) await createWarehouse(page, `Árvíztűrő ajánlat ${String(i).padStart(2, '0')}`, 'Szeged');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/warehouses');
    const input = page.getByRole('combobox', { name: 'Raktárak keresése', exact: true });
    await input.fill('arvizturo');
    const suggestions = page.getByRole('listbox', { name: 'Kereső raktárjavaslatai', exact: true });
    await expect(suggestions.getByRole('option')).toHaveCount(8);
    const bounds = await suggestions.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
    await input.fill('arvizturo szeged 03');
    await expect(suggestions.getByRole('option')).toHaveCount(1);
    await input.press('ArrowDown');
    await expect(input).toHaveAttribute('aria-activedescendant', 'warehouse-search-option-0');
    await input.press('Escape');
    await expect(suggestions).not.toBeVisible();
    await input.press('ArrowDown');
    await input.press('Tab');
    await expect(suggestions).not.toBeVisible();
    await input.click();
    await input.press('ArrowDown');
    await page.screenshot({ path: 'tests/Browser/.runtime/warehouse-suggestions-mobile.png' });
    await input.press('Enter');
    await expect(page.getByRole('article')).toHaveCount(1);
    await expect(page.getByRole('article')).toContainText('Árvíztűrő ajánlat 03');
    expect(new URL(page.url()).searchParams.get('warehouse_id')).toBeTruthy();
    await page.getByRole('link', { name: 'Kiválasztott raktár szűrésének törlése', exact: true }).click();
    expect(new URL(page.url()).searchParams.has('warehouse_id')).toBe(false);
});

test('changing stock alone refreshes the warehouse list and keeps search, sorting and page size', async ({ page }) => {
    await page.goto('/warehouses?search=Teszt&sort_by=location&sort_dir=desc&per_page=25&page=2');
    await page.getByLabel('Készlet', { exact: true }).selectOption('stocked');
    await expect(page).toHaveURL(url => url.searchParams.get('stock') === 'stocked' && !url.searchParams.has('page'));
    let params = new URL(page.url()).searchParams;
    for (const [key, value] of Object.entries({ search: 'Teszt', sort_by: 'location', sort_dir: 'desc', per_page: '25' })) expect(params.get(key)).toBe(value);
    await expect(page.locator('tr[data-warehouse-id="1"]')).toBeVisible();
    await page.getByLabel('Készlet', { exact: true }).selectOption('empty');
    await expect(page).toHaveURL(url => url.searchParams.get('stock') === 'empty');
    await expect(page.locator('tr[data-warehouse-id="1"]')).toHaveCount(0);
    await page.getByLabel('Készlet', { exact: true }).selectOption('');
    await expect(page).toHaveURL(url => !url.searchParams.get('stock'));
    await expect(page.locator('tr[data-warehouse-id="1"]')).toBeVisible();
    params = new URL(page.url()).searchParams;
    expect(params.get('search')).toBe('Teszt');
    expect(params.get('per_page')).toBe('25');
});

test('deleting an exactly selected warehouse clears the now-invalid warehouse filter', async ({ page }) => {
    await page.goto('/warehouses');
    await createWarehouse(page, 'Pontos-torles-edge');
    await page.reload();
    const input = page.getByRole('combobox', { name: 'Raktárak keresése', exact: true });
    await input.fill('Pontos-torles-edge');
    await page.getByRole('listbox', { name: 'Kereső raktárjavaslatai', exact: true }).getByRole('option', { name: /Pontos-torles-edge/ }).click();
    await expect(page).toHaveURL(url => Boolean(url.searchParams.get('warehouse_id')));
    await page.getByRole('button', { name: /^Műveletek:/ }).click();
    await page.getByRole('menuitem', { name: 'Törlés', exact: true }).click();
    await expect(page).toHaveURL(url => !url.searchParams.has('warehouse_id'));
    await expect(page.getByRole('status').first()).toContainText('Raktár törölve');
    await expect(page.getByRole('alert')).toHaveCount(0);
});

test('an open warehouse menu closes when switching between desktop rows and mobile cards', async ({ page }) => {
    await page.goto('/warehouses?search=Teszt Test');
    await page.getByRole('button', { name: /^Műveletek:/ }).click();
    await expect(page.getByRole('menu')).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('menu')).toHaveCount(0);
    await page.getByRole('button', { name: /^Műveletek:/ }).click();
    await expect(page.getByRole('menu')).toBeVisible();
    await page.setViewportSize({ width: 1280, height: 800 });
    await expect(page.getByRole('menu')).toHaveCount(0);
});

for (const mobile of [false, true]) {
    test(`warehouse stock count opens matching products and browser Back restores the list (${mobile ? 'mobile' : 'desktop'})`, async ({ page }) => {
        await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1280, height: 800 });
        await page.goto('/warehouses?search=Teszt%20Test&stock=stocked&per_page=25&sort_by=location&sort_dir=desc');
        const originalUrl = page.url();
        const warehouse = page.locator(`${mobile ? 'article' : 'tr'}[data-warehouse-id="1"]`);
        const link = warehouse.getByRole('link', { name: /készleten lévő termékfajta megtekintése$/ });
        const expectedCount = Number((await link.innerText()).replace(/\s/g, ''));
        expect(expectedCount).toBeGreaterThan(0);
        await page.screenshot({ path: `tests/Browser/.runtime/warehouse-stock-link-${mobile ? 'mobile' : 'desktop'}.png` });
        await link.focus();
        await link.press('Enter');
        await expect(page).toHaveURL(url => url.pathname === '/products' && url.searchParams.get('warehouse_id') === '1');
        const params = new URL(page.url()).searchParams;
        expect(params.get('sort_by')).toBe('product_name');
        expect(params.get('sort_dir')).toBe('asc');
        for (const key of ['search', 'stock', 'stock_status', 'page', 'per_page', 'category_id']) expect(params.has(key)).toBe(false);
        await expect(page.locator('select[name="warehouse_id"]')).toHaveValue('1');
        await expect(page.locator('tbody tr')).toHaveCount(expectedCount);
        await page.goBack();
        await expect(page).toHaveURL(originalUrl);
        await expect(page.getByLabel('Készlet', { exact: true })).toHaveValue('stocked');
        await expect(page.getByLabel('Találatok oldalanként', { exact: true })).toHaveValue('25');
        await expect(link).toHaveText(String(expectedCount));
    });
}

test('a zero warehouse stock count opens an empty product list without showing other warehouses stock', async ({ page }) => {
    await page.goto('/warehouses');
    await createWarehouse(page, 'Gyorslink-ures');
    await page.goto('/warehouses?search=Gyorslink-ures');
    const link = page.getByRole('link', { name: 'Gyorslink-ures: 0 készleten lévő termékfajta megtekintése', exact: true });
    await expect(link).toHaveText('0');
    const warehouseId = new URL(await link.getAttribute('href')).searchParams.get('warehouse_id');
    await link.click();
    await expect(page).toHaveURL(url => url.pathname === '/products' && url.searchParams.get('warehouse_id') === warehouseId);
    await expect(page.locator('tbody').getByText(/Nincs a szűrési feltételeknek megfelelő termék\./)).toBeVisible();
    await expect(page.locator('select[name="warehouse_id"]')).toHaveValue(warehouseId);
});
