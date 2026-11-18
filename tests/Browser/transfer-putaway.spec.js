import { expect, test } from '@playwright/test';
import { join } from 'node:path';

async function capture(page, filename) {
    const directory = process.env.TRANSFER_SCREENSHOT_DIR;
    if (!directory) return;
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await page.screenshot({ path: join(directory, filename), fullPage: true, animations: 'disabled' });
}

async function choose(page, label, query, option) {
    await page.getByRole('combobox', { name: label, exact: true }).fill(query);
    await page.getByRole('option', { name: option, exact: true }).click();
}

test('standalone transfer recommends locations and preserves manual selection', async ({ page }) => {
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);

    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const headers = { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' };
    const createdWarehouse = await page.request.post('/warehouses', { headers, form: {
        warehouse_name: 'Betárolási próbaraktár', location: 'Teszt',
    }, maxRedirects: 0 });
    expect(createdWarehouse.status()).toBe(302);
    await page.goto('/warehouses?search=' + encodeURIComponent('Betárolási próbaraktár'));
    const warehouseLink = await page.getByRole('link', { name: 'Betárolási próbaraktár', exact: true }).first().getAttribute('href');
    const warehousePath = new URL(warehouseLink, page.url()).pathname;
    const rack = await page.request.post(warehousePath + '/floor-plan/systems', { headers, data: {
        version: 1, kind: 'rack', name: 'Ajánlási próbaállvány', zone: 'PUT', code: 'PUT-RACK',
        width: 4, height: 2, rack_height: 2, x: 0, y: 0, levels: 1, bins: 4,
        bin_weight: 50, level_weight: 50, system_weight: 50,
    } });
    expect(rack.status(), await rack.text()).toBe(201);
    const shelf = await page.request.post(warehousePath + '/locations', { headers, form: {
        code: 'PUT-KEZI', name: 'Kézi próbatárhely', kind: 'shelf', max_weight_kg: '40',
    }, maxRedirects: 0 });
    expect(shelf.status()).toBe(302);
    const manualId = shelf.headers().location.split('/').at(-1);

    await page.request.get(shelf.headers().location);
    await page.setViewportSize({ width: 1680, height: 1000 });
    await page.goto('/transfers/create');
    const summary = page.getByRole('region', { name: /Átmozgatás összegzése/ });
    const start = page.getByRole('button', { name: 'Átmozgatás indítása' });
    await expect(summary).toBeVisible();
    await expect(start).toBeDisabled();
    await capture(page, '01-kezdoallapot.png');

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByText('Kezdd a termékkel vagy a forrásraktárral.', { exact: false })).toBeVisible();
    await choose(page, 'Termék', 'ALPHA', 'Alpha teszttermék (ALPHA-001)');
    await page.getByRole('combobox', { name: 'Forrásraktár', exact: true }).fill('Betárolási');
    await expect(page.getByRole('listbox', { name: 'Raktártalálatok' }).getByRole('option')).toHaveCount(0);
    await choose(page, 'Forrásraktár', 'Teszt raktár', 'Teszt raktár · Test');
    await page.getByRole('combobox', { name: 'Termék', exact: true }).fill('DEMO-010');
    await expect(page.getByRole('listbox', { name: 'Terméktalálatok' }).getByRole('option')).toHaveCount(0);
    await choose(page, 'Termék', 'ALPHA', 'Alpha teszttermék (ALPHA-001)');
    await choose(page, 'Célraktár', 'Betárolási', 'Betárolási próbaraktár · Teszt');
    const destination = page.getByRole('combobox', { name: 'Cél tárhely', exact: true });
    await expect(destination).toBeHidden();
    await expect(page.getByRole('button', { name: 'Kézi célhelyválasztás' })).toHaveAttribute('aria-expanded', 'false');
    const targetInput = page.locator('input[name="to_location_id"]');
    const quantity = page.getByLabel('Mennyiség (db)');
    const useAvailable = page.getByRole('button', { name: 'Teljes kiadható mennyiség', exact: true });
    const capacityEstimate = summary.getByText(/Betárolás után várhatóan/);
    const weight = page.getByLabel('Teljes tömeg (kg)');
    const palletMode = page.getByLabel('Raklapigény', { exact: true });
    const slots = page.getByLabel('Szükséges raklaphelyek');
    const find = page.getByRole('button', { name: 'Ajánlott tárolóhelyek keresése' });
    const show = page.getByRole('button', { name: 'Ajánlások megjelenítése' });
    const manualToggle = page.getByRole('button', { name: 'Kézi célhelyválasztás' });
    await expect(summary).toContainText('Alpha teszttermék');
    await expect(summary).toContainText('1 db');
    await expect(summary).toContainText('Teszt raktár');
    await expect(summary).toContainText('Betárolási próbaraktár');
    const sourceStock = await (await page.request.get('/movements/stock?product_id=1&warehouse_id=1')).json();
    await expect(useAvailable).toBeEnabled();
    await useAvailable.click();
    await expect(quantity).toHaveValue(String(sourceStock.quantity));
    await expect(summary).toContainText(sourceStock.quantity + ' db');
    await expect(page).toHaveURL(/\/transfers\/create$/);
    await quantity.fill('1');
    await expect(start).toBeDisabled();
    await expect(palletMode).toHaveValue('unknown');
    await expect(slots).toBeHidden();
    await summary.getByRole('button', { name: 'Add meg a teljes tömeget.' }).click();
    await expect(weight).toBeFocused();
    await expect(weight).toBeInViewport();
    const recommendations = page.getByRole('list', { name: 'Ajánlott tárolóhelyek' });
    const unknownRequest = page.waitForRequest('**/transfers/putaway-recommendations?*');
    await find.click();
    expect(new URL((await unknownRequest).url()).searchParams.has('pallet_slots')).toBe(false);
    await expect(recommendations).toBeVisible();
    await expect(recommendations.getByText('Ellenőrzött', { exact: true })).toHaveCount(0);
    const firstPartial = recommendations.locator(':scope > li').first();
    await expect(firstPartial.getByText('Részben ellenőrzött', { exact: true })).toBeVisible();
    await expect(recommendations.locator(':scope > li')).toHaveCount(3);
    await expect(page.getByText('Az ajánlás pontosításához', { exact: true })).toHaveCount(1);
    await expect(page.getByText(/Teljes tömeg, Raklaphelyigény, A rakomány külső méretei/i)).toBeVisible();
    const basicsRegion = page.getByRole('region', { name: /Átmozgatás alapadatai/ });
    const cargoRegion = page.getByRole('region', { name: /Rakományadatok/ });
    const targetRegion = page.getByRole('region', { name: /Célhely kiválasztása/ });
    for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        const target = await targetRegion.boundingBox();
        const sidebar = await summary.boundingBox();
        expect(Math.abs(target.x - sidebar.x)).toBeLessThan(2);
        expect(sidebar.y).toBeGreaterThanOrEqual(target.y + target.height);
    }
    await page.setViewportSize({ width: 1680, height: 1000 });
    const basics = await basicsRegion.boundingBox();
    const cargo = await cargoRegion.boundingBox();
    const target = await targetRegion.boundingBox();
    const sidebar = await summary.boundingBox();
    expect(Math.abs(target.x - basics.x)).toBeLessThan(2);
    expect(target.y).toBeGreaterThanOrEqual(cargo.y + cargo.height);
    expect(sidebar.x).toBeGreaterThanOrEqual(basics.x + basics.width);
    const contentWidth = sidebar.x + sidebar.width - basics.x;
    expect(contentWidth).toBeGreaterThanOrEqual(1250);
    expect(contentWidth).toBeLessThanOrEqual(1400);
    expect(basics.width / contentWidth).toBeGreaterThanOrEqual(0.60);
    expect(basics.width / contentWidth).toBeLessThanOrEqual(0.70);
    await expect(page.locator('[data-transfer-summary]')).toHaveCSS('position', 'sticky');
    const productInput = await page.getByRole('combobox', { name: 'Termék', exact: true }).boundingBox();
    const sourceInput = await page.getByRole('combobox', { name: 'Forrásraktár', exact: true }).boundingBox();
    const destinationInput = await page.getByRole('combobox', { name: 'Célraktár', exact: true }).boundingBox();
    const quantityInput = await quantity.boundingBox();
    expect(Math.abs(sourceInput.y - destinationInput.y)).toBeLessThan(2);
    expect(Math.abs(sourceInput.width - destinationInput.width)).toBeLessThan(2);
    expect(Math.abs(productInput.x - sourceInput.x)).toBeLessThan(2);
    expect(Math.abs(productInput.x + productInput.width - destinationInput.x - destinationInput.width)).toBeLessThan(2);
    expect(quantityInput.y).toBeGreaterThanOrEqual(sourceInput.y + sourceInput.height);

    await firstPartial.getByRole('button', { name: /Tárhely kiválasztása/ }).click();
    await expect(recommendations).toBeHidden();
    await expect(summary.getByText('Részben ellenőrzött', { exact: true })).toBeVisible();
    await expect(capacityEstimate).toBeHidden();
    await expect(start).toBeDisabled();
    await palletMode.selectOption('non-pallet');
    await weight.fill('30');
    await expect(targetInput).toHaveValue('');
    await expect(recommendations).toBeHidden();
    await expect(slots).toBeHidden();
    const dimensions = page.getByText('Rakományméretek (opcionális)', { exact: true });
    await dimensions.click();
    for (const label of ['Szélesség (cm)', 'Mélység (cm)', 'Magasság (cm)']) {
        await page.getByLabel(label, { exact: true }).fill('20');
    }
    await dimensions.click();
    await expect(recommendations).toBeHidden();
    await show.click();
    const request = page.waitForRequest('**/transfers/putaway-recommendations?*');
    const response = page.waitForResponse('**/transfers/putaway-recommendations?*');
    await find.click();
    expect(new URL((await request).url()).searchParams.get('pallet_slots')).toBe('0');
    const ranked = (await (await response).json()).recommendations;
    const best = recommendations.locator(':scope > li').first();
    await expect(best).toContainText('PUT-RACK-01-01');
    await expect(best).toContainText('Ellenőrzött');
    await expect(best).toContainText('50 kg');
    await expect(best.getByText('20 kg szabad teherbírás maradna', { exact: false })).toBeHidden();
    await expect(best).toContainText(/20 kg marad szabadon/i);
    await expect(recommendations.locator(':scope > li')).toHaveCount(3);
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await expect(best).toBeInViewport({ ratio: 0.5 });
    await capture(page, '02-ajanlaslista.png');
    await page.getByRole('button', { name: 'További ajánlások (2)' }).click();
    await expect(recommendations.locator(':scope > li')).toHaveCount(5);
    const unplaced = recommendations.locator(':scope > li').filter({ hasText: 'PUT-KEZI' });
    await expect(unplaced).toContainText('Részben ellenőrzött');
    await expect(unplaced).toContainText('Nincs elhelyezve a rajzon.');
    await expect(unplaced.getByRole('link', { name: /Tervrajzon megtekintés/ })).toHaveAttribute('href', new RegExp('location=' + manualId));
    const secondBin = recommendations.locator(':scope > li').filter({ hasText: 'PUT-RACK-01-02' });
    await expect(secondBin).toContainText(/PUT.*PUT-RACK.*1\. szint.*2\. rekesz/);
    await secondBin.locator('summary').click();
    await expect(secondBin.getByText(/Fizikai elférés/)).toBeVisible();
    await secondBin.getByRole('button', { name: /Tárhely kiválasztása/ }).click();
    await expect(recommendations).toBeHidden();
    await expect(destination).toBeHidden();
    await expect(summary).toContainText('Kiválasztott célhely');
    await expect(summary).toContainText('Ajánlásból kiválasztva');
    await expect(start).toBeEnabled();
    await expect(page.locator('input[name="putaway_selection_mode"]')).toHaveValue('recommended');
    await expect(summary).toContainText('PUT-RACK-01-02');
    await expect(capacityEstimate).toBeVisible();
    await expect(capacityEstimate).toHaveText(/várhatóan.*20\s*kg/i);
    await expect(targetInput).toHaveValue(String(ranked.find(item => item.code === 'PUT-RACK-01-02').location_id));
    await capture(page, '03-kivalasztott-celhely.png');
    const popup = page.waitForEvent('popup');
    await summary.getByRole('link', { name: /Tervrajzon megtekintés/ }).click();
    const plan = await popup;
    await expect(plan).toHaveURL(/floor-plan\?system=\d+&bin=\d+#floor-inspector/);
    await plan.getByRole('link', { name: 'Állvány megnyitása' }).click();
    await expect(plan.getByRole('heading', { name: /^PUT-RACK-01-02/ })).toBeVisible();
    await plan.close();

    await summary.getByRole('button', { name: 'Célhely módosítása' }).click();
    await expect(recommendations).toBeVisible();
    const selectBin = secondBin.getByRole('button', { name: /Tárhely kiválasztása/ });
    await expect(selectBin).toHaveAttribute('aria-pressed', 'true');
    await selectBin.click();
    await manualToggle.click();
    await destination.fill('PUT-RACK szint 3 rekesz');
    await expect(page.getByRole('option', { name: /PUT-RACK-01-03 · PUT · Ajánlási próbaállvány · 1. szint · 3. rekesz.*szabad/ })).toBeVisible();
    await expect(page.getByRole('listbox', { name: 'Tárhelytalálatok' }).getByRole('option')).toHaveCount(1);
    await destination.press('ArrowDown');
    await destination.press('Enter');
    await expect(summary).toContainText('PUT-RACK-01-03');
    await expect(summary).toContainText('Kézzel kiválasztva');
    await expect(page.locator('input[name="putaway_selection_mode"]')).toHaveValue('manual');
    await expect(summary.getByText('Ellenőrzött', { exact: true })).toBeHidden();
    await expect(capacityEstimate).toBeHidden();
    await expect(recommendations).toBeHidden();
    await expect(summary.getByRole('link', { name: /Tervrajzon megtekintés/ })).toHaveAttribute('href', /system=.*&bin=/);

    await show.click();
    await expect(selectBin).toHaveAttribute('aria-pressed', 'false');
    await selectBin.click();

    await quantity.fill('2');
    await expect(recommendations).toBeHidden();
    await expect(targetInput).toHaveValue('');
    await expect(capacityEstimate).toBeHidden();
    await manualToggle.click();
    await destination.fill('PUT-KEZI');
    await page.getByRole('option', { name: /PUT-KEZI · Kézi próbatárhely/ }).click();
    await expect(targetInput).toHaveValue(manualId);
    await weight.fill('31');
    await expect(targetInput).toHaveValue(manualId);
    await expect(recommendations).toBeHidden();
    await show.click();

    let held;
    await page.route('**/transfers/putaway-recommendations?*', route => { held = route; }, { times: 1 });
    await find.click();
    await expect.poll(() => Boolean(held)).toBe(true);
    await palletMode.selectOption('pallet');
    await expect(slots).toBeVisible();
    await slots.fill('1');
    const palletRequest = page.waitForRequest('**/transfers/putaway-recommendations?*');
    await find.click();
    const palletParams = new URL((await palletRequest).url()).searchParams;
    expect(palletParams.get('pallet_slots')).toBe('1');
    expect(palletParams.has('cargo_weight_kg')).toBe(false);
    await expect(page.getByText('A megadott adatokkal nincs ajánlható tárhely.', { exact: false })).toBeVisible();
    const oldResponse = page.waitForResponse(response =>
        response.url().includes('/transfers/putaway-recommendations?') && new URL(response.url()).searchParams.get('pallet_slots') === '0');
    await held.fulfill({ status: 422, json: { message: 'Elavult válasz.' } });
    await (await oldResponse).finished();
    await expect(page.getByText('Elavult válasz.')).toBeHidden();
    await expect(recommendations).toBeHidden();
    await expect(targetInput).toHaveValue(manualId);

    await palletMode.selectOption('non-pallet');
    await expect(slots).toBeHidden();
    let pending;
    await page.route('**/transfers/putaway-recommendations?*', route => { pending = route; }, { times: 1 });
    await find.click();
    await expect.poll(() => Boolean(pending)).toBe(true);
    await manualToggle.click();
    await destination.fill('PUT-KEZI');
    await page.getByRole('option', { name: /PUT-KEZI · Kézi próbatárhely/ }).click();
    const arrived = page.waitForResponse('**/transfers/putaway-recommendations?*');
    await pending.fulfill({ response: await pending.fetch() });
    await (await arrived).finished();
    await expect(recommendations).toBeHidden();
    await expect(summary).toContainText('Kézzel kiválasztva');
    await expect(targetInput).toHaveValue(manualId);
    await show.click();
    await expect(recommendations).toBeVisible();
    await choose(page, 'Termék', 'ZETA', 'Zeta teszttermék (ZETA-002)');
    await expect(recommendations).toBeHidden();
    await expect(targetInput).toHaveValue(manualId);
    await palletMode.selectOption('pallet');
    await slots.fill('1');
    await expect(summary.getByText('Raklapos rakományhoz válassz raklaphelyet.')).toBeVisible();
    await expect(start).toBeDisabled();
    await palletMode.selectOption('non-pallet');
    const confirmWeight = page.getByRole('button', { name: 'Tömeg megerősítése', exact: true });
    await confirmWeight.click();
    await expect(start).toBeEnabled();

    await quantity.fill('999');
    await expect(targetInput).toHaveValue(manualId);
    await expect(start).toBeDisabled();
    await summary.getByRole('button', { name: 'A megadott mennyiség több a kiadható készletnél.' }).click();
    await expect(quantity).toBeFocused();
    await quantity.fill('2');
    await confirmWeight.click();
    await expect(start).toBeEnabled();

    await choose(page, 'Célraktár', 'Nyugati', 'Nyugati tesztraktár · Bemutató célállomás');
    await expect(targetInput).toHaveValue('');
    await expect(page.getByText('A megadott adatokkal nincs ajánlható tárhely.', { exact: false })).toBeHidden();
    expect(pageErrors).toEqual([]);
});
