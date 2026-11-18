import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';

async function choose(page, label, query, option) {
    await page.getByRole('combobox', { name: label, exact: true }).fill(query);
    await page.getByRole('option', { name: option, exact: true }).click();
}

test('a boxed transfer confirms the calculated weight and reserves and receives quantities in pieces', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);

    const headers = { 'X-CSRF-TOKEN': await page.locator('meta[name="csrf-token"]').getAttribute('content'), Accept: 'application/json' };
    const post = (url, data) => page.request.post(url, { headers, data, maxRedirects: 0 });
    const productName = 'Dobozos próbaáru';
    const sku = 'BOX-TRANSFER-' + randomUUID().slice(0, 8);
    const createdProduct = await post('/products', {
        product_name: productName, sku, category_id: 1, price: 1,
        packaging: { name: 'Doboz', pieces_per_package: 100, gross_weight_kg: '5.5', width_cm: 40, depth_cm: 30, height_cm: 20 },
    });
    expect(createdProduct.status(), await createdProduct.text()).toBe(302);
    const listing = await (await page.request.get('/products?search=' + sku)).text();
    const match = listing.match(/href="[^"]*\/products\/(\d+)\/edit/);
    expect(match).not.toBeNull();
    const product = Number(match[1]);

    const missingWeightName = 'Ismeretlen tömegű dobozos áru';
    const missingWeightSku = sku + '-UNKNOWN';
    const unknownWeightProduct = await post('/products', {
        product_name: missingWeightName, sku: missingWeightSku, category_id: 1, price: 1,
        packaging: { name: 'Doboz', pieces_per_package: 100 },
    });
    expect(unknownWeightProduct.status(), await unknownWeightProduct.text()).toBe(302);

    const sourceStock = await post('/movements', { request_key: randomUUID(), product_id: product, warehouse_id: 1, quantity: 325, type: 'in' });
    expect(sourceStock.status(), await sourceStock.text()).toBe(302);
    const warehouseName = 'Dobozos átmozgatás tesztraktára';
    const createdWarehouse = await post('/warehouses', { warehouse_name: warehouseName, location: 'Teszt' });
    expect(createdWarehouse.status(), await createdWarehouse.text()).toBe(302);
    await page.goto('/warehouses?search=' + encodeURIComponent(warehouseName));
    const warehouseLink = await page.getByRole('link', { name: warehouseName, exact: true }).first().getAttribute('href');
    const warehousePath = new URL(warehouseLink, page.url()).pathname;
    const targetWarehouse = Number(warehousePath.split('/').at(-1));
    const shelf = await post(warehousePath + '/locations', {
        code: 'DOBOZ-01', name: 'Dobozos tesztpolc', kind: 'shelf', max_weight_kg: 100,
    });
    expect(shelf.status(), await shelf.text()).toBe(302);

    await page.goto('/transfers/create');
    const boxes = page.getByLabel('Mennyiség (doboz)', { exact: true });
    const pieces = page.getByLabel('Mennyiség (db)', { exact: true });
    const boxMode = page.getByRole('button', { name: 'Doboz', exact: true });
    const pieceMode = page.getByRole('button', { name: 'Darab', exact: true });
    const start = page.getByRole('button', { name: 'Átmozgatás indítása', exact: true });
    const summary = page.getByRole('region', { name: /Átmozgatás összegzése/ });
    const summaryQuantity = summary.locator('[data-transfer-summary-quantity]');
    const summaryWeight = summary.locator('[data-transfer-summary-weight]');
    const findAdvice = page.getByRole('button', { name: 'Ajánlott tárolóhelyek keresése', exact: true });
    const weight = page.getByLabel('Teljes tömeg (kg)', { exact: true });
    const calculated = page.getByText('Számított', { exact: true });
    const formula = page.getByText('2 doboz × 5,5 kg = 11 kg', { exact: true });
    const confirmCalculated = page.getByRole('button', { name: 'Ez a teljes tömeg', exact: true });
    const confirmManual = page.getByRole('button', { name: 'Tömeg megerősítése', exact: true });
    const palletMode = page.getByLabel('Raklapigény', { exact: true });
    const show = page.getByRole('button', { name: 'Ajánlások megjelenítése', exact: true });
    const recommendation = page.getByRole('button', { name: 'Tárhely kiválasztása: DOBOZ-01', exact: true });
    const chosen = page.locator('input[name="to_location_id"]');
    const requestAdvice = async () => {
        const request = page.waitForRequest('**/transfers/putaway-recommendations?*');
        await findAdvice.click();
        return new URL((await request).url()).searchParams;
    };

    await choose(page, 'Termék', 'ALPHA', 'Alpha teszttermék (ALPHA-001)');
    await expect(boxMode).toBeHidden();
    await expect(pieces).toBeVisible();
    await expect(weight).toHaveValue('');
    await choose(page, 'Termék', missingWeightSku, missingWeightName + ' (' + missingWeightSku + ')');
    await pieces.fill('200');
    await expect(boxMode).toBeVisible();
    await expect(weight).toHaveValue('');
    await expect(calculated).toBeHidden();
    await choose(page, 'Termék', sku, productName + ' (' + sku + ')');
    await choose(page, 'Forrásraktár', 'Teszt raktár', 'Teszt raktár · Test');
    await choose(page, 'Célraktár', warehouseName, warehouseName + ' · Teszt');
    await palletMode.selectOption('non-pallet');

    await pieces.fill('200');
    await expect(weight).toHaveValue('11');
    await expect(calculated).toBeVisible();
    await expect(formula).toBeVisible();
    await boxMode.click();
    await expect(boxes).toHaveValue('2');
    await expect(weight).toHaveValue('11');
    await expect(summaryQuantity).toContainText('200 db');
    await expect(summaryWeight).toHaveText('–');
    await confirmCalculated.click();
    await expect(summaryWeight).toHaveText('11 kg');

    await page.setViewportSize({ width: 1440, height: 900 });
    const boxInput = await boxes.boundingBox();
    const unitSwitch = await page.getByRole('group', { name: 'Mennyiség egysége' }).boundingBox();
    expect(boxInput.x + boxInput.width <= unitSwitch.x + 1 || unitSwitch.x + unitSwitch.width <= boxInput.x + 1).toBe(true);
    expect(Math.abs(boxInput.y - unitSwitch.y)).toBeLessThan(boxInput.height);

    await boxes.fill('3');
    await expect(weight).toHaveValue('16.5');
    await expect(summaryWeight).toHaveText('–');
    await page.setViewportSize({ width: 320, height: 740 });
    for (const invalid of ['1,5', '1.5']) {
        await boxes.fill(invalid);
        await boxes.press('Tab');
        await expect(boxes).toHaveValue(invalid);
        await expect(page.getByText('Pozitív egész dobozszámot adj meg.', { exact: true }).first()).toBeVisible();
        await expect(summaryQuantity).toHaveText('–');
        await expect(summaryWeight).toHaveText('–');
        await expect(calculated).toBeHidden();
        await expect(findAdvice).toBeDisabled();
        await expect(start).toBeDisabled();
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    }
    await pieceMode.click();
    await expect(pieces).toHaveValue('300');
    await expect(summaryQuantity).toHaveText('300 db');
    await pieces.fill('150');
    await boxMode.click();
    await expect(pieces).toHaveValue('150');
    await expect(boxes).toBeHidden();
    await expect(page.getByText('A megadott darabszám nem váltható át egész dobozokra.')).toBeVisible();
    await pieces.fill('200');
    await boxMode.click();
    await expect(boxes).toHaveValue('2');
    await expect(formula).toBeVisible();
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.getByRole('button', { name: 'Teljes kiadható mennyiség', exact: true }).click();
    await expect(pieces).toHaveValue('325');
    await expect(boxes).toBeHidden();
    await expect(weight).toHaveValue('');
    await expect(calculated).toBeHidden();
    await boxMode.click();
    await expect(page.getByText('A megadott darabszám nem váltható át egész dobozokra.')).toBeVisible();
    await expect(pieces).toHaveValue('325');

    await page.getByRole('button', { name: 'Új dobozmennyiség megadása', exact: true }).click();
    await expect(boxes).toHaveValue('');
    await expect(start).toBeDisabled();
    await boxes.fill('4');
    const insufficient = summary.getByRole('button', { name: 'A megadott mennyiség több a kiadható készletnél.' });
    await expect(insufficient).toBeVisible();
    await expect(findAdvice).toBeDisabled();
    await expect(start).toBeDisabled();
    await insufficient.click();
    await expect(boxes).toBeFocused();
    await boxes.fill('2');
    await expect(page.getByText('200 db kerül átmozgatásra.', { exact: true })).toBeVisible();
    await pieceMode.click();
    await expect(pieces).toHaveValue('200');
    await boxMode.click();
    await expect(boxes).toHaveValue('2');

    const unconfirmed = await requestAdvice();
    expect(unconfirmed.get('quantity')).toBe('200');
    expect(unconfirmed.has('cargo_weight_kg')).toBe(false);
    expect(unconfirmed.has('cargo_weight_source')).toBe(false);
    expect(unconfirmed.has('cargo_weight_packaging')).toBe(false);
    await recommendation.click();
    await expect(summary.getByText('Részben ellenőrzött', { exact: true })).toBeVisible();
    await expect(start).toBeDisabled();

    await weight.fill('13');
    await expect(page.getByText('Manuálisan megadva', { exact: true })).toBeVisible();
    await expect(chosen).toHaveValue('');
    await show.click();
    expect((await requestAdvice()).get('cargo_weight_kg')).toBe('13');
    await recommendation.click();
    await expect(start).toBeEnabled();

    await boxes.fill('3');
    await expect(weight).toHaveValue('13');
    await expect(chosen).toHaveValue('');
    await expect(confirmManual).toBeVisible();
    await show.click();
    const stale = await requestAdvice();
    expect(stale.get('quantity')).toBe('300');
    expect(stale.has('cargo_weight_kg')).toBe(false);
    await expect(recommendation).toBeVisible();

    await boxes.fill('2');
    await confirmManual.click();
    expect((await requestAdvice()).get('cargo_weight_kg')).toBe('13');
    await expect(recommendation).toBeVisible();
    await palletMode.selectOption('pallet');
    const slots = page.getByLabel('Szükséges raklaphelyek', { exact: true });
    await slots.fill('2');
    const palletInput = await palletMode.boundingBox();
    const slotInput = await slots.boundingBox();
    expect(Math.abs(palletInput.x - slotInput.x)).toBeLessThan(2);
    expect(Math.abs(palletInput.width - slotInput.width)).toBeLessThan(2);
    expect(slotInput.y - (palletInput.y + palletInput.height)).toBeGreaterThanOrEqual(0);
    expect(slotInput.y - (palletInput.y + palletInput.height)).toBeLessThan(95);
    await expect(weight).toHaveValue('13');
    await expect(confirmManual).toBeVisible();
    const pallet = await requestAdvice();
    expect(pallet.get('pallet_slots')).toBe('2');
    expect(pallet.has('cargo_weight_kg')).toBe(false);
    await expect(page.getByText('A megadott adatokkal nincs ajánlható tárhely.', { exact: false })).toBeVisible();

    await palletMode.selectOption('non-pallet');
    await page.getByRole('button', { name: 'Vissza a számítotthoz', exact: true }).click();
    await expect(weight).toHaveValue('11');
    await expect(formula).toBeVisible();
    await confirmCalculated.click();
    await pieceMode.click();
    await expect(pieces).toHaveValue('200');
    await boxMode.click();
    await expect(boxes).toHaveValue('2');
    await expect(confirmCalculated).toBeHidden();
    const confirmed = await requestAdvice();
    expect(confirmed.get('quantity')).toBe('200');
    expect(confirmed.get('cargo_weight_kg')).toBe('11');
    expect(confirmed.get('cargo_weight_source')).toBe('calculated');
    const packagingRevision = confirmed.get('cargo_weight_packaging');
    expect(packagingRevision).toMatch(/^[a-f0-9]{64}$/);
    expect([...confirmed.keys()].some(key => key.startsWith('dimensions_cm'))).toBe(false);
    await recommendation.click();

    const submission = page.waitForRequest(request => request.method() === 'POST' && new URL(request.url()).pathname === '/transfers');
    await start.click();
    const submitted = new URLSearchParams((await submission).postData());
    expect(submitted.getAll('quantity')).toEqual(['200']);
    expect(submitted.get('box_count')).toBe('2');
    expect(submitted.get('cargo_weight_kg')).toBe('11');
    expect(submitted.get('cargo_weight_source')).toBe('calculated');
    expect(submitted.get('cargo_weight_packaging')).toBe(packagingRevision);
    await expect(page.getByRole('status')).toContainText('Átmozgatás elindítva');
    const stockAt = async warehouse => (await (await page.request.get('/movements/stock?product_id=' + product + '&warehouse_id=' + warehouse)).json()).quantity;
    expect(await stockAt(1)).toBe(125);
    expect(await stockAt(targetWarehouse)).toBe(0);

    const transfer = page.getByRole('row').filter({ has: page.getByRole('link', { name: productName, exact: true }) });
    page.once('dialog', dialog => dialog.accept());
    await transfer.getByRole('button', { name: 'Átvétel', exact: true }).click();
    await expect(page.getByRole('status')).toContainText(/átvétel rögzítve/i);
    expect(await stockAt(targetWarehouse)).toBe(200);

    await page.goto('/transfers/create');
    await choose(page, 'Termék', sku, productName + ' (' + sku + ')');
    await choose(page, 'Forrásraktár', 'Teszt raktár', 'Teszt raktár · Test');
    await choose(page, 'Célraktár', warehouseName, warehouseName + ' · Teszt');
    await pieces.fill('100');
    await palletMode.selectOption('non-pallet');
    await weight.fill('7.5');
    const manuallyConfirmed = await requestAdvice();
    expect(manuallyConfirmed.get('cargo_weight_kg')).toBe('7.5');
    expect(manuallyConfirmed.get('cargo_weight_packaging')).toBe(packagingRevision);
    expect(manuallyConfirmed.has('cargo_weight_source')).toBe(false);
    await recommendation.click();
    await expect(start).toBeEnabled();

    const edit = await page.context().newPage();
    await edit.goto('/products/' + product + '/edit');
    await edit.getByLabel('Bruttó tömeg (kg)', { exact: true }).fill('6.5');
    await edit.getByRole('button', { name: 'Frissítés', exact: true }).click();
    await expect(edit).toHaveURL(/\/products$/);
    await edit.close();

    await show.click();
    const rejection = page.waitForResponse('**/transfers/putaway-recommendations?*');
    await requestAdvice();
    const staleResponse = await rejection;
    expect(staleResponse.status()).toBe(422);
    expect(await staleResponse.json()).toHaveProperty('errors.cargo_weight_packaging');
    await expect(page.getByText('A csomagolás közben módosult. Töltsd újra az űrlapot, és ellenőrizd a teljes tömeget.', { exact: true })).toBeVisible();
    await expect(weight).toHaveValue('7.5');
    await expect(confirmManual).toBeHidden();
    await expect(chosen).toHaveValue('');
    await expect(start).toBeDisabled();

    await page.reload();
    await choose(page, 'Termék', sku, productName + ' (' + sku + ')');
    await pieces.fill('100');
    await expect(weight).toHaveValue('6.5');
    await expect(confirmCalculated).toBeVisible();
});
