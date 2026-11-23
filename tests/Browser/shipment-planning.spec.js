import { expect, test } from '@playwright/test';
import { seedUsableStock } from './movement-fixtures';
import { randomUUID } from 'node:crypto';
import { join } from 'node:path';
import { plan, shipmentCommand as command } from './shipment-fixtures';

const admin = 'warehouse-admin@example.test';
const pageUrl = id => `/shipments/${id}`;
const apiUrl = id => `/shipment-plans/${id}`;
const intentUrl = id => `/transfer-intents/${id}`;
const stockUrl = (warehouse, product) => `/movements/stock?warehouse_id=${warehouse}&product_id=${product}`;
const screenshots = process.env.SHIPMENT_PLANNING_SCREENSHOTS;

async function login(page, email = admin) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}

async function json(page, url) {
    const response = await page.request.get(url, { headers: { Accept: 'application/json' } });
    expect(response.ok(), await response.text()).toBe(true);
    return response.json();
}

async function warehouse(page, name) {
    await command(page, 'POST', '/warehouses', { warehouse_name: name, location: 'Tesztkapu' }, 302);
    await page.goto(`/warehouses?search=${encodeURIComponent(name)}`);
    const link = await page.getByRole('link', { name, exact: true }).getAttribute('href');
    return { name, id: Number(new URL(link, page.url()).pathname.split('/').at(-1)) };
}

async function product(page, origin, quantity) {
    const sku = 'FUVAR-' + randomUUID().slice(0, 8);
    await command(page, 'POST', '/products', { sku, product_name: 'Acél szerelőkészlet', category_id: 1, price: 1 }, 302);
    const html = await (await page.request.get(`/products?search=${sku}`)).text();
    const id = Number(html.match(/href="[^"]*\/products\/(\d+)\/edit/)[1]);
    seedUsableStock({ product_id: id, warehouse_id: origin, quantity });
    return { id, sku };
}

async function intent(page, { origin, destination, productId, quantity = 1, approve = true, dedicated = false }) {
    const request = await command(page, 'POST', '/transfer-intents', {
        operation_key: randomUUID(), product_id: productId, from_warehouse_id: origin, to_warehouse_id: destination, quantity,
        requested_arrival_at: '2026-12-10T12:45:00+01:00', priority: 'urgent',
        shipping_preference: dedicated ? 'dedicated_requested' : 'groupable',
    }, 201);
    const { intent: pending } = await request.json();
    if (!approve) return pending;
    const decision = await command(page, 'PATCH', `${intentUrl(pending.id)}/approve`, {
        operation_key: randomUUID(), version: pending.version,
    }, 200);
    return (await decision.json()).intent;
}

async function stage(page, demand, qty) {
    const base = intentUrl(demand.id);
    const reserved = await command(page, 'POST', `${base}/stock-reservation`, { operation_key: randomUUID(), version: demand.version }, 201);
    const created = await command(page, 'POST', `${base}/picking-task`, { operation_key: randomUUID(), version: (await reserved.json()).intent.version }, 201);
    await command(page, 'POST', `${base}/picking-task/picks`, {
        operation_key: randomUUID(), version: (await created.json()).intent.version, quantity: qty,
        source_allocations: [{ source_type: 'unassigned', location_id: null, quantity: qty }],
        unassigned_confirmation: { found: true, description: 'Kézi gyűjtőhely mellett.' },
    }, 200);
}

const byHeading = (page, name) => page.getByRole('heading', { name, exact: true });
const button = (page, name) => page.getByRole('button', { name, exact: true });
const eligible = page => page.locator('[data-plan-eligible]');
const eligibleRow = (page, demand) => eligible(page).locator('[data-plan-eligible-row]').filter({ hasText: demand.reference });
const assignment = (page, demand) => page.locator('[data-plan-assignment]').filter({ hasText: demand.reference });
const stops = page => page.locator('[data-plan-stop]');
const loading = page => page.locator('[data-plan-loading-stop]');

async function createPlanThroughUi(page, reference, originName) {
    let signalBundle;
    let releaseBundle;
    const requested = new Promise(resolve => { signalBundle = resolve; });
    const held = new Promise(resolve => { releaseBundle = resolve; });
    await page.route('**/build/assets/app-*.js', async route => {
        signalBundle();
        await held;
        await route.continue();
    }, { times: 1 });
    const open = page.getByRole('link', { name: 'Új fuvar tervezése', exact: true }).first().click();
    const referenceField = page.getByLabel('Szállítmányhivatkozás', { exact: true });
    try {
        await requested;
        await byHeading(page, 'Új fuvar tervezése').waitFor();
        await referenceField.fill(reference);
        await expect(referenceField).toHaveValue(reference);
    } finally {
        releaseBundle();
    }
    await open;
    const origin = page.getByRole('combobox', { name: 'Induló raktár', exact: true });
    await origin.fill(originName);
    await page.getByRole('option', { name: originName, exact: false }).click();
    await expect(referenceField).toHaveValue(reference);
    await page.getByLabel(/Jármű vagy rendszám/).fill('E2E-200');
    await page.getByLabel(/Tervezett indulás/).fill('2026-12-07T08:30');
    await button(page, 'Fuvar létrehozása').click();
    await expect(page).toHaveURL(/\/shipments\/\d+(?:\?.*)?$/);

    return Number(new URL(page.url()).pathname.split('/').at(-1));
}

async function assertViewport(page, width, label) {
    const geometry = await page.evaluate(() => ({ body: document.body.scrollWidth, root: document.documentElement.scrollWidth, width: innerWidth }));
    expect(Math.max(geometry.body, geometry.root), label + ' ' + width).toBeLessThanOrEqual(geometry.width);
}

async function capture(page, name) {
    if (!screenshots) return;
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: join(screenshots, name + '.png'), fullPage: true, animations: 'disabled' });
}

test('the same shipments tab plans three approved intents across two destinations without booking stock', async ({ page }) => {
    test.setTimeout(120000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    const source = await warehouse(page, 'Központi szerelvényelosztó és műszaki készlet-előkészítő raktár');
    const pecs = await warehouse(page, 'Pécs – Dél-dunántúli áruátvételi és ellenőrző központ');
    const szekszard = await warehouse(page, 'Szekszárd – Regionális szerelvény-átvevő raktár');
    const goods = await product(page, source.id, 500);
    const input = { origin: source.id, productId: goods.id };
    const a = await intent(page, { ...input, destination: pecs.id, quantity: 200 });
    const b = await intent(page, { ...input, destination: szekszard.id, quantity: 50, dedicated: true });
    const c = await intent(page, { ...input, destination: pecs.id, quantity: 100 });
    const pending = await intent(page, { ...input, destination: pecs.id, approve: false });
    const otherSource = await intent(page, { ...input, origin: pecs.id, destination: source.id });
    const occupied = await intent(page, { ...input, destination: pecs.id });
    await plan(page, 'Másik próbaterv', source.id, [occupied.id]);
    await stage(page, b, 15);
    await stage(page, c, 100);
    const balances = () => Promise.all([source, pecs, szekszard].map(warehouse => json(page, stockUrl(warehouse.id, goods.id))));
    const before = await balances();
    expect(before[0]).toMatchObject({ physical_quantity: 500, source_reserved_quantity: 35, staged_quantity: 115 });
    expect(before.slice(1).map(balance => balance.physical_quantity)).toEqual([0, 0]);

    await page.setViewportSize({ width: 1440, height: 960 });
    await page.goto('/atmozgatasok');
    const nav = page.getByRole('navigation', { name: 'Átmozgatások modul', exact: true });
    await nav.getByRole('link', { name: 'Fuvarok és szállítmányok', exact: true }).click();
    await expect(nav.getByRole('link', { name: 'Fuvarok és szállítmányok', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(page).toHaveURL(/\/shipments$/);
    const id = await createPlanThroughUi(page, 'Dél-dunántúli közös fuvar', source.name);
    let detail = await json(page, apiUrl(id));
    expect(detail.shipment).toMatchObject({ origin_warehouse_id: source.id, vehicle_label: 'E2E-200', status: 'draft' });
    expect(detail.assignments).toHaveLength(0);
    await byHeading(page, 'Jóváhagyott igények hozzáadása').waitFor();
    for (const demand of [a, b, c]) await expect(eligibleRow(page, demand)).toBeVisible();
    for (const demand of [pending, otherSource, occupied]) await expect(eligibleRow(page, demand)).toHaveCount(0);
    await capture(page, '01-ures-fuvar-1440');
    await page.setViewportSize({ width: 375, height: 860 });
    await assertViewport(page, 375, 'Üres fuvar');
    await capture(page, '01-ures-fuvar-375');

    const search = eligible(page).getByRole('searchbox', { name: 'Igények keresése', exact: true });
    const filtered = page.waitForResponse(response => new URL(response.url()).pathname === '/shipment-plans/eligible-intents'
        && new URL(response.url()).searchParams.get('search') === goods.sku && response.ok());
    await search.fill(goods.sku);
    await filtered;
    await expect(eligibleRow(page, a)).toBeVisible();
    for (const demand of [a, b, c]) {
        await eligibleRow(page, demand).getByRole('checkbox', { name: `Kijelölés · ${demand.reference}`, exact: true }).check();
    }
    await button(page, 'Kijelölt igények hozzáadása').click();
    const grouping = page.getByRole('dialog', { name: 'Külön fuvart kérő igény csoportosítása', exact: true });
    const groupSave = button(grouping, 'Hozzárendelés megerősítése');
    await expect(grouping).toBeVisible();
    await expect(groupSave).toBeDisabled();
    const reason = 'Közös jármű, elkülönített lerakodás Szekszárdon.';
    await grouping.getByLabel('A csoportosítás indoka', { exact: true }).fill(reason);
    await expect(groupSave).toBeDisabled();
    await grouping.getByLabel('Megerősítem a közös fuvarba sorolást', { exact: true }).check();
    const attachments = page.waitForResponse(resp => resp.request().method() === 'POST' && new URL(resp.url()).pathname === `${apiUrl(id)}/intents`);
    await groupSave.click();
    expect((await attachments).ok()).toBe(true);
    for (const [demand, state] of [[a, 'Készletbiztosításra vár'], [b, 'Kitárolás folyamatban'], [c, 'Összekészítve']]) {
        await expect(assignment(page, demand)).toContainText(state);
        await expect(assignment(page, demand)).toContainText(`${demand.quantity} db`);
    }
    await expect(stops(page)).toHaveCount(2);
    await expect(stops(page).nth(0)).toContainText(pecs.name);
    await expect(stops(page).nth(1)).toContainText(szekszard.name);
    await expect(loading(page).nth(0)).toContainText(szekszard.name);
    await expect(loading(page).nth(1)).toContainText(pecs.name);
    await expect(page.locator('[data-plan-preparation]')).toContainText('1');
    await expect(page.locator('[data-plan-transport]')).toContainText(/tömege|tömeg/);
    await expect(page.getByRole('button', { name: /^(Fuvar indítása|Feladás|Szállítmány indítása)$/ })).toHaveCount(0);
    detail = await json(page, apiUrl(id));
    const groupAudit = detail.operations.find(operation => operation.action === 'attach');
    expect(groupAudit.reason).toBe(reason);
    expect(detail.stops.map(stop => stop.intent_ids)).toEqual([[a.id, c.id], [b.id]]);
    expect(detail.assignments.map(row => row.intent.quantity)).toEqual([200, 50, 100]);
    expect(await balances()).toEqual(before);

    for (const width of [1440, 1024, 768, 375, 320]) {
        await page.setViewportSize({ width, height: width < 768 ? 850 : 960 });
        await assertViewport(page, width, 'Több megálló');
        await capture(page, `02-tobbmegallos-fuvar-${width}`);
    }

    await button(page, `Hátrébb · ${pecs.name}`).click();
    await button(page, 'Sorrend mentése').click();
    await expect(loading(page).nth(0)).toContainText(pecs.name);
    await expect(loading(page).nth(1)).toContainText(szekszard.name);
    await assignment(page, c).getByRole('link', { name: `Igény megnyitása · ${c.reference}`, exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/atmozgatasok/igenyek/${c.id}$`));
    await page.goto(pageUrl(id));
    await button(assignment(page, b), `Leválasztás · ${b.reference}`).click();
    const detach = page.getByRole('dialog', { name: 'Igény leválasztása', exact: true });
    await expect(detach).toContainText(szekszard.name);
    await expect(detach).toContainText(/foglalás|foglalt/);
    await button(detach, 'Leválasztás megerősítése').click();
    await expect(stops(page)).toHaveCount(1);
    await expect(assignment(page, b)).toHaveCount(0);
    await capture(page, '04-reszben-feltoltott-fuvar-320');
    expect(await balances()).toEqual(before);
    const alternative = await plan(page, 'Áttervezett útvonal', source.id);
    await page.goto(pageUrl(alternative.id));
    await eligibleRow(page, b).getByRole('checkbox').check();
    await button(page, 'Kijelölt igények hozzáadása').click();
    await expect(assignment(page, b)).toContainText('Kitárolás folyamatban');
    const reassigned = await json(page, apiUrl(alternative.id));
    expect(reassigned.assignments[0].transfer_intent_id).toBe(b.id);
    await command(page, 'POST', `${pageUrl(alternative.id)}/start`, { version: reassigned.shipment.version }, 409);
    expect((await json(page, apiUrl(alternative.id))).shipment.status).toBe('draft');
    expect(await balances()).toEqual(before);
    expect(errors).toEqual([]);
});

test('double submission and explicit refresh handle a conflicting plan version and preserve the selection', async ({ page }) => {
    await login(page);
    const source = await warehouse(page, 'Ütközés teszt – induló raktár');
    const goods = await product(page, source.id, 3);
    const first = await intent(page, { origin: source.id, destination: 2, productId: goods.id });
    const concurrent = await intent(page, { origin: source.id, destination: 3, productId: goods.id });
    const current = await plan(page, 'Párhuzamos fülek ellenőrzése', source.id);
    await page.goto(pageUrl(current.id));
    await eligibleRow(page, first).getByRole('checkbox').check();
    await command(page, 'POST', `${apiUrl(current.id)}/intents`, {
        operation_key: randomUUID(), version: current.version, intent_ids: [concurrent.id],
    }, 200);
    const sent = [];
    await page.route(`**${apiUrl(current.id)}/intents`, async route => {
        sent.push(route.request().postDataJSON());
        await route.continue();
    });
    const attach = button(page, 'Kijelölt igények hozzáadása');
    await attach.evaluate(el => { el.click(); el.click(); });
    const refresh = button(page, 'Fuvarterv frissítése');
    await expect(refresh).toBeVisible();
    await expect(attach).toBeDisabled();
    expect(sent).toHaveLength(1);
    await refresh.click();
    await expect(assignment(page, concurrent)).toBeVisible();
    await expect(eligibleRow(page, first).getByRole('checkbox')).toBeChecked();
    expect(sent).toHaveLength(1);
    await attach.click();
    await expect(assignment(page, first)).toBeVisible();
    expect(sent).toHaveLength(2);
    expect(sent[0].operation_key).not.toBe(sent[1].operation_key);
    expect(sent[1].version).toBeGreaterThan(sent[0].version);

    for (let index = 0; index < 13; index++) {
        await intent(page, { origin: source.id, destination: 2, productId: goods.id });
    }
    await page.setViewportSize({ width: 320, height: 840 });
    await page.reload();
    const rows = eligible(page).locator('[data-plan-eligible-row]');
    const pages = eligible(page).getByRole('group', { name: 'Igények lapozása', exact: true });
    await expect(rows).toHaveCount(12);
    await expect(pages).toContainText('1–12 / 13 igény');
    await assertViewport(page, 320, 'Lapozható igénylista');
    await capture(page, '06-lapozhato-igenyek-320');
    const firstChoice = rows.first().getByRole('checkbox');
    const firstChoiceLabel = await firstChoice.getAttribute('aria-label');
    await firstChoice.check();
    await button(pages, 'Következő oldal').click();
    await expect(rows).toHaveCount(1);
    await expect(pages).toContainText('13–13 / 13 igény');
    await button(pages, 'Előző oldal').click();
    await expect(eligible(page).getByRole('checkbox', { name: firstChoiceLabel, exact: true })).toBeChecked();
});

test('creating a plan retries a lost response with exactly one recorded creation', async ({ page }) => {
    await login(page);
    await page.goto('/shipments/create');
    for (const width of [1440, 1024, 768, 375, 320]) {
        await page.setViewportSize({ width, height: 840 });
        await assertViewport(page, width, 'Új fuvar tervezése');
    }
    await capture(page, '05-uj-fuvar-320');
    const reference = 'Újrapróbált fuvar ' + randomUUID().slice(0, 8);
    await page.getByLabel('Szállítmányhivatkozás', { exact: true }).fill(reference);
    const origin = page.getByRole('combobox', { name: 'Induló raktár', exact: true });
    await origin.fill('Teszt raktár');
    await page.getByRole('option', { name: 'Teszt raktár', exact: true }).click();
    const requests = [];
    await page.route('**/shipment-plans', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        requests.push(route.request().postDataJSON());
        if (requests.length !== 1) return route.continue();
        const response = await route.fetch();
        expect(response.status()).toBe(201);
        await route.abort('failed');
    });
    const save = button(page, 'Fuvar létrehozása');
    await save.evaluate(element => { element.click(); element.click(); });
    const retry = button(page, 'Mentés újrapróbálása');
    await expect(retry).toBeEnabled();
    await expect(page.getByLabel('Szállítmányhivatkozás', { exact: true })).toHaveValue(reference);
    await expect(page.getByLabel('Szállítmányhivatkozás', { exact: true })).toBeDisabled();
    expect(requests).toHaveLength(1);
    await retry.click();
    await expect(page).toHaveURL(/\/shipments\/\d+$/);
    expect(requests).toHaveLength(2);
    expect(requests[1]).toEqual(requests[0]);
    const results = await json(page, `/shipment-plans?search=${encodeURIComponent(reference)}`);
    expect(results.total).toBe(1);
    expect(results.data[0].reference).toBe(reference);
});

test('the planning list searches, filters, sorts and paginates on mobile', async ({ page }) => {
    await login(page);
    const marker = 'LISTA-' + randomUUID().slice(0, 8);
    const second = await plan(page, `${marker} B`, 1);
    const first = await plan(page, `${marker} A`, 1);
    await plan(page, `${marker} C`, 2);
    await plan(page, 'Eltérő fuvarnév', 1);
    const rows = page.locator('[data-plan-id]');
    await page.setViewportSize({ width: 320, height: 840 });
    await page.goto('/shipments?per_page=1');
    await page.getByRole('combobox', { name: 'Fuvarok keresése', exact: true }).fill(marker);
    await page.getByRole('combobox', { name: 'Induló raktár', exact: true }).selectOption('1');
    await page.getByRole('combobox', { name: 'Állapot', exact: true }).selectOption('draft');
    const mobileSort = page.locator('[data-listing-sort]');
    await mobileSort.locator('[data-listing-sort-trigger]').click();
    await mobileSort.getByRole('combobox', { name: 'Elsődleges szempont', exact: true }).selectOption('reference');
    await mobileSort.getByRole('combobox', { name: 'Elsődleges irány', exact: true }).selectOption('asc');
    await mobileSort.getByRole('button', { name: 'Rendezés alkalmazása', exact: true }).click();
    await expect(page).toHaveURL(/sort_by=reference/);
    expect(new URL(page.url()).searchParams.get('sort_dir')).toBe('asc');
    expect(new URL(page.url()).searchParams.get('per_page')).toBe('1');
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toHaveAttribute('data-plan-id', String(first.id));
    await assertViewport(page, 320, 'Fuvarlista');
    await capture(page, '03-fuvarlista-320');
    await page.goto(`/shipments?search=${marker}&origin_warehouse_id=1&sort_by=reference&sort_dir=asc&per_page=1&page=2`);
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toHaveAttribute('data-plan-id', String(second.id));
    await rows.first().getByRole('link', { name: new RegExp('^' + second.shipment_number) }).click();
    await expect(page).toHaveURL(new RegExp(`/shipments/${second.id}$`));

    await page.setViewportSize({ width: 1440, height: 960 });
    await page.goto(`/shipments?search=${marker}-NO-MATCH`);
    await expect(page.getByText('A szűrésnek megfelelő fuvar nem található.', { exact: true })).toBeVisible();
    await expect(rows).toHaveCount(0);
    await capture(page, '03-ures-fuvarlista-1440');
});

test('source picks are visible to dispatchers while requesters cannot open planning URLs', async ({ page, browser, baseURL }) => {
    await login(page);
    await page.goto('/warehouses?search=Kit%C3%A1rol%C3%A1si%20pr%C3%B3barakt%C3%A1r');
    const link = await page.getByRole('link', { name: 'Kitárolási próbaraktár', exact: true }).getAttribute('href');
    const origin = Number(new URL(link, page.url()).pathname.split('/').at(-1));
    const current = await plan(page, 'Saját raktári fuvar', origin);
    const unrelated = await plan(page, 'Másik raktár fuvarja', 1);
    for (const [email, canView] of [['picking-dispatch@example.test', true], ['browser@example.test', false], ['receiving-intents@example.test', false]]) {
        const ctx = await browser.newContext({ baseURL });
        try {
            const rolePage = await ctx.newPage();
            await login(rolePage, email);
            expect((await rolePage.goto(pageUrl(current.id))).status()).toBe(canView ? 200 : 403);
            if (canView) {
                await expect(byHeading(rolePage, 'Saját raktári fuvar')).toBeVisible();
                await expect(button(rolePage, 'Kijelölt igények hozzáadása')).toHaveCount(0);
                expect((await rolePage.goto(pageUrl(unrelated.id))).status()).toBe(403);
            }
            expect((await rolePage.goto('/shipments/create')).status()).toBe(403);
        } finally {
            await ctx.close();
        }
    }
});
