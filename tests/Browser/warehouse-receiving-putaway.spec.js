import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { join } from 'node:path';
import { shipmentCommand as command } from './shipment-fixtures';
import { verifyPutawayDialogIsolation } from './putaway-dialog-regression';

const workUrl = '/raktari-feladatok';
const receivingUrl = workUrl + '/atvetel';
const putawayUrl = workUrl + '/betarolas';
const receiptPage = (shipment, stop) => receivingUrl + '/' + shipment.id + '/' + stop.id;
const receiptApi = (shipment, stop) => '/shipment-plans/' + shipment.id + '/stops/' + stop.id;
const putawayApi = item => '/shipment-receipt-items/' + item + '/putaway';
const admin = 'warehouse-admin@example.test';
const screenshots = process.env.RECEIVING_SCREENSHOT_DIR;
const button = (page, name) => page.getByRole('button', { name, exact: true });
const link = (page, name) => page.getByRole('link', { name, exact: true });
const receivingReview = page => page.getByRole('dialog', { name: 'Átvétel megerősítése', exact: true });
const putawayReview = page => page.getByRole('dialog', { name: 'Betárolás megerősítése', exact: true });
const line = (page, product) => page.getByTestId('receiving-line').filter({ hasText: product.sku });
const location = (page, code) => page.locator('[data-putaway-location="' + code + '"]');
const task = (page, product) => page.locator('[data-putaway-task-row]').filter({ hasText: product.sku });
const receiveConfirmation = 'Ellenőriztem a szállítmányt, és a megadott mennyiségeket ténylegesen átvettem.';
const putawayConfirmation = 'Ellenőriztem, hogy a megadott mennyiséget ténylegesen ebbe a rekeszbe helyezték.';
test.use({ actionTimeout: 10000 });

const siteConfirmation = 'A helyszínen ellenőriztem, hogy a rakomány elfér a rekeszben.';

function fixture(...args) {
    return JSON.parse(execFileSync('php', ['tests/Browser/prepare-shipment-receiving.php', ...args], { encoding: 'utf8' }));
}

function state(shipment) {
    return JSON.parse(execFileSync('php', ['tests/Browser/read-shipment-receiving.php', String(shipment.id)], { encoding: 'utf8' }));
}

async function login(page, email) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await button(page, 'Log in').click();
    await expect(page).toHaveURL(/dashboard/);
}

async function capture(page, name, dialog = null, directory = screenshots) {
    if (!directory) return;
    const options = { path: join(directory, name + '.png'), animations: 'disabled' };
    if (dialog) {
        await dialog.screenshot(options);
        return;
    }
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await page.screenshot({ ...options, fullPage: true });
}

async function geometry(page, width, name, dialog = null) {
    await page.setViewportSize({ width, height: width < 800 ? 900 : 960 });
    if (dialog) await expect(dialog).toBeVisible();
    const metrics = await page.evaluate(() => {
        const open = document.querySelector('dialog[open]');
        return {
            width: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
            modal: open ? { left: open.getBoundingClientRect().left, right: open.getBoundingClientRect().right } : null,
        };
    });
    expect(metrics.width, name + ', ' + width + ' px').toBeLessThanOrEqual(width);
    if (metrics.modal) {
        expect(metrics.modal.left).toBeGreaterThanOrEqual(0);
        expect(metrics.modal.right).toBeLessThanOrEqual(width + 1);
    }
}

async function proveResponsive(page, name, dialog = null) {
    for (const width of [320, 375, 768, 1024, 1440]) await geometry(page, width, name, dialog);
}

async function receiveEverything(page) {
    const all = button(page, 'Minden tétel teljes mennyisége');
    if (await all.isVisible()) await all.click();
    else await button(page, 'Teljes mennyiség').click();
    await button(page, 'Átvétel rögzítése').press('Enter');
    const dialog = receivingReview(page);
    await expect(dialog).toBeVisible();
    await dialog.getByRole('checkbox', { name: receiveConfirmation }).check();
    return dialog;
}

async function putawayCargo(page, { quantity, weight, size }) {
    await expect(page.locator('[data-putaway-remaining]')).toHaveText(/\d+.* db/);
    await page.locator('[data-putaway-quantity]').fill(String(quantity));
    await page.locator('[data-putaway-weight]').fill(String(weight));
    await page.locator('[data-putaway-pallets]').fill('0');
    if (!size) return;
    if (!await page.getByLabel('Szélesség (cm)').isVisible()) await page.getByText('Külső méretek (cm)', { exact: true }).click();
    await page.getByLabel('Szélesség (cm)').fill(String(size));
    await page.getByLabel('Mélység (cm)').fill(String(size));
    await page.getByLabel('Magasság (cm)').fill(String(size));
}

async function searchLocation(page, value) {
    await page.locator('[data-putaway-search]').fill(value);
}

async function selectLocation(page, code) {
    const row = location(page, code);
    await expect(row).toBeVisible();
    await button(row, 'Kiválasztás').click();
}

async function openPutawayReview(page, site = false, touch = false) {
    const review = button(page, 'Betárolás ellenőrzése');
    await expect(review).toBeEnabled();
    if (touch) await review.tap();
    else await review.press('Enter');
    const dialog = putawayReview(page);
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('checkbox', { name: siteConfirmation })).toHaveCount(site ? 1 : 0);
    if (site) await dialog.getByRole('checkbox', { name: siteConfirmation }).check();
    await dialog.getByRole('checkbox', { name: putawayConfirmation }).check();
    return dialog;
}

async function bookPutaway(page, site = false) {
    const dialog = await openPutawayReview(page, site);
    await button(dialog, 'Betárolás rögzítése').click();
    await expect(page.locator('[data-putaway-success]')).toBeVisible();
}

function assertBalances(shipment, planned) {
    const db = state(shipment);
    expect(db.transit.map(row => [row.remaining_quantity, row.physical_quantity])).toEqual(planned);
    expect(db.transit.map(row => row.source_quantity)).toEqual([0, 0, 0]);
    return db;
}

test('a valóban feladott kétmegállós fuvar teljes átvétele és rekeszes betárolása', async ({ page, browser, baseURL }) => {
    test.setTimeout(90000);
    const shipment = fixture();
    const { pecs, gyor, products } = shipment;
    const jsErrors = [];
    page.on('pageerror', error => jsErrors.push(error.message));
    expect(state(shipment).shipment[0].status).toBe('in_transit');
    assertBalances(shipment, [[200, 0], [40, 0], [24, 0]]);

    await login(page, admin);
    await page.setViewportSize({ width: 1440, height: 960 });
    await page.goto(workUrl);
    await expect(link(page, 'Kitárolás')).toBeVisible();
    await expect(link(page, 'Átvétel')).toBeVisible();
    await expect(link(page, 'Betárolás')).toBeVisible();
    await capture(page, '01-raktari-feladatok-1440');
    await link(page, 'Átvétel').click();
    const adminStopRows = page.getByTestId('receiving-stop-row').filter({ hasText: shipment.number });
    await expect(adminStopRows).toHaveCount(2);
    await capture(page, '02-erkezo-szallitmanyok-1440');
    await proveResponsive(page, 'Átvételi lista');
    await geometry(page, 1024, 'Átvételi lista');
    await capture(page, '14-erkezo-szallitmanyok-1024');
    await geometry(page, 768, 'Átvételi lista');
    await capture(page, '15-erkezo-szallitmanyok-768');

    const pecsContext = await browser.newContext({ baseURL, viewport: { width: 1440, height: 960 } });
    const desk = await pecsContext.newPage();
    desk.on('pageerror', error => jsErrors.push(error.message));
    try {
        await login(desk, pecs.email);
        await desk.goto(receivingUrl);
        const rows = desk.getByTestId('receiving-stop-row').filter({ hasText: shipment.number });
        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText('Pécs');
        expect((await desk.request.get(receiptPage(shipment, gyor))).status()).toBe(403);
        const search = desk.getByRole('combobox', { name: 'Keresés', exact: true });
        await search.fill(products.part.sku);
        await button(desk, 'Szűrés').click();
        await expect(desk).toHaveURL(url => url.searchParams.get('search') === products.part.sku);
        await expect(rows).toHaveCount(0);
        await expect(search).toHaveAttribute('aria-expanded', 'false');
        await search.fill(products.screw.sku);
        await button(desk, 'Szűrés').click();
        await expect(desk).toHaveURL(url => url.searchParams.get('search') === products.screw.sku);
        await expect(rows).toHaveCount(1);
        await expect(rows).toContainText('2 tétel');
        await link(rows, shipment.number).click();
        await expect(desk).toHaveURL(receiptPage(shipment, pecs));
        await expect(line(desk, products.screw)).toContainText(products.screw.intentReference);
        await expect(line(desk, products.part)).toHaveCount(0);
        await expect(button(desk, 'Átvétel rögzítése')).toBeDisabled();
        await proveResponsive(desk, 'Átvételi munkalap');
        await geometry(desk, 320, 'Átvételi munkalap');
        await capture(desk, '16-megallo-munkalap-320');
        await geometry(desk, 1440, 'Átvételi munkalap');
        await capture(desk, '03-megallo-munkalap-1440');
        const review = await receiveEverything(desk);
        await expect(review).toContainText('200 db');
        await expect(review).toContainText('40 db');
        await proveResponsive(desk, 'Átvétel megerősítése', review);
        await capture(desk, '05-atvetel-megerositese-1440', review);
        await button(review, 'Átvétel véglegesítése').click();
        const receiptSuccess = desk.getByTestId('receiving-success');
        await expect(receiptSuccess).toContainText('240 db sikeresen átvéve');
        await expect(desk.getByTestId('receiving-history')).toContainText('200 db');
        const afterReceive = assertBalances(shipment, [[0, 200], [0, 40], [24, 0]]);
        expect(afterReceive.shipment[0].status).toBe('in_transit');
        expect(afterReceive.receiptItems.map(row => row.pending_putaway_quantity)).toEqual([200, 40]);
        expect(afterReceive.inMovements.map(row => row.quantity)).toEqual([200, 40]);
        expect(afterReceive.placements).toEqual([]);

        await link(receiptSuccess, 'Betárolás megkezdése').click();
        await expect(desk).toHaveURL(new RegExp(putawayUrl + '\\?'));
        await expect(task(desk, products.screw)).toContainText('200 db');
        await expect(task(desk, products.machine)).toContainText('40 db');
        await expect(task(desk, products.part)).toHaveCount(0);
        await proveResponsive(desk, 'Betárolási feladatlista');
        await capture(desk, '06-betarolasra-varo-tetelek-1440');
        await link(task(desk, products.screw), products.screw.name).click();
        const screwItem = afterReceive.receiptItems.find(row => row.product_id === products.screw.id);
        await expect(desk).toHaveURL(putawayUrl + '/' + screwItem.id);
        await expect(desk.locator('[data-putaway-workspace]')).toBeVisible();
        await putawayCargo(desk, { quantity: 120, weight: 12 });
        await button(desk, 'Ajánlások frissítése').click();
        await expect(location(desk, 'A-01')).toContainText('Feltételes');
        await expect(location(desk, 'A-01').locator('[data-putaway-stock="bin"]')).toHaveText('Ebből a termékből a rekeszben: 0 → 120 db');
        expect(state(shipment).placements).toEqual([]);
        await putawayCargo(desk, { quantity: 120, weight: 12, size: 20 });
        await expect(location(desk, 'A-01')).toHaveCount(0);
        await button(desk, 'Ajánlások frissítése').click();
        await expect(location(desk, 'A-01')).toContainText('Ellenőrzött');
        await proveResponsive(desk, 'Smart Putaway ajánlások');
        await capture(desk, '07-smart-putaway-ajanlasok-1440');
        await selectLocation(desk, 'A-01');

        await expect(button(location(desk, 'A-01'), 'Kiválasztva')).toHaveAttribute('aria-pressed', 'true');
        await expect(desk.locator('[data-putaway-selection]')).toBeHidden();
        await expect(desk.locator('[data-putaway-target-stock]')).toHaveText('Ebből a termékből a célrekeszben 0 → 120 db');
        await expect(location(desk, 'A-01').locator('[data-putaway-rank-summary]')).toBeHidden();
        await location(desk, 'A-01').getByText('Részletek', { exact: true }).click();
        await expect(location(desk, 'A-01').locator('[data-putaway-rank-detail]')).toContainText('a rekeszkód szerinti sorrend döntött');
        const mapLink = location(desk, 'A-01').getByRole('link', { name: /^Megtekintés az alaprajzon/ });
        const ownUrl = desk.url();
        const [map] = await Promise.all([desk.waitForEvent('popup'), mapLink.click()]);
        await expect(map).toHaveURL(putawayUrl + '/' + screwItem.id + '/alaprajz/' + shipment.slots['A-01']);
        await expect(map.locator('[data-app-topbar]').getByRole('link', { name: 'Betárolás', exact: true }))
            .toHaveAttribute('aria-current', 'page');
        await expect(map.locator('[data-putaway-plan]')).toContainText('A-01');
        await expect(map.locator('[data-putaway-target="A-01"][aria-current="true"]')).toBeVisible();
        expect((await desk.request.get(putawayUrl + '/' + screwItem.id + '/alaprajz/' + shipment.slots['GY-01'])).status()).toBe(404);
        await capture(map, '13-celrekesz-alaprajzon-1440');
        await map.close();
        expect(desk.url()).toBe(ownUrl);
        expect(state(shipment).placements).toEqual([]);

        const firstPutaway = await openPutawayReview(desk);
        await expect(firstPutaway).toContainText('A-01');
        await expect(firstPutaway).toContainText('120 db');
        await expect(firstPutaway).toContainText('80 db');
        await proveResponsive(desk, 'Betárolás megerősítése', firstPutaway);
        await capture(desk, '09-betarolas-megerositese-1440', firstPutaway);
        await button(firstPutaway, 'Betárolás rögzítése').click();
        await expect(desk.locator('[data-putaway-success]')).toContainText('120 db');
        await expect(desk.locator('[data-putaway-history]')).toContainText('A-01');
        await capture(desk, '10-reszben-betarolt-tetel-1440');
        const partial = assertBalances(shipment, [[0, 200], [0, 40], [24, 0]]);
        expect(partial.receiptItems.map(row => row.pending_putaway_quantity)).toEqual([80, 40]);
        expect(partial.placements).toMatchObject([{ item_id: screwItem.id, location_code: 'A-01', quantity: 120, cargo_weight_grams: 12000 }]);

        await putawayCargo(desk, { quantity: 80, weight: 8, size: 20 });
        await button(desk, 'Kézi rekeszkeresés').click();
        await searchLocation(desk, 'INAKTIV');
        await expect(location(desk, 'INAKTIV')).toContainText('Nem alkalmas');
        await expect(button(location(desk, 'INAKTIV'), 'Kiválasztás')).toBeHidden();
        await searchLocation(desk, 'KIS-01');
        await expect(location(desk, 'KIS-01')).toContainText('Nem alkalmas');
        await expect(button(location(desk, 'KIS-01'), 'Kiválasztás')).toBeHidden();
        await searchLocation(desk, 'GY-01');
        await expect(location(desk, 'GY-01')).toHaveCount(0);
        await searchLocation(desk, 'Csavarzóna');
        await expect(location(desk, 'A-02')).toContainText('Ellenőrzött');
        await proveResponsive(desk, 'Kézi rekeszkeresés');
        await capture(desk, '08-kezi-rekeszkereses-1440');
        await selectLocation(desk, 'A-02');
        await bookPutaway(desk);
        await expect(desk.locator('[data-putaway-workspace]')).toContainText('Teljesen betárolva');
        await expect(desk.locator('[data-putaway-history]')).toContainText('A-02');
        await capture(desk, '11-teljesen-betarolt-tetel-1440');

        await desk.goto(putawayUrl);
        await expect(task(desk, products.screw)).toHaveCount(0);
        await desk.goto(putawayUrl + '?status=completed');
        await expect(task(desk, products.screw)).toContainText('Teljesen betárolva');
        await desk.goto(putawayUrl);
        await link(task(desk, products.machine), products.machine.name).click();
        await putawayCargo(desk, { quantity: 40, weight: 400, size: 50 });
        await button(desk, 'Ajánlások frissítése').click();
        await selectLocation(desk, 'GEP-01');
        await bookPutaway(desk);
        let current = assertBalances(shipment, [[0, 200], [0, 40], [24, 0]]);
        expect(current.receiptItems.map(row => row.pending_putaway_quantity)).toEqual([0, 0]);
        expect(current.placements.map(row => [row.location_code, row.quantity, row.cargo_weight_grams])).toEqual([
            ['A-01', 120, 12000], ['A-02', 80, 8000], ['GEP-01', 40, 400000],
        ]);
        expect(current.inMovements).toEqual(afterReceive.inMovements);
    } finally {
        await pecsContext.close();
    }

    await page.goto('/shipments/' + shipment.id);
    const feedback = page.locator('[data-receiving-stop-summary]');
    await expect(feedback.filter({ hasText: 'Pécs' })).toContainText('Teljesen átvéve');
    await expect(feedback.filter({ hasText: 'Győr' })).toContainText('Átvételre vár');
    await expect(link(feedback.filter({ hasText: 'Győr' }), 'Átvétel megnyitása')).toBeVisible();

    const gyorContext = await browser.newContext({ baseURL, viewport: { width: 375, height: 900 }, hasTouch: true, isMobile: true });
    const mobile = await gyorContext.newPage();
    mobile.on('pageerror', error => jsErrors.push(error.message));
    try {
        await login(mobile, gyor.email);
        await mobile.goto(receiptPage(shipment, gyor));
        await expect(line(mobile, products.part)).toContainText('24 db');
        await capture(mobile, '12a-mobilos-atvetel-375');
        const review = await receiveEverything(mobile);
        await geometry(mobile, 375, 'Mobilos átvétel', review);
        await capture(mobile, '12b-mobilos-atvetel-megerositese-375', review);
        await button(review, 'Átvétel véglegesítése').click();
        const success = mobile.getByTestId('receiving-success');
        await expect(success).toContainText('24 db sikeresen átvéve');
        const delivered = assertBalances(shipment, [[0, 200], [0, 40], [0, 24]]);
        expect(delivered.shipment[0].status).toBe('completed');
        expect(delivered.receiptItems.map(row => row.pending_putaway_quantity)).toEqual([0, 0, 24]);
        await link(success, 'Betárolás megkezdése').click();
        await expect(mobile).toHaveURL(new RegExp(putawayUrl));
        if (new URL(mobile.url()).pathname === putawayUrl) await link(task(mobile, products.part), products.part.name).click();
        await expect(mobile.locator('[data-putaway-workspace]')).toBeVisible();
        await expect(mobile.locator('[data-putaway-remaining]')).toHaveText('24 db');
        const touchWeight = mobile.locator('[data-putaway-weight]');
        const touchPallets = mobile.locator('[data-putaway-pallets]');
        for (const [control, value] of [[touchWeight, '48'], [touchPallets, '0']]) {
            await control.tap();
            await control.pressSequentially(value);
            await expect(control).toHaveValue(value);
        }
        await button(mobile, 'Ajánlások frissítése').tap();
        await expect(location(mobile, 'GY-01')).toContainText('Feltételes');
        const details = location(mobile, 'GY-01').locator('details');
        await details.locator('summary').tap();
        await expect(details).toHaveJSProperty('open', true);
        await details.locator('summary').tap();
        await expect(details).toHaveJSProperty('open', false);
        await capture(mobile, '12c-mobilos-betarolas-375');
        await selectLocation(mobile, 'GY-01');
        await expect(touchWeight).toHaveValue('48');
        await expect(touchPallets).toHaveValue('0');
        const placement = await openPutawayReview(mobile, true, true);
        await geometry(mobile, 320, 'Mobilos betárolás', placement);
        await capture(mobile, '12d-mobilos-betarolas-megerositese-320', placement);
        await button(placement, 'Betárolás rögzítése').tap();
        await expect(mobile.locator('[data-putaway-workspace]')).toContainText('Teljesen betárolva');
        const complete = assertBalances(shipment, [[0, 200], [0, 40], [0, 24]]);
        expect(complete.inMovements).toEqual(delivered.inMovements);
        expect(complete.receiptItems.map(row => row.pending_putaway_quantity)).toEqual([0, 0, 0]);
        expect(complete.placements.at(-1)).toMatchObject({
            current_location_id: shipment.slots['GY-01'], quantity: 24, cargo_weight_grams: 48000,
            geometry_status: 'site_verified', pallet_slots: 0,
        });
        expect(complete.receiptItems.map(row => row.transfer_intent_id)).toEqual(
            [products.screw.intent, products.machine.intent, products.part.intent],
        );
    } finally {
        await gyorContext.close();
    }
    await page.goto(receivingUrl + '?status=received');
    await expect(page.getByTestId('receiving-stop-row').filter({ hasText: shipment.number })).toHaveCount(2);
    expect(jsErrors).toEqual([]);
});

test('részátvétel, elavult állapot, billentyűzet és az elveszett válasz idempotens újrapróbálása', async ({ page }) => {
    test.setTimeout(90000);
    const shipment = fixture();
    const { pecs, gyor, products } = shipment;
    await login(page, pecs.email);
    await page.setViewportSize({ width: 1440, height: 960 });
    await page.goto(receiptPage(shipment, pecs));
    const screw = line(page, products.screw);
    const amount = screw.getByLabel('Most átvett mennyiség');
    const details = screw.getByLabel('Tétel megjegyzése');
    const note = '5 db még nem érkezett meg.';
    await amount.fill('195');
    await details.fill(note);
    const open = button(page, 'Átvétel rögzítése');
    await open.press('Enter');
    const dialog = receivingReview(page);
    await expect(dialog).toContainText('5 db');
    await dialog.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(open).toBeFocused();

    const before = state(shipment);
    const machine = before.transit.find(row => row.product_id === products.machine.id);
    const api = receiptApi(shipment, pecs) + '/receipts';
    await command(page, 'POST', api, {
        operation_key: randomUUID(), version: 1, confirmed: true, items: [{ transit_item_id: machine.id, quantity: 1 }],
    }, 201);

    await open.click();
    await dialog.getByRole('checkbox', { name: receiveConfirmation }).check();
    const conflictResponse = page.waitForResponse(response => response.url().endsWith(api) && response.status() === 409);
    await button(dialog, 'Átvétel véglegesítése').click();
    await conflictResponse;
    await button(dialog, 'Átvételi adatok frissítése').click();
    await expect(dialog).toBeHidden();
    await expect(amount).toHaveValue('195');
    await expect(details).toHaveValue(note);
    await expect(page.locator('main')).toContainText('Részben átvéve');

    const keys = [];
    await page.route('**' + api, async route => {
        if (route.request().method() !== 'POST') return route.continue();
        keys.push(route.request().postDataJSON().operation_key);
        if (keys.length !== 1) return route.continue();
        const processed = await route.fetch();
        expect(processed.status(), await processed.text()).toBe(201);
        await route.abort('failed');
    });
    await open.click();
    const confirmation = dialog.getByRole('checkbox', { name: receiveConfirmation });
    await expect(confirmation).not.toBeChecked();
    await confirmation.check();
    await button(dialog, 'Átvétel véglegesítése').evaluate(element => { element.click(); element.click(); });
    const retry = button(dialog, 'Átvétel újrapróbálása');
    await expect(retry).toBeEnabled();
    await expect(page.getByTestId('receiving-success')).toBeHidden();
    await retry.click();
    await expect(page.getByTestId('receiving-success')).toContainText('195 db sikeresen átvéve');
    await capture(page, '04-reszleges-atvetel-1440');
    expect(keys).toHaveLength(2);
    expect(keys[0]).toBe(keys[1]);
    const after = assertBalances(shipment, [[5, 195], [39, 1], [24, 0]]);
    expect(after.receiptItems.filter(row => row.product_id === products.screw.id)).toMatchObject([{
        received_quantity: 195, pending_putaway_quantity: 195, note, operation_key: keys[0],
    }]);
    expect(after.inMovements.filter(row => row.product_id === products.screw.id)).toHaveLength(1);
    expect(after.transit.at(-1).shipment_stop_id).toBe(gyor.id);
    expect(after.placements).toEqual([]);
    await expect(page.getByTestId('receiving-history')).toContainText(note);
});

test('fogadási jogosultság és rekeszkapacitás hibái a tényleges munkalaphoz kötve', async ({ page, browser, baseURL }) => {
    test.setTimeout(90000);
    const shipment = fixture();
    const { pecs, gyor, products } = shipment;
    await login(page, shipment.dispatcher);
    const restricted = await page.request.get(receiptPage(shipment, pecs));
    expect(restricted.status()).toBe(403);
    await page.goto(workUrl);
    await expect(link(page, 'Kitárolás')).toBeVisible();
    await expect(link(page, 'Átvétel')).toBeHidden();
    await expect(link(page, 'Betárolás')).toBeHidden();

    const context = await browser.newContext({ baseURL });
    const desk = await context.newPage();
    try {
        await login(desk, pecs.email);
        const screw = state(shipment).transit.find(row => row.product_id === products.screw.id);
        const receive = await command(desk, 'POST', receiptApi(shipment, pecs) + '/receipts', {
            operation_key: randomUUID(), version: 1, confirmed: true, items: [{ transit_item_id: screw.id, quantity: 200 }],
        }, 201);
        const item = (await receive.json()).items[0].id;
        expect((await page.request.get(putawayUrl + '/' + item)).status()).toBe(403);
        await desk.goto(putawayUrl + '/' + item);
        await desk.locator('[data-putaway-quantity]').fill('200');
        const packaging = desk.getByRole('checkbox', { name: /^A részlet egész, bontatlan csomagokból áll/ });
        await expect(packaging).toBeVisible();
        await expect(packaging).not.toBeChecked();
        await expect(desk.getByText('2 csomag × 5,5 kg = 11 kg', { exact: true })).toBeVisible();
        await packaging.check();
        await expect(desk.locator('[data-putaway-weight]')).toBeDisabled();
        await packaging.uncheck();
        let releaseLookup;
        let markLookupHeld;
        let markLookupFinished;
        const lookupHeld = new Promise(resolve => { markLookupHeld = resolve; });
        const lookupFinished = new Promise(resolve => { markLookupFinished = resolve; });
        const holdLookup = new Promise(resolve => { releaseLookup = resolve; });
        await desk.route('**/putaway/recommendations', async route => {
            if (route.request().postDataJSON().cargo_weight_kg !== 2) return route.continue();
            try {
                const oldResponse = await route.fetch();
                expect(oldResponse.ok(), await oldResponse.text()).toBe(true);
                markLookupHeld();
                await holdLookup;
                // The application's abort may already have cancelled this request.
                await route.fulfill({ response: oldResponse }).catch(() => {});
            } finally {
                markLookupFinished();
            }
        });
        try {
            await putawayCargo(desk, { quantity: 150, weight: 2, size: 20 });
            await lookupHeld;
            await putawayCargo(desk, { quantity: 150, weight: 600, size: 20 });
            await expect(desk.getByText(/nincs ajánlható (rekesz|tárhely)|nem találtunk alkalmas/i)).toBeVisible();
            await expect(button(desk, 'Kiválasztás')).toBeHidden();
        } finally {
            releaseLookup();
        }
        await lookupFinished;
        await expect(location(desk, 'A-01')).toHaveCount(0);
        await expect(button(desk, 'Betárolás ellenőrzése')).toBeDisabled();
        await putawayCargo(desk, { quantity: 150, weight: 15, size: 20 });
        const note = 'Megerősítve a próbaműszakban.';
        await desk.getByLabel('Megjegyzés (opcionális)').fill(note);
        await button(desk, 'Ajánlások frissítése').click();
        await selectLocation(desk, 'A-01');
        const api = putawayApi(item);
        const dialog = await openPutawayReview(desk);
        const competing = await command(desk, 'POST', api, {
            operation_key: randomUUID(), version: 1, confirmed: true, quantity: 10, cargo_weight_kg: 1,
            pallet_slots: 0, to_location_id: shipment.slots['A-02'], dimensions_cm: { width: 20, depth: 20, height: 20 },
        }, 201);
        expect((await competing.json()).item.pending_putaway_quantity).toBe(190);
        const conflictResponse = desk.waitForResponse(response => response.url().endsWith(api) && response.status() === 409);
        await button(dialog, 'Betárolás rögzítése').click();
        await conflictResponse;
        await expect(desk.locator('[data-putaway-success-location]')).toBeHidden();
        await expect(desk.getByText(/meg(változott|változtak)|Frissítsd az átvételi tételt/).filter({ visible: true }).first()).toBeVisible();
        expect(state(shipment).placements.map(row => row.location_code)).toEqual(['A-02']);
        await expect(desk.locator('[data-putaway-quantity]')).toHaveValue('150');
        await expect(desk.locator('[data-putaway-weight]')).toHaveValue('15');
        await expect(desk.getByLabel('Megjegyzés (opcionális)')).toHaveValue(note);
        const selected = desk.getByText('Kiválasztott célrekesz: A-01', { exact: true });
        await expect(selected).toBeVisible();
        await expect(desk.locator('[data-putaway-selection]').getByText('Új ellenőrzés szükséges', { exact: true })).toBeVisible();
        await button(desk, 'Tétel frissítése').click();
        await expect(desk.locator('[data-putaway-remaining]')).toHaveText('190 db');
        await expect(button(location(desk, 'A-01'), 'Kiválasztva')).toBeVisible();
        await expect(selected).toBeHidden();
        await expect(button(desk, 'Betárolás ellenőrzése')).toBeEnabled();
        const retryKeys = [];
        await desk.route('**' + api, async route => {
            if (route.request().method() !== 'POST') return route.continue();
            retryKeys.push(route.request().postDataJSON().operation_key);
            if (retryKeys.length !== 1) return route.continue();
            const processed = await route.fetch();
            expect(processed.status(), await processed.text()).toBe(201);
            await route.abort('failed');
        });
        const refreshed = await openPutawayReview(desk);
        await button(refreshed, 'Betárolás rögzítése').click();
        const retry = button(refreshed, 'Mentés újrapróbálása');
        await expect(retry).toBeEnabled();
        await expect(desk.locator('[data-putaway-success]')).toBeHidden();
        await retry.click();
        await expect(desk.locator('[data-putaway-success]')).toContainText('150 db');
        expect(retryKeys).toEqual([retryKeys[0], retryKeys[0]]);
        const final = assertBalances(shipment, [[0, 200], [40, 0], [24, 0]]);
        expect(final.placements.map(row => [row.location_code, row.quantity, row.cargo_weight_grams])).toEqual([
            ['A-02', 10, 1000], ['A-01', 150, 15000],
        ]);
        await expect(desk.locator('[data-putaway-history]')).toContainText(note);
        expect(final.inMovements).toHaveLength(1);
        expect(final.receiptItems[0].pending_putaway_quantity).toBe(40);
        const foreignItem = await desk.request.get(receiptPage(shipment, gyor));
        expect(foreignItem.status()).toBe(403);
    } finally {
        await context.close();
    }
});

test('részátvett 200 db két külön tételre bomlik, a rekesz kapacitása újraellenőrizhető', async ({ page }) => {
    test.setTimeout(90_000);
    const shipment = fixture();
    const { pecs, products } = shipment;
    await login(page, pecs.email);
    const [screw, machine] = state(shipment).transit;
    const receiveUrl = receiptApi(shipment, pecs) + '/receipts';
    const first = await command(page, 'POST', receiveUrl, {
        operation_key: randomUUID(), version: 1, confirmed: true, items: [{ transit_item_id: screw.id, quantity: 120 }],
    }, 201);
    const firstId = (await first.json()).items[0].id;
    assertBalances(shipment, [[80, 120], [40, 0], [24, 0]]);
    await page.goto(putawayUrl + '/' + firstId);

    const firstAdvice = page.waitForResponse(response => response.url().endsWith('/putaway/recommendations')
        && response.request().postDataJSON().dimensions_cm?.height === 20);
    await putawayCargo(page, { quantity: 70, weight: 7, size: 20 });
    expect((await firstAdvice).request().postDataJSON()).toMatchObject({
        quantity: 70, cargo_weight_kg: 7, pallet_slots: 0, dimensions_cm: { width: 20, depth: 20, height: 20 },
    });
    await expect(location(page, 'A-01')).toContainText('Ellenőrzött');
    await selectLocation(page, 'A-01');
    await bookPutaway(page);
    const afterFirst = assertBalances(shipment, [[80, 120], [40, 0], [24, 0]]);
    expect(afterFirst.receiptItems[0].pending_putaway_quantity).toBe(50);

    await putawayCargo(page, { quantity: 50, weight: 5, size: 25 });
    await expect(location(page, 'A-01')).toContainText('Feltételes');
    const details = location(page, 'A-01').getByText('Részletek', { exact: true });
    await details.focus();
    await details.press('Enter');
    await expect(location(page, 'A-01').locator('[data-putaway-fit-warnings]')).toContainText('egybefüggő szabad hely');
    await expect(location(page, 'A-02').locator('[data-putaway-stock="level"]')).toHaveText('Ezen a polcszinten: 70 → 120 db');
    await selectLocation(page, 'A-02');
    await bookPutaway(page);
    const afterSecond = assertBalances(shipment, [[80, 120], [40, 0], [24, 0]]);
    expect(afterSecond.receiptItems[0].pending_putaway_quantity).toBe(0);
    expect(afterSecond.placements.map(row => [row.item_id, row.location_code, row.quantity])).toEqual([
        [firstId, 'A-01', 70], [firstId, 'A-02', 50],
    ]);

    const later = await command(page, 'POST', receiveUrl, {
        operation_key: randomUUID(), version: 2, confirmed: true,
        items: [{ transit_item_id: screw.id, quantity: 80 }, { transit_item_id: machine.id, quantity: 1 }],
    }, 201);
    const arrived = (await later.json()).items;
    const laterId = arrived.find(row => row.product_id === screw.product_id).id;
    const machineId = arrived.find(row => row.product_id === machine.product_id).id;
    expect(laterId).not.toBe(firstId);
    const beforeThird = assertBalances(shipment, [[0, 200], [39, 1], [24, 0]]);
    expect(beforeThird.receiptItems.find(row => row.id === laterId).pending_putaway_quantity).toBe(80);
    expect(beforeThird.inMovements.filter(row => row.product_id === screw.product_id)
        .map(row => [row.shipment_receipt_item_id, row.quantity])).toEqual([[firstId, 120], [laterId, 80]]);

    await page.goto(putawayUrl);
    const nextTask = task(page, products.screw);
    await expect(nextTask).toHaveCount(1);
    await expect(nextTask).toContainText('80 db');
    await link(nextTask, products.screw.name).click();
    await expect(page).toHaveURL(putawayUrl + '/' + laterId);
    await putawayCargo(page, { quantity: 80, weight: 8, size: 30 });
    await expect(location(page, 'A-02')).toContainText('Feltételes');
    await expect(location(page, 'A-02').locator('[data-putaway-stock="bin"]')).toHaveText('Ebből a termékből a rekeszben: 50 → 130 db');
    await selectLocation(page, 'A-02');
    await command(page, 'POST', putawayApi(machineId), {
        operation_key: randomUUID(), version: 1, confirmed: true, site_verified: true, quantity: 1,
        cargo_weight_kg: 90, pallet_slots: 0, to_location_id: shipment.slots['A-02'],
        dimensions_cm: { width: 10, depth: 10, height: 10 },
    }, 201);

    await button(page, 'Betárolás ellenőrzése').press('Enter');
    await expect(putawayReview(page)).toBeHidden();
    await expect(page.getByText('Kiválasztott célrekesz: A-02', { exact: true })).toBeVisible();
    await expect(page.getByText('Nem alkalmas', { exact: true }).filter({ visible: true })).toBeVisible();
    await expect(page.getByText('A-02 tárhelyen 5 kg szabad teherbírás maradt.', { exact: true }).first()).toBeVisible();
    await expect(button(page, 'Betárolás ellenőrzése')).toBeDisabled();
    const final = assertBalances(shipment, [[0, 200], [39, 1], [24, 0]]);
    expect(final.receiptItems.find(row => row.id === laterId).pending_putaway_quantity).toBe(80);
    expect(final.placements).toHaveLength(3);
    expect(final.placements.some(row => row.item_id === laterId)).toBe(false);
});

const taskTabs = page => page.locator('[data-warehouse-task-tabs]');
const lineQuantity = (row, key) => row.locator('[data-receiving-line-quantity="' + key + '"]');
const lineStatus = row => row.locator('[data-receiving-line-status]');
const previousLocation = (page, code) => page.getByRole('link', { name: code, exact: true }).and(page.locator('[data-putaway-history-location]'));
const lastLocation = page => page.locator('[data-putaway-success-location]');

async function expectTaskCounts(page, picking, receiving, putaway) {
    const tabs = taskTabs(page);
    for (const [name, label, count] of [
        ['picking', 'Kitárolás', picking], ['receiving', 'Átvétel', receiving], ['putaway', 'Betárolás', putaway],
    ]) {
        if (count == null) {
            await expect(link(tabs, label)).toHaveCount(0);
            continue;
        }
        await expect(link(tabs, label)).toBeVisible();
        await expect(link(tabs, label)).toHaveAccessibleDescription(count + ' nyitott feladat');
        await expect(tabs.locator('[data-task-count="' + name + '"]')).toHaveText(new RegExp('^\\(?\\s*' + count + '\\s*\\)?$'));
    }
}

async function captureTaskUx(page, name) {
    for (const width of [1440, 1024, 768, 375, 320]) {
        await geometry(page, width, name);
        const fits = await taskTabs(page).evaluate(tabs => {
            const bounds = tabs.getBoundingClientRect();
            return [...tabs.querySelectorAll('a')].every(link => {
                const box = link.getBoundingClientRect();
                return box.left >= bounds.left && box.right <= bounds.right;
            });
        });
        expect(fits, name + ' – munkatípusok, ' + width + ' px').toBe(true);
        await capture(page, name + '-' + width, null, process.env.WAREHOUSE_TASKS_UX_SCREENSHOT_DIR);
    }
    await geometry(page, 1440, name);
}

async function stageUnassigned(page, quantity) {
    await button(page, 'Kézi forráshelyválasztás').click();
    await button(page.locator('[data-picking-unassigned]'), 'Forrás hozzáadása').click();
    await page.getByRole('textbox', { name: 'Mennyiség · Tárhelyhez nem rendelt készlet', exact: true }).fill(String(quantity));
    await button(page, 'Kitárolás rögzítése').click();
    const confirmation = page.getByRole('dialog', { name: 'Kitárolás megerősítése', exact: true });
    await confirmation.getByRole('textbox', { name: /^Hol találtad meg a terméket\?/ }).fill('Az elkülönített bemutató rakodóterület mellett.');
    await confirmation.getByRole('checkbox', { name: 'Ellenőriztem, hogy a terméket fizikailag megtaláltam.', exact: true }).check();
    await button(confirmation, 'Rögzítés').click();
    await expect(confirmation).toBeHidden();
}

async function recordReceiptLine(page, product, quantity) {
    await line(page, product).getByLabel('Most átvett mennyiség').fill(String(quantity));
    await button(page, 'Átvétel rögzítése').press('Enter');
    const review = receivingReview(page);
    await review.getByRole('checkbox', { name: receiveConfirmation }).check();
    await button(review, 'Átvétel véglegesítése').click();
    await expect(page.getByTestId('receiving-success')).toContainText(quantity + ' db sikeresen átvéve');
}

async function openLocationInNewTab(page, target) {
    await target.focus();
    await expect(target).toBeFocused();
    const [popup] = await Promise.all([page.waitForEvent('popup'), target.press('Enter')]);
    return popup;
}

test('élő feladatszámok, tételenkénti átvétel és a legutóbbi sikeres rekeszlink', async ({ page, browser, baseURL }) => {
    test.setTimeout(120000);
    const shipment = fixture('--open-picking');
    const { pecs, gyor, products } = shipment;
    const longCode = 'BEMUTATO-FUV-HOSSZU-REKESZ-01234';
    await login(page, pecs.email);

    await page.goto(receivingUrl + '?search=nincs-talalat');
    await expect(page.getByTestId('receiving-stop-row')).toHaveCount(0);
    await expectTaskCounts(page, 1, 1, 0);
    await link(taskTabs(page), 'Kitárolás').click();
    await expectTaskCounts(page, 1, 1, 0);
    await page.goto(workUrl + '/kitarolas/' + shipment.pickingIntent);
    await expectTaskCounts(page, 1, 1, 0);
    const navigationMarker = randomUUID();
    await page.evaluate(value => { window.receivingTestNavigationMarker = value; }, navigationMarker);
    await stageUnassigned(page, 4);
    await expect(page.locator('[data-picking-history]')).toContainText('4 db');
    await expectTaskCounts(page, 1, 1, 0);
    await stageUnassigned(page, 5);
    await expect(page.locator('[data-picking-task]')).toContainText('Kitárolva');
    await expectTaskCounts(page, 0, 1, 0);
    expect(await page.evaluate(() => window.receivingTestNavigationMarker)).toBe(navigationMarker);

    await page.goto(receiptPage(shipment, pecs));
    const screw = line(page, products.screw);
    const machine = line(page, products.machine);
    await expect(lineStatus(screw)).toHaveText('Átvételre vár');
    await page.evaluate(value => { window.receivingTestNavigationMarker = value; }, navigationMarker);
    await recordReceiptLine(page, products.screw, 100);
    await expectTaskCounts(page, 0, 1, 1);
    expect(await page.evaluate(() => window.receivingTestNavigationMarker)).toBe(navigationMarker);
    await expect(lineStatus(screw)).toHaveText('Részben átvéve');
    await expect(lineStatus(machine)).toHaveText('Átvételre vár');
    for (const [row, expected] of [[screw, [200, 100, 100]], [machine, [40, 0, 40]]]) {
        for (const [index, field] of ['dispatched', 'received', 'remaining'].entries()) {
            await expect(lineQuantity(row, field)).toHaveText(expected[index] + ' db');
        }
    }
    const summary = page.getByTestId('receiving-summary');
    await expect(summary.locator('[data-receiving-line-count="total"]')).toContainText('2');
    await expect(summary.locator('[data-receiving-line-count="partial"]')).toContainText('1');
    await expect(summary.locator('[data-receiving-line-count="pending"]')).toContainText('1');
    await captureTaskUx(page, 'reszatvetel');
    const receipt = assertBalances(shipment, [[100, 100], [40, 0], [24, 0]]);
    const item = receipt.receiptItems[0].id;

    await page.goto(putawayUrl + '/' + item);
    await expectTaskCounts(page, 0, 1, 1);
    await page.evaluate(value => { window.receivingTestNavigationMarker = value; }, navigationMarker);
    await putawayCargo(page, { quantity: 40, weight: 4, size: 20 });
    await button(page, 'Ajánlások frissítése').click();
    await selectLocation(page, 'A-01');
    await expect(lastLocation(page)).toBeHidden();
    await bookPutaway(page);
    await expectTaskCounts(page, 0, 1, 1);
    await expect(page.locator('[data-putaway-remaining]')).toHaveText('60 db');
    const mapUrl = putawayUrl + '/' + item + '/alaprajz/' + shipment.slots['A-01'];
    await expect(lastLocation(page)).toHaveAccessibleName('Rekesz megtekintése');
    await expect(lastLocation(page)).toHaveAttribute('href', new RegExp(mapUrl + '$'));
    const historyLink = previousLocation(page, 'A-01');
    await expect(historyLink).toHaveAttribute('href', new RegExp(mapUrl + '$'));
    await capture(page, 'reszben-betarolva-alaprajz-link', null, process.env.WAREHOUSE_TASKS_UX_SCREENSHOT_DIR);
    const beforePreview = state(shipment);
    const map = await openLocationInNewTab(page, historyLink);
    await expect(map).toHaveURL(mapUrl);
    await expect(map.locator('[data-putaway-target="A-01"][aria-current="true"]')).toBeVisible();
    await expect(map.locator('[data-putaway-plan]')).toContainText('Megtekintési mód');
    await map.close();
    expect(state(shipment)).toEqual(beforePreview);

    await putawayCargo(page, { quantity: 60, weight: 6, size: 20 });
    await button(page, 'Kézi rekeszkeresés').click();
    await searchLocation(page, longCode);
    await selectLocation(page, longCode);
    await expect(lastLocation(page)).toHaveAttribute('href', new RegExp(mapUrl + '$'));
    await bookPutaway(page);
    await expectTaskCounts(page, 0, 1, 0);
    expect(await page.evaluate(() => window.receivingTestNavigationMarker)).toBe(navigationMarker);
    const locationUrl = '/warehouses/' + pecs.warehouseId + '/locations/' + shipment.slots[longCode];
    await expect(lastLocation(page)).toHaveAttribute('href', new RegExp(locationUrl + '$'));
    await expect(previousLocation(page, longCode)).toHaveAttribute('href', new RegExp(locationUrl + '$'));
    await expect(previousLocation(page, 'A-01')).toHaveAttribute('href', new RegExp(mapUrl + '$'));
    await expect(link(page.locator('[data-putaway-success]'), 'Másik betárolási feladat választása')).toBeVisible();
    await captureTaskUx(page, 'teljes-betarolas-hosszu-rekeszkod');
    const fallback = await openLocationInNewTab(page, lastLocation(page));
    await expect(fallback).toHaveURL(locationUrl);
    await expect(fallback.locator('main')).toContainText(longCode);
    await fallback.close();
    expect(state(shipment).inMovements).toEqual(receipt.inMovements);

    await page.goto(receiptPage(shipment, pecs));
    await recordReceiptLine(page, products.screw, 100);
    await expect(lineStatus(screw)).toHaveText('Teljesen átvéve');
    await expect(lineQuantity(screw, 'remaining')).toHaveText('0 db');
    await expect(screw.getByLabel('Most átvett mennyiség')).toHaveCount(0);
    await expect(lineStatus(machine)).toHaveText('Átvételre vár');
    await expectTaskCounts(page, 0, 1, 1);
    await recordReceiptLine(page, products.machine, 40);
    await expectTaskCounts(page, 0, 0, 2);
    const final = assertBalances(shipment, [[0, 200], [0, 40], [24, 0]]);
    expect(final.receiptItems.map(row => row.pending_putaway_quantity)).toEqual([0, 100, 40]);
    expect(final.placements.map(row => [row.location_code, row.quantity])).toEqual([['A-01', 40], [longCode, 60]]);

    const remote = await browser.newContext({ baseURL });
    try {
        const remotePage = await remote.newPage();
        await login(remotePage, gyor.email);
        await remotePage.goto(receivingUrl);
        await expectTaskCounts(remotePage, 0, 1, 0);
        expect((await remotePage.request.get(mapUrl)).status()).toBe(403);
        expect((await remotePage.request.get(putawayApi(item) + '/history')).status()).toBe(403);
    } finally {
        await remote.close();
    }
});


test('fuvaros betárolásnál a késői kézi keresés érintetlenül hagyja a nyitott megerősítést', async ({ page }) => {
    const shipment = fixture();
    const { pecs } = shipment;
    await login(page, pecs.email);
    const screw = state(shipment).transit[0];
    const received = await command(page, 'POST', receiptApi(shipment, pecs) + '/receipts', {
        operation_key: randomUUID(), version: 1, confirmed: true, items: [{ transit_item_id: screw.id, quantity: 20 }],
    }, 201);
    const id = (await received.json()).items[0].id;
    await page.goto(putawayUrl + '/' + id);
    await putawayCargo(page, { quantity: 5, weight: 1 });
    await selectLocation(page, 'A-01');
    const dialog = putawayReview(page);
    await verifyPutawayDialogIsolation(page, {
        routePattern: '**' + putawayApi(id) + '/locations', code: 'A-01',
        review: button(page, 'Betárolás ellenőrzése'), dialog,
        save: button(dialog, 'Betárolás rögzítése'), site: dialog.getByRole('checkbox', { name: siteConfirmation }),
        physical: dialog.getByRole('checkbox', { name: putawayConfirmation }),
    });
    expect(state(shipment).receiptItems[0].pending_putaway_quantity).toBe(20);
});
