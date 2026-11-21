import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { shipmentCommand } from './shipment-fixtures';

const fixture = id => JSON.parse(execFileSync('php', ['tests/Browser/manual-issue-fixture.php', ...(id ? [String(id)] : [])], { encoding: 'utf8' }));
const quantity = page => page.getByLabel('Mennyiség (db)', { exact: true });
const check = page => page.getByRole('button', { name: 'Kiadás ellenőrzése', exact: true });
const review = page => page.getByRole('dialog', { name: 'Kiadás megerősítése' });
const advise = page => page.getByRole('button', { name: 'Smart Picking ajánlás', exact: true });
const sourceQuantity = (page, name) => page.getByRole('textbox', { name: name + ' · Kiadandó darab', exact: true });
const summary = page => page.getByRole('complementary', { name: 'Kiadás összegzése' });

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}

async function open(page, data) {
    await login(page);
    await page.goto('/movements/create?' + new URLSearchParams({ product_id: data.product_id, warehouse_id: data.warehouse_id }));
    await page.getByLabel('Mozgás típusa', { exact: true }).selectOption('out');
    await expect(sourceQuantity(page, 'Tárhely nélküli készlet')).toHaveValue('1');
}

async function addBin(page, code) {
    const picker = page.getByRole('combobox', { name: 'Rekesz keresése' });
    await picker.fill(code);
    await expect(page.getByRole('option', { name: new RegExp('^' + code) })).toBeVisible();
    await picker.press('ArrowDown');
    await picker.press('Enter');
    await page.getByRole('button', { name: 'Forráshely hozzáadása' }).click();
}

test('a mixed Smart Picking issue books once after a lost response and shows its source history', async ({ page }) => {
    const data = fixture();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await open(page, data);
    await quantity(page).fill('45');
    await expect(sourceQuantity(page, 'Tárhely nélküli készlet')).toHaveValue('20');
    await expect(summary(page)).toContainText('Még 25 db kijelölendő.');
    await addBin(page, 'A-01');
    await sourceQuantity(page, 'A-01').fill('10');
    await advise(page).click();
    const advice = page.getByRole('region', { name: 'Smart Picking ajánlás' });
    await expect(advice).toContainText('A-01');
    await expect(advice).toContainText('A-02');
    await expect(advice).toContainText('A rekesz terhelése ellenőrzésre vár.');
    await expect(advice.getByRole('link', { name: 'Megjelenítés az alaprajzon' })).toHaveAttribute('href', new RegExp('location=' + data.bins['A-01']));
    await advice.getByRole('button', { name: 'Ajánlás használata' }).click();
    await expect(sourceQuantity(page, 'Tárhely nélküli készlet')).toHaveValue('20');
    await expect(sourceQuantity(page, 'A-01')).toHaveValue('20');
    await expect(sourceQuantity(page, 'A-02')).toHaveValue('5');
    await page.getByLabel('Bizonylatszám vagy megjegyzés').fill('KIADÁSI PRÓBA');
    await expect(summary(page)).toContainText('45 db');
    await check(page).click();
    await expect(review(page)).toContainText('KIADÁSI PRÓBA');
    expect(fixture(data.stock).quantity).toBe(65);

    const issuedKeys = [];
    await page.route('**/movements', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        issuedKeys.push(route.request().postDataJSON().request_key);
        if (issuedKeys.length !== 1) return route.continue();
        const response = await route.fetch();
        expect(response.ok()).toBe(true);
        await route.abort();
    });
    const confirm = review(page).getByRole('button', { name: 'Kiadás véglegesítése' });
    await confirm.evaluate(button => { button.click(); button.click(); });
    await expect(review(page)).toContainText('A kapcsolat megszakadt');
    await review(page).getByRole('button', { name: 'Mentés újrapróbálása' }).click();
    await expect(page).toHaveURL(/\/movements\/\d+/);
    const history = page.getByRole('region', { name: 'Kiadási forráshelyek' });
    await expect(history.getByRole('link', { name: 'A-01' })).toBeVisible();
    await expect(history).toContainText('Tárhely nélküli készlet');
    expect(issuedKeys).toHaveLength(2);
    expect(issuedKeys[0]).toBe(issuedKeys[1]);
    const saved = fixture(data.stock);
    expect(saved.quantity).toBe(20);
    expect(saved.bins).toEqual({ [data.bins['A-02']]: 15, [data.bins['Z-01']]: 5 });
    expect(saved.movements).toHaveLength(1);
    expect(saved.movements[0].issue_sources.map(source => source.quantity)).toEqual([20, 20, 5]);
    expect(errors).toEqual([]);
});

test('manual selection, partial and late advice, box conversion and narrow layouts keep their state', async ({ page }) => {
    const data = fixture();
    await open(page, data);
    await quantity(page).fill('21');
    await page.getByRole('button', { name: 'Doboz', exact: true }).click();
    await expect(quantity(page)).toHaveValue('21');
    await expect(page.getByText('A megadott mennyiség nem váltható egész dobozokra. A darabszám megmaradt.')).toBeVisible();
    await quantity(page).fill('20');
    await page.getByRole('button', { name: 'Doboz', exact: true }).click();
    await expect(page.getByLabel('Mennyiség (doboz)', { exact: true })).toHaveValue('2');
    await page.getByRole('button', { name: 'Darab', exact: true }).click();
    await expect(quantity(page)).toHaveValue('20');
    await quantity(page).fill('70');
    await advise(page).click();
    await expect(page.getByText(/Részleges ajánlás · 60 db/)).toBeVisible();
    await expect(check(page)).toBeDisabled();

    await quantity(page).fill('30');
    await page.getByRole('button', { name: 'Tárhely nélküli készlet eltávolítása' }).click();
    await addBin(page, 'A-01');
    await addBin(page, 'A-02');
    await expect(sourceQuantity(page, 'A-02')).toHaveValue('10');
    await expect(page.getByRole('combobox', { name: 'Rekesz keresése' })).toBeHidden();
    await sourceQuantity(page, 'A-02').fill('25');
    await expect(page.getByText('Ebből a forrásból legfeljebb 20 db adható ki.')).toBeVisible();
    await expect(check(page)).toBeDisabled();
    await sourceQuantity(page, 'A-02').fill('10');
    await expect(check(page)).toBeEnabled();
    await sourceQuantity(page, 'A-02').fill('9');

    let release;
    const delayed = new Promise(resolve => { release = resolve; });
    const adviceStarted = page.waitForRequest(request => request.url().includes('/movements/out/sources') && request.url().includes('quantity=30'));
    await page.route(/\/movements\/out\/sources\?.*quantity=30(?:&|$)/, async route => { await delayed; await route.continue(); });
    await advise(page).click();
    await adviceStarted;
    await quantity(page).fill('31');
    const adviceReturned = page.waitForResponse(response => response.url().includes('/movements/out/sources') && response.url().includes('quantity=30'));
    release();
    await adviceReturned;
    await expect(page.getByRole('region', { name: 'Smart Picking ajánlás' })).toBeHidden();
    await expect(sourceQuantity(page, 'A-02')).toHaveValue('9');
    await expect(check(page)).toBeDisabled();
    await quantity(page).fill('30');
    await sourceQuantity(page, 'A-02').fill('10');
    for (const [width, height] of [[1440, 900], [1280, 800], [1024, 768], [768, 800], [375, 667], [320, 568]]) {
        await page.setViewportSize({ width, height });
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await expect(check(page)).toBeEnabled();
    }
    let releaseValidation;
    const delayedValidation = new Promise(resolve => { releaseValidation = resolve; });
    await page.route('**/movements/out/validate', async route => { await delayedValidation; await route.continue(); }, { times: 1 });
    const validationStarted = page.waitForRequest('**/movements/out/validate');
    await check(page).click();
    await validationStarted;
    await quantity(page).fill('31');
    const validationReturned = page.waitForResponse('**/movements/out/validate');
    releaseValidation();
    await validationReturned;
    await expect(review(page)).toBeHidden();
    await expect(check(page)).toBeDisabled();
    await quantity(page).fill('30');
    await check(page).press('Enter');
    await expect(review(page)).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(review(page)).toBeHidden();
    await expect(check(page)).toBeFocused();
    expect(fixture(data.stock).quantity).toBe(65);
});

test('a bin emptied after preflight keeps the failed draft and needs fresh validation', async ({ page }) => {
    const data = fixture();
    await open(page, data);
    await quantity(page).fill('40');
    await page.getByRole('button', { name: 'Tárhely nélküli készlet eltávolítása' }).click();
    await addBin(page, 'A-01');
    await addBin(page, 'A-02');
    await check(page).click();
    await expect(review(page)).toBeVisible();
    await shipmentCommand(page, 'POST', '/movements', { product_id: data.product_id, warehouse_id: data.warehouse_id,
        type: 'out', quantity: 20, request_key: randomUUID(), source_allocations: [{ source_type: 'location', location_id: data.bins['A-01'], quantity: 20 }],
    }, 200);
    const keys = [];
    await page.route('**/movements', route => {
        if (route.request().method() === 'POST') keys.push(route.request().postDataJSON().request_key);
        return route.continue();
    });
    await review(page).getByRole('button', { name: 'Kiadás véglegesítése' }).click();
    await expect(review(page)).toBeHidden();
    await expect(summary(page)).toContainText('nem rendelkezik');
    await expect(sourceQuantity(page, 'A-01')).toHaveValue('20');
    await page.getByRole('button', { name: 'Elérhetőség frissítése' }).click();
    await expect(page.getByText('Ez a forrás jelenleg nem használható.')).toBeVisible();
    await expect(sourceQuantity(page, 'A-01')).toHaveAttribute('aria-invalid', 'true');
    await expect(sourceQuantity(page, 'A-02')).not.toHaveAttribute('aria-invalid', 'true');
    await expect(check(page)).toBeDisabled();
    await page.getByRole('button', { name: 'A-01 eltávolítása' }).click();
    await page.getByRole('button', { name: 'Tárhely nélküli készlet hozzáadása' }).click();
    await check(page).click();
    await review(page).getByRole('button', { name: 'Kiadás véglegesítése' }).click();
    await expect(page).toHaveURL(/\/movements\/\d+/);
    expect(keys).toHaveLength(2);
    expect(keys[1]).not.toBe(keys[0]);
    expect(fixture(data.stock).quantity).toBe(5);
});

test('only loose stock is automatically assigned and never shows a bin suggestion action', async ({ page }) => {
    await open(page, fixture('loose'));
    await quantity(page).fill('20');
    await expect(sourceQuantity(page, 'Tárhely nélküli készlet')).toHaveValue('20');
    await expect(advise(page)).toBeHidden();
    await expect(page.getByText('A kiadás tárhely nélküli készletből teljesíthető.')).toBeVisible();
    await expect(check(page)).toBeEnabled();
    await sourceQuantity(page, 'Tárhely nélküli készlet').fill('21');
    await expect(summary(page)).toContainText('1 db többlet van kijelölve.');
    await expect(check(page)).toBeDisabled();
});

const wideTest = test.extend({
    wideIssue: async ({}, use) => {
        const data = fixture('wide');
        try {
            await use(data);
        } finally {
            execFileSync('php', ['tests/Browser/manual-issue-fixture.php', 'remove-wide', String(data.stock), String(data.partner_id)]);
        }
    },
});

wideTest('six sources and long names fit the workspace and keyboard review at desktop and mobile sizes', async ({ page, wideIssue: data }) => {
    await open(page, data);
    await quantity(page).fill('65');
    const partner = page.getByRole('combobox', { name: 'Partner', exact: true });
    await partner.fill('Elrendezési próbavevő');
    await page.getByRole('option', { name: data.partner, exact: false }).click();
    await advise(page).click();
    await page.getByRole('button', { name: 'Ajánlás használata' }).click();

    const sourceTable = page.getByRole('table', { name: 'Kiválasztott források' });
    await expect(sourceTable.locator('tbody tr')).toHaveCount(6);
    for (const name of [data.product, data.warehouse, data.partner]) await expect(summary(page)).toContainText(name);
    await expect(page.getByText('Kijelölve: 65 db / 65 db')).toBeVisible();
    for (const [width, height] of [[1440, 900], [1280, 800], [1024, 768], [768, 800], [375, 667], [320, 568]]) {
        await page.setViewportSize({ width, height });
        const product = await page.getByLabel('Termék', { exact: true }).boundingBox();
        const warehouse = await page.getByLabel('Raktár', { exact: true }).boundingBox();
        const amount = await quantity(page).boundingBox();
        const customer = await partner.boundingBox();
        const left = await page.getByRole('region', { name: 'Forráshelyek' }).boundingBox();
        const right = await summary(page).boundingBox();
        if (width >= 768) {
            expect(product.y).toBeCloseTo(warehouse.y, 0);
            expect(amount.y).toBeCloseTo(customer.y, 0);
            expect(product.x + product.width).toBeLessThan(warehouse.x);
            expect(amount.x + amount.width).toBeLessThan(customer.x);
        }
        if (width >= 1024) {
            expect(left.x + left.width).toBeLessThan(right.x);
            expect(left.width / (left.width + right.width)).toBeCloseTo(2 / 3, 1);
            if (width === 1440) expect(left.width + right.width).toBeGreaterThan(1100);
        } else expect(left.y + left.height).toBeLessThan(right.y);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await expect(check(page)).toBeEnabled();
    }

    await check(page).press('Enter');
    await expect(review(page)).toBeVisible();
    await expect(review(page).getByRole('heading')).toBeFocused();
    await expect(review(page).getByRole('listitem')).toHaveCount(6);
    for (const name of [data.product, data.warehouse, data.partner]) await expect(review(page)).toContainText(name);
    const dialog = await review(page).boundingBox();
    const confirm = await review(page).getByRole('button', { name: 'Kiadás véglegesítése' }).boundingBox();
    expect(dialog.x + dialog.width).toBeLessThanOrEqual(320);
    expect(confirm.y + confirm.height).toBeLessThanOrEqual(568);
    await page.keyboard.press('Escape');
    await expect(check(page)).toBeFocused();
    expect(fixture(data.stock).movements).toEqual([]);
});

test('OUT narrows either picker on mobile and IN restores products without stock', async ({ page }) => {
    const data = fixture('options');
    await page.setViewportSize({ width: 375, height: 667 });
    await login(page);
    await page.goto('/movements/create');
    const type = page.getByLabel('Mozgás típusa', { exact: true });
    const product = page.getByRole('combobox', { name: 'Termék', exact: true });
    const warehouse = page.getByRole('combobox', { name: 'Raktár', exact: true });
    const productOption = name => page.getByRole('listbox', { name: 'Terméktalálatok' }).getByRole('option', { name });
    const warehouseOption = name => page.getByRole('listbox', { name: 'Raktártalálatok' }).getByRole('option', { name });
    const selectProduct = page.locator('input[name=product_id]');
    const selectWarehouse = page.locator('input[name=warehouse_id]');
    const inactiveProduct = /DEMO-010/;
    const stockedProduct = new RegExp(data.sku);
    await type.selectOption('out');
    await product.fill('DEMO-010');
    await expect(page.getByText('Nincs kiadható készlettel egyező találat.').filter({ visible: true })).toBeVisible();
    await expect(productOption(inactiveProduct)).toHaveCount(0);
    await product.fill(data.sku);
    await productOption(stockedProduct).click();
    await warehouse.fill('Teszt raktár');
    await expect(warehouseOption(/^Teszt raktár/)).toHaveCount(0);
    await warehouse.fill(data.warehouse);
    await expect(warehouseOption(/^Kiadási szűrőpróba/)).toContainText('Kitárolható: 60 db · Közvetlenül: 20 db');
    await warehouse.press('ArrowDown');
    await warehouse.press('Enter');
    await expect(selectWarehouse).toHaveValue(String(data.warehouse_id));
    await quantity(page).fill('75');
    await expect(summary(page)).toContainText('Jelenleg legfeljebb 60 db tárolható ki.');
    await expect(selectWarehouse).toHaveValue(String(data.warehouse_id));

    await page.getByRole('button', { name: 'Termék törlése a mezőből' }).click();
    await product.fill('ALPHA-001');
    await expect(productOption(/ALPHA-001/)).toHaveCount(0);
    await product.fill(data.sku);
    await expect(productOption(stockedProduct)).toContainText('Kitárolható: 60 db');
    await productOption(stockedProduct).click();
    await expect(selectProduct).toHaveValue(String(data.product_id));
    await page.getByRole('button', { name: 'Raktár törlése a mezőből' }).click();
    await page.getByRole('button', { name: 'Termék törlése a mezőből' }).click();
    await warehouse.fill('Teszt raktár');
    await warehouseOption(/^Teszt raktár/).click();
    await product.fill('ALPHA-001');
    await productOption(/ALPHA-001/).click();
    await expect(selectProduct).toHaveValue('1');

    await type.selectOption('in');
    await product.fill('DEMO-010');
    await productOption(inactiveProduct).click();
    await expect(selectProduct).not.toHaveValue('');
    await type.selectOption('out');
    await expect(page.getByText(/korábbi termékválasztást töröltük/)).toBeVisible();
    await expect(selectProduct).toHaveValue('');
    await expect(selectWarehouse).toHaveValue('');
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
});

test('an old OUT choice response cannot replace a new product filter', async ({ page }) => {
    await login(page);
    await page.goto('/movements/create');
    await page.getByLabel('Mozgás típusa').selectOption('out');
    const product = page.getByRole('combobox', { name: 'Termék', exact: true });
    const warehouse = page.getByRole('combobox', { name: 'Raktár', exact: true });
    let release;
    const delayed = new Promise(resolve => { release = resolve; });
    const first = /\/movements\/out\/options\?product_id=1$/;
    await page.route(first, async route => {
        await delayed;
        await route.fulfill({ json: { products: [], warehouses: [] } });
    }, { times: 1 });
    const started = page.waitForRequest(first);
    await product.fill('ALPHA-001');
    await page.getByRole('option', { name: /ALPHA-001/ }).click();
    await started;
    await product.fill('ZETA-002');
    await page.getByRole('option', { name: /ZETA-002/ }).click();
    await warehouse.fill('Teszt raktár');
    const current = page.getByRole('option', { name: /Teszt raktár.*Kitárolható/ });
    await expect(current).toBeVisible();
    const returned = page.waitForResponse(first);
    release();
    await returned;
    await expect(current).toBeVisible();
    await current.click();
    await expect(page.locator('input[name=product_id]')).toHaveValue('2');
    await expect(page.locator('input[name=warehouse_id]')).toHaveValue('1');
});
