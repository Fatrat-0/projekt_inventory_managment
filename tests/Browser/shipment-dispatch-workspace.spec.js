import { expect, test } from '@playwright/test';
import { seedUsableStock } from './movement-fixtures';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { join } from 'node:path';
import { setBrowserServerTime } from './browser-server-time';
import { plan, shipmentCommand as command } from './shipment-fixtures';

const admin = 'warehouse-admin@example.test';
const api = id => `/shipment-plans/${id}`;
const pageUrl = id => `/shipments/${id}`;
const intentUrl = id => `/transfer-intents/${id}`;
const vehiclesUrl = '/shipment-plans/vehicles';
const registryUrl = '/atmozgatasok/jarmuvek';
const screenshots = process.env.SHIPMENT_DISPATCH_SCREENSHOTS;
const confirmText = 'Ellenőriztem, hogy a teljes rakományt ténylegesen átadták szállításra.';
const action = (scope, name) => scope.getByRole('button', { name, exact: true });
const dialog = (page, name) => page.getByRole('dialog', { name, exact: true });
const item = (page, demand) => page.locator('[data-plan-cargo-item]').filter({ hasText: demand.reference });
const capacity = page => page.locator('[data-plan-capacity]');
const checklist = page => page.locator('[data-plan-checklist]');
const registryRows = page => page.locator('[data-vehicle-id]:visible');
const registryRow = (page, plate) => registryRows(page).filter({ hasText: plate });
const registrySearch = page => page.getByRole('searchbox', { name: 'Jármű keresése', exact: true });
const registryMenu = page => page.getByRole('menu', { name: 'Jármű műveletei', exact: true });
const registryMenuTrigger = row => row.getByRole('button', { name: /^Műveletek:/ });

async function useRegistryAction(page, row, name) {
    await registryMenuTrigger(row).click();
    await registryMenu(page).getByRole('menuitem', { name, exact: true }).click();
}

async function login(page, email = admin) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await action(page, 'Log in').click();
    await expect(page).toHaveURL(/dashboard/);
}

async function json(page, url) {
    const response = await page.request.get(url, { headers: { Accept: 'application/json' } });
    expect(response.ok(), await response.text()).toBe(true);
    return response.json();
}

async function findWarehouse(page, name) {
    await page.goto(`/warehouses?search=${encodeURIComponent(name)}`);
    const href = await page.getByRole('link', { name, exact: true }).getAttribute('href');
    return { id: Number(new URL(href, page.url()).pathname.split('/').at(-1)), name };
}

async function newWarehouse(page, name) {
    await command(page, 'POST', '/warehouses', { warehouse_name: name, location: 'Böngészőpróba' }, 302);
    return findWarehouse(page, name);
}

async function receive(page, product, warehouse, quantity) {
    seedUsableStock({ product_id: product.id, warehouse_id: warehouse.id, quantity });
}

async function product(page, name, prefix, packaging = null) {
    const sku = `${prefix}-${randomUUID().slice(0, 8)}`;
    const data = { sku, product_name: name, category_id: 1, price: 1 };
    if (packaging) data.packaging = packaging;
    await command(page, 'POST', '/products', data, 302);
    const html = await (await page.request.get(`/products?search=${sku}`)).text();
    const id = Number(html.match(/href="[^"]*\/products\/(\d+)\/edit/)?.[1]);
    expect(id).toBeGreaterThan(0);
    return { id, sku, name };
}

async function demand(page, origin, destination, product, quantity) {
    const { intent: pending } = await (await command(page, 'POST', '/transfer-intents', {
        operation_key: randomUUID(), product_id: product.id, from_warehouse_id: origin.id, to_warehouse_id: destination.id,
        quantity, priority: 'normal', shipping_preference: 'groupable',
    }, 201)).json();
    const { intent } = await (await command(page, 'PATCH', `${intentUrl(pending.id)}/approve`, {
        operation_key: randomUUID(), version: pending.version,
    }, 200)).json();
    return intent;
}

async function pick(page, shipmentIntent, version, quantity) {
    return (await command(page, 'POST', `${intentUrl(shipmentIntent.id)}/picking-task/picks`, {
        operation_key: randomUUID(), version, quantity,
        source_allocations: [{ source_type: 'unassigned', location_id: null, quantity }],
        unassigned_confirmation: { found: true, description: 'Ellenőrzött böngészőpróba a rakodótér mellett.' },
    }, 200)).json();
}

async function stage(page, shipmentIntent, quantity = shipmentIntent.quantity) {
    const base = intentUrl(shipmentIntent.id);
    const { intent: reserved } = await (await command(page, 'POST', `${base}/stock-reservation`, {
        operation_key: randomUUID(), version: shipmentIntent.version,
    }, 201)).json();
    const { intent: created } = await (await command(page, 'POST', `${base}/picking-task`, {
        operation_key: randomUUID(), version: reserved.version,
    }, 201)).json();
    return pick(page, shipmentIntent, created.version, quantity);
}

async function createVehicle(page, name, plate, kilograms, slots, active = true) {
    const { vehicle } = await (await command(page, 'POST', vehiclesUrl, {
        operation_key: randomUUID(), name, registration_number: plate, payload_capacity_kg: kilograms,
        pallet_slots_capacity: slots, active,
    }, 201)).json();
    return vehicle;
}

async function useVehicle(page, vehicle) {
    await action(page, 'Jármű kiválasztása').click();
    const picker = dialog(page, 'Jármű kiválasztása');
    await picker.getByLabel('Jármű keresése').fill(vehicle.registration_number);
    await action(picker, `${vehicle.name} · ${vehicle.registration_number}`).click();
    await action(picker, 'Jármű hozzárendelése').click();
    await expect(picker).toBeHidden();
    await expect(page.locator('[data-plan-vehicle]')).toContainText(vehicle.registration_number);
}

async function editItem(page, shipmentIntent) {
    await action(item(page, shipmentIntent), `Tétel szerkesztése · ${shipmentIntent.reference}`).click();
    const editor = dialog(page, 'Tétel rakományadatai');
    await expect(editor).toBeVisible();
    return editor;
}

async function total(page, { trigger = 'Teljes rakomány rögzítése', kilograms, slots, common, checkEmpty = false }) {
    await action(page, trigger).click();
    const editor = dialog(page, 'Teljes rakomány megerősítése');
    await expect(editor).toBeVisible();
    await editor.getByLabel('Teljes bruttó rakománytömeg (kg)').fill(kilograms);
    await editor.getByLabel('Közös rakodási segédanyagok (kg)').fill(common);
    const palletInput = editor.getByLabel('Tényleges raklaphelyigény');
    const save = action(editor, 'Rakomány megerősítése');
    if (checkEmpty) {
        await expect(palletInput).toHaveValue('');
        await save.click();
        await expect(editor.getByRole('alert').filter({ hasText: 'Adj meg egész számot' })).toBeVisible();
    }
    await palletInput.fill(slots);
    await save.click();
    await expect(editor).toBeHidden();
}

async function geometry(page, width) {
    const view = await page.evaluate(() => ({
        documentWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
        dialog: Array.from(document.querySelectorAll('dialog[open]')).map(el => ({
            left: el.getBoundingClientRect().left, right: el.getBoundingClientRect().right,
        })),
    }));
    expect(view.documentWidth, `A teljes oldal szélessége ${width} px-en`).toBeLessThanOrEqual(width);
    for (const bounds of view.dialog) {
        expect(bounds.left).toBeGreaterThanOrEqual(0);
        expect(bounds.right).toBeLessThanOrEqual(width + 1);
    }
}

async function capture(page, name, element = null) {
    if (!screenshots) return;
    const options = { path: join(screenshots, `${name}.png`), animations: 'disabled' };
    if (element) {
        await element.screenshot(options);
        return;
    }
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await page.screenshot({ ...options, fullPage: true });
}

async function balance(page, warehouse, goods) {
    return json(page, `/movements/stock?warehouse_id=${warehouse.id}&product_id=${goods.id}`);
}

async function verifyLoadingPlan(page, shipment, firstToUnload, lastToUnload) {
    const stops = page.locator('[data-plan-loading-stop]');
    await expect(stops).toHaveCount(2);
    await expect(stops.nth(0)).toContainText(lastToUnload.name);
    await expect(stops.nth(1)).toContainText(firstToUnload.name);
    await expect(action(page, 'Rakodólista (PDF)')).toBeVisible();
    const pdf = await page.request.get(`/shipments/${shipment.id}/loading-plan`);
    expect(pdf.status()).toBe(200);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');
}

function readDatabase(shipment, origin, firstStop, secondStop, firstProduct, secondProduct) {
    const ids = [shipment.id, origin.id, firstStop.id, secondStop.id, firstProduct.id, secondProduct.id];
    const raw = execFileSync('php', ['tests/Browser/read-shipment-dispatch.php', ...ids.map(String)], { encoding: 'utf8' });
    return JSON.parse(raw);
}

async function timedDispatchPlan(page, { twoStops = false } = {}) {
    await login(page);
    await setBrowserServerTime(page, '2026-11-23T14:23:37Z');
    const origin = { id: 1 };
    const destination = { id: 2 };
    const token = randomUUID().slice(0, 8);
    const goods = await product(page, 'Időpont-próba', 'FUV-IDOPONT');
    await receive(page, goods, origin, 20);
    const request = await demand(page, origin, destination, goods, 10);
    await stage(page, request);
    const requests = [request];
    if (twoStops) {
        const second = await demand(page, origin, { id: 3 }, goods, 1);
        await stage(page, second);
        requests.push(second);
    }
    const shipment = await plan(page, 'Ellenőrzött indulás ' + token, origin.id, requests.map(row => row.id));
    const base = api(shipment.id);
    const version = async () => (await json(page, base)).shipment.version;
    const update = async (path, fields) => (await (await command(page, 'PATCH', base + path, {
        operation_key: randomUUID(), version: await version(), ...fields,
    }, 200)).json());
    const vehicle = await createVehicle(page, 'Indulási időpont-próba', 'DATE-' + token, 100, 2);
    await update('/vehicle', { vehicle_id: vehicle.id });
    let basis;
    for (const assignment of (await json(page, base)).assignments) {
        const itemCargo = await update('/cargo/items/' + assignment.id, {
            source: 'manual', confirmed: true, gross_weight_grams: assignment.intent.quantity * 1000,
        });
        basis = itemCargo.cargo.basis_revision;
    }
    await update('/cargo', { basis_revision: basis, gross_weight_grams: twoStops ? 11000 : 10000, pallet_slots: 0 });
    await update('/departure', { planned_departure_at: '2026-11-23T15:24:00+01:00' });

    return { shipment, origin, goods, base, update, version };
}

test('a changed departure and changed stop order block dispatch until saved or discarded', async ({ page }) => {
    test.setTimeout(90_000);
    const { shipment, base, update } = await timedDispatchPlan(page, { twoStops: true });
    await page.goto(pageUrl(shipment.id));
    const launch = action(page, 'Fuvar feladása');
    const edit = action(page, 'Tervezett indulás módosítása');
    const field = page.getByLabel('Új tervezett indulás', { exact: true });
    const save = action(page, 'Indulás mentése');
    const handoff = dialog(page, 'Fuvar feladásának megerősítése');
    await expect(launch).toBeEnabled();
    await edit.click();
    await field.fill('2026-11-23T16:30');
    await expect(launch).toBeDisabled();
    await expect(checklist(page)).toContainText('A tervezett indulás módosítása még nincs mentve.');
    await expect(handoff).toBeHidden();
    await page.locator('[data-plan-stop]').first().getByRole('button', { name: /^Hátrébb/ }).click();
    await expect(checklist(page)).toContainText('a megállók módosított sorrendjét');
    await action(page, 'Módosítás elvetése').click();
    await expect(launch).toBeDisabled();
    await action(page.locator('form').filter({ has: field }), 'Mégse').click();
    await launch.click();
    await expect(handoff).toContainText('2026. 11. 23. 15:24');
    await action(handoff, 'Mégse').click();

    await edit.click();
    await field.fill('2026-11-23T16:30');
    await update('/departure', { planned_departure_at: '2026-11-23T15:25:00+01:00' });
    await save.click();
    await expect(page.getByRole('alert').filter({ hasText: 'A fuvarterv verziója megváltozott' })).toBeVisible();
    await expect(field).toHaveValue('2026-11-23T16:30');
    await expect(launch).toBeDisabled();
    await action(page, 'Fuvarterv frissítése').click();
    await expect(save).toBeEnabled();
    await expect(field).toHaveValue('2026-11-23T16:30');

    let release;
    const held = new Promise(resolve => { release = resolve; });
    let onCommit;
    const committed = new Promise(resolve => { onCommit = resolve; });
    await page.route('**' + base + '/departure', async route => {
        const response = await route.fetch();
        onCommit(response.status());
        await held;
        return route.fulfill({ response });
    }, { times: 1 });
    await save.click();
    try {
        expect(await committed).toBe(200);
        await expect(launch).toBeDisabled();
        await expect(field).toBeDisabled();
        await expect(handoff).toBeHidden();
    } finally {
        release();
    }
    await expect(launch).toBeEnabled();
    await launch.click();
    await expect(handoff).toContainText('2026. 11. 23. 16:30');
    await handoff.getByRole('checkbox', { name: confirmText }).check();
    await action(handoff, 'Feladás véglegesítése').click();
    await expect(handoff).toBeHidden();
    const record = await json(page, base + '/dispatch');
    expect(record.dispatch.dispatched_at).toBe('2026-11-23T14:23:37.000000Z');
    expect(record.dispatch.snapshot.shipment.planned_departure_at).toBe('2026-11-23T15:30:00.000000Z');
});

test('lost departure save blocks dispatch until the original edit is recovered, and cancelling a later edit uses the saved time', async ({ page }) => {
    test.setTimeout(75_000);
    const { shipment, base, version } = await timedDispatchPlan(page);
    await page.goto(pageUrl(shipment.id));
    const launch = action(page, 'Fuvar feladása');
    const edit = action(page, 'Tervezett indulás módosítása');
    const field = page.getByLabel('Új tervezett indulás', { exact: true });
    const handoff = dialog(page, 'Fuvar feladásának megerősítése');
    await expect(launch).toBeEnabled();
    const initialVersion = await version();
    const requests = [];
    await page.route('**' + base + '/departure', async route => {
        if (route.request().method() !== 'PATCH') return route.continue();
        requests.push(route.request().postDataJSON());
        const response = await route.fetch();
        expect(response.status()).toBe(200);
        if (requests.length === 1) return route.abort('failed');
        return route.fulfill({ response });
    });
    await edit.click();
    await field.fill('2026-11-23T16:30');
    await action(page, 'Indulás mentése').click();
    const retry = action(page, 'Mentés újrapróbálása');
    await expect(retry).toBeEnabled();
    await expect(launch).toBeDisabled();
    await expect(field).toHaveValue('2026-11-23T16:30');
    await expect(field).toBeDisabled();
    await retry.click();
    await expect(launch).toBeEnabled();
    expect(requests).toHaveLength(2);
    expect(requests[1]).toEqual(requests[0]);
    expect(await version()).toBe(initialVersion + 1);

    await edit.click();
    await field.fill('2026-11-23T16:45');
    await expect(launch).toBeDisabled();
    await action(page.locator('form').filter({ has: field }), 'Mégse').click();
    await launch.click();
    await expect(handoff).toContainText('2026. 11. 23. 16:30');
    await handoff.getByRole('checkbox', { name: confirmText }).check();
    await action(handoff, 'Feladás véglegesítése').click();
    await expect(handoff).toBeHidden();
    expect((await json(page, base + '/dispatch')).dispatch.snapshot.shipment.planned_departure_at).toBe('2026-11-23T15:30:00.000000Z');
});

test('dispatch checks the server clock on preview and confirmation and can recover from an expired plan', async ({ page }) => {
    test.setTimeout(75_000);
    const { shipment, origin, goods, base } = await timedDispatchPlan(page);

    await page.setViewportSize({ width: 375, height: 820 });
    await page.goto(pageUrl(shipment.id));
    const launch = action(page, 'Fuvar feladása');
    const handoff = dialog(page, 'Fuvar feladásának megerősítése');
    const edit = scope => action(scope, 'Tervezett indulás módosítása');
    const time = page.getByLabel('Új tervezett indulás', { exact: true });
    const expired = 'A tervezett indulási időpont már elmúlt. A feladás előtt frissítsd az indulási tervet.';
    const saveTime = async value => {
        await expect(time).toBeFocused();
        await time.fill(value);
        await action(page, 'Indulás mentése').click();
        await expect(time).toBeHidden();
        await expect(launch).toBeEnabled();
    };

    await expect(launch).toBeEnabled();
    await setBrowserServerTime(page, '2026-11-23T14:25:00Z');
    await launch.click();
    await expect(checklist(page)).toContainText(expired);
    await expect(handoff).toBeHidden();
    await expect(launch).toBeDisabled();
    await edit(page).press('Enter');
    await expect(time).toHaveAttribute('min', '2026-11-23T15:25');
    await expect(time).toHaveValue('2026-11-23T15:24');
    await saveTime('2026-11-23T15:25');

    await launch.click();
    await expect(handoff).toBeVisible();
    await handoff.getByRole('checkbox', { name: confirmText }).check();
    await setBrowserServerTime(page, '2026-11-23T14:26:31Z');
    await action(handoff, 'Feladás véglegesítése').click();
    await expect(handoff.getByRole('alert')).toHaveText(expired);
    expect(await balance(page, origin, goods)).toMatchObject({ physical_quantity: 20, staged_quantity: 10 });
    expect((await json(page, base + '/dispatch')).dispatch).toBeNull();

    await edit(handoff).click();
    await expect(time).toHaveAttribute('min', '2026-11-23T15:26');
    await saveTime('2026-11-23T15:26');
    await launch.click();
    await expect(handoff).toBeVisible();
    await handoff.getByRole('checkbox', { name: confirmText }).check();
    const sent = page.waitForRequest(req => req.method() === 'POST' && req.url().endsWith(base + '/dispatch'));
    await action(handoff, 'Feladás véglegesítése').click();
    const attempt = (await sent).postDataJSON();
    await expect(handoff).toBeHidden();
    await expect(page.getByText('A fuvar feladásra került.', { exact: true })).toBeVisible();
    const recorded = await json(page, base + '/dispatch');
    expect(recorded.dispatch.dispatched_at).toBe('2026-11-23T14:26:31.000000Z');
    expect(recorded.transit_items).toHaveLength(1);
    await setBrowserServerTime(page, '2026-11-23T14:27:31Z');
    const replay = await command(page, 'POST', base + '/dispatch', attempt, 200);
    expect((await replay.json()).dispatch).toEqual(recorded.dispatch);
    await page.reload();
    await expect(page.getByText('Tényleges feladás', { exact: true })).toBeVisible();
    await expect(edit(page)).toBeHidden();
    expect(await json(page, '/movements/stock?warehouse_id=1&product_id=' + goods.id + '&details=1'))
        .toMatchObject({ physical_quantity: 10, staged_quantity: 0 });
});

test('three requests on two stops move through the cargo workspace, explicit dispatch and a single booked transit', async ({ page }) => {
    test.setTimeout(180000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    const token = randomUUID().slice(0, 8);
    const origin = await newWarehouse(page, `Központi szerelvényelosztó és készlet-előkészítő raktár ${token}`);
    const pecs = await newWarehouse(page, `Pécs – Dél-dunántúli áruátvételi központ ${token}`);
    const szekszard = await newWarehouse(page, `Szekszárd – Regionális szerelvény-átvevő raktár ${token}`);
    const screw = await product(page, 'Acél csavar, horganyzott ipari rögzítőelem hosszú cikknévvel', 'FUV-CSAVAR', {
        name: 'Doboz', pieces_per_package: 100, gross_weight_kg: '5.500',
    });
    const washer = await product(page, 'Nagy tömegű ipari köracél alátét és teherelosztó szerelvény', 'FUV-ALATET');
    for (const [goods, warehouse, quantity] of [[screw, origin, 380], [washer, origin, 90], [screw, pecs, 7], [washer, szekszard, 11]]) {
        await receive(page, goods, warehouse, quantity);
    }
    const a = await demand(page, origin, pecs, screw, 200);
    const b = await demand(page, origin, szekszard, washer, 50);
    const c = await demand(page, origin, pecs, screw, 100);
    for (const request of [a, b, c]) await stage(page, request);
    const shipment = await plan(page, `Dél-dunántúli bemutatófuvar ${token}`, origin.id, [a.id, b.id, c.id]);
    const small = await createVehicle(page, `Kis teherautó ${token}`, `KIS-${token}`, '800', 3);
    const inactive = await createVehicle(page, `Inaktív teherautó ${token}`, `STOP-${token}`, '1200', 6, false);

    await page.setViewportSize({ width: 1440, height: 960 });
    await page.goto(pageUrl(shipment.id));
    const dispatch = action(page, 'Fuvar feladása');
    await expect(dispatch).toBeDisabled();
    await expect(checklist(page)).toContainText(/még meg kell erősíteni|Még nincs/);
    await expect(item(page, a)).toContainText(screw.sku);
    await expect(item(page, a)).toContainText(/2\s*×\s*5,5\s*kg\s*=\s*11\s*kg/);
    await expect(item(page, a)).toContainText('200 db');
    await expect(action(page, 'Jármű kiválasztása')).toBeEnabled();
    await verifyLoadingPlan(page, shipment, pecs, szekszard);
    await capture(page, '01-hianyos-rakomany-1440');

    await expect(page.locator('#plan-vehicle-registry-title')).toHaveCount(0);
    await page.getByRole('link', { name: 'Járműtörzs kezelése', exact: true }).click();
    await expect(page).toHaveURL(registryUrl);
    await action(page, 'Új jármű').click();
    const registry = dialog(page, 'Új jármű');
    const large = { name: `Bemutató teherautó ${token}`, registration_number: `DEMO-${token}` };
    await registry.getByLabel(/Megnevezés|Jármű neve/).fill(large.name);
    await registry.getByLabel('Rendszám', { exact: true }).fill(large.registration_number);
    await registry.getByLabel('Hasznos teherbírás (kg)').fill('1200');
    await registry.getByLabel('Raklaphelykapacitás').fill('6');
    await action(registry, 'Jármű mentése').click();
    await expect(registryRow(page, large.registration_number)).toContainText(large.name);
    await page.goto(pageUrl(shipment.id));

    await action(page, 'Jármű kiválasztása').click();
    const picker = dialog(page, 'Jármű kiválasztása');
    await picker.getByLabel('Jármű keresése').fill(inactive.registration_number);
    await expect(picker.getByText('Nincs a keresésnek megfelelő aktív jármű.')).toBeVisible();
    await expect(action(picker, `${inactive.name} · ${inactive.registration_number}`)).toHaveCount(0);
    await expect(action(picker, 'Jármű hozzárendelése')).toBeDisabled();
    await action(picker, 'Mégse').click();
    await useVehicle(page, large);
    const vehicle = page.locator('[data-plan-vehicle]');
    await expect(vehicle).toContainText(/1\s*200\s*kg/);
    await expect(vehicle).toContainText(/6\s*(hely|raklaphely)/);

    let editor = await editItem(page, a);
    await editor.getByRole('radio', { name: 'Csomagolásból számított' }).check();
    await action(editor, 'Tételtömeg megerősítése').click();
    await expect(editor).toBeHidden();
    await expect(item(page, a)).toContainText('Megerősítve');

    editor = await editItem(page, c);
    await editor.getByRole('radio', { name: 'Kézzel mért' }).check();
    await editor.getByLabel('Mért bruttó tömeg (kg)').fill('5,75');
    await action(editor, 'Tételtömeg megerősítése').click();
    await expect(editor.getByRole('alert').filter({ hasText: 'Add meg, miért tér el a mért tömeg' })).toBeVisible();
    await editor.getByLabel('Az eltérés indoka').fill('A mérés külön csomagolással történt.');
    await action(editor, 'Tételtömeg megerősítése').click();
    await expect(editor).toBeHidden();
    await expect(item(page, c)).toContainText(/5,75\s*kg/);

    editor = await editItem(page, b);
    await editor.getByRole('radio', { name: 'Kézzel mért' }).check();
    await editor.getByLabel('Mért bruttó tömeg (kg)').fill('958,25');
    await action(editor, 'Tételtömeg megerősítése').click();
    await expect(editor).toBeHidden();

    await total(page, { kilograms: '1000', common: '25', slots: '0', checkEmpty: true });
    const zero = await json(page, `${api(shipment.id)}/cargo`);
    expect(zero.confirmation).toMatchObject({ gross_weight_grams: 1000000, pallet_slots: 0, shared_materials_weight_grams: 25000 });
    await total(page, { trigger: 'Teljes rakomány módosítása', kilograms: '1000', common: '25', slots: '4' });
    await expect(dispatch).toBeEnabled();

    await useVehicle(page, small);
    await expect(page.getByText('Új megerősítés szükséges', { exact: true }).first()).toBeVisible();
    await expect(dispatch).toBeDisabled();
    await total(page, { trigger: 'Új megerősítés', kilograms: '1000', common: '25', slots: '4' });
    await expect(capacity(page)).toContainText(/Túllépés/);
    await expect(capacity(page)).toContainText(/200\s*kg/);
    await expect(checklist(page)).toContainText('A teljes rakomány meghaladja a jármű hasznos teherbírását.');
    await expect(checklist(page)).toContainText('A szükséges raklaphelyek száma meghaladja a jármű raklaphelykapacitását.');
    await expect(dispatch).toBeDisabled();
    await capture(page, '02-tullepett-kapacitas-1440');

    await useVehicle(page, large);
    await total(page, { trigger: 'Új megerősítés', kilograms: '1000', common: '25', slots: '4' });
    await expect(capacity(page)).toContainText(/1\s*000\s*kg/);
    await expect(capacity(page)).toContainText(/1\s*200\s*kg/);
    await expect(capacity(page)).toContainText(/200\s*kg/);
    await expect(dispatch).toBeEnabled();
    await action(page, `Hátrébb · ${pecs.name}`).click();
    await expect(dispatch).toBeDisabled();
    await expect(checklist(page)).toContainText('A feladás előtt mentsd vagy vesd el a megállók módosított sorrendjét.');
    await action(page, 'Módosítás elvetése').click();
    await expect(dispatch).toBeEnabled();
    await capture(page, '03-megerositett-teljes-rakomany-1440');
    await capture(page, '04-sikeres-kapacitas-1440', capacity(page));
    for (const width of [1440, 1024, 768, 375, 320]) {
        await page.setViewportSize({ width, height: width >= 768 ? 960 : 820 });
        await geometry(page, width);
        if (width !== 1440) await capture(page, `05-feladhato-fuvar-${width}`);
    }

    const before = readDatabase(shipment, origin, pecs, szekszard, screw, washer);
    expect(before.shipment[0].status).toBe('draft');
    expect(before.tasks.map(task => [task.status, task.staged_quantity, task.dispatched_at])).toEqual([
        ['picked', 200, null], ['picked', 50, null], ['picked', 100, null],
    ]);
    expect(before.dispatches).toHaveLength(0);
    expect(before.transit).toHaveLength(0);
    expect(await balance(page, origin, screw)).toMatchObject({ physical_quantity: 380, staged_quantity: 300, direct_issue_quantity: 80 });
    expect(await balance(page, origin, washer)).toMatchObject({ physical_quantity: 90, staged_quantity: 50, direct_issue_quantity: 40 });

    await page.setViewportSize({ width: 1440, height: 960 });
    await dispatch.focus();
    await page.keyboard.press('Enter');
    const handoff = dialog(page, 'Fuvar feladásának megerősítése');
    await expect(handoff).toContainText(origin.name);
    await expect(handoff).toContainText(large.registration_number);
    await expect(handoff).toContainText(pecs.name);
    await expect(handoff).toContainText(szekszard.name);
    for (const request of [a, b, c]) await expect(handoff).toContainText(request.reference);
    const confirmation = handoff.getByRole('checkbox', { name: confirmText, exact: true });
    const submit = action(handoff, 'Feladás véglegesítése');
    await expect(confirmation).not.toBeChecked();
    await expect(submit).toBeDisabled();
    await capture(page, '06-feladas-megerosites-1440', handoff);
    await page.setViewportSize({ width: 320, height: 820 });
    await geometry(page, 320);
    await capture(page, '07-mobilos-feladas-320', handoff);

    const sent = [];
    await page.route(`**${api(shipment.id)}/dispatch`, async route => {
        if (route.request().method() !== 'POST') return route.continue();
        sent.push(route.request().postDataJSON());
        if (sent.length !== 1) return route.continue();
        const result = await route.fetch();
        expect(result.status(), await result.text()).toBe(200);
        await route.abort('failed');
    });
    await handoff.getByLabel('Feladási megjegyzés').fill('A plombát és a rakodási sorrendet ellenőriztem.');
    await confirmation.focus();
    await page.keyboard.press('Space');
    await expect(confirmation).toBeChecked();
    await expect(submit).toBeEnabled();
    await submit.evaluate(element => { element.click(); element.click(); });
    const retry = action(handoff, 'Feladás újrapróbálása');
    await expect(retry).toBeEnabled();
    await expect(page.getByText('A fuvar feladásra került.', { exact: true })).toHaveCount(0);
    expect(sent).toHaveLength(1);
    await retry.click();
    await expect(page.getByText('A fuvar feladásra került.', { exact: true })).toBeVisible();
    expect(sent).toHaveLength(2);
    expect(sent[1]).toEqual(sent[0]);
    expect(Object.keys(sent[0]).sort()).toEqual(['confirmed', 'note', 'operation_key', 'version']);
    expect(sent[0]).toMatchObject({ confirmed: true, note: 'A plombát és a rakodási sorrendet ellenőriztem.', version: before.shipment[0].version });

    const transit = page.locator('[data-plan-transit-item]');
    await expect(transit).toHaveCount(3);
    for (const [request, goods, target] of [[a, screw, pecs], [b, washer, szekszard], [c, screw, pecs]]) {
        const row = transit.filter({ hasText: request.reference });
        await expect(row).toContainText(goods.sku);
        await expect(row).toContainText(target.name);
        await expect(row).toContainText(`${request.quantity} db`);
    }
    await expect(page.getByText('Úton', { exact: true }).first()).toBeVisible();
    await expect(dispatch).toBeHidden();
    await expect(action(page, 'Jármű kiválasztása')).toBeHidden();
    await expect(page.getByRole('button', { name: /^Leválasztás · / })).toHaveCount(0);
    await verifyLoadingPlan(page, shipment, pecs, szekszard);
    const after = readDatabase(shipment, origin, pecs, szekszard, screw, washer);
    expect(after.shipment[0].status).toBe('in_transit');
    expect(after.dispatches).toHaveLength(1);
    expect(after.operations).toEqual([{ operation_key: sent[0].operation_key }]);
    expect(after.transit.map(row => [row.transfer_intent_id, row.dispatched_quantity, row.remaining_quantity])).toEqual([
        [a.id, 200, 200], [b.id, 50, 50], [c.id, 100, 100],
    ]);
    expect(after.tasks.every(task => task.status === 'picked' && !!task.dispatched_at)).toBe(true);
    expect(after.tasks.map(task => task.staged_quantity)).toEqual(before.tasks.map(task => task.staged_quantity));
    expect(after.stocks).toEqual(before.stocks.map(stock => stock.warehouse_id !== origin.id ? stock : {
        ...stock, quantity: stock.quantity - (stock.product_id === screw.id ? 300 : 50),
    }));
    expect(after.movements.map(row => [row.product_id, row.type, row.quantity, row.stock_before, row.stock_after])).toEqual([
        [screw.id, 'out', 200, 380, 180], [screw.id, 'out', 100, 180, 80], [washer.id, 'out', 50, 90, 40],
    ]);
    for (const [goods, free] of [[screw, 80], [washer, 40]]) {
        const actual = await balance(page, origin, goods);
        expect(actual.physical_quantity).toBe(free);
        expect(actual.staged_quantity ?? 0).toBe(0);
        expect(actual.direct_issue_quantity ?? actual.quantity).toBe(free);
    }

    const authoritative = await json(page, `${api(shipment.id)}/dispatch`);
    expect(authoritative.dispatch.snapshot.stops.map(stop => [stop.warehouse_id, stop.position, stop.loading_position])).toEqual([
        [pecs.id, 1, 2], [szekszard.id, 2, 1],
    ]);
    const currentVehicle = (await json(page, vehiclesUrl)).data.find(row => row.registration_number === large.registration_number);
    await command(page, 'PATCH', `${vehiclesUrl}/${currentVehicle.id}`, {
        operation_key: randomUUID(), version: currentVehicle.version, name: 'Később átnevezett teherautó',
        payload_capacity_kg: '800', pallet_slots_capacity: 3,
    }, 200);
    await command(page, 'POST', `${api(shipment.id)}/dispatch`, { ...sent[0], operation_key: randomUUID() }, 409);
    await page.setViewportSize({ width: 1440, height: 960 });
    await page.reload();
    await expect(transit).toHaveCount(3);
    await expect(page.getByText('Később átnevezett teherautó', { exact: true })).toHaveCount(0);
    await expect(page.getByText(large.name, { exact: false }).first()).toBeVisible();
    expect(await json(page, `${api(shipment.id)}/dispatch`)).toEqual(authoritative);
    await capture(page, '08-uton-levo-fuvar-1440');
    for (const width of [1440, 1024, 768, 375, 320]) {
        await page.setViewportSize({ width, height: width >= 768 ? 960 : 820 });
        await geometry(page, width);
    }
    await capture(page, '09-tranzittetelek-320', page.locator('[data-plan-transit]'));
    expect(errors).toEqual([]);
});

test('picking and permissions block dispatch, and a stale tab explicitly refreshes the authoritative result', async ({ page, browser, baseURL }) => {
    test.setTimeout(120000);
    await login(page);
    const marker = randomUUID().slice(0, 8);
    const origin = await findWarehouse(page, 'Kitárolási próbaraktár');
    const destination = await newWarehouse(page, `Átvételi próbaraktár ${marker}`);
    const goods = await product(page, `Mért rakomány tétel ${marker}`, 'FUV-MERT');
    await receive(page, goods, origin, 80);
    const first = await demand(page, origin, destination, goods, 65);
    const alternative = await demand(page, origin, destination, goods, 10);
    await stage(page, first, 30);
    const shipment = await plan(page, `Verzióütközés ${marker}`, origin.id, [first.id]);
    const vehicle = await createVehicle(page, `Tesztszállító ${marker}`, `TESZT-${marker}`, '500', 4);
    const url = api(shipment.id);
    await command(page, 'PATCH', `${url}/vehicle`, {
        operation_key: randomUUID(), version: shipment.version, vehicle_id: vehicle.id,
    }, 200);
    let cargo = await json(page, `${url}/cargo`);
    await command(page, 'PATCH', `${url}/cargo/items/${cargo.items[0].assignment_id}`, {
        operation_key: randomUUID(), version: cargo.version, confirmed: true, source: 'manual', gross_weight_grams: 20000,
    }, 200);
    const confirmCargo = async note => {
        const current = await json(page, `${url}/cargo`);
        return command(page, 'PATCH', `${url}/cargo`, {
            operation_key: randomUUID(), version: current.version, basis_revision: current.basis_revision,
            gross_weight_grams: 20000, pallet_slots: 0, reason: note,
        }, 200);
    };
    await confirmCargo('Mért, nem raklapos próbarakomány.');

    await page.goto(pageUrl(shipment.id));
    const release = action(page, 'Fuvar feladása');
    await expect(release).toBeDisabled();
    await expect(checklist(page)).toContainText(/A kitárolás még nem fejeződött be/);
    const taskLink = checklist(page).getByRole('link', { name: `Előkészítés folytatása · ${first.reference}`, exact: true });
    await expect(taskLink).toHaveText('Kitárolási feladat megnyitása');
    await expect(taskLink).toHaveAttribute('href', /raktari-feladatok/);

    let version = (await json(page, `${url}/cargo`)).version;
    await command(page, 'POST', `${url}/intents`, {
        operation_key: randomUUID(), version, intent_ids: [alternative.id],
    }, 200);
    await page.reload();
    await expect(page.getByText('Új megerősítés szükséges', { exact: true }).first()).toBeVisible();
    await expect(release).toBeDisabled();
    version = (await json(page, `${url}/cargo`)).version;
    await command(page, 'DELETE', `${url}/intents/${alternative.id}`, {
        operation_key: randomUUID(), version, reason: 'A tízdarabos igény a következő fuvarra kerül.',
    }, 200);
    const task = await json(page, `${intentUrl(first.id)}/picking-task`);
    await pick(page, first, task.version, 35);
    await page.reload();
    await total(page, { trigger: 'Új megerősítés', kilograms: '20', common: '', slots: '0' });
    await expect(release).toBeEnabled();

    const operatorContext = await browser.newContext({ baseURL });
    try {
        const operator = await operatorContext.newPage();
        await login(operator, 'picking-dispatch@example.test');
        await operator.goto(pageUrl(shipment.id));
        await expect(capacity(operator)).toContainText(/20\s*kg/);
        await expect(action(operator, 'Fuvar feladása')).toBeHidden();
        await expect(action(operator, 'Jármű kiválasztása')).toBeHidden();
        await expect(operator.getByRole('link', { name: 'Járműtörzs kezelése', exact: true })).toHaveCount(0);
        await command(operator, 'POST', `${url}/dispatch`, {
            operation_key: randomUUID(), version: (await json(operator, `${url}/cargo`)).version, confirmed: true,
        }, 403);
    } finally {
        await operatorContext.close();
    }

    await release.click();
    const handoff = dialog(page, 'Fuvar feladásának megerősítése');
    const accepted = handoff.getByRole('checkbox', { name: confirmText, exact: true });
    const submit = action(handoff, 'Feladás véglegesítése');
    await accepted.check();
    await confirmCargo('A másik munkamenet ismét ellenőrizte a tömeget.');
    const posts = [];
    page.on('request', request => {
        if (request.method() === 'POST' && new URL(request.url()).pathname === `${url}/dispatch`) posts.push(request.postDataJSON());
    });
    await submit.click();
    const refresh = action(handoff, 'Fuvarterv frissítése');
    await expect(refresh).toBeEnabled();
    expect(posts).toHaveLength(1);
    expect((await json(page, `${url}/dispatch`)).dispatch).toBeNull();
    await refresh.click();
    await expect(handoff).toBeHidden();
    await expect(release).toBeEnabled();
    expect(posts).toHaveLength(1);

    await release.click();
    await expect(accepted).not.toBeChecked();
    await accepted.check();
    cargo = await json(page, `${url}/cargo`);
    const booking = await (await command(page, 'POST', `${url}/dispatch`, {
        operation_key: randomUUID(), version: cargo.version, confirmed: true, note: 'Másik munkamenetben rögzítve.',
    }, 200)).json();
    await submit.click();
    await expect(refresh).toBeEnabled();
    await refresh.click();
    await expect(page.locator('[data-plan-transit-item]')).toContainText(first.reference);
    await expect(release).toBeHidden();
    expect(posts).toHaveLength(2);
    expect(posts[1].operation_key).not.toBe(posts[0].operation_key);
    expect((await json(page, `${url}/dispatch`)).dispatch.id).toBe(booking.dispatch.id);
});

test('vehicle registry searches, edits and records downtime without altering in-transit shipments', async ({ page, browser, baseURL }) => {
    test.setTimeout(150000);
    await login(page);
    const token = randomUUID().slice(0, 8);
    const origin = await findWarehouse(page, 'Kitárolási próbaraktár');
    const destination = await newWarehouse(page, `Járműállapot próbafuvar célraktár ${token}`);
    const goods = await product(page, `Szervizpróba rakomány ${token}`, 'SZERVIZ');
    await receive(page, goods, origin, 50);
    const vehicle = await createVehicle(page, `Északi fuvarozó teherautó ${token}`, `SZER-${token}`, '1200', 6);
    const patchUrl = `${vehiclesUrl}/${vehicle.id}`;

    async function readyPlan(label) {
        const request = await demand(page, origin, destination, goods, 10);
        await stage(page, request);
        const shipment = await plan(page, label, origin.id, [request.id]);
        const url = api(shipment.id);
        await command(page, 'PATCH', `${url}/vehicle`, {
            operation_key: randomUUID(), version: shipment.version, vehicle_id: vehicle.id,
        }, 200);
        const cargo = await json(page, `${url}/cargo`);
        await command(page, 'PATCH', `${url}/cargo/items/${cargo.items[0].assignment_id}`, {
            operation_key: randomUUID(), version: cargo.version, confirmed: true, source: 'manual', gross_weight_grams: 20000,
        }, 200);
        const prepared = await json(page, `${url}/cargo`);
        await command(page, 'PATCH', `${url}/cargo`, {
            operation_key: randomUUID(), version: prepared.version, basis_revision: prepared.basis_revision,
            gross_weight_grams: 20000, pallet_slots: 1,
        }, 200);
        return { ...shipment, version: (await json(page, `${url}/cargo`)).version };
    }

    const historical = await readyPlan(`Már feladott szervizpróba ${token}`);
    const historyUrl = `${api(historical.id)}/dispatch`;
    await command(page, 'POST', historyUrl, {
        operation_key: randomUUID(), version: historical.version, confirmed: true,
    }, 200);
    const snapshot = await json(page, historyUrl);
    const draft = await readyPlan(`Tervezett szervizpróba ${token}`);
    const unassigned = await plan(page, `Új szervizpróba ${token}`, origin.id);
    await page.goto(pageUrl(draft.id));
    await expect(action(page, 'Fuvar feladása')).toBeEnabled();
    const registryTab = page.getByRole('navigation', { name: 'Átmozgatások modul' })
        .getByRole('link', { name: 'Járművek', exact: true });
    await registryTab.click();
    await expect(page).toHaveURL(registryUrl);
    await expect(registryTab).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('heading', { name: 'Járművek', exact: true })).toBeVisible();

    const filter = page.getByLabel('Állapot', { exact: true });
    const row = registryRow(page, vehicle.registration_number);
    await expect(filter).toHaveValue('active');
    await registrySearch(page).fill('eszaki fuvarozo');
    await expect(row).toBeVisible();
    await registrySearch(page).fill(vehicle.registration_number.toLowerCase());
    await expect(row).toBeVisible();
    await expect(row).toContainText(/1\s*200\s*kg/);
    await expect(row).toContainText(/6\s*(hely|raklaphely)/);
    for (const width of [1440, 1024, 768, 375, 320]) {
        await page.setViewportSize({ width, height: width >= 768 ? 930 : 820 });
        await geometry(page, width);
        await capture(page, `jarmuvek-${width}`);
    }

    const trigger = registryMenuTrigger(row);
    await trigger.press('ArrowDown');
    await expect(registryMenu(page).getByRole('menuitem', { name: 'Kapcsolódó fuvarok', exact: true })).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(registryMenu(page)).toBeHidden();
    await expect(trigger).toBeFocused();

    await useRegistryAction(page, row, 'Kapcsolódó fuvarok');
    const related = dialog(page, 'Kapcsolódó fuvarok');
    await expect(related.getByRole('link', { name: new RegExp(draft.reference) })).toBeVisible();
    await expect(related.getByRole('link', { name: new RegExp(historical.reference) })).toBeVisible();
    await action(related, 'Bezárás').click();

    await useRegistryAction(page, row, 'Inaktiválás');
    const inactivate = dialog(page, 'Jármű inaktiválása');
    await expect(inactivate).toContainText('tervezett fuvarokat');
    await geometry(page, 320);
    await capture(page, 'jarmu-inaktivalas-320', inactivate);
    await inactivate.getByLabel(/Inaktiválás indoka/).fill('Szervizben');
    const inactiveResponse = page.waitForResponse(response =>
        new URL(response.url()).pathname === patchUrl && response.request().method() === 'PATCH');
    await action(inactivate, 'Inaktiválás megerősítése').click();
    const statusResponse = await inactiveResponse;
    expect(statusResponse.ok()).toBe(true);
    expect((await statusResponse.json()).event.status_change).toMatchObject({ previous_active: true, active: false, reason: 'Szervizben' });
    await expect(inactivate).toBeHidden();
    await expect(row).toHaveCount(0);
    await filter.selectOption('inactive');
    await expect(row).toBeVisible();
    await expect(row).toContainText('Inaktív');
    expect(await json(page, historyUrl)).toEqual(snapshot);
    await filter.selectOption('all');
    await expect(row).toBeVisible();

    await useRegistryAction(page, row, 'Szerkesztés');
    const editor = dialog(page, 'Jármű szerkesztése');
    await editor.getByLabel('Megjegyzés', { exact: false }).fill('Visszaérkezés után használható.');
    await action(editor, 'Jármű mentése').click();
    await expect(editor).toBeHidden();
    await expect(row).toContainText('Visszaérkezés után használható.');

    const draftPage = await page.context().newPage();
    try {
        await draftPage.goto(pageUrl(draft.id));
        const inactiveWarning = draftPage.getByText('A hozzárendelt jármű inaktív.', { exact: false });
        await expect(inactiveWarning).toBeVisible();
        await expect(draftPage.locator('[data-plan-vehicle]')).toContainText(vehicle.registration_number);
        await expect(action(draftPage, 'Jármű cseréje')).toBeEnabled();
        await expect(action(draftPage, 'Fuvar feladása')).toBeDisabled();
        await expect(checklist(draftPage)).toContainText('A kiválasztott jármű inaktív.');
        const rejection = await command(draftPage, 'POST', `${api(draft.id)}/dispatch`, {
            operation_key: randomUUID(), version: draft.version, confirmed: true,
        }, 409);
        expect((await rejection.json()).blockers).toEqual(expect.arrayContaining([
            expect.objectContaining({ code: 'vehicle_inactive' }),
        ]));

        await page.goto(pageUrl(unassigned.id));
        await action(page, 'Jármű kiválasztása').click();
        const picker = dialog(page, 'Jármű kiválasztása');
        await picker.getByLabel('Jármű keresése').fill(vehicle.registration_number);
        await expect(picker.getByText('Nincs a keresésnek megfelelő aktív jármű.')).toBeVisible();
        await expect(action(picker, `${vehicle.name} · ${vehicle.registration_number}`)).toHaveCount(0);
        await action(picker, 'Mégse').click();
        await page.getByRole('link', { name: 'Járműtörzs kezelése', exact: true }).click();

        await filter.selectOption('inactive');
        await registrySearch(page).fill(vehicle.registration_number);
        await useRegistryAction(page, row, 'Újraaktiválás');
        const reactivate = dialog(page, 'Jármű újraaktiválása');
        await action(reactivate, 'Újraaktiválás megerősítése').click();
        await expect(reactivate).toBeHidden();
        await expect(page.getByRole('status').filter({ hasText: 'Jármű újraaktiválva' })).toBeVisible();
        await expect(row).toHaveCount(0);
        await filter.selectOption('active');
        await expect(row).toBeVisible();
        await action(draftPage, 'Járműállapot frissítése').click();
        await expect(action(draftPage, 'Fuvar feladása')).toBeEnabled();
        await expect(inactiveWarning).toBeHidden();

        await page.goto(pageUrl(unassigned.id));
        await useVehicle(page, vehicle);
        await page.goto(pageUrl(historical.id));
        await expect(page.locator('[data-plan-transit]')).toContainText(vehicle.name);
        expect(await json(page, historyUrl)).toEqual(snapshot);
    } finally {
        await draftPage.close();
    }

    const viewer = await browser.newContext({ baseURL });
    try {
        const operator = await viewer.newPage();
        await login(operator, 'picking-dispatch@example.test');
        await operator.goto(registryUrl);
        await expect(registryRow(operator, vehicle.registration_number)).toBeVisible();
        await expect(action(operator, 'Új jármű')).toHaveCount(0);
        await registryMenuTrigger(registryRow(operator, vehicle.registration_number)).click();
        await expect(registryMenu(operator).getByRole('menuitem')).toHaveText(['Kapcsolódó fuvarok']);
        await command(operator, 'PATCH', patchUrl, {
            operation_key: randomUUID(), version: vehicle.version, active: false,
        }, 403);
    } finally {
        await viewer.close();
    }
});

test('vehicle filters retain a search and status entered before Alpine starts', async ({ page }) => {
    await login(page);
    const token = randomUUID().slice(0, 8);
    const plate = `START-${token}`;
    await createVehicle(page, `Korai keresés ${token}`, plate, '100', 1, false);

    let releaseScript;
    const script = new Promise(resolve => { releaseScript = resolve; });
    await page.route('**/build/assets/app-*.js', async route => {
        await script;
        await route.continue();
    });
    try {
        await page.goto(registryUrl, { waitUntil: 'commit' });
        await page.getByLabel('Állapot', { exact: true }).selectOption('inactive');
        await registrySearch(page).fill(plate);
    } finally {
        releaseScript();
    }
    await expect(registryRow(page, plate)).toBeVisible();
    await expect(registrySearch(page)).toHaveValue(plate);
    await expect(page.getByLabel('Állapot', { exact: true })).toHaveValue('inactive');
});

test('vehicle headers and mobile cards sort filtered rows with unknown capacities last', async ({ page }) => {
    await login(page);
    const token = randomUUID().slice(0, 8);
    const heavy = await createVehicle(page, `Zéta ${token}`, `ZETA-${token}`, '10', 2);
    const unknownB = await createVehicle(page, `Ypsilon ${token}`, `YPSZ-${token}`, null, null);
    const light = await createVehicle(page, `Béta ${token}`, `BETA-${token}`, '2', 10);
    const zero = await createVehicle(page, `Alfa ${token}`, `ALFA-${token}`, '0', 0);
    const unknownA = await createVehicle(page, `Gépkocsi ${token}`, `GEPK-${token}`, null, null);

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(registryUrl);
    await registrySearch(page).fill(token);
    const filter = page.getByLabel('Állapot', { exact: true });
    const expectRowOrder = async vehicles => {
        await expect.poll(() => registryRows(page).evaluateAll(rows => rows.map(row => Number(row.dataset.vehicleId))))
            .toEqual(vehicles.map(vehicle => vehicle.id));
    };

    const headers = page.getByRole('columnheader');
    for (const label of ['Megnevezés', 'Rendszám', 'Hasznos teherbírás', 'Raklaphelykapacitás', 'Állapot', 'Megjegyzés']) {
        await expect(headers.getByRole('button', { name: new RegExp('^' + label) })).toBeVisible();
    }
    await expectRowOrder([zero, light, unknownA, unknownB, heavy]);
    const weightHeader = page.getByRole('columnheader', { name: /^Hasznos teherbírás/ });
    const weightSort = weightHeader.getByRole('button');
    await weightSort.click();
    await expect(weightHeader).toHaveAttribute('aria-sort', 'ascending');
    await expectRowOrder([zero, light, heavy, unknownA, unknownB]);
    await weightSort.click();
    await expect(weightHeader).toHaveAttribute('aria-sort', 'descending');
    await expectRowOrder([heavy, light, zero, unknownA, unknownB]);
    await expect(registryRow(page, zero.registration_number)).toContainText(/0\s*kg/);
    await expect(registryRow(page, unknownA.registration_number)).toContainText('Nincs megadva');

    await page.setViewportSize({ width: 375, height: 820 });
    await geometry(page, 375);
    await expect(weightHeader).toBeHidden();
    await expectRowOrder([heavy, light, zero, unknownA, unknownB]);
    const mobileSort = page.locator('[data-vehicle-registry] [data-listing-card-sort] details');
    await mobileSort.locator('summary').click();
    await mobileSort.getByRole('button', { name: /^Raklaphelykapacitás/ }).click();
    await expectRowOrder([zero, heavy, light, unknownA, unknownB]);
    await expect(registrySearch(page)).toHaveValue(token);
    await expect(filter).toHaveValue('active');
});
