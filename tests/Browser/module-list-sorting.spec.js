import { chromium, expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { plan, shipmentCommand as command } from './shipment-fixtures';
import { setBrowserServerTime } from './browser-server-time';

const moduleUrl = '/raktari-feladatok';
const workUrl = moduleUrl + '/kitarolas';
const receivingUrl = moduleUrl + '/atvetel';
const putawayUrl = moduleUrl + '/betarolas';
function fixture() {
    return JSON.parse(execFileSync('php', ['tests/Browser/prepare-shipment-receiving.php', '--open-picking'], { encoding: 'utf8' }));
}

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}

function sortLabel(page) {
    return page.locator('[data-listing-sort-trigger]:visible, [data-listing-sort-fallback] summary');
}

async function openSort(page) {
    const sort = page.locator('[data-listing-sort]');
    await expect(sort).toHaveCount(1);
    const fallback = sort.locator('[data-listing-sort-fallback]');
    if (await fallback.count()) {
        if (await fallback.getAttribute('open') === null) await fallback.locator('summary').click();
        return fallback;
    }
    const dialog = sort.locator('[data-listing-sort-dialog]');
    if (await dialog.getAttribute('open') === null) await sort.locator('[data-listing-sort-trigger]').click();
    await expect(dialog).toBeVisible();
    return dialog;
}

async function sortControl(page, key, direction, retained = {}, secondary = null) {
    const editor = await openSort(page);
    await editor.getByRole('combobox', { name: 'Elsődleges szempont', exact: true }).selectOption(key);
    await editor.getByRole('combobox', { name: 'Elsődleges irány', exact: true }).selectOption(direction);
    if (secondary) {
        const add = editor.getByRole('button', { name: /Másodlagos rendezés hozzáadása$/ });
        const field = editor.getByRole('combobox', { name: 'Másodlagos szempont', exact: true });
        // Promoting the secondary removes its field before Alpine reveals the add button.
        await expect(add.or(field).filter({ visible: true })).toHaveCount(1);
        if (await add.isVisible()) await add.click();
        await field.selectOption(secondary.key);
        await editor.getByRole('combobox', { name: 'Másodlagos irány', exact: true }).selectOption(secondary.direction);
    }
    // A repeated sort key can already match the old URL before the queued GET starts.
    await Promise.all([
        page.waitForEvent('framenavigated', { predicate: frame => frame === page.mainFrame() }),
        editor.getByRole('button', { name: 'Rendezés alkalmazása', exact: true }).click(),
    ]);
    await page.waitForLoadState('load');
    await expect(page).toHaveURL(url => url.searchParams.get('sort_by') === key && url.searchParams.get('sort_dir') === direction);
    const query = new URL(page.url()).searchParams;
    expect(query.get('sort_dir')).toBe(direction);
    expect(query.get('page')).toBeNull();
    for (const [name, value] of Object.entries(retained)) expect(query.get(name), name).toBe(String(value));
    if (secondary) {
        expect(query.get('then_by')).toBe(secondary.key);
        expect(query.get('then_dir')).toBe(secondary.direction);
    }
    await expect(sortLabel(page)).toContainText(direction === 'asc' ? '↑' : '↓');
}

async function sortHeader(page, label, key, direction, retained = {}) {
    await sortControl(page, key, direction, retained);
    await expect(page.getByRole('columnheader').getByRole('link')).toHaveCount(0);
    await expect(page.getByRole('columnheader').getByRole('button')).toHaveCount(0);
}

async function sortMobile(page, label, key, direction) {
    await sortControl(page, key, direction);
}
async function assertViewport(page, name) {
    expect(await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)),
        name + ' vízszintes túlcsordulása').toBeLessThanOrEqual(page.viewportSize().width);
}

async function assertOnlyIdentityLink(row, path) {
    const identity = row.getByRole('link');
    await expect(identity).toHaveCount(1);
    await expect(identity).toHaveAttribute('href', new RegExp(path + '$'));
    await expect(identity).not.toHaveAccessibleName(/^(Részletek|Megnyitás|Átvétel megnyitása|Betárolás megkezdése)$/);
    return identity;
}

test('az igény- és fuvarlista közös vezérlőből rendez, megtartja a szűrőket és a névből nyit részleteket', async ({ page }) => {
    test.setTimeout(90000);
    const shipment = fixture();
    const { source, products } = shipment;
    await login(page);
    await page.setViewportSize({ width: 1440, height: 960 });

    await page.goto('/atmozgatasok?warehouse_id=' + source + '&per_page=2&page=2');
    await page.locator('#intent-search').fill(products.screw.sku);
    await sortHeader(page, 'Mennyiség', 'quantity', 'asc', {
        warehouse_id: source, search: products.screw.sku, per_page: 2,
    });
    const demands = page.locator('[data-transfer-intent-list] [role="rowgroup"] > [role="row"]');
    await expect(demands).toHaveCount(1);
    const identity = await assertOnlyIdentityLink(demands.first(), '/atmozgatasok/igenyek/' + products.screw.intent);
    await expect(identity).toContainText(products.screw.intentReference);
    await expect(identity).toContainText(products.screw.name);
    await page.locator('#intent-search').fill('');
    await sortHeader(page, 'Mennyiség', 'quantity', 'desc', { warehouse_id: source, per_page: 2 });
    await expect(demands).toHaveCount(2);
    await expect(demands.first()).toContainText(products.screw.sku);
    await expect(demands.nth(1)).toContainText(products.machine.sku);
    await expect(page.locator('[data-listing-sort]')).toHaveCount(1);

    await page.setViewportSize({ width: 320, height: 900 });
    await sortMobile(page, 'Mennyiség', 'quantity', 'asc');
    await expect(demands).toHaveCount(2);
    const firstDemand = await assertOnlyIdentityLink(demands.first(), '/atmozgatasok/igenyek/' + shipment.pickingIntent);
    await assertViewport(page, 'Igénylista');

    await firstDemand.press('Enter');
    await expect(page).toHaveURL('/atmozgatasok/igenyek/' + shipment.pickingIntent);

    const a = await plan(page, shipment.reference + ' A', source);
    const z = await plan(page, shipment.reference + ' Z', source);
    await page.setViewportSize({ width: 1440, height: 960 });
    await page.goto('/shipments?origin_warehouse_id=' + source + '&per_page=2&page=2');
    await page.locator('#plan-search').fill(shipment.reference);
    await sortHeader(page, 'Hivatkozás', 'reference', 'asc', {
        origin_warehouse_id: source, search: shipment.reference, per_page: 2,
    });
    const plans = page.locator('[data-plan-id]');
    await expect(plans).toHaveCount(2);
    await expect(plans.first()).toHaveAttribute('data-plan-id', String(shipment.id));
    await expect(plans.nth(1)).toHaveAttribute('data-plan-id', String(a.id));
    await sortHeader(page, 'Hivatkozás', 'reference', 'desc');
    await expect(plans.first()).toHaveAttribute('data-plan-id', String(z.id));
    const planIdentity = await assertOnlyIdentityLink(plans.first(), '/shipments/' + z.id);
    await expect(planIdentity).toContainText(z.reference);

    await page.setViewportSize({ width: 375, height: 900 });
    await sortMobile(page, 'Hivatkozás', 'reference', 'asc');
    await expect(plans.first()).toHaveAttribute('data-plan-id', String(shipment.id));
    await assertViewport(page, 'Fuvarlista');

    await (await assertOnlyIdentityLink(plans.first(), '/shipments/' + shipment.id)).click();
    await expect(page).toHaveURL('/shipments/' + shipment.id);
});

test('a három raktári munkalista rendezése és névhivatkozásai ugyanarra a munkalapra vezetnek', async ({ page }) => {
    test.setTimeout(90000);
    const shipment = fixture();
    const { source, pecs, gyor, products } = shipment;
    await login(page);
    await page.setViewportSize({ width: 1440, height: 960 });

    await page.goto(workUrl + '?warehouse_id=' + source + '&status=all&per_page=2&page=2');
    await page.locator('#task-search').fill(products.screw.sku);
    await sortHeader(page, 'Hátralévő', 'remaining_quantity', 'asc', {
        warehouse_id: source, status: 'all', search: products.screw.sku, per_page: 2,
    });
    const picks = page.locator('[data-picking-task-list] [role="rowgroup"] > [role="row"]');
    await expect(picks).toHaveCount(1);
    const pickIdentity = picks.first().getByRole('link');
    await expect(pickIdentity).toHaveCount(1);
    await expect(pickIdentity).toHaveText(products.screw.intentReference);
    await expect(pickIdentity).toHaveAttribute('href', new RegExp(workUrl + '/' + products.screw.intent + '\\?task=\\d+$'));
    await page.locator('#task-search').fill('');
    await sortHeader(page, 'Hátralévő', 'remaining_quantity', 'desc');
    await expect(picks.first()).toContainText('9 db');



    await page.setViewportSize({ width: 320, height: 900 });
    await sortMobile(page, 'Hátralévő', 'remaining_quantity', 'asc');
    await assertViewport(page, 'Kitárolási lista');

    await page.setViewportSize({ width: 1440, height: 960 });

    await page.goto(receivingUrl + '?search=' + shipment.number + '&per_page=1&page=2');
    await page.locator('#receiving-filters input[name="search"]').fill(products.screw.sku);
    await page.locator('#receiving-filters select[name="warehouse_id"]').selectOption(String(pecs.warehouseId));
    await sortHeader(page, 'Még átvehető', 'remaining_quantity', 'asc', {
        warehouse_id: pecs.warehouseId, search: products.screw.sku, per_page: 1,
    });
    const arrivals = page.getByTestId('receiving-stop-row');
    await expect(arrivals).toHaveCount(1);
    const receivingIdentity = await assertOnlyIdentityLink(arrivals.first(), receivingUrl + '/' + shipment.id + '/' + pecs.id);
    await expect(receivingIdentity).toHaveText(shipment.number);
    await page.locator('#receiving-filters input[name="search"]').fill(shipment.number);
    await page.locator('#receiving-filters select[name="warehouse_id"]').selectOption('');
    await sortHeader(page, 'Még átvehető', 'remaining_quantity', 'desc');
    await expect(arrivals.first()).toContainText(pecs.name);

    await page.setViewportSize({ width: 320, height: 900 });
    await sortMobile(page, 'Még átvehető', 'remaining_quantity', 'asc');
    await expect(arrivals.first()).toContainText(gyor.name);
    await assertViewport(page, 'Átvételi lista');

    await (await assertOnlyIdentityLink(arrivals.first(), receivingUrl + '/' + shipment.id + '/' + gyor.id)).press('Enter');
    await expect(page).toHaveURL(receivingUrl + '/' + shipment.id + '/' + gyor.id);

    const receipt = await command(page, 'POST', '/shipment-plans/' + shipment.id + '/stops/' + pecs.id + '/receipts', {
        operation_key: randomUUID(), version: 1, confirmed: true,
        items: [
            { transit_item_id: stateTransit(shipment).find(row => row.product_id === products.screw.id).id, quantity: 200 },
            { transit_item_id: stateTransit(shipment).find(row => row.product_id === products.machine.id).id, quantity: 40 },
        ],
    }, 201);
    const received = await receipt.json();
    const machineItem = received.items.find(item => item.product_id === products.machine.id);
    await page.setViewportSize({ width: 1440, height: 960 });
    await page.goto(putawayUrl + '?receipt_id=' + received.receipt.id + '&per_page=1&page=2');
    await page.locator('#putaway-task-search').fill(products.machine.sku);
    await page.locator('#putaway-task-warehouse').selectOption(String(pecs.warehouseId));
    await sortHeader(page, 'Betárolásra vár', 'pending_putaway_quantity', 'asc', {
        warehouse_id: pecs.warehouseId, search: products.machine.sku, receipt_id: received.receipt.id, per_page: 1,
    });
    const putaways = page.locator('[data-putaway-task-row]');
    await expect(putaways).toHaveCount(1);
    await expect(putaways.first()).toContainText(products.machine.sku);
    const putawayIdentity = await assertOnlyIdentityLink(putaways.first(), putawayUrl + '/' + machineItem.id);
    await expect(putawayIdentity).toHaveText(products.machine.name);
    await page.locator('#putaway-task-search').fill('');
    await sortHeader(page, 'Betárolásra vár', 'pending_putaway_quantity', 'desc');
    await expect(putaways.first()).toContainText(products.screw.sku);

    await page.setViewportSize({ width: 320, height: 900 });
    await sortMobile(page, 'Betárolásra vár', 'pending_putaway_quantity', 'asc');
    await expect(putaways.first()).toContainText(products.machine.sku);
    await assertViewport(page, 'Betárolási lista');

    await (await assertOnlyIdentityLink(putaways.first(), putawayUrl + '/' + machineItem.id)).press('Enter');
    await expect(page).toHaveURL(putawayUrl + '/' + machineItem.id);
});

function stateTransit(shipment) {
    return JSON.parse(execFileSync('php', ['tests/Browser/read-shipment-receiving.php', String(shipment.id)], { encoding: 'utf8' })).transit;
}


test('a közös rendező JavaScript nélkül is megőrzi a mentett szűrést', async ({ browser, baseURL }) => {
    const shipment = fixture();
    const context = await browser.newContext({ baseURL, javaScriptEnabled: false, viewport: { width: 1440, height: 960 } });
    const page = await context.newPage();
    try {
        await login(page);
        await page.goto('/atmozgatasok?warehouse_id=' + shipment.source + '&per_page=2&sort_by=quantity&sort_dir=desc');
        await sortControl(page, 'quantity', 'asc', {
            warehouse_id: shipment.source, per_page: 2,
        }, { key: 'priority', direction: 'desc' });
        const rows = page.locator('[data-transfer-intent-list] [role="rowgroup"] > [role="row"]');
        await expect(rows.first()).toContainText('9 db');
        await page.setViewportSize({ width: 320, height: 900 });
        await sortMobile(page, 'Mennyiség', 'quantity', 'desc');
        await expect(rows.first()).toContainText(shipment.products.screw.sku);
        const identity = await assertOnlyIdentityLink(rows.first(), '/atmozgatasok/igenyek/' + shipment.products.screw.intent);
        await identity.click();
        await expect(page).toHaveURL('/atmozgatasok/igenyek/' + shipment.products.screw.intent);

        await page.setViewportSize({ width: 1440, height: 960 });
        await page.goto(receivingUrl + '?search=' + shipment.number + '&per_page=1&sort_by=remaining_quantity&sort_dir=desc');
        await sortHeader(page, 'Még átvehető', 'remaining_quantity', 'asc', {
            search: shipment.number, per_page: 1,
        });
        const rowsReceiving = page.getByTestId('receiving-stop-row');
        await expect(rowsReceiving.first()).toContainText(shipment.gyor.name);
        const receivingIdentity = await assertOnlyIdentityLink(rowsReceiving.first(), receivingUrl + '/' + shipment.id + '/' + shipment.gyor.id);
        await receivingIdentity.press('Enter');
        await expect(page).toHaveURL(receivingUrl + '/' + shipment.id + '/' + shipment.gyor.id);
    } finally {
        await context.close();
    }
});


async function createIntent(page, shipment, overrides) {
    const response = await command(page, 'POST', '/transfer-intents', {
        product_id: shipment.products.screw.id, from_warehouse_id: shipment.source,
        to_warehouse_id: shipment.pecs.warehouseId, quantity: 2,
        operation_key: randomUUID(), ...overrides,
    }, 201);
    return (await response.json()).intent;
}

async function intentRowIds(page) {
    return page.locator('[data-transfer-intent-list] [role="rowgroup"] [role="row"] a').evaluateAll(links =>
        links.map(link => Number(new URL(link.href).pathname.split('/').at(-1))));
}

test('az elsődleges és másodlagos rendezés teljes találati halmazon dönt, és stabilan lapozható', async ({ page }) => {
    test.setTimeout(90000);
    const shipment = fixture();
    await login(page);
    await setBrowserServerTime(page, '2026-11-23T14:23:37Z');
    const rows = [];
    for (const [priority, requested_arrival_at, quantity] of [
        ['normal', '2027-01-03T10:00:00+01:00', 100],
        ['urgent', '2027-01-04T10:00:00+01:00', 2],
        ['critical', '2027-01-04T10:00:00+01:00', 100],
        ['urgent', '2027-01-02T10:00:00+01:00', 10],
        ['urgent', '2027-01-02T10:00:00+01:00', 2],
        ['critical', null, 10],
        ['normal', null, 2],
    ]) rows.push(await createIntent(page, shipment, { priority, requested_arrival_at, quantity }));
    await page.goto('/atmozgatasok?search=' + shipment.products.screw.sku
        + '&warehouse_id=' + shipment.source + '&status=pending_approval&per_page=2&page=2');
    await sortControl(page, 'priority', 'desc', {
        search: shipment.products.screw.sku, warehouse_id: shipment.source, status: 'pending_approval', per_page: 2,
    }, { key: 'requested_arrival_at', direction: 'asc' });
    const expected = [rows[2], rows[5], rows[4], rows[3], rows[1], rows[0], rows[6]].map(row => row.id);
    const actual = [];
    for (let number = 1; number <= 4; number++) {
        if (number !== 1) {
            const next = new URL(page.url());
            next.searchParams.set('page', String(number));
            await page.goto(next.toString());
        }
        actual.push(...await intentRowIds(page));
        expect(new URL(page.url()).searchParams.get('then_by')).toBe('requested_arrival_at');
        await expect(sortLabel(page)).toContainText('Prioritás ↓');
        await expect(sortLabel(page)).toContainText('Határidő ↑');
        if (number !== 4) {
            const nextLinks = page.getByRole('navigation', { name: /lapozás/i }).locator('a[rel="next"]');
            const visibleNext = nextLinks.filter({ visible: true });
            await expect(visibleNext, 'a két reszponzív lapozóváltozat közül pontosan egy látható').toHaveCount(1);
            await expect(visibleNext).toHaveAttribute('href', /then_by=requested_arrival_at/);
            const variants = await nextLinks.evaluateAll(links => links.map(link => ({
                href: link.href,
                display: getComputedStyle(link.parentElement).display,
                visible: !!link.getClientRects().length,
            })));
            expect(variants.filter(link => link.visible)).toHaveLength(1);
            for (const link of variants) expect(new URL(link.href).searchParams.get('then_by')).toBe('requested_arrival_at');
        }
    }
    expect(actual).toEqual(expected);
    expect(new Set(actual).size).toBe(rows.length);
    await sortControl(page, 'priority', 'asc', {}, { key: 'quantity', direction: 'asc' });
    expect(await intentRowIds(page)).toEqual([rows[6].id, rows[0].id]);
    await sortControl(page, 'priority', 'desc', {}, { key: 'quantity', direction: 'desc' });
    expect(await intentRowIds(page)).toEqual([rows[2].id, rows[5].id]);
});

test('a rendezési párbeszéd hozzáadása, eltávolítása, mezőütközése és billentyűzetes fókusza biztonságos', async ({ page }) => {
    const shipment = fixture();
    await login(page);
    await page.goto('/atmozgatasok?warehouse_id=' + shipment.source + '&per_page=2');
    const trigger = page.locator('[data-listing-sort-trigger]');
    const dialog = page.getByRole('dialog', { name: 'Rendezés beállítása', exact: true });
    await trigger.focus();
    await trigger.press('Enter');
    await expect(dialog).toBeVisible();
    await expect.poll(() => dialog.evaluate(el => el.contains(document.activeElement))).toBe(true);
    for (let step = 0; step < 10; step++) {
        await page.keyboard.press('Tab');
        await expect.poll(() => dialog.evaluate(el => el.contains(document.activeElement))).toBe(true);
    }
    for (let step = 0; step < 10; step++) {
        await page.keyboard.press('Shift+Tab');
        await expect.poll(() => dialog.evaluate(el => el.contains(document.activeElement))).toBe(true);
    }
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await trigger.press('Space');
    await dialog.getByRole('combobox', { name: 'Elsődleges szempont', exact: true }).selectOption('priority');
    await dialog.getByRole('button', { name: /Másodlagos rendezés hozzáadása$/ }).click();
    await dialog.getByRole('combobox', { name: 'Másodlagos szempont', exact: true }).selectOption('requested_arrival_at');
    await expect(dialog.getByRole('combobox', { name: 'Másodlagos szempont', exact: true }).locator('option[value="priority"]')).toHaveJSProperty('disabled', true);
    await dialog.getByRole('combobox', { name: 'Elsődleges szempont', exact: true }).selectOption('requested_arrival_at');
    await expect(dialog.getByRole('combobox', { name: 'Másodlagos szempont', exact: true })).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: /Másodlagos rendezés hozzáadása$/ })).toBeVisible();
    await dialog.getByRole('combobox', { name: 'Elsődleges szempont', exact: true }).selectOption('priority');
    await dialog.getByRole('combobox', { name: 'Elsődleges irány', exact: true }).selectOption('desc');
    await dialog.getByRole('button', { name: /Másodlagos rendezés hozzáadása$/ }).click();
    await dialog.getByRole('combobox', { name: 'Másodlagos szempont', exact: true }).selectOption('requested_arrival_at');
    await dialog.getByRole('combobox', { name: 'Másodlagos irány', exact: true }).selectOption('asc');
    await dialog.getByRole('button', { name: 'Rendezés alkalmazása', exact: true }).click();
    await expect(page).toHaveURL(/then_by=requested_arrival_at/);
    await openSort(page);
    await expect(dialog.getByRole('combobox', { name: 'Másodlagos szempont', exact: true })).toHaveValue('requested_arrival_at');
    await dialog.getByRole('button', { name: 'Másodlagos rendezés eltávolítása', exact: true }).click();
    await dialog.getByRole('button', { name: 'Rendezés alkalmazása', exact: true }).click();
    await expect(page).not.toHaveURL(/then_by=/);
    expect(new URL(page.url()).searchParams.get('then_dir')).toBeNull();
    await expect(trigger).not.toContainText('Határidő');
    await page.goBack();
    await expect(trigger).toContainText('Határidő ↑');
});

test('az öt lista azonos rendezővel minden korábbi mezőt kínál és 1440–320 px között elfér', async ({ page }) => {
    test.setTimeout(180000);
    const shipment = fixture();
    await login(page);
    const transit = stateTransit(shipment);
    const receipt = await command(page, 'POST', '/shipment-plans/' + shipment.id + '/stops/' + shipment.pecs.id + '/receipts', {
        operation_key: randomUUID(), version: 1, confirmed: true,
        items: transit.filter(row => row.to_warehouse_id === shipment.pecs.warehouseId || row.product_id === shipment.products.screw.id || row.product_id === shipment.products.machine.id)
            .map(row => ({ transit_item_id: row.id, quantity: row.remaining_quantity })),
    }, 201);
    const received = await receipt.json();
    const views = [
        { name: 'igenyek', path: '/atmozgatasok?warehouse_id=' + shipment.source, by: 'priority', then: 'requested_arrival_at', fields: ['reference', 'product_name', 'from_warehouse', 'to_warehouse', 'quantity', 'status', 'priority', 'created_at', 'requested_arrival_at'] },
        { name: 'fuvarok', path: '/shipments?origin_warehouse_id=' + shipment.source, by: 'status', then: 'reference', fields: ['id', 'reference', 'origin_warehouse', 'planned_departure_at', 'vehicle_label', 'status', 'created_at'] },
        { name: 'kitarolas', path: workUrl + '?warehouse_id=' + shipment.source + '&status=all', by: 'priority', then: 'remaining_quantity', fields: ['id', 'reference', 'product_name', 'from_warehouse', 'to_warehouse', 'remaining_quantity', 'status', 'priority'] },
        { name: 'atvetel', path: receivingUrl + '?search=' + shipment.number + '&status=all', by: 'status', then: 'remaining_quantity', fields: ['shipment_id', 'reference', 'origin_warehouse', 'warehouse_name', 'item_count', 'remaining_quantity', 'status', 'dispatched_at'] },
        { name: 'betarolas', path: putawayUrl + '?receipt_id=' + received.receipt.id, by: 'status', then: 'pending_putaway_quantity', fields: ['id', 'product_name', 'warehouse_name', 'received_quantity', 'placed_quantity', 'pending_putaway_quantity', 'received_at', 'status', 'origin'] },
    ];
    const health = watchBrowserHealth(page);
    for (const view of views) {
        for (const viewport of [
            { width: 1440, height: 900 }, { width: 1024, height: 768 }, { width: 768, height: 800 },
            { width: 375, height: 667 }, { width: 320, height: 568 },
        ]) {
            const label = `${view.name} ${viewport.width}×${viewport.height}`;
            await page.setViewportSize(viewport);
            await page.goto(view.path + '&sort_by=' + view.by + '&sort_dir=desc&then_by=' + view.then + '&then_dir=asc');
            const sort = page.locator('[data-listing-sort]');
            await expect(sort).toHaveCount(1);
            await expect(page.locator('[data-listing-card-sort]')).toHaveCount(0);
            await expect(page.getByRole('columnheader').getByRole('link')).toHaveCount(0);
            await expect(page.getByRole('columnheader').getByRole('button')).toHaveCount(0);
            await assertViewport(page, label);
            const dialog = await openSort(page);
            expect(await dialog.getByRole('combobox', { name: 'Elsődleges szempont', exact: true }).locator('option').evaluateAll(options => options.map(option => option.value))).toEqual(view.fields);
            await expect(dialog.getByRole('combobox', { name: 'Elsődleges szempont', exact: true })).toHaveValue(view.by);
            await expect(dialog.getByRole('combobox', { name: 'Elsődleges irány', exact: true })).toHaveValue('desc');
            await expect(dialog.getByRole('combobox', { name: 'Másodlagos szempont', exact: true })).toHaveValue(view.then);
            await expect(dialog.getByRole('combobox', { name: 'Másodlagos irány', exact: true })).toHaveValue('asc');
            await assertDialogLayout(page, dialog, label);
            await clickBlankAreas(page, dialog);
            for (const name of ['Elsődleges szempont', 'Elsődleges irány', 'Másodlagos szempont', 'Másodlagos irány']) {
                await assertClickable(dialog.getByRole('combobox', { name, exact: true }), label + ' ' + name);
            }
            const removal = dialog.getByRole('button', { name: 'Másodlagos rendezés eltávolítása', exact: true });
            await assertClickable(removal, label + ' utolsó tartalmi művelet');
            await removal.click();
            await expect(dialog.getByRole('combobox', { name: 'Másodlagos szempont', exact: true })).toHaveCount(0);
            await assertDialogLayout(page, dialog, label + ' egy szint');
            const add = dialog.getByRole('button', { name: /Másodlagos rendezés hozzáadása$/ });
            await assertClickable(add, label + ' hozzáadás');
            await add.click();
            await dialog.getByRole('combobox', { name: 'Másodlagos szempont', exact: true }).selectOption(view.then);
            await dialog.getByRole('combobox', { name: 'Másodlagos irány', exact: true }).selectOption('desc');
            await assertDialogLayout(page, dialog, label + ' két szint');
            await assertClickable(dialog.getByRole('button', { name: 'Rendezés alkalmazása', exact: true }), label + ' alkalmazás');
            await dialog.getByRole('button', { name: 'Mégse', exact: true }).click();
            await expect(dialog).not.toBeVisible();
            await expect(page.locator('[data-listing-sort-trigger]')).toBeFocused();
        }
    }
    health.assertHealthy();
});

function watchBrowserHealth(page) {
    const crashes = [];
    const exceptions = [];
    const consoleErrors = [];
    const serverErrors = [];
    page.on('crash', () => crashes.push('renderer crash'));
    page.on('pageerror', error => exceptions.push(error.message));
    page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
    page.on('response', response => { if (response.status() >= 500) serverErrors.push(response.status() + ' ' + response.url()); });
    return {
        assertHealthy() {
            expect(crashes, 'böngésző-renderer összeomlás').toEqual([]);
            expect(exceptions, 'JavaScript-kivétel').toEqual([]);
            expect(consoleErrors, 'konzolhiba').toEqual([]);
            expect(serverErrors, 'szerveroldali hiba').toEqual([]);
        },
    };
}

async function assertClickable(locator, label) {
    await locator.scrollIntoViewIfNeeded();
    await expect(locator, label).toBeVisible();
    await locator.click({ trial: true, timeout: 5000 });
    const hit = await locator.evaluate(el => {
        const rect = el.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;
        const target = document.elementFromPoint(x, y);
        const scroller = el.closest('[data-listing-sort-body]')?.getBoundingClientRect();
        return {
            inside: x >= 0 && x < innerWidth && y >= 0 && y < innerHeight,
            topmost: el === target || el.contains(target),
            fullyVisible: rect.top >= 0 && rect.bottom <= innerHeight && rect.left >= 0 && rect.right <= innerWidth
                && (!scroller || rect.top >= scroller.top - 1 && rect.bottom <= scroller.bottom + 1),
        };
    });
    expect(hit, label + ' teljes láthatósága és tényleges kattinthatósága').toEqual({ inside: true, topmost: true, fullyVisible: true });
}

async function assertDialogLayout(page, dialog, label) {
    const geometry = await dialog.evaluate(el => {
        const rect = node => {
            const box = node.getBoundingClientRect();
            return { left: box.left, right: box.right, top: box.top, bottom: box.bottom, width: box.width, height: box.height };
        };
        const body = el.querySelector('[data-listing-sort-body]');
        const footer = el.querySelector('[data-listing-sort-footer]');
        return {
            dialog: rect(el), body: rect(body), footer: rect(footer),
            bodyScrolls: body.scrollHeight > body.clientHeight + 1,
            outerScrolls: el.scrollHeight > el.clientHeight + 1,
        };
    });
    expect(geometry.dialog.left, label).toBeGreaterThanOrEqual(0);
    expect(geometry.dialog.top, label).toBeGreaterThanOrEqual(0);
    expect(geometry.dialog.right, label).toBeLessThanOrEqual(page.viewportSize().width);
    expect(geometry.dialog.bottom, label).toBeLessThanOrEqual(page.viewportSize().height);
    if (page.viewportSize().width >= 768) {
        expect(geometry.dialog.width, label + ' kompakt asztali szélesség').toBeGreaterThanOrEqual(520);
        expect(geometry.dialog.width, label + ' kompakt asztali szélesség').toBeLessThanOrEqual(600);
    }
    expect(geometry.body.bottom, label + ' lábléc nem fedi a tartalmi területet').toBeLessThanOrEqual(geometry.footer.top + 1);
    expect(geometry.outerScrolls, label + ' nincs egymásba ágyazott görgetés').toBe(false);
    if (page.viewportSize().height >= 768) expect(geometry.bodyScrolls, label + ' normál magasságnál nem kell görgetni').toBe(false);
    await assertViewport(page, label);
    await expect(dialog.getByRole('button', { name: 'Mégse', exact: true })).toBeInViewport({ ratio: 1 });
    await expect(dialog.getByRole('button', { name: 'Rendezés alkalmazása', exact: true })).toBeInViewport({ ratio: 1 });
}

async function clickBlankAreas(page, dialog) {
    const beforeUrl = page.url();
    const before = await dialog.getByRole('combobox').evaluateAll(fields => fields.map(field => field.value));
    const regions = [dialog.locator('[data-listing-sort-panel]'), dialog.locator('[data-listing-sort-body]'), dialog.locator('[data-listing-sort-footer]')];
    for (const region of regions) {
        const box = await region.boundingBox();
        await page.mouse.click(box.x + 5, box.y + Math.min(5, box.height / 2));
        await expect(dialog).toBeVisible();
        expect(await dialog.getByRole('combobox').evaluateAll(fields => fields.map(field => field.value))).toEqual(before);
        expect(page.url()).toBe(beforeUrl);
        expect(await page.evaluate(() => document.readyState)).toBe('complete');
    }
}


test('az üres belső kattintások és ismételt hozzáadás nem alkalmaznak rendezést, a Mégse és az Escape elveti a vázlatot', async ({ page }) => {
    test.setTimeout(90000);
    const shipment = fixture();
    const health = watchBrowserHealth(page);
    await login(page);
    const initialUrl = '/atmozgatasok?warehouse_id=' + shipment.source + '&per_page=2&sort_by=quantity&sort_dir=desc';
    await page.goto(initialUrl);
    const applied = await sortLabel(page).textContent();
    const beforeRows = await intentRowIds(page);
    const dialog = await openSort(page);
    expect(await dialog.evaluate(el => el.matches(':modal'))).toBe(true);
    await page.locator('#intent-search').evaluate(el => el.focus());
    await expect(page.locator('#intent-search')).not.toBeFocused();
    await expect.poll(() => dialog.evaluate(el => el.contains(document.activeElement))).toBe(true);
    for (let cycle = 0; cycle < 12; cycle++) {
        const primary = dialog.getByRole('combobox', { name: 'Elsődleges szempont', exact: true });
        await primary.selectOption('priority');
        await primary.focus();
        await clickBlankAreas(page, dialog);
        await dialog.getByRole('button', { name: /Másodlagos rendezés hozzáadása$/ }).click();
        await dialog.getByRole('combobox', { name: 'Másodlagos szempont', exact: true }).selectOption('requested_arrival_at');
        await dialog.getByRole('combobox', { name: 'Másodlagos irány', exact: true }).selectOption('asc');
        await clickBlankAreas(page, dialog);
        await dialog.getByRole('button', { name: 'Másodlagos rendezés eltávolítása', exact: true }).click();
    }
    await dialog.getByRole('button', { name: 'Mégse', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('[data-listing-sort-trigger]')).toBeFocused();
    expect(page.url()).toContain('sort_by=quantity&sort_dir=desc');
    expect(await sortLabel(page).textContent()).toBe(applied);
    expect(await intentRowIds(page)).toEqual(beforeRows);
    await openSort(page);
    await expect(dialog.getByRole('combobox', { name: 'Elsődleges szempont', exact: true })).toHaveValue('quantity');
    await dialog.getByRole('combobox', { name: 'Elsődleges irány', exact: true }).selectOption('asc');
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('[data-listing-sort-trigger]')).toBeFocused();
    await openSort(page);
    await expect(dialog.getByRole('combobox', { name: 'Elsődleges irány', exact: true })).toHaveValue('desc');
    const bounds = await dialog.boundingBox();
    expect(bounds.x).toBeGreaterThan(2);
    await page.mouse.click(2, 2);
    await expect(dialog).not.toBeVisible();
    await expect(page.locator('[data-listing-sort-trigger]')).toBeFocused();
    health.assertHealthy();
});


test('nagyon alacsony ablakban minden rendezési mező és az utolsó művelet a lábléc átfedése nélkül kezelhető', async ({ page }) => {
    const shipment = fixture();
    const health = watchBrowserHealth(page);
    await login(page);
    await page.setViewportSize({ width: 320, height: 360 });
    await page.goto('/atmozgatasok?warehouse_id=' + shipment.source + '&sort_by=priority&sort_dir=desc');
    const dialog = await openSort(page);
    await assertDialogLayout(page, dialog, '320×360 egy szint');
    await dialog.getByRole('button', { name: /Másodlagos rendezés hozzáadása$/ }).click();
    await assertDialogLayout(page, dialog, '320×360 két szint');
    for (const [name, value] of [['Elsődleges szempont', 'priority'], ['Elsődleges irány', 'asc'], ['Másodlagos szempont', 'requested_arrival_at'], ['Másodlagos irány', 'desc']]) {
        const field = dialog.getByRole('combobox', { name, exact: true });
        await assertClickable(field, name);
        await field.selectOption(value);
    }
    await assertClickable(dialog.getByRole('button', { name: 'Másodlagos rendezés eltávolítása', exact: true }), 'utolsó tartalmi gomb');
    await assertClickable(dialog.getByRole('button', { name: 'Rendezés alkalmazása', exact: true }), 'lábléc gomb');
    await dialog.getByRole('button', { name: 'Rendezés alkalmazása', exact: true }).click();
    await expect(page).toHaveURL(/then_by=requested_arrival_at/);
    expect(new URL(page.url()).searchParams.get('sort_dir')).toBe('asc');
    expect(new URL(page.url()).searchParams.get('then_dir')).toBe('desc');
    await openSort(page);
    await expect(dialog.getByRole('combobox', { name: 'Másodlagos irány', exact: true })).toHaveValue('desc');
    await page.keyboard.press('Escape');
    health.assertHealthy();
});

test('valódi BFCache-vissza- és előrelépés megtartja a rendezési szinteket és a szűrőket', async ({ baseURL }) => {
    test.setTimeout(90000);
    const shipment = fixture();
    // Playwright normally disables BFCache, which would hide a stale in-memory draft.
    const cacheBrowser = await chromium.launch({
        channel: 'chrome', headless: true,
        ignoreDefaultArgs: ['--disable-back-forward-cache'],
        args: ['--enable-automation', '--enable-features=BackForwardCache'],
    });
    const context = await cacheBrowser.newContext({ baseURL, viewport: { width: 1440, height: 900 } });
    try {
        await context.addInitScript(() => {
            window.sortPageShows = [];
            window.sortDepartures = [];
            window.addEventListener('pageshow', event => window.sortPageShows.push(event.persisted));
            window.addEventListener('pagehide', () => {
                const form = document.querySelector('#intent-filters');
                if (!form) return;
                const fields = [...form.querySelectorAll('[data-listing-sort] input[type="hidden"]')];
                window.sortDepartures.push(Object.fromEntries(fields.filter(field => !field.disabled)
                    .map(field => [field.name, field.value])));
            });
        });
        const page = await context.newPage();
        const health = watchBrowserHealth(page);
        const protocol = await cacheBrowser.newBrowserCDPSession();
        const commandLine = await protocol.send('Browser.getBrowserCommandLine');
        expect(commandLine.arguments).not.toContain('--disable-back-forward-cache');
        await protocol.detach();
        await login(page);
        const filters = { warehouse_id: String(shipment.source), per_page: '2', status: 'approved', search: '5C' };
        const original = { sort_by: 'quantity', sort_dir: 'asc', then_by: 'priority', then_dir: 'desc' };
        const alternative = { sort_by: 'priority', sort_dir: 'desc', then_by: 'reference', then_dir: 'asc' };
        const fieldLabels = { sort_by: 'Elsődleges szempont', sort_dir: 'Elsődleges irány', then_by: 'Másodlagos szempont', then_dir: 'Másodlagos irány' };
        const navigation = { waitUntil: 'commit', timeout: 10000 };
        const capture = async sort => ({ sort, url: page.url(), summary: await sortLabel(page).textContent(), rows: await intentRowIds(page) });
        const restored = async (view, visit) => {
            await expect(page).toHaveURL(view.url);
            await expect.poll(() => page.evaluate(() => window.sortPageShows.filter(Boolean).length)).toBe(visit);
            await expect(sortLabel(page)).toHaveText(view.summary);
            expect(await intentRowIds(page)).toEqual(view.rows);
            await expect(page.locator('#intent-search')).toHaveValue(filters.search);
            await expect(page.locator('#intent-filter-status')).toHaveValue(filters.status);
            await expect(page.locator('#intent-filter-warehouse')).toHaveValue(filters.warehouse_id);
            const dialog = await openSort(page);
            for (const [name, value] of Object.entries(view.sort)) {
                const field = page.locator('[data-listing-sort] input[type="hidden"][name="' + name + '"]');
                await expect(field).toHaveValue(value);
                await expect(field).toBeEnabled();
                await expect(dialog.getByRole('combobox', { name: fieldLabels[name], exact: true })).toHaveValue(value);
            }
            return dialog;
        };

        await page.goto('/atmozgatasok?' + new URLSearchParams({ ...filters, ...original }));
        const first = await capture(original);
        await sortControl(page, alternative.sort_by, alternative.sort_dir, filters,
            { key: alternative.then_by, direction: alternative.then_dir });
        const second = await capture(alternative);
        expect(second.summary).not.toBe(first.summary);
        expect(second.rows).not.toEqual(first.rows);

        await page.goBack(navigation);
        const dialog = await restored(first, 1);
        // The outgoing request must never overwrite the cached document's filter controls.
        expect(await page.evaluate(() => window.sortDepartures.at(-1))).toEqual(original);
        await dialog.getByRole('button', { name: 'Másodlagos rendezés eltávolítása', exact: true }).click();
        await dialog.getByRole('button', { name: 'Mégse', exact: true }).click();
        await expect(page).toHaveURL(first.url);
        await expect(sortLabel(page)).toBeFocused();

        await page.goForward(navigation);
        await restored(second, 1);
        await page.keyboard.press('Escape');
        await expect(sortLabel(page)).toBeFocused();
        await sortLabel(page).press('Enter');
        await dialog.getByRole('button', { name: 'Másodlagos rendezés eltávolítása', exact: true }).click();
        await sortControl(page, alternative.sort_by, 'asc', filters);
        const singleSort = new URL(page.url()).searchParams;
        expect(singleSort.get('then_by')).toBeNull();
        expect(singleSort.get('then_dir')).toBeNull();

        await page.goBack(navigation);
        await restored(second, 2);
        await page.keyboard.press('Escape');
        await page.locator('#intent-search').fill(shipment.products.screw.sku);
        await page.getByRole('button', { name: 'Szűrés', exact: true }).click();
        await expect(page).toHaveURL(url => url.searchParams.get('search') === shipment.products.screw.sku);
        const query = new URL(page.url()).searchParams;
        for (const [name, value] of Object.entries(alternative)) expect(query.get(name), name).toBe(value);
        expect(query.get('search')).toBe(shipment.products.screw.sku);
        for (const name of ['warehouse_id', 'per_page', 'status']) expect(query.get(name), name).toBe(filters[name]);
        await expect(page.locator('[data-transfer-intent-list] [role="rowgroup"] [role="row"]')).toHaveCount(1);
        health.assertHealthy();
    } finally {
        await context.close();
        await cacheBrowser.close();
    }
});
