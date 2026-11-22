import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { shipmentCommand as command } from './shipment-fixtures';
import { verifyPutawayDialogIsolation } from './putaway-dialog-regression';

const modulePath = '/raktari-feladatok/athelyezes';
const fixture = key => JSON.parse(execFileSync('php', ['tests/Browser/warehouse-relocation-fixture.php', key], { encoding: 'utf8' }));
const button = (page, name) => page.getByRole('button', { name, exact: true });
const combobox = (page, name) => page.getByRole('combobox', { name, exact: true });
const modal = page => page.getByRole('dialog', { name: 'Áthelyezés megerősítése', exact: true });
const reviewButton = page => button(page, 'Áthelyezés ellenőrzése');
const bin = (page, code) => page.locator('[data-putaway-location="' + code + '"]');
const idFields = data => ({ warehouse_id: data.warehouse_id, product_id: data.product_id });
const quantity = (page, units = 'db') => page.getByRole('textbox', { name: 'Áthelyezendő mennyiség (' + units + ')', exact: true });

async function login(page, useLegacyForm = true) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await button(page, 'Log in').click();
    await expect(page).toHaveURL(/dashboard/);
    if (useLegacyForm) {
        await page.goto(modulePath);
        await page.getByRole('link', { name: '+ Új áthelyezés', exact: true }).click();
    }
}

async function choose(page, label, search, match, keyboard = false) {
    const input = combobox(page, label);
    await input.fill(search);
    const option = page.getByRole('option', { name: match });
    await expect(option).toHaveCount(1);
    if (keyboard) {
        await input.press('ArrowDown');
        await input.press('Enter');
    } else await option.click();
}

async function selectSource(page, data, productFirst = false) {
    if (productFirst) {
        await choose(page, 'Termék', data.sku, new RegExp(data.sku), true);
        const warehouse = combobox(page, 'Raktár');
        await warehouse.fill(data.remote_name);
        await expect(page.getByRole('listbox', { name: 'Raktártalálatok' }).getByRole('option')).toHaveCount(0);
        await warehouse.press('Escape');
    }
    await choose(page, 'Raktár', data.warehouse_name.slice(0, 28), new RegExp(data.warehouse_name.trim()), productFirst);
    if (!productFirst) {
        const input = combobox(page, 'Termék');
        const ready = page.waitForResponse(response => new URL(response.url()).searchParams.get('search') === data.loose_sku
            && new URL(response.url()).pathname.endsWith('/forrasok'));
        await input.fill(data.loose_sku);
        await ready;
        await expect(page.getByRole('listbox', { name: 'Terméktalálatok' }).getByRole('option')).toHaveCount(0);
        await choose(page, 'Termék', data.sku, new RegExp(data.sku));
    }
    await choose(page, 'Forrásrekesz', 'A-01', /A-01/, productFirst);
    await expect(page.getByText('Áthelyezhető legfeljebb 40 db.')).toBeVisible();
}

async function dimensions(page, width = 30) {
    const group = page.locator('details').filter({ hasText: 'Külső méretek (cm)' }).first();
    if (await group.getAttribute('open') === null) await group.locator('summary').click();
    await page.getByLabel('Szélesség (cm)', { exact: true }).fill(String(width));
    await page.getByLabel('Mélység (cm)', { exact: true }).fill('20');
    await page.getByLabel('Magasság (cm)', { exact: true }).fill('20');
}

async function review(page) {
    await reviewButton(page).click();
    await expect(modal(page)).toBeVisible();
    await expect(modal(page).getByRole('heading')).toBeFocused();
}

async function confirm(page) {
    await modal(page).getByRole('checkbox', { name: /fizikailag áthelyeztem/ }).check();
    await button(modal(page), 'Áthelyezés rögzítése').click();
}

async function sizes(page, checkDialog = false) {
    for (const width of [1440, 1024, 768, 375, 320]) {
        const height = width < 600 ? 650 : 860;
        await page.setViewportSize({ width, height });
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        if (checkDialog) {
            const box = await modal(page).boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
            expect(box.y + box.height).toBeLessThanOrEqual(height + 1);
        }
    }
}

async function stock(page, data) {
    const url = '/movements/stock?' + new URLSearchParams({ ...idFields(data), details: '1' });
    return (await page.request.get(url)).json();
}

async function audit(page, data) {
    return (await page.request.get(modulePath + '/elozmenyek?' + new URLSearchParams(idFields(data)))).json();
}

test('partial relocation uses recommendation, explicit zero and site check, followed by the audit page', async ({ page }) => {
    test.setTimeout(75_000);
    const data = fixture('RESZ');
    await login(page);
    await selectSource(page, data);
    await quantity(page).fill('10');
    await button(page, 'Doboz').click();
    await expect(quantity(page, 'doboz')).toHaveValue('1');
    await expect(page.locator('aside [data-relocation-source-check]')).toHaveText('Utólagos terhelésellenőrzés szükséges');
    await page.getByLabel('Mért bruttó tömeg (kg)', { exact: true }).fill('3,5');
    await button(page, 'Ajánlások frissítése').click();
    await expect(bin(page, 'B-01')).toContainText('Rakományadatok szükségesek');
    await button(bin(page, 'B-01'), 'Kiválasztás').click();
    await expect(reviewButton(page)).toBeDisabled();
    const picked = button(bin(page, 'B-01'), 'Kiválasztva');
    await expect(picked).toBeFocused();
    await expect(page.locator('[data-putaway-selection]')).toBeHidden();
    await bin(page, 'B-01').locator('summary').click();
    const position = bin(page, 'B-01').getByRole('link', { name: /Megtekintés az alaprajzon/ });
    const [map] = await Promise.all([page.waitForEvent('popup'), position.click()]);
    await expect(map.locator('[data-putaway-plan]')).toContainText('B-01');
    await map.close();
    await expect(quantity(page, 'doboz')).toHaveValue('1');
    await expect(picked).toBeVisible();
    await expect(page.getByLabel('Raklaphelyigény', { exact: true })).toHaveValue('');
    await page.getByLabel('Raklaphelyigény', { exact: true }).fill('0');
    await sizes(page);
    await reviewButton(page).click();
    const site = page.getByRole('checkbox', { name: /ellenőriztem, hogy a rakomány elfér/ });
    await expect(site).toBeVisible();
    await expect(modal(page)).toBeHidden();
    await site.check();
    await review(page);
    await expect(modal(page)).toContainText('0 · Nem raklapos');
    await expect(modal(page)).toContainText('30 db');
    await expect(modal(page).locator('[data-relocation-target-check]')).toHaveText('Helyszíni ellenőrzéssel igazolt');
    await expect(modal(page).locator('[data-relocation-source-check]')).toHaveText('Utólagos terhelésellenőrzés szükséges');
    await sizes(page, true);
    await page.keyboard.press('Escape');
    await expect(reviewButton(page)).toBeFocused();
    await review(page);
    await confirm(page);
    await expect(page.getByText('10 db sikeresen áthelyezve: A-01 → B-01.', { exact: true })).toBeVisible();
    await expect(quantity(page, 'doboz')).toBeHidden();
    await expect.poll(() => stock(page, data)).toMatchObject({ physical_quantity: 100, assigned_quantity: 40 });
    await page.getByRole('link', { name: 'Áthelyezés adatlapja', exact: true }).click();
    await expect(page.getByRole('heading', { name: /^REK-/ })).toBeVisible();
    await expect(page.getByText('40 db → 30 db', { exact: true })).toBeVisible();
    await expect(page.getByText('0 db → 10 db', { exact: true })).toBeVisible();
    await expect(page.locator('[data-relocation-target-check]')).toHaveText('Helyszíni ellenőrzéssel igazolt');
    await expect(page.locator('[data-relocation-source-check]')).toHaveText('Utólagos terhelésellenőrzés szükséges');
    await sizes(page);
    await page.getByRole('link', { name: 'Vissza az előzményekhez', exact: true }).click();
    await expect(page.locator('[data-relocation-row]').filter({ hasText: data.sku })).toHaveCount(1);
    await sizes(page);
});

test('product-first keyboard choice and manual target lookup keep the recorded load for a whole move', async ({ page }) => {
    const data = fixture('TELJES');
    await login(page);
    await selectSource(page, data, true);
    await button(page, 'Teljes mennyiség').click();
    await expect(quantity(page)).toHaveValue('40');
    await expect(page.getByText('12 kg', { exact: true }).filter({ visible: true })).toBeVisible();
    await dimensions(page);
    await button(page, 'Kézi rekeszkeresés').click();
    const search = page.getByRole('searchbox', { name: 'Rekesz keresése', exact: true });
    await search.fill('A-01');
    await expect(page.getByText('A keresésre nem található célrekesz ebben a raktárban.')).toBeVisible();
    await search.fill('B-02');
    await expect(bin(page, 'B-02')).toContainText('Nem alkalmas');
    await expect(button(bin(page, 'B-02'), 'Kiválasztás')).toBeHidden();
    let failSearch = true;
    await page.route('**/celhelyek', route => {
        if (failSearch && route.request().postDataJSON()?.search === 'B-01') {
            failSearch = false;
            return route.abort('failed');
        }
        return route.continue();
    });
    await search.fill('B-01');
    await button(page, 'Keresés újrapróbálása').click();
    await expect(bin(page, 'B-01')).toContainText('Ellenőrzött');
    await button(bin(page, 'B-01'), 'Kiválasztás').click();
    await review(page);
    await expect(modal(page)).toContainText('12 kg');
    await confirm(page);
    await expect(page.getByText(/40 db sikeresen áthelyezve/)).toBeVisible();
    await expect.poll(() => audit(page, data)).toMatchObject({
        total: 1, data: [{ load_source: 'recorded', balances: { from_after: 0, to_after: 40, warehouse_physical_quantity: 100 } }],
    });
});

test('stale target and source revisions preserve the draft and require a fresh confirmation', async ({ page }) => {
    test.setTimeout(65_000);
    const data = fixture('STALE');
    await login(page);
    await selectSource(page, data);
    await quantity(page).fill('10');
    await page.getByLabel('Mért bruttó tömeg (kg)').fill('3');
    await page.getByLabel('Raklaphelyigény').fill('0');
    await page.getByLabel('Megjegyzés (opcionális)').fill('Megőrzött megjegyzés');
    await dimensions(page);
    await button(page, 'Ajánlások frissítése').click();
    await button(bin(page, 'B-01'), 'Kiválasztás').click();
    await review(page);
    const external = (from, to) => command(page, 'POST', modulePath, {
        ...idFields(data), operation_key: randomUUID(), confirmed: true,
        from_location_id: data.locations[from], to_location_id: data.locations[to], quantity: 5,
        cargo_weight_kg: 1, pallet_slots: 0, site_verified: true,
    }, 201);

    await external('C-01', 'B-01');
    await confirm(page);
    await expect(modal(page)).toBeHidden();
    await expect(page.getByRole('alert').filter({ hasText: 'A célrekesz kapacitása közben megváltozott.' })).toBeVisible();
    await expect(page.getByLabel('Megjegyzés (opcionális)')).toHaveValue('Megőrzött megjegyzés');
    await expect(quantity(page)).toHaveValue('10');
    await reviewButton(page).click();
    const site = page.getByRole('checkbox', { name: /ellenőriztem, hogy a rakomány elfér/ });
    await site.check();
    await review(page);

    await external('A-01', 'C-01');
    await confirm(page);
    await expect(modal(page)).toBeHidden();
    await expect(page.getByRole('alert').filter({ hasText: /forrásrekesz készlete vagy terhelése megváltozott/ })).toBeVisible();
    await expect(page.getByText('Áthelyezhető legfeljebb 35 db.')).toBeVisible();
    await expect(page.getByLabel('Megjegyzés (opcionális)')).toHaveValue('Megőrzött megjegyzés');
    await expect.poll(() => audit(page, data)).toMatchObject({ total: 2 });
    await expect.poll(() => stock(page, data)).toMatchObject({ physical_quantity: 100 });
});

test('lost save response reuses the same operation key and moves stock only once', async ({ page }) => {
    const data = fixture('UJRA');
    await page.setViewportSize({ width: 375, height: 667 });
    await login(page);
    await selectSource(page, data);
    await button(page, 'Teljes mennyiség').click();
    await dimensions(page);
    await button(page, 'Ajánlások frissítése').click();
    await button(bin(page, 'B-01'), 'Kiválasztás').click();
    const requests = [];
    await page.route('**' + modulePath, async route => {
        if (route.request().method() !== 'POST') return route.continue();
        requests.push(route.request().postDataJSON());
        if (requests.length > 1) return route.continue();
        const booked = await route.fetch();
        expect(booked.status()).toBe(201);
        await route.abort();
    });
    await review(page);
    await modal(page).getByRole('checkbox', { name: /fizikailag áthelyeztem/ }).check();
    await button(modal(page), 'Áthelyezés rögzítése').evaluate(element => { element.click(); element.click(); });
    await button(modal(page), 'Mentés újrapróbálása').click();
    await expect(page.getByText(/40 db sikeresen áthelyezve/)).toBeVisible();
    expect(requests).toHaveLength(2);
    expect(requests[0]).toEqual(requests[1]);
    await expect.poll(() => audit(page, data)).toMatchObject({ total: 1 });
    await expect.poll(() => stock(page, data)).toMatchObject({ physical_quantity: 100, assigned_quantity: 40 });
});

test('warehouse and bin entry points keep their context and the compact rows work with keyboard and narrow viewports', async ({ page }) => {
    const data = fixture('TOBB');
    const single = fixture('EGY');
    const warehouse = '/warehouses/' + data.warehouse_id;
    const relocation = warehouse + '/relocations';
    await page.setViewportSize({ width: 1440, height: 900 });
    await login(page, false);
    await page.goto(warehouse);
    const nav = page.getByRole('navigation', { name: 'Raktári nézetek' });
    await expect(nav.getByRole('link', { name: 'Áthelyezési előzmények', exact: true })).toHaveAttribute('href', new RegExp(relocation));
    await page.getByRole('link', { name: '+ Új áthelyezés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === relocation + '/create');
    await expect(page.locator('#relocation-warehouse')).toHaveText(data.warehouse_name);
    await expect(nav.getByRole('link', { name: 'Áthelyezési előzmények', exact: true })).toHaveAttribute('aria-current', 'page');
    await page.getByRole('link', { name: 'Vissza az előzményekhez', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === relocation);
    await expect(page.getByRole('heading', { name: 'Áthelyezési előzmények' })).toBeVisible();

    await page.goto(warehouse + '/locations/' + data.locations['A-01']);
    await page.getByRole('link', { name: 'Áthelyezés innen', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === relocation + '/create'
        && url.searchParams.get('from_location_id') === String(data.locations['A-01']));
    await expect(page.locator('#relocation-warehouse')).toHaveText(data.warehouse_name);
    await expect(page.locator('#relocation-source')).toContainText('A-01');
    const picker = combobox(page, 'Termék');
    await expect(picker).toHaveValue('');
    await picker.focus();
    await expect(page.getByRole('option', { name: new RegExp(data.sku) })).toBeVisible();
    await expect(page.getByRole('option', { name: new RegExp(data.loose_sku) })).toHaveCount(0);
    await choose(page, 'Termék', data.additional_sku, new RegExp(data.additional_sku), true);
    await expect(page.getByText('Áthelyezhető legfeljebb 7 db.')).toBeVisible();
    await button(page, 'Teljes mennyiség').click();
    await dimensions(page);
    await button(page, 'Ajánlások frissítése').click();
    await expect(bin(page, 'B-01')).toContainText('Ellenőrzött');
    await expect(bin(page, 'B-01').locator('[data-putaway-stock="bin"] strong')).toHaveText('0 → 7 db');
    const sameLevel = bin(page, 'C-01');
    await expect(sameLevel).toContainText('Helyszíni ellenőrzés szükséges');
    await expect(sameLevel.locator('[data-putaway-stock="bin"]')).toHaveText('Ebből a termékből a rekeszben: 3 → 10 db');
    await expect(sameLevel.locator('[data-putaway-stock="level"]')).toHaveText('Ezen a polcszinten: 10 db · változatlan');
    await expect(bin(page, 'B-01').locator('[data-putaway-weight-capacity]')).toHaveText('Szabad teherbírás utána: 23 kg');
    const rackStock = sameLevel.locator('[data-putaway-stock="system"]');
    await expect(rackStock).toBeHidden();
    const rowDetails = bin(page, 'B-01').locator('details');
    await expect(rowDetails).not.toHaveAttribute('open', '');
    await sizes(page);
    await rowDetails.locator('summary').focus();
    await page.keyboard.press('Enter');
    await expect(rowDetails).toHaveAttribute('open', '');
    await expect(rowDetails.getByRole('link', { name: /Megtekintés az alaprajzon/ })).toBeVisible();
    await expect(rowDetails).not.toContainText('23 kg');
    await expect(bin(page, 'B-01').locator('[data-putaway-rank-summary]')).toBeHidden();
    await expect(rowDetails.locator('[data-putaway-rank-detail]')).toContainText('Az ellenőrzött alkalmasságú rekeszek előrébb kerülnek');
    await expect(rowDetails).toContainText('100 × 100 × 100 cm');
    await sameLevel.locator('summary').focus();
    await page.keyboard.press('Enter');
    await expect(rackStock).toBeVisible();
    await expect(rackStock).toHaveText('Ebből a termékből az állványon: 10 db · változatlan');
    await expect(sameLevel.locator('[data-putaway-fit-warnings]')).toContainText(/egybefüggő szabad hely a rakománynak/);
    await sizes(page);
    await button(bin(page, 'B-01'), 'Kiválasztás').click();
    await expect(page.locator('aside [data-relocation-target-check]')).toHaveText('Méret- és kapacitásadatok alapján ellenőrzött');
    await expect(page.locator('aside [data-relocation-source-check]')).toHaveText('Ellenőrzött, nincs további teendő');
    await dimensions(page, 500);
    await expect(bin(page, 'B-01')).toHaveCount(0);
    await expect(page.locator('aside [data-relocation-target-check]')).toHaveText('Az elférés még nem igazolt');
    await expect(page.locator('aside [data-relocation-target-check]')).toHaveText('A célrekesz nem alkalmas');
    await expect(reviewButton(page)).toBeDisabled();
    await expect(modal(page)).toBeHidden();

    await page.goto('/warehouses/' + single.warehouse_id + '/locations/' + single.locations['A-01']);
    await page.getByRole('link', { name: 'Áthelyezés innen', exact: true }).click();
    await expect(combobox(page, 'Termék')).toHaveValue(new RegExp(single.sku));
    await expect(page.getByText('Áthelyezhető legfeljebb 40 db.')).toBeVisible();
    await expect(page.getByText('Kiválasztott célrekesz:', { exact: true })).toBeHidden();
});

test('stale recommendation quantities cannot replace the current projection after a quantity edit', async ({ page }) => {
    const data = fixture('KESOI');
    await login(page);
    await selectSource(page, data);
    const projected = bin(page, 'B-01').locator('[data-putaway-stock="bin"] strong');
    await quantity(page).fill('10');
    const summary = page.locator('[data-relocation-target-stock]');
    await expect(summary).toBeHidden();

    let releaseOld;
    const oldGate = new Promise(resolve => { releaseOld = resolve; });
    let finishOld;
    const oldFinished = new Promise(resolve => { finishOld = resolve; });
    await page.route('**/celhelyek', async route => {
        const request = route.request().postDataJSON();
        if (request.mode !== 'recommendations' || request.quantity !== 10) return route.continue();
        const reply = await route.fetch();
        await oldGate;
        await route.fulfill({ response: reply }).catch(() => {});
        finishOld();
    });
    try {
        const oldRequest = page.waitForRequest(request => request.url().endsWith('/celhelyek')
            && request.postDataJSON()?.mode === 'recommendations' && request.postDataJSON().quantity === 10);
        await oldRequest;
        await quantity(page).fill('15');
        await expect(projected).toHaveText('0 → 15 db');
        releaseOld();
        await oldFinished;
        await expect(projected).toHaveText('0 → 15 db');
        await button(bin(page, 'B-01'), 'Kiválasztás').click();
        await expect(summary).toHaveText('Ebből a termékből a célrekeszben 0 → 15 db');
        await button(page, 'Kézi rekeszkeresés').click();
        const search = page.getByRole('searchbox', { name: 'Rekesz keresése' });
        let releaseSearch;
        const searchGate = new Promise(resolve => { releaseSearch = resolve; });
        let finishSearch;
        const searchFinished = new Promise(resolve => { finishSearch = resolve; });
        const manualTerms = [];
        await page.route('**/celhelyek', async route => {
            const request = route.request().postDataJSON();
            if (request.mode === 'search' && request.search) manualTerms.push(request.search);
            if (request.mode !== 'search' || request.search !== 'B-01') return route.fallback();
            try {
                const reply = await route.fetch();
                await searchGate;
                await route.fulfill({ response: reply }).catch(() => {});
            } finally {
                finishSearch();
            }
        });
        try {
            const previous = page.waitForRequest(request => request.url().endsWith('/celhelyek')
                && request.postDataJSON()?.search === 'B-01');
            await search.fill('B');
            await search.fill('B-01');
            await previous;
            await search.fill('C-01');
            await expect(button(bin(page, 'C-01'), 'Kiválasztás')).toBeVisible();
            releaseSearch();
            await searchFinished;
            await expect(bin(page, 'B-01')).toHaveCount(0);
            await expect(summary).toHaveText('Ebből a termékből a célrekeszben 0 → 15 db');
            expect(manualTerms).toEqual(['B-01', 'C-01']);
        } finally {
            releaseSearch();
        }
        await button(bin(page, 'C-01'), 'Kiválasztás').click();
        await expect(summary).toHaveText('Ebből a termékből a célrekeszben 6 → 21 db');
        await quantity(page).fill('20');
        await expect(summary).toHaveText('Ebből a termékből a célrekeszben Új ellenőrzés szükséges.');
        await expect(bin(page, 'C-01')).toHaveCount(0);
        await expect(summary).toHaveText('Ebből a termékből a célrekeszben 6 → 26 db');
        await quantity(page).fill('0');
        await expect(summary).toBeHidden();
        await expect(page.getByRole('complementary', { name: 'Áthelyezés összegzése' })).toContainText(/Raktári készletváltozás\s*0 db/);
    } finally {
        releaseOld();
    }
});

test('auto advice debounces input, preserves server order and keeps a lower-ranked selection after collapse and mode changes', async ({ page }) => {
    const data = fixture('BOV');
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('crash', () => errors.push('A böngészőlap összeomlott.'));
    await login(page);
    await choose(page, 'Raktár', data.warehouse_name.slice(0, 28), new RegExp(data.warehouse_name.trim()));
    await choose(page, 'Termék', data.sku, new RegExp(data.sku));
    await combobox(page, 'Forrásrekesz').fill('A-01');
    const source = page.getByRole('option', { name: /A-01/ });
    await expect(source).toContainText(/40 db tárolva.*25 db áthelyezhető/);
    await source.click();
    await expect(page.getByText('Áthelyezhető legfeljebb 25 db.')).toBeVisible();
    const calls = [];
    page.on('request', req => {
        if (req.url().endsWith('/celhelyek') && req.postDataJSON()?.mode === 'recommendations') calls.push(req.postDataJSON().quantity);
    });
    const response = page.waitForResponse(res => res.url().endsWith('/celhelyek') && res.request().postDataJSON()?.mode === 'recommendations');
    for (const amount of ['1', '14', '3']) await quantity(page).fill(amount);
    const ranked = (await (await response).json()).recommendations.map(row => row.code);
    expect(ranked).toHaveLength(5);
    expect(calls).toEqual([3]);

    const rows = page.locator('[data-putaway-location]');
    const codes = () => rows.evaluateAll(elements => elements.map(row => row.dataset.putawayLocation));
    await expect(rows).toHaveCount(3);
    expect(await codes()).toEqual(ranked.slice(0, 3));
    await button(page, 'További ajánlások megjelenítése').click();
    await expect(rows).toHaveCount(5);
    expect(await codes()).toEqual(ranked);
    const fourth = ranked[3];
    const firstSummary = bin(page, ranked[0]).locator('summary');
    const fourthSummary = bin(page, fourth).locator('summary');
    const openDetails = page.getByRole('list', { name: 'Rekesztalálatok' }).locator('details[open]');
    await expect(openDetails).toHaveCount(0);
    await firstSummary.press('Enter');
    await expect(bin(page, ranked[0]).locator('details')).toHaveJSProperty('open', true);
    await fourthSummary.click();
    await expect(openDetails).toHaveCount(1);
    await expect(bin(page, ranked[0]).locator('details')).toHaveJSProperty('open', false);
    await fourthSummary.press('Space');
    await expect(openDetails).toHaveCount(0);
    await expect(fourthSummary).toBeFocused();

    await button(bin(page, fourth), 'Kiválasztás').press('Enter');
    await expect(button(bin(page, fourth), 'Kiválasztva')).toBeFocused();
    await button(page, 'Kevesebb megjelenítése').click();
    await expect(rows).toHaveCount(4);
    expect(await codes()).toEqual(ranked.slice(0, 4));
    await expect(button(bin(page, fourth), 'Kiválasztva')).toHaveAttribute('aria-pressed', 'true');
    const selection = page.locator('[data-putaway-selection]');
    await expect(selection).toBeHidden();
    await expect(bin(page, fourth)).toContainText('hosszúrekesznév');
    await sizes(page);
    await button(page, 'Kézi rekeszkeresés').click();
    const manualSearch = page.getByRole('searchbox', { name: 'Rekesz keresése' });
    await expect(manualSearch).toBeFocused();
    await manualSearch.fill('A-01');
    await expect(page.getByRole('status').filter({ hasText: 'A keresésre nem található célrekesz' })).toBeVisible();
    await expect(selection).toContainText('Kiválasztott célrekesz: ' + fourth);
    await expect(selection.getByRole('link', { name: /Megtekintés az alaprajzon/ })).toBeVisible();
    await manualSearch.fill(fourth);
    await expect(button(bin(page, fourth), 'Kiválasztva')).toHaveAttribute('aria-pressed', 'true');
    await expect(selection).toBeHidden();
    await manualSearch.clear();
    await expect(bin(page, ranked[0])).toBeVisible();
    await expect(button(bin(page, fourth), 'Kiválasztva')).toBeVisible();
    await button(page, 'Ajánlott rekeszek').click();
    await expect(rows).toHaveCount(4);
    await expect(selection).toBeHidden();
    await expect(bin(page, fourth)).toContainText('Feltételes');
    await fourthSummary.click();
    await expect(openDetails).toHaveCount(1);
    let failRefresh = true;
    await page.route('**/celhelyek', route => {
        if (failRefresh && route.request().postDataJSON()?.mode === 'recommendations') {
            failRefresh = false;
            return route.abort('failed');
        }
        return route.continue();
    });
    const refresh = button(page, 'Ajánlások frissítése');
    await expect(refresh.locator('svg')).toBeVisible();
    const modes = page.getByRole('group', { name: 'Célrekesz választási módja' });
    await expect(refresh).toHaveAttribute('title', 'Ajánlások frissítése');
    for (const width of [320, 375]) {
        await page.setViewportSize({ width, height: 650 });
        const modeBox = await modes.boundingBox();
        const refreshBox = await refresh.boundingBox();
        expect(refreshBox.x).toBeGreaterThanOrEqual(modeBox.x + modeBox.width);
        expect(refreshBox.width).toBeGreaterThanOrEqual(44);
        expect(refreshBox.height).toBeGreaterThanOrEqual(44);
        const wordLines = await button(modes, 'Kézi rekeszkeresés').evaluate(element => {
            const text = [...element.childNodes].find(node => node.textContent.includes('rekeszkeresés'));
            const start = text.textContent.indexOf('rekeszkeresés');
            const range = document.createRange();
            range.setStart(text, start);
            range.setEnd(text, start + 'rekeszkeresés'.length);
            return range.getClientRects().length;
        });
        expect(wordLines).toBe(1);
    }
    await refresh.press('Enter');
    await expect(page.getByRole('alert').filter({ hasText: 'A kapcsolat megszakadt.' })).toBeVisible();
    await expect(selection.getByText('Új ellenőrzés szükséges')).toBeVisible();
    await expect(selection).toContainText(fourth);
    await refresh.click();
    await expect(rows).toHaveCount(4);
    await expect(selection).toBeHidden();
    await expect(bin(page, fourth)).toContainText('Feltételes');
    await expect(openDetails).toHaveCount(0);
    await sizes(page);
    expect(errors).toEqual([]);
});

test('recommendation cards use short explanations for actual capacity and size advantages', async ({ page }) => {
    test.setTimeout(60_000);
    await login(page, false);
    for (const [key, code, summary, detail] of [
        ['INDOK', 'B-01', 'Az áru elhelyezése után itt kevesebb teherbírás marad kihasználatlanul.', 'fennmaradó teherbírás döntött'],
        ['MERET', 'C-01', 'A rakomány kisebb rekeszben is elfér.', 'kisebb rekesztérfogat döntött'],
        ['AZONOS', 'C-01', 'Itt már tárolunk ebből a termékből.', 'ahol a termék már megtalálható'],
    ]) {
        const data = fixture(key);
        await page.goto(modulePath + '/uj');
        await selectSource(page, data);
        await button(page, 'Teljes mennyiség').click();
        if (key !== 'AZONOS') await dimensions(page);
        const row = bin(page, code);
        const compact = row.locator('[data-putaway-rank-summary]');
        const details = row.locator('details');
        await expect(compact).toHaveText(summary);
        await expect(compact).toBeVisible();
        await expect(details.locator('[data-putaway-rank-detail]')).toBeHidden();
        await details.locator('summary').click();
        await expect(details.locator('[data-putaway-rank-detail]')).toContainText(detail);
        await expect(details.getByText(summary, { exact: true }).filter({ visible: true })).toHaveCount(0);
        if (key === 'AZONOS') {
            await expect(row).toContainText('Feltételes');
            await expect(details.locator('[data-putaway-fit-warnings]')).toContainText('A rakomány külső méretei hiányosak.');
        } else await expect(row).toContainText('Ellenőrzött');
        await sizes(page);
    }
});

test('a late manual search keeps the validated relocation and its confirmation intact', async ({ page }) => {
    const data = fixture('ABLAK');
    await login(page);
    await selectSource(page, data);
    await button(page, 'Teljes mennyiség').click();
    await dimensions(page);
    await button(bin(page, 'B-01'), 'Kiválasztás').click();
    const dialog = modal(page);
    await verifyPutawayDialogIsolation(page, {
        routePattern: '**/celhelyek', code: 'B-01', review: reviewButton(page), dialog,
        save: button(dialog, 'Áthelyezés rögzítése'),
        physical: dialog.getByRole('checkbox', { name: /fizikailag áthelyeztem/ }),
    });
    expect((await audit(page, data)).total).toBe(0);
});

test('a manual search with stale source stock refreshes context and preserves the draft', async ({ page }) => {
    const data = fixture('KER409');
    await login(page);
    await selectSource(page, data);
    await quantity(page).fill('10');
    const weight = page.getByLabel('Mért bruttó tömeg (kg)');
    const note = page.getByLabel('Megjegyzés (opcionális)');
    await weight.fill('3');
    await note.fill('A megjegyzés megmarad.');
    await button(bin(page, 'B-01'), 'Kiválasztás').click();
    await button(page, 'Kézi rekeszkeresés').click();
    await expect(bin(page, 'B-01')).toBeVisible();
    await command(page, 'POST', modulePath, {
        ...idFields(data), operation_key: randomUUID(), confirmed: true,
        from_location_id: data.locations['A-01'], to_location_id: data.locations['C-01'], quantity: 5,
        cargo_weight_kg: 1, pallet_slots: 0, site_verified: true,
    }, 201);

    const replies = [];
    page.on('response', response => {
        if (response.url().endsWith('/celhelyek') && response.request().postDataJSON()?.search === 'C-01') replies.push(response.status());
    });
    const search = page.getByRole('searchbox', { name: 'Rekesz keresése' });
    await search.fill('C-01');
    const repair = button(page, 'Forrás és cél frissítése');
    await expect(repair).toBeVisible();
    expect(replies).toEqual([409]);
    await expect(button(page, 'Keresés újrapróbálása')).toBeHidden();
    await expect(reviewButton(page)).toBeDisabled();
    await repair.press('Enter');

    await expect(bin(page, 'C-01')).toBeVisible();
    expect(replies).toEqual([409, 200]);
    await expect(quantity(page)).toHaveValue('10');
    await expect(weight).toHaveValue('3');
    await expect(note).toHaveValue('A megjegyzés megmarad.');
    await expect(page.getByText('Áthelyezhető legfeljebb 35 db.')).toBeVisible();
    const selected = page.locator('[data-putaway-selection]');
    await expect(selected).toContainText('Kiválasztott célrekesz: B-01');

    await command(page, 'POST', modulePath, {
        ...idFields(data), operation_key: randomUUID(), confirmed: true,
        from_location_id: data.locations['A-01'], to_location_id: data.locations['C-01'], quantity: 35,
        cargo_weight_kg: 8, pallet_slots: 0, site_verified: true, source_load_verified: true,
    }, 201);
    await search.fill('B-01');
    await repair.click();
    await expect(page.getByRole('status').filter({ hasText: 'A korábbi forrásrekeszből már nincs áthelyezhető készlet.' })).toBeVisible();
    await expect(combobox(page, 'Forrásrekesz')).toHaveValue('');
    await expect(note).toHaveValue('A megjegyzés megmarad.');
    await expect(selected.getByText('Új ellenőrzés szükséges')).toBeVisible();
    await expect(reviewButton(page)).toBeDisabled();
});

test('expansion reflects the actual hidden cards with three, four and five recommendations', async ({ page }) => {
    const data = fixture('BOVHATAR');
    await login(page);
    await selectSource(page, data);
    let count = 3;
    let ranked;
    await page.route('**/celhelyek', async route => {
        if (route.request().postDataJSON()?.mode !== 'recommendations') return route.continue();
        const response = await route.fetch();
        const body = await response.json();
        body.recommendations = body.recommendations.slice(0, count);
        ranked = body.recommendations.map(row => row.code);
        await route.fulfill({ response, json: body });
    });
    await quantity(page).fill('3');
    const rows = page.locator('[data-putaway-location]');
    const codes = () => rows.evaluateAll(elements => elements.map(row => row.dataset.putawayLocation));
    const more = button(page, 'További ajánlások megjelenítése');
    const less = button(page, 'Kevesebb megjelenítése');
    const refresh = button(page, 'Ajánlások frissítése');
    await expect(rows).toHaveCount(3);
    await button(rows.last(), 'Kiválasztás').click();
    await expect(more).toBeHidden();
    expect(await codes()).toEqual(ranked);

    count = 4;
    await refresh.click();
    await more.click();
    await expect(rows).toHaveCount(4);
    await button(rows.last(), 'Kiválasztás').click();
    await expect(less).toBeHidden();
    await expect(more).toBeHidden();
    expect(await codes()).toEqual(ranked);
    await expect(button(rows.last(), 'Kiválasztva')).toBeVisible();

    count = 5;
    await refresh.click();
    await more.click();
    await expect(rows).toHaveCount(5);
    await button(rows.last(), 'Kiválasztás').click();
    await less.click();
    await expect(rows).toHaveCount(4);
    expect(await codes()).toEqual([...ranked.slice(0, 3), ranked[4]]);
    await expect(button(rows.last(), 'Kiválasztva')).toBeVisible();
    await expect(more).toBeVisible();
});
