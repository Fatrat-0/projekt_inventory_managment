import { test, expect } from '@playwright/test';

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
}

async function chooseProduct(page, id = '1') {
    const label = id === '1' ? 'Alpha teszttermék (ALPHA-001)' : 'Zeta teszttermék (ZETA-002)';
    await page.getByRole('combobox', { name: 'Termék', exact: true }).fill(id === '1' ? 'ALPHA' : 'ZETA');
    await page.getByRole('option', { name: label, exact: true }).click();
}

async function chooseWarehouse(page) {
    await page.getByRole('combobox', { name: 'Raktár', exact: true }).fill('raktár');
    await page.getByRole('option', { name: 'Teszt raktár · Test', exact: true }).click();
}

async function openForm(page) {
    await page.goto('/movements/create');
    await chooseProduct(page);
    await chooseWarehouse(page);
}

test.beforeEach(async ({ page }) => { await login(page); });

test('product suggestions appear in the same field and editing clears the old identifier', async ({ page }) => {
    await openForm(page);
    const input = page.getByRole('combobox', { name: 'Termék', exact: true });
    await input.fill('ZETA-002');
    await expect(page.getByRole('option', { name: 'Zeta teszttermék (ZETA-002)', exact: true })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Alpha teszttermék (ALPHA-001)', exact: true })).toHaveCount(0);
    await expect(page.locator('input[name="product_id"]')).toHaveValue('');
    await input.press('ArrowDown');
    await input.press('Enter');
    await expect(input).toHaveValue('Zeta teszttermék (ZETA-002)');
    await expect(page.locator('input[name="product_id"]')).toHaveValue('2');
    await input.fill('nincsilyen');
    await expect(page.getByText('Nincs egyező találat.', { exact: true }).filter({ visible: true })).toBeVisible();
    await expect(page.locator('input[name="product_id"]')).toHaveValue('');
    expect(await input.evaluate(element => element.checkValidity())).toBe(false);
});

test('a slow previous stock response cannot overwrite the new selection', async ({ page }) => {
    await page.route('**/movements/stock?**', async route => {
        const id = new URL(route.request().url()).searchParams.get('product_id');
        if (id === '1') await new Promise(resolve => setTimeout(resolve, 500));
        await route.fulfill({ json: { quantity: id === '1' ? 20 : 7 } });
    });
    await openForm(page);
    await chooseProduct(page, '2');
    await expect(page.getByRole('status')).toContainText('7 db');
    await page.waitForTimeout(650);
    await expect(page.getByRole('status')).toContainText('7 db');
    await page.getByLabel('Mozgás típusa').selectOption('out');
    await page.getByLabel('Mennyiség (db)').fill('8');
    await expect(page.getByText('A megadott mennyiség meghaladja a legutóbb lekért készletet.')).toBeVisible();
});

test('stock request failure offers retry and recovers', async ({ page }) => {
    let requests = 0;
    await page.route('**/movements/stock?**', async route => {
        requests++;
        if (requests === 1) return route.abort('failed');
        await route.fulfill({ json: { quantity: 20 } });
    });
    await openForm(page);
    await expect(page.getByRole('status')).toContainText('A készlet nem tölthető be');
    await page.getByRole('button', { name: 'Frissítés', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('20 db');
});

test('cancel and successful save retain the original list context', async ({ page }) => {
    const list = '/movements?search=Alpha&type=in&sort_by=quantity&sort_dir=asc&per_page=10&page=2';
    await page.goto(list);
    await page.getByRole('link', { name: '+ Új mozgás' }).click();
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    await expect(page).toHaveURL(new RegExp('search=Alpha.*page=2'));
    await page.getByRole('link', { name: '+ Új mozgás' }).click();
    await chooseProduct(page);
    await chooseWarehouse(page);
    await page.getByLabel('Mennyiség (db)').fill('1');
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page).toHaveURL(new RegExp('search=Alpha.*page=2'));
    await expect(page.getByRole('status')).toContainText('Mozgás rögzítve: MOZ-');
});

test('insufficient stock shows linked field errors and preserves the form', async ({ page }) => {
    await openForm(page);
    await page.getByLabel('Mozgás típusa').selectOption('out');
    await page.getByLabel('Mennyiség (db)').fill('999');
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page.locator('#movement-quantity')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('#movement-quantity-error')).toContainText('Nincs elegendő készlet');
    await expect(page.getByLabel('Mennyiség (db)')).toHaveValue('999');
    await expect(page.locator('input[name="product_id"]')).toHaveValue('1');
});

test('first submit disables the button and repeated submit events are blocked', async ({ page }) => {
    await openForm(page);
    // Keep the first submission on this document so its pending state can be inspected.
    await page.evaluate(() => {
        document.querySelector('form[action$="/movements"]').addEventListener('submit', event => event.preventDefault(), { once: true });
    });
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Mentés…', exact: true })).toBeDisabled();
    const prevented = await page.evaluate(() => {
        const event = new Event('submit', { bubbles: true, cancelable: true });
        document.querySelector('form[action$="/movements"]').dispatchEvent(event);
        return event.defaultPrevented;
    });
    expect(prevented).toBe(true);
});


test('warehouse supports keyboard choice, Escape, and clearing', async ({ page }) => {
    await page.goto('/movements/create');
    await chooseProduct(page);
    const input = page.getByRole('combobox', { name: 'Raktár', exact: true });
    await input.fill('test');
    await expect(page.getByRole('option', { name: 'Teszt raktár · Test', exact: true })).toBeVisible();
    await input.press('ArrowDown');
    await input.press('Enter');
    await expect(page.locator('input[name="warehouse_id"]')).toHaveValue('1');
    await expect(page.getByRole('status')).toContainText('db');
    await input.click();
    await input.press('Escape');
    await expect(input).toHaveAttribute('aria-expanded', 'false');
    await page.getByRole('button', { name: 'Raktár törlése a mezőből', exact: true }).click();
    await expect(input).toHaveValue('');
    await expect(page.locator('input[name="warehouse_id"]')).toHaveValue('');
    await expect(page.getByText('Válassz terméket és raktárt a készlet megtekintéséhez.', { exact: true })).toBeVisible();
});

test('list product filter uses suggestions and clearing restores all products', async ({ page }) => {
    await page.goto('/movements');
    await page.getByRole('button', { name: 'Szűrők', exact: true }).click();
    await chooseProduct(page);
    await page.getByRole('button', { name: 'Szűrés alkalmazása', exact: true }).click();
    await expect(page).toHaveURL(/product_id=1/);
    await expect(page.getByRole('combobox', { name: 'Termék', exact: true })).toHaveValue('Alpha teszttermék (ALPHA-001)');
    await page.getByRole('button', { name: 'Termék törlése a mezőből', exact: true }).click();
    await page.getByRole('button', { name: 'Szűrés alkalmazása', exact: true }).click();
    expect(new URL(page.url()).searchParams.get('product_id')).not.toBe('1');
});

test('replaying the same form creates one movement and details show its original balances', async ({ page }) => {
    await openForm(page);
    const reference = 'BROWSER-REPLAY';
    await page.getByLabel('Bizonylatszám vagy megjegyzés (opcionális)').fill(reference);
    const csrf = await page.getByRole('main').locator('input[name="_token"]').getAttribute('value');
    const key = await page.locator('input[name="request_key"]').getAttribute('value');
    const stockResponse = await page.request.get('/movements/stock?product_id=1&warehouse_id=1');
    const before = (await stockResponse.json()).quantity;
    const first = await page.request.post('/movements', { form: {
        _token: csrf, request_key: key, product_id: '1', warehouse_id: '1', type: 'in', quantity: '1', reference,
    }});
    expect(first.ok()).toBe(true);
    // The still-open original form submits exactly the same key and business payload.
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page.getByRole('status')).toContainText('Mozgás rögzítve: MOZ-');
    const after = await page.request.get('/movements/stock?product_id=1&warehouse_id=1');
    expect((await after.json()).quantity).toBe(before + 1);
    await page.goto('/movements?search='+reference);
    await expect(page.getByRole('heading', { name: 'Készletmozgások 1 tétel' })).toBeVisible();
    await page.getByRole('link', { name: /^MOZ-/ }).click();
    await expect(page.getByRole('heading', { name: /^MOZ-/ })).toBeVisible();
    const balances = page.getByRole('region', { name: 'Készletváltozás' });
    await expect(balances.getByText(before+' db', { exact: true })).toBeVisible();
    await expect(balances.getByText((before+1)+' db', { exact: true })).toBeVisible();
    await page.screenshot({ path: 'tests/Browser/.runtime/movement-detail.png', fullPage: true });
    await page.getByRole('link', { name: '← Készletmozgások', exact: true }).click();
    await expect(page.getByRole('combobox', { name: 'Mozgások keresése' })).toHaveValue(reference);
});

test('movement number search opens details and the product link restores the full history', async ({ page }) => {
    await page.goto('/movements');
    const search = page.getByRole('combobox', { name: 'Mozgások keresése' });
    await search.fill('moz-000001 Alpha');
    await search.press('Enter');
    await expect(page.getByRole('heading', { name: 'Készletmozgások 1 tétel' })).toBeVisible();
    await page.getByRole('link', { name: 'MOZ-000001', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'MOZ-000001', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Termék mozgásai', exact: true }).click();
    const url = new URL(page.url());
    expect(url.searchParams.get('product_id')).toBe('1');
    expect(url.searchParams.has('search')).toBe(false);
    await expect(page.getByRole('combobox', { name: 'Mozgások keresése' })).toHaveValue('');
    await expect(page.getByRole('link', { name: /^MOZ-/ })).toHaveCount(10);
});

test('quick date filters preserve other filters, reset pagination and accept custom dates', async ({ page }) => {
    await page.goto('/movements?search=Alpha&type=in&product_id=1&sort_by=quantity&sort_dir=asc&per_page=25&page=2');
    const presets = page.getByRole('group', { name: 'Gyors dátumszűrők' });
    await presets.getByRole('link', { name: 'Elmúlt 7 nap', exact: true }).click();
    const params = new URL(page.url()).searchParams;
    expect(params.has('page')).toBe(false);
    for (const [key, value] of Object.entries({ search: 'Alpha', type: 'in', product_id: '1', sort_by: 'quantity', sort_dir: 'asc', per_page: '25' })) {
        expect(params.get(key)).toBe(value);
    }
    await expect(presets.getByRole('link', { name: 'Elmúlt 7 nap', exact: true })).toHaveAttribute('aria-current', 'true');
    const from = await page.getByLabel('Dátumtól', { exact: true }).inputValue();
    const to = await page.getByLabel('Dátumig (beleértve)', { exact: true }).inputValue();
    expect((Date.parse(to) - Date.parse(from)) / 86400000).toBe(6);
    await expect(page.getByRole('link', { name: /^MOZ-/ })).toHaveCount(25);
    await page.screenshot({ path: 'tests/Browser/.runtime/movement-quick-filters.png', fullPage: true });

    await page.getByLabel('Dátumtól', { exact: true }).fill('2020-01-01');
    await page.getByLabel('Dátumig (beleértve)', { exact: true }).fill('2020-01-02');
    await page.getByRole('button', { name: 'Szűrés alkalmazása', exact: true }).click();
    await expect(presets.locator('[aria-current]')).toHaveCount(0);
    await expect(page.getByText('Nincs a feltételeknek megfelelő készletmozgás.')).toBeVisible();
});

test('quick date filters submit edited fields and respect product picker validation on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/movements?search=Alpha&type=in&product_id=1&per_page=25&page=2');
    await page.getByRole('combobox', { name: 'Mozgások keresése' }).fill('Zeta');
    await page.getByLabel('Mozgás típusa', { exact: true }).selectOption('out');
    const product = page.getByRole('combobox', { name: 'Termék', exact: true });
    await product.fill('nincsilyen');
    const urlBefore = page.url();
    const today = page.getByRole('group', { name: 'Gyors dátumszűrők' }).getByRole('link', { name: 'Ma', exact: true });
    await today.click();
    expect(await product.evaluate(element => element.checkValidity())).toBe(false);
    expect(page.url()).toBe(urlBefore);
    await chooseProduct(page, '2');
    await today.click();
    await expect(page.getByRole('combobox', { name: 'Mozgások keresése' })).toHaveValue('Zeta');
    const params = new URL(page.url()).searchParams;
    expect(params.get('search')).toBe('Zeta');
    expect(params.get('type')).toBe('out');
    expect(params.get('product_id')).toBe('2');
    expect(params.get('per_page')).toBe('25');
    expect(params.get('date_from')).toBe(params.get('date_to'));
    expect(params.has('page')).toBe(false);
    await expect(today).toHaveAttribute('aria-current', 'true');
    const empty = page.getByRole('status').filter({ hasText: 'Nincs a feltételeknek megfelelő készletmozgás.' });
    await expect(empty).toBeVisible();
    const bounds = await empty.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
    await expect(page.getByRole('table')).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/movement-quick-filters-mobile.png', fullPage: true });
});

test('main search offers full product names and SKUs and mouse choice replaces the product filter', async ({ page }) => {
    await page.goto('/movements?product_id=2&type=in&sort_by=quantity&sort_dir=asc&per_page=25&page=2');
    await page.getByLabel('Mozgás típusa', { exact: true }).selectOption('out');
    await page.getByLabel('Dátumtól', { exact: true }).fill('2020-01-01');
    await page.getByLabel('Dátumig (beleértve)', { exact: true }).fill('2099-12-31');
    const search = page.getByRole('combobox', { name: 'Mozgások keresése' });
    await search.fill('alpha');
    const suggestions = page.getByRole('listbox', { name: 'Kereső termékjavaslatai' });
    const option = suggestions.getByRole('option', { name: 'Alpha teszttermék ALPHA-001', exact: true });
    await expect(option).toBeVisible();
    await expect(suggestions.getByRole('option')).toHaveCount(1);
    await page.screenshot({ path: 'tests/Browser/.runtime/movement-search-suggestions.png', fullPage: true });
    await option.click();
    await expect(search).toHaveValue('');
    const params = new URL(page.url()).searchParams;
    for (const [key, value] of Object.entries({ product_id: '1', type: 'out', sort_by: 'quantity', sort_dir: 'asc', per_page: '25', date_from: '2020-01-01', date_to: '2099-12-31' })) {
        expect(params.get(key)).toBe(value);
    }
    expect(params.has('page')).toBe(false);
    expect(params.has('search')).toBe(false);
    await expect(page.getByRole('combobox', { name: 'Termék', exact: true })).toHaveValue('Alpha teszttermék (ALPHA-001)');
});

test('main search supports accent-insensitive multiword suggestions and keyboard selection', async ({ page }) => {
    await page.goto('/movements');
    const search = page.getByRole('combobox', { name: 'Mozgások keresése' });
    await search.fill('ekezetes DEMO-003');
    const option = page.getByRole('listbox', { name: 'Kereső termékjavaslatai' }).getByRole('option');
    await expect(option).toContainText('Ékezetes teszttermék 03');
    await expect(option).toContainText('DEMO-003');
    await search.press('ArrowDown');
    await expect(option).toHaveAttribute('aria-selected', 'true');
    await expect(search).toHaveAttribute('aria-activedescendant', 'movement-search-option-0');
    await search.press('Enter');
    await expect(page).toHaveURL(/product_id=5/);
    await expect(search).toHaveValue('');
    expect(new URL(page.url()).searchParams.has('search')).toBe(false);
});

test('main search Enter remains free text unless a suggestion is selected and Escape closes suggestions', async ({ page }) => {
    await page.goto('/movements');
    const search = page.getByRole('combobox', { name: 'Mozgások keresése' });
    await search.fill('Alpha');
    await expect(search).toHaveAttribute('aria-expanded', 'true');
    await search.press('Enter');
    await expect(search).toHaveValue('Alpha');
    expect(new URL(page.url()).searchParams.get('search')).toBe('Alpha');
    expect(new URL(page.url()).searchParams.get('product_id')).toBe('');
    await search.click();
    await search.press('ArrowDown');
    await search.press('Escape');
    await expect(search).toHaveAttribute('aria-expanded', 'false');
    await expect(search).toHaveValue('Alpha');
    await search.fill('MOZ-000001');
    await expect(page.getByText('Nincs egyező termékjavaslat. A megadott szövegre továbbra is kereshetsz.')).toBeVisible();
    await search.press('Enter');
    await expect(page.getByRole('heading', { name: 'Készletmozgások 1 tétel' })).toBeVisible();
});

test('main search limits suggestions, supports Tab and outside dismissal, and fits mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/movements');
    const search = page.getByRole('combobox', { name: 'Mozgások keresése' });
    await search.fill('ekezetes');
    const suggestions = page.getByRole('listbox', { name: 'Kereső termékjavaslatai' });
    await expect(suggestions.getByRole('option')).toHaveCount(8);
    await expect(page.getByText('Az első 8 termék látható. Pontosítsd a keresést a további találatokhoz.')).toBeVisible();
    const bounds = await suggestions.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
    await search.press('ArrowUp');
    await expect(search).toHaveAttribute('aria-activedescendant', 'movement-search-option-7');
    await search.press('Tab');
    await expect(search).toHaveAttribute('aria-expanded', 'false');
    await search.click();
    await expect(search).toHaveAttribute('aria-expanded', 'true');
    await page.getByRole('heading', { name: /^Készletmozgások/ }).click();
    await expect(search).toHaveAttribute('aria-expanded', 'false');
    await search.fill('');
    await expect(search).toHaveAttribute('aria-expanded', 'false');
});
