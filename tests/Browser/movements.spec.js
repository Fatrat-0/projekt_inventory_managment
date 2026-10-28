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
    await expect(page.getByRole('searchbox', { name: 'Mozgások keresése' })).toHaveValue(reference);
});
