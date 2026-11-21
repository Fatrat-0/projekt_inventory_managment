import { expect, test } from '@playwright/test';
import { seedUsableStock } from './movement-fixtures';
import { randomUUID } from 'node:crypto';
import { join } from 'node:path';

const moduleUrl = '/raktari-feladatok/kitarolas';
const intentPage = id => '/atmozgatasok/igenyek/' + id;
const taskPage = id => moduleUrl + '/' + id;
const intentApi = id => '/transfer-intents/' + id;
const taskApi = id => intentApi(id) + '/picking-task';
const reservationApi = id => intentApi(id) + '/stock-reservation';
const adminEmail = 'warehouse-admin@example.test';
const supervisorEmail = 'picking-supervisor@example.test';
const dispatcherEmail = 'picking-dispatch@example.test';
const dispatcherName = 'Kitároló próbafelhasználó';
const pickingWarehouseName = 'Kitárolási próbaraktár';
const unassigned = 'Tárhelyhez nem rendelt készlet';
const locationDescription = 'Az átvételi kapu melletti raklapon.';
const physicalConfirmation = { found: true, description: locationDescription };
const screenshotDir = process.env.PICKING_SCREENSHOT_DIR;

async function login(page, email = adminEmail) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}

async function command(page, method, url, data, expected) {
    const headers = {
        Accept: 'application/json',
        'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content'),
    };
    const response = await page.request.fetch(url, { method, headers, data, maxRedirects: 0 });
    expect(response.status(), await response.text()).toBe(expected);

    return response;
}

async function createProduct(page, name, stock, warehouse = 1) {
    const sku = 'PICK-UI-' + randomUUID().slice(0, 8);
    await command(page, 'POST', '/products', { product_name: name, sku, category_id: 1, price: 1 }, 302);
    const response = await page.request.get('/products?search=' + sku);
    const listing = await response.text();
    const id = Number(listing.match(/href="[^"]*\/products\/(\d+)\/edit/)[1]);
    if (stock > 0) {
        seedUsableStock({ product_id: id, warehouse_id: warehouse, quantity: stock });
    }

    return { id, sku, name };
}

async function createWarehouse(page, name) {
    await command(page, 'POST', '/warehouses', { warehouse_name: name, location: 'Átvétel a 2. kapunál' }, 302);
    await page.goto(`/warehouses?search=${encodeURIComponent(name)}`);
    return warehouseId(page, name);
}

async function warehouseId(page, name) {
    const link = await page.getByRole('link', { name, exact: true }).first().getAttribute('href');

    return Number(new URL(link, page.url()).pathname.split('/').at(-1));
}

async function approvedIntent(page, product, quantity, from = 1, to = 2) {
    const response = await command(page, 'POST', '/transfer-intents', {
        operation_key: randomUUID(), product_id: product.id, from_warehouse_id: from, to_warehouse_id: to,
        quantity, priority: 'urgent',
    }, 201);
    const { intent } = await response.json();
    const approval = await command(page, 'PATCH', intentApi(intent.id) + '/approve', {
        operation_key: randomUUID(), version: intent.version,
    }, 200);

    return (await approval.json()).intent;
}

async function createTask(page, intent) {
    const reserved = await command(page, 'POST', reservationApi(intent.id), {
        operation_key: randomUUID(), version: intent.version,
    }, 201);
    const { intent: current } = await reserved.json();
    const created = await command(page, 'POST', taskApi(intent.id), {
        operation_key: randomUUID(), version: current.version,
    }, 201);

    return (await created.json()).intent;
}

async function place(page, id, product, quantity) {
    await command(page, 'POST', id + '/placements', {
        request_key: randomUUID(), product_id: product.id, quantity, cargo_weight_kg: quantity,
    }, 302);
}

async function createSources(page, product, warehouse) {
    const warehouseUrl = '/warehouses/' + warehouse;
    const rackResponse = await command(page, 'POST', warehouseUrl + '/floor-plan/systems', {
        version: 1, kind: 'rack', name: 'Északi szervizfolyosó rakodóállványa', zone: 'Fő szerelőtér',
        code: 'PICK-HOSSZU-AZONOSITO', width: 4, height: 2, rack_height: 2, x: 1, y: 1,
        levels: 1, bins: 1, bin_weight: 200, level_weight: 200, system_weight: 200,
    }, 201);
    const { id } = await rackResponse.json();
    await page.goto(warehouseUrl + '/storage-systems/' + id);
    const rackBin = await page.getByRole('link', { name: 'Tárhely adatlapja', exact: true }).getAttribute('href');
    await place(page, new URL(rackBin, page.url()).pathname, product, 40);

    const standaloneCode = 'PICK-NYUGATI-SZERELOFOLYOSO';
    await createStandaloneSource(page, product, warehouse, standaloneCode, 30);

    return { rackCode: 'PICK-HOSSZU-AZONOSITO-01-01', standaloneCode };
}

async function createStandaloneSource(page, product, warehouse, code, quantity) {
    const response = await command(page, 'POST', `/warehouses/${warehouse}/locations`, {
        code, name: 'Nyugati alkatrész-előkészítő folyosó meghosszabbított megnevezésű rekesze',
        kind: 'shelf', max_weight_kg: 200,
    }, 302);
    await place(page, new URL(response.headers().location, page.url()).pathname, product, quantity);
}

function byName(page, name) {
    return page.getByRole('button', { name, exact: true });
}

function byRegion(page, name) {
    return page.getByRole('region', { name, exact: true });
}

function visibleSource(page, code) {
    return page.locator('[data-picking-source]:visible').filter({ hasText: code });
}

function draftField(page, code) {
    return page.getByRole('textbox', { name: 'Mennyiség · ' + code, exact: true });
}

const applyAdvice = page => byName(page, 'Ajánlás használata');
const unassignedSource = page => page.locator('[data-picking-unassigned]');

async function addUnassigned(page, quantity) {
    await byName(page, 'Kézi forráshelyválasztás').click();
    await byName(unassignedSource(page), 'Forrás hozzáadása').click();
    await draftField(page, unassigned).fill(String(quantity));
}

function physicalFound(dialog) {
    return dialog.getByRole('checkbox', { name: 'Ellenőriztem, hogy a terméket fizikailag megtaláltam.', exact: true });
}

function physicalLocation(dialog) {
    return dialog.getByRole('textbox', { name: /^Hol találtad meg a terméket\?/ });
}

async function confirmPhysicalLocation(dialog) {
    await physicalLocation(dialog).fill(locationDescription);
    await physicalFound(dialog).check();
}

async function openPickConfirmation(page) {
    await byName(page, 'Kitárolás rögzítése').click();
    const dialog = page.getByRole('dialog', { name: 'Kitárolás megerősítése', exact: true });
    await expect(dialog).toBeVisible();

    return dialog;
}

async function readJson(page, path) {
    const response = await page.request.get(path, { headers: { Accept: 'application/json' } });
    expect(response.ok(), await response.text()).toBe(true);

    return response.json();
}

async function assertViewport(page, width, state) {
    const viewport = await page.evaluate(() => ({
        html: document.documentElement.scrollWidth, body: document.body.scrollWidth, width: innerWidth,
    }));
    expect(viewport.html, state + ' document at ' + width + ' px').toBeLessThanOrEqual(viewport.width);
    expect(viewport.body, state + ' body at ' + width + ' px').toBeLessThanOrEqual(viewport.width);
}

async function capture(page, name, fullPage = true) {
    if (!screenshotDir) return;
    await page.screenshot({ path: join(screenshotDir, name + '.png'), fullPage, animations: 'disabled' });
}

test('the supervisor secures stock and opens a task, then a dispatcher uses advice and manual sources through completion', async ({ page, browser, baseURL }) => {
    test.setTimeout(90000);
    await login(page);
    await page.goto('/warehouses?search=' + encodeURIComponent(pickingWarehouseName));
    const sourceWarehouse = await warehouseId(page, pickingWarehouseName);
    const product = await createProduct(page, 'Kitárolási munkalap próbaáru', 80, sourceWarehouse);
    const { rackCode, standaloneCode } = await createSources(page, product, sourceWarehouse);
    const shortage = await approvedIntent(page, product, 100, sourceWarehouse);
    const intent = await approvedIntent(page, product, 75, sourceWarehouse);

    const supervisorContext = await browser.newContext({ baseURL });
    const dispatcherContext = await browser.newContext({ baseURL });
    try {
        const supervisor = await supervisorContext.newPage();
        await login(supervisor, supervisorEmail);
        const preparation = byRegion(supervisor, 'Készlet és előkészítés');
        const reserve = byName(preparation, 'Készlet biztosítása');
        const makeTask = byName(preparation, 'Kitárolási feladat létrehozása');
        const release = byName(preparation, 'Foglalás feloldása');
        await supervisor.goto(intentPage(shortage.id));
        await expect(preparation).toContainText('Nincs készletfoglalás');
        await reserve.click();
        await expect(preparation).toContainText(/80 db foglalható/i);
        expect((await readJson(supervisor, reservationApi(shortage.id))).reservation).toBeNull();

        await supervisor.goto(intentPage(intent.id));
        await expect(preparation).toContainText('Még biztosítandó');
        await expect(preparation).toContainText('75 db');
        await expect(makeTask).toHaveCount(0);
        await reserve.click();
        await expect(preparation).toContainText('Aktív foglalás');
        await expect(release).toBeVisible();
        await makeTask.click();
        await expect(preparation.getByRole('link', { name: 'Kitárolási feladat megnyitása', exact: true }))
            .toHaveAttribute('href', new RegExp(taskPage(intent.id) + '(?:\\?task=\\d+)?$'));
        await capture(supervisor, '06-igeny-elokeszitese');

        const dispatcher = await dispatcherContext.newPage();
        await login(dispatcher, dispatcherEmail);
        await dispatcher.goto(moduleUrl + '?search=' + product.sku);
        await expect(dispatcher.getByRole('heading', { name: /^Kitárolási feladatok\b/, level: 1 })).toBeVisible();
        await expect(dispatcher.getByText(product.sku, { exact: true })).toBeVisible();
        await dispatcher.getByRole('link', { name: intent.reference, exact: true }).click();
        await expect(dispatcher).toHaveURL(new RegExp(taskPage(intent.id) + '(?:\\?task=\\d+)?$'));
        await expect(byName(dispatcher, 'Ajánlott forráshelyek')).toBeVisible();
        await expect(visibleSource(dispatcher, rackCode)).toBeVisible();
        await expect(visibleSource(dispatcher, standaloneCode)).toBeVisible();
        await expect(visibleSource(dispatcher, unassigned)).toHaveCount(0);
        await expect(dispatcher.locator('[data-picking-history]')).not.toContainText(dispatcherName);

        const plan = visibleSource(dispatcher, rackCode).getByRole('link', { name: /^Megtekintés az alaprajzon/ });
        const [planPage] = await Promise.all([dispatcher.waitForEvent('popup'), plan.click()]);
        await expect(planPage).toHaveURL(/\/alaprajz\/\d+\?task=\d+$/);
        await expect(planPage.locator('[data-app-topbar]').getByRole('link', { name: 'Kitárolás', exact: true }))
            .toHaveAttribute('aria-current', 'page');
        await expect(planPage.locator('[data-picking-source=' + rackCode + ']')).toBeVisible();
        await expect(planPage.locator('[data-floor-tile^=system-][aria-pressed=true]')).toBeVisible();
        await expect(byName(planPage, 'Tervrajz szerkesztése')).toHaveCount(0);
        await capture(planPage, '07-alaprajz');
        await planPage.close();

        await applyAdvice(dispatcher).click();
        await expect(dispatcher.getByRole('heading', { name: 'Rögzítendő kitárolás', exact: true })).toBeFocused();
        await expect(dispatcher.getByRole('status').filter({ hasText: /2 forrás hozzáadva, összesen 70 db/ })).toBeVisible();
        await expect(draftField(dispatcher, rackCode)).toHaveValue('40');
        await expect(draftField(dispatcher, standaloneCode)).toHaveValue('30');
        await expect(draftField(dispatcher, unassigned)).toHaveCount(0);
        expect((await readJson(dispatcher, taskApi(intent.id))).allocations).toHaveLength(0);
        await draftField(dispatcher, rackCode).fill('41');
        await expect(byName(dispatcher, 'Kitárolás rögzítése')).toBeDisabled();
        await draftField(dispatcher, rackCode).fill('20');
        await draftField(dispatcher, standaloneCode).fill('10');
        await addUnassigned(dispatcher, 10);
        const confirm = await openPickConfirmation(dispatcher);
        await expect(confirm).toContainText(product.name);
        await expect(confirm).toContainText(rackCode);
        await expect(confirm).toContainText('40 db');
        await expect(byName(confirm, 'Rögzítés')).toBeDisabled();
        await confirmPhysicalLocation(confirm);
        await byName(confirm, 'Rögzítés').click();
        await expect(confirm).not.toBeVisible();
        await expect(dispatcher.locator('[data-picking-history]')).toContainText(dispatcherName);
        await expect(dispatcher.locator('[data-picking-history]')).toContainText(standaloneCode);
        await expect(dispatcher.locator('[data-picking-history]')).toContainText(locationDescription);
        await expect(visibleSource(dispatcher, rackCode)).toContainText('A rekesz terhelése ellenőrzésre vár.');
        await capture(dispatcher, '08-reszleges-kitarolas');
        expect((await readJson(dispatcher, taskApi(intent.id))).task).toMatchObject({ staged_quantity: 40, status: 'in_progress' });
        const partial = await readJson(dispatcher, `/movements/stock?warehouse_id=${sourceWarehouse}&product_id=${product.id}`);
        expect(partial).toMatchObject({ physical_quantity: 80, staged_quantity: 40, source_reserved_quantity: 35 });
        const remainingStock = await readJson(dispatcher, `/transfers/picking-sources?from_warehouse_id=${sourceWarehouse}&product_id=${product.id}`);
        expect(remainingStock.available_quantity).toBe(5);

        await supervisor.reload();
        await expect(byName(preparation, 'Foglalás feloldása')).toHaveCount(0);
        const current = await readJson(supervisor, taskApi(intent.id));
        await command(supervisor, 'PATCH', reservationApi(intent.id) + '/release', {
            operation_key: randomUUID(), version: current.version,
        }, 409);

        await byName(dispatcher, 'Kézi forráshelyválasztás').click();
        await dispatcher.getByRole('searchbox', { name: 'Forráshely keresése' }).fill('fő szerelőtér');
        await expect(visibleSource(dispatcher, standaloneCode)).toHaveCount(0);
        await byName(visibleSource(dispatcher, rackCode), 'Forrás hozzáadása').click();
        await draftField(dispatcher, rackCode).fill('20');
        await dispatcher.getByRole('searchbox', { name: 'Forráshely keresése' }).fill(standaloneCode);
        await byName(visibleSource(dispatcher, standaloneCode), 'Forrás hozzáadása').click();
        await draftField(dispatcher, standaloneCode).fill('15');
        await byName(await openPickConfirmation(dispatcher), 'Rögzítés').click();
        await expect(dispatcher.locator('[data-picking-task]')).toContainText('Kitárolva');
        await expect(dispatcher.locator('[data-picking-history]')).toContainText(unassigned);
        await expect(dispatcher.locator('[data-picking-history]').getByText(standaloneCode, { exact: false }).first()).toBeVisible();
        await expect(byName(dispatcher, 'Kitárolás rögzítése')).not.toBeVisible();
        await expect(byName(dispatcher, 'Szállítás indítása')).toHaveCount(0);
        const completed = await readJson(dispatcher, taskApi(intent.id));
        expect(completed.task).toMatchObject({ staged_quantity: 75, status: 'picked' });
        expect(completed.free_quantity).toBe(5);
        expect(completed.allocations).toHaveLength(5);
        expect(completed.allocations.filter(row => row.location_code === standaloneCode).map(row => row.quantity)).toEqual([10, 15]);
        expect(completed.allocations.find(row => row.source_type === 'unassigned')).toMatchObject({
            performer_name: dispatcherName, unassigned_confirmation: physicalConfirmation,
        });
        const after = await readJson(dispatcher, `/movements/stock?warehouse_id=${sourceWarehouse}&product_id=${product.id}`);
        expect(after).toMatchObject({ physical_quantity: 80, staged_quantity: 75, source_reserved_quantity: 0 });
        expect((await readJson(dispatcher, '/movements/stock?warehouse_id=2&product_id=' + product.id)).physical_quantity).toBe(0);
        await supervisor.reload();
        await expect(preparation).toContainText('Összekészítve');
        await expect(preparation).toContainText('75 db');
    } finally {
        await supervisorContext.close();
        await dispatcherContext.close();
    }
});

test('unassigned stock is a manual choice with an auditable physical check, and empty sources cannot be submitted', async ({ page }) => {
    await login(page);
    const source = await createWarehouse(page, 'Tárhely nélküli próbaraktár');
    const product = await createProduct(page, 'Tárhely nélküli próbaáru', 60, source);
    const intent = await createTask(page, await approvedIntent(page, product, 10, source));
    const endpoint = new RegExp(taskApi(intent.id) + '/recommendations(?:\\?.*)?$');

    // Represent a source-data change after reservation without editing the test database directly.
    await page.route(endpoint, async route => {
        const response = await route.fetch();
        const data = await response.json();
        const sources = data.sources.map(source => ({ ...source, stored_quantity: 0, available_quantity: 0 }));
        await route.fulfill({ response, json: { ...data, available_quantity: 0, sources, suggestion: [] } });
    }, { times: 1 });
    await page.setViewportSize({ width: 1440, height: 960 });
    await page.goto(taskPage(intent.id));
    await expect(page.getByRole('status').filter({ hasText: 'Jelenleg nincs ehhez a feladathoz kitárolható készlet.' })).toBeVisible();
    await expect(applyAdvice(page)).not.toBeVisible();
    await expect(byName(page, 'Tárhely nélküli készlet kiválasztása')).not.toBeVisible();
    await expect(byName(page, 'Kitárolás rögzítése')).toBeDisabled();
    await assertViewport(page, 1440, 'Nincs használható készlet');
    await capture(page, '09-nincs-hasznalhato-forras');

    await byName(page, 'Forráshelyek frissítése').click();
    const advice = page.getByRole('list', { name: 'Ajánlott forráshelyek listája' });
    await expect(page.getByText('Nem található kitárolásra alkalmas rekesz.', { exact: true })).toBeVisible();
    await expect(advice).not.toBeVisible();
    await expect(applyAdvice(page)).not.toBeVisible();
    await expect(page.locator('[data-picking-allocation]')).toHaveCount(0);
    await capture(page, '10-tarhely-nelkuli-ajanlas-1440');

    await page.setViewportSize({ width: 375, height: 800 });
    await byName(page, 'Tárhely nélküli készlet kiválasztása').click();
    const manual = unassignedSource(page);
    await expect(manual.getByRole('heading', { name: unassigned, exact: true })).toBeFocused();
    await expect(manual).toContainText('A készlet szerepel a raktár nyilvántartásában, de nincs hozzá konkrét rekesz megadva.');
    await expect(manual).toContainText('60 db');
    await expect(manual).toContainText('10 db');
    await assertViewport(page, 375, 'Kézi, tárhely nélküli forrás');
    await capture(page, '11-tarhely-nelkuli-kezi-375');
    await byName(manual, 'Forrás hozzáadása').click();
    const quantity = draftField(page, unassigned);
    await quantity.fill('11');
    await expect(byName(page, 'Kitárolás rögzítése')).toBeDisabled();
    await quantity.fill('10');
    const dialog = await openPickConfirmation(page);
    const save = byName(dialog, 'Rögzítés');
    await expect(physicalFound(dialog)).not.toBeChecked();
    await physicalLocation(dialog).fill(locationDescription);
    await expect(save).toBeDisabled();
    await physicalFound(dialog).check();
    await physicalLocation(dialog).fill('   ');
    await expect(save).toBeDisabled();
    expect((await readJson(page, taskApi(intent.id))).allocations).toHaveLength(0);
    await physicalLocation(dialog).fill(locationDescription);
    await assertViewport(page, 375, 'Fizikai megtalálás megerősítése');
    await capture(page, '12-megtalalas-megerositese-375', false);
    await save.click();
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('[data-picking-history]')).toContainText('Fizikai megtalálás visszaigazolva');
    await expect(page.locator('[data-picking-history]')).toContainText(locationDescription);
    const status = await readJson(page, taskApi(intent.id));
    expect(status.task).toMatchObject({ status: 'picked', staged_quantity: 10 });
    expect(status.free_quantity).toBe(50);
    expect(status.allocations).toHaveLength(1);
    expect(status.allocations[0]).toMatchObject({ source_type: 'unassigned', quantity: 10, unassigned_confirmation: physicalConfirmation });
    expect(status.allocations[0].performer_name).toBeTruthy();
    expect((await readJson(page, `/movements/stock?warehouse_id=${source}&product_id=${product.id}`)).physical_quantity).toBe(60);
});

test('a lost success response retries the same pick, but a conflict requires refresh and a new explicit confirmation', async ({ page }) => {
    test.setTimeout(70000);
    await login(page);
    const product = await createProduct(page, 'Műveleti azonosító próbaáru', 30);
    const intent = await createTask(page, await approvedIntent(page, product, 20));
    await page.goto(taskPage(intent.id));
    await addUnassigned(page, 4);
    const amount = draftField(page, unassigned);
    const dialog = await openPickConfirmation(page);
    await confirmPhysicalLocation(dialog);

    const requests = [];
    let releaseLostResponse;
    const lostResponse = new Promise(resolve => { releaseLostResponse = resolve; });
    await page.route('**' + taskApi(intent.id) + '/picks', async route => {
        const payload = route.request().postDataJSON();
        requests.push(payload);
        if (requests.length !== 1) return route.continue();
        const response = await route.fetch();
        expect(response.ok(), await response.text()).toBe(true);
        await lostResponse;
        await route.abort('failed');
    });
    const save = dialog.getByRole('button', { name: /^Rögzítés(?:…)?$/ });
    await save.evaluate(button => { button.click(); button.click(); });
    try {
        await expect.poll(async () => (await readJson(page, taskApi(intent.id))).task.staged_quantity).toBe(4);
        await expect(save).toBeDisabled();
        expect(requests).toHaveLength(1);
    } finally {
        releaseLostResponse();
    }
    await expect(save).toBeEnabled();
    await expect(dialog).toContainText('4 db');
    await expect(amount).toBeDisabled();
    await save.click();
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('[data-picking-history]')).toContainText('4 db');
    expect(requests).toHaveLength(2);
    expect(requests[1]).toEqual(requests[0]);
    expect((await readJson(page, taskApi(intent.id))).allocations).toHaveLength(1);
    await page.unroute('**' + taskApi(intent.id) + '/picks');

    await addUnassigned(page, 3);
    await openPickConfirmation(page);
    await confirmPhysicalLocation(dialog);
    const current = await readJson(page, taskApi(intent.id));
    await command(page, 'POST', taskApi(intent.id) + '/picks', {
        version: current.version, quantity: 2, operation_key: randomUUID(),
        source_allocations: [{ source_type: 'unassigned', location_id: null, quantity: 2 }], unassigned_confirmation: physicalConfirmation,
    }, 200);
    page.on('request', request => {
        if (request.method() === 'POST' && request.url().endsWith('/picking-task/picks')) requests.push(request.postDataJSON());
    });
    await save.click();
    await expect(dialog.getByRole('alert')).toContainText(/közben megváltozott/);
    await expect(save).toBeDisabled();
    await byName(dialog, 'Feladat és készlet frissítése').click();
    await expect(dialog).not.toBeVisible();
    await expect(amount).toHaveValue('3');
    await expect(page.locator('[data-picking-history]')).toContainText('2 db');
    expect(requests).toHaveLength(3);
    await openPickConfirmation(page);
    await expect(physicalLocation(dialog)).toHaveValue(locationDescription);
    await expect(physicalFound(dialog)).not.toBeChecked();
    await physicalFound(dialog).check();
    await byName(dialog, 'Rögzítés').click();
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('[data-picking-history]')).toContainText('3 db');
    expect(requests).toHaveLength(4);
    expect(requests[3].operation_key).not.toBe(requests[2].operation_key);
    expect(requests[3].version).toBeGreaterThan(requests[2].version);
    const result = await readJson(page, taskApi(intent.id));
    expect(result.task.staged_quantity).toBe(9);
    expect(result.allocations.map(event => event.quantity)).toEqual([4, 2, 3]);
});

test('a response from an older task version cannot become an actionable recommendation', async ({ page }) => {
    await login(page);
    const source = await createWarehouse(page, 'Elavult ajánlás próbaraktár');
    const code = 'FRISS-01';
    const product = await createProduct(page, 'Elavult ajánlás próbaáru', 25, source);
    await createStandaloneSource(page, product, source, code, 25);
    const intent = await createTask(page, await approvedIntent(page, product, 15, source));
    const endpoint = new RegExp(taskApi(intent.id) + '/recommendations(?:\\?.*)?$');
    await page.route(endpoint, async route => {
        const response = await route.fetch();
        expect(response.ok()).toBe(true);
        const received = await response.json();
        await route.fulfill({ response, json: { ...received, version: received.version - 1 } });
    }, { times: 1 });
    await page.goto(taskPage(intent.id));
    await expect(page.getByRole('alert')).toContainText(/feladat időközben megváltozott/i);
    await expect(applyAdvice(page)).not.toBeVisible();
    await expect(page.locator('[data-picking-allocation]')).toHaveCount(0);
    await expect(byName(page, 'Kitárolás rögzítése')).toBeDisabled();
    await byName(page, 'Feladat és készlet frissítése').click();
    await applyAdvice(page).click();
    await expect(draftField(page, code)).toHaveValue('15');
    expect((await readJson(page, taskApi(intent.id))).allocations).toHaveLength(0);

    await page.route(endpoint, route => route.abort('failed'), { times: 1 });
    await byName(page, 'Forráshelyek frissítése').click();
    await expect(page.getByRole('alert')).toHaveText('A kapcsolat megszakadt. Próbáld újra a frissítést.');
    await expect(draftField(page, code)).toHaveValue('15');
    await expect(byName(page, 'Kitárolás rögzítése')).toBeDisabled();
    await byName(page, 'Forráshelyek frissítése').click();
    await expect(byName(page, 'Kitárolás rögzítése')).toBeEnabled();

    await page.route(endpoint, route => route.fulfill({ status: 403, json: { message: 'A raktári jogosultság megszűnt.' } }), { times: 1 });
    await byName(page, 'Forráshelyek frissítése').click();
    const blocked = page.getByRole('alert', { name: '' });
    await expect(blocked).toHaveText('A raktári jogosultság megszűnt.');
    await expect(blocked).toBeFocused();
    await expect(byName(page, 'Kitárolás rögzítése')).not.toBeVisible();
    await expect(page.locator('[data-picking-allocation]')).toHaveCount(0);
    await expect(page.locator('[data-picking-history]')).not.toBeVisible();
});

test('task list, long names, partial work, confirmation and completed work fit all requested widths', async ({ page }) => {
    test.setTimeout(160000);
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    const widths = [1440, 1024, 768, 375, 320];
    const captureWidth = async (state, width, fullPage) => {
        await assertViewport(page, width, state);
        await capture(page, state + '-' + width, fullPage);
    };
    await login(page);
    const longSource = 'Központi áruelosztó és műszaki készlet-előkészítő regionális bemutatóraktár';
    const longTarget = 'Dél-dunántúli szerelőipari alkatrészek fogadó és ellenőrző logisztikai központja';
    const source = await createWarehouse(page, longSource);
    const target = await createWarehouse(page, longTarget);
    const product = await createProduct(page, 'Rozsdamentes hosszú szárú szerelőkészlet megerősített ipari csomagolással és rögzítőelemekkel', 80, source);
    const { rackCode, standaloneCode } = await createSources(page, product, source);
    const intent = await createTask(page, await approvedIntent(page, product, 65, source, target));

    for (const width of widths) {
        await page.setViewportSize({ width, height: width <= 375 ? 800 : 960 });
        await page.goto(moduleUrl + '?search=' + product.sku);
        const row = page.getByRole('row').filter({ hasText: product.sku });
        await expect(row.getByText(longSource)).toBeVisible();
        await expect(row.getByText(longTarget)).toBeVisible();
        await captureWidth('01-feladatlista', width);
        await row.getByRole('link', { name: intent.reference, exact: true }).click();
        await expect(page.getByText(product.name, { exact: true })).toBeVisible();
        await expect(visibleSource(page, rackCode)).toBeVisible();
        await expect(visibleSource(page, standaloneCode)).toBeVisible();
        await expect(visibleSource(page, unassigned)).toHaveCount(0);
        await captureWidth('02-ajanlasok', width);

        await byName(page, 'Kézi forráshelyválasztás').focus();
        await page.keyboard.press('Enter');
        const search = page.getByRole('searchbox', { name: 'Forráshely keresése' });
        await expect(search).toBeFocused();
        await search.fill('fő szerelőtér');
        await expect(visibleSource(page, standaloneCode)).toHaveCount(0);
        await byName(visibleSource(page, rackCode), 'Forrás hozzáadása').click();
        const draft = draftField(page, rackCode);
        await expect(draft).toBeFocused();
        await draft.fill('5');
        const dialog = await openPickConfirmation(page);
        await expect(byName(dialog, 'Vissza')).toBeFocused();
        const bounds = await dialog.boundingBox();
        expect(bounds.x, 'Dialog left at ' + width).toBeGreaterThanOrEqual(0);
        expect(bounds.x + bounds.width, 'Dialog right at ' + width).toBeLessThanOrEqual(width);
        await captureWidth('03-megerosites', width, false);
        await page.keyboard.press('Escape');
        await expect(dialog).not.toBeVisible();
        await expect(draft).toHaveValue('5');
    }

    const state = await readJson(page, taskApi(intent.id));
    const recommended = await readJson(page, taskApi(intent.id) + '/recommendations');
    await command(page, 'POST', taskApi(intent.id) + '/picks', {
        version: state.version, quantity: 65, operation_key: randomUUID(),
        source_allocations: recommended.suggestion,
    }, 200);

    for (const width of widths) {
        await page.setViewportSize({ width, height: width <= 375 ? 800 : 960 });
        await page.goto(taskPage(intent.id));
        await expect(page.getByText('A kitárolás befejeződött.', { exact: true })).toBeVisible();
        await expect(page.locator('[data-picking-history]')).toContainText(standaloneCode);
        await captureWidth('04-kitarolva', width);
        await page.goto(moduleUrl + '?search=nincs-ilyen-kitárolási-feladat');
        await expect(page.getByText('Nincs a szűrésnek megfelelő kitárolási feladat.', { exact: true })).toBeVisible();
        await captureWidth('05-ures-lista', width);
    }
    expect(pageErrors).toEqual([]);
});
