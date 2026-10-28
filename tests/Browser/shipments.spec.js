import { test, expect } from '@playwright/test';

async function quantity(page, product = 1, warehouse = 1) {
    const response = await page.request.get(`/movements/stock?product_id=${product}&warehouse_id=${warehouse}`);
    expect(response.ok()).toBe(true);
    return (await response.json()).quantity;
}
async function draft(page, reference, excessive = false) {
    await page.goto('/shipments/create');
    await page.getByLabel('Szállítmány hivatkozása', { exact: true }).fill(reference);
    await page.getByLabel('Jármű / rendszám (opcionális)').fill('E2E-123');
    await page.getByRole('combobox', { name: 'Induló raktár', exact: true }).fill('Teszt raktár');
    await page.getByRole('listbox', { name: 'Raktártalálatok', exact: true }).getByRole('option', { name: 'Teszt raktár', exact: true }).click();
    for (const [index, warehouse, product] of [[0, '2', '1'], [1, '3', '2'], [2, '4', '1']]) {
        if (index) await page.getByRole('button', { name: '+ Megálló hozzáadása', exact: true }).click();
        const stop = page.locator('fieldset').nth(index);
        await stop.getByLabel('Célraktár', { exact: true }).selectOption(warehouse);
        await stop.getByLabel('Termék', { exact: true }).selectOption(product);
        await stop.getByLabel('Mennyiség (db)', { exact: true }).fill(excessive ? '999' : '1');
    }
    return page.getByRole('region', { name: 'Rakodási sorrend előnézete' });
}
test.beforeEach(async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});

test('shipment builder reorders stops and produces reverse loading order without touching stock', async ({ page }) => {
    const before = await quantity(page);
    const preview = await draft(page, 'E2E-Rakodási sorrend');
    await expect(preview.getByRole('listitem')).toHaveText([
        /1\. felrakodás: Nyugati tesztraktár.*3\. lerakodási megálló/,
        /2\. felrakodás: Déli tesztraktár.*2\. lerakodási megálló/,
        /3\. felrakodás: Északi tesztraktár.*1\. lerakodási megálló/,
    ]);
    await page.getByRole('button', { name: '1. megálló hátrébb', exact: true }).click();
    await expect(page.locator('fieldset').nth(0).getByLabel('Célraktár', { exact: true })).toHaveValue('3');
    await expect(page.locator('fieldset').nth(0).getByLabel('Termék', { exact: true })).toHaveValue('2');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await expect(page).toHaveURL(/\/shipments\/\d+$/);
    const loading = page.getByRole('list', { name: 'Felrakodási sorrend' });
    await expect(loading.getByRole('listitem').filter({ has: page.locator('strong') })).toHaveCount(3);
    await expect(loading.locator(':scope > li').nth(2)).toContainText('Déli tesztraktár');
    expect(await quantity(page)).toBe(before);
    await page.screenshot({ path: 'tests/Browser/.runtime/shipment-loading-plan.png', fullPage: true });
    // Check the actual PDF through this authenticated browser session. The desktop
    // sandbox may cancel Chrome's native download manager independently of the response.
    const trigger = page.getByRole('button', { name: 'Rakodólista (PDF)', exact: true });
    const detailUrl = page.url();
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Rakodólista előnézete', exact: true });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Előnézet bezárása', exact: true })).toBeFocused();
    await expect(dialog.locator('iframe')).toHaveAttribute('src', /preview=1.*#zoom=page-width$/);
    const previewResponse = await page.request.get(await dialog.getByRole('link', { name: 'Előnézet külön lapon', exact: true }).getAttribute('href'));
    expect(previewResponse.ok()).toBe(true);
    expect(previewResponse.headers()['content-disposition']).toMatch(/^inline;/);
    expect((await previewResponse.body()).subarray(0, 5).toString()).toBe('%PDF-');
    const pdf = await page.request.get(await dialog.getByRole('link', { name: 'PDF letöltése', exact: true }).getAttribute('href'));
    expect(pdf.ok()).toBe(true);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect(pdf.headers()['content-disposition']).toMatch(/attachment;.*SZALL-\d+-rakodasi-terv\.pdf/);
    const bytes = await pdf.body();
    expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(trigger).toBeFocused();
    await expect(dialog.locator('iframe')).toHaveCount(0);
    expect(page.url()).toBe(detailUrl);
    await trigger.click();
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Előnézet bezárása', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await page.getByRole('link', { name: 'Terv szerkesztése', exact: true }).click();
    await expect(page.locator('fieldset').nth(0).getByLabel('Célraktár', { exact: true })).toHaveValue('3');
    await expect(page.locator('fieldset').nth(0).getByLabel('Termék', { exact: true })).toHaveValue('2');
});

test('shipment can start once and be received stop by stop', async ({ page }) => {
    const before = await quantity(page);
    const targetBefore = await quantity(page, 1, 2);
    await draft(page, 'E2E-Teljesített útvonal');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    const path = new URL(page.url()).pathname;
    const csrf = await page.getByRole('main').locator('input[name="_token"]').first().inputValue();
    await page.getByRole('button', { name: 'Szállítmány indítása', exact: true }).click();
    await expect(page.getByText('Úton', { exact: true })).toBeVisible();
    expect(await quantity(page)).toBe(before - 2);
    const replay = await page.request.post(path+'/start', { form: { _token: csrf, version: '1' } });
    expect(replay.ok()).toBe(true);
    expect(await quantity(page)).toBe(before - 2);
    await expect(page.getByRole('link', { name: 'Terv szerkesztése', exact: true })).toHaveCount(0);
    for (let i = 0; i < 3; i++) await page.getByRole('button', { name: 'Megálló átvétele', exact: true }).click();
    await expect(page.getByText('Teljesítve', { exact: true })).toBeVisible();
    expect(await quantity(page, 1, 2)).toBe(targetBefore + 1);
    await expect(page.getByRole('button', { name: 'Megálló átvétele', exact: true })).toHaveCount(0);
});

test('insufficient shipment stock keeps the entire shipment as a draft', async ({ page }) => {
    const before = await quantity(page);
    await draft(page, 'E2E-Nincs elegendő készlet', true);
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await page.getByRole('button', { name: 'Szállítmány indítása', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Nincs elegendő készlet');
    await expect(page.getByText('Tervezet', { exact: true })).toBeVisible();
    expect(await quantity(page)).toBe(before);
    await expect(page.getByRole('link', { name: 'Terv szerkesztése', exact: true })).toBeVisible();
});

test('partial cancellation returns the remaining cargo and leaves received stock at the destination', async ({ page }) => {
    const before = await quantity(page);
    const targetBefore = await quantity(page, 1, 2);
    const zetaBefore = await quantity(page, 2, 1);
    await draft(page, 'E2E-Részleges visszavonás');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await page.getByRole('button', { name: 'Szállítmány indítása', exact: true }).click();
    await page.getByRole('button', { name: 'Megálló átvétele', exact: true }).click();
    await page.getByText('Szállítmány visszavonása', { exact: true }).click();
    await page.getByRole('button', { name: 'Visszavonás rögzítése', exact: true }).click();
    await expect(page.getByText('Visszavonva', { exact: true })).toBeVisible();
    expect(await quantity(page)).toBe(before - 1);
    expect(await quantity(page, 1, 2)).toBe(targetBefore + 1);
    expect(await quantity(page, 2, 1)).toBe(zetaBefore);
});

test('shipment form preserves rows on duplicate destination validation and fits mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await draft(page, 'E2E-Hibás útvonal');
    // A forged request must still be rejected even though the UI now disables duplicates.
    const submission = await page.getByRole('main').locator('form').evaluate(form => ({ action: form.action, data: Object.fromEntries(new FormData(form)) }));
    submission.data['stops[1][warehouse_id]'] = '2';
    const response = await page.request.post(submission.action, { form: submission.data, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    await page.goto('/shipments/create');
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.locator('fieldset')).toHaveCount(3);
    await expect(page.getByLabel('Szállítmány hivatkozása', { exact: true })).toHaveValue('E2E-Hibás útvonal');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/shipment-builder-mobile.png', fullPage: true });
});

test('destinations already used at another stop stay disabled until changed or removed', async ({ page }) => {
    await draft(page, 'E2E-Egyedi célraktárak');
    const first = page.locator('fieldset').nth(0).getByLabel('Célraktár', { exact: true });
    const second = page.locator('fieldset').nth(1).getByLabel('Célraktár', { exact: true });
    await expect(first.locator('option[value="1"]')).toBeDisabled();
    await expect(first.locator('option[value="2"]')).toBeEnabled();
    await expect(second.locator('option[value="2"]')).toBeDisabled();
    await expect(first.locator('option[value="3"]')).toBeDisabled();
    const add = page.getByRole('button', { name: '+ Megálló hozzáadása', exact: true });
    await expect(add).toBeDisabled();
    await page.getByRole('button', { name: '2. megálló törlése', exact: true }).click();
    await expect(add).toBeEnabled();
    await expect(first.locator('option[value="3"]')).toBeEnabled();
    await first.selectOption('3');
    await expect(page.locator('fieldset').nth(1).getByLabel('Célraktár', { exact: true }).locator('option[value="2"]')).toBeEnabled();
    await page.getByRole('button', { name: '1. megálló hátrébb', exact: true }).click();
    await expect(page.locator('fieldset').nth(0).getByLabel('Célraktár', { exact: true }).locator('option[value="3"]')).toBeDisabled();
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await expect(page).toHaveURL(/\/shipments\/\d+$/);
    await page.getByRole('link', { name: 'Terv szerkesztése', exact: true }).click();
    await expect(page.locator('fieldset').nth(0).getByLabel('Célraktár', { exact: true })).toHaveValue('4');
    await expect(page.locator('fieldset').nth(1).getByLabel('Célraktár', { exact: true }).locator('option[value="4"]')).toBeDisabled();
});

test('editing product rows and changing origin keep the remaining route and quantities consistent', async ({ page }) => {
    await draft(page, 'E2E-Terméksorok és forrásváltás');
    const first = page.locator('fieldset').nth(0);
    await first.getByRole('button', { name: '+ Termék hozzáadása', exact: true }).click();
    await first.getByLabel('Termék', { exact: true }).nth(1).selectOption('2');
    await first.getByLabel('Mennyiség (db)', { exact: true }).nth(1).fill('7');
    await first.getByRole('button', { name: '1. termék törlése a(z) 1. megállóból', exact: true }).click();
    await expect(first.getByLabel('Termék', { exact: true })).toHaveValue('2');
    await expect(first.getByLabel('Mennyiség (db)', { exact: true })).toHaveValue('7');

    await page.getByRole('combobox', { name: 'Induló raktár', exact: true }).fill('Északi');
    await page.getByRole('listbox', { name: 'Raktártalálatok', exact: true }).getByRole('option', { name: 'Északi tesztraktár', exact: true }).click();
    await expect(first.getByLabel('Célraktár', { exact: true })).toHaveValue('');
    await expect(first.getByLabel('Célraktár', { exact: true }).locator('option[value="2"]')).toBeDisabled();
    await first.getByLabel('Célraktár', { exact: true }).selectOption('1');
    await page.getByRole('button', { name: '2. megálló törlése', exact: true }).click();
    await expect(page.locator('fieldset')).toHaveCount(2);
    await expect(page.locator('fieldset').nth(1).getByLabel('Célraktár', { exact: true })).toHaveValue('4');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await expect(page).toHaveURL(/\/shipments\/\d+$/);
    await page.getByRole('link', { name: 'Terv szerkesztése', exact: true }).click();
    await expect(page.locator('fieldset')).toHaveCount(2);
    await expect(page.getByRole('combobox', { name: 'Induló raktár', exact: true })).toHaveValue('Északi tesztraktár');
    await expect(page.locator('fieldset').nth(0).getByLabel('Termék', { exact: true })).toHaveValue('2');
    await expect(page.locator('fieldset').nth(0).getByLabel('Mennyiség (db)', { exact: true })).toHaveValue('7');
});

test('loading PDF preview fits mobile and its close button restores the details page', async ({ page }) => {
    await draft(page, 'E2E-Mobil PDF-előnézet');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: 'Rakodólista (PDF)', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Rakodólista előnézete', exact: true });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Fekvő (A4)', exact: true }).click();
    await dialog.getByLabel('Cikkszámok (SKU)', { exact: true }).uncheck();
    const expandedFrame = await dialog.locator('iframe').boundingBox();
    await dialog.getByRole('button', { name: 'Beállítások elrejtése', exact: true }).click();
    await expect(dialog.locator('aside')).not.toBeVisible();
    expect((await dialog.locator('iframe').boundingBox()).height).toBeGreaterThan(expandedFrame.height + 100);
    await expect(dialog.locator('iframe')).toHaveAttribute('src', /preview=1.*#zoom=page-width$/);
    const bounds = await dialog.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
    expect(bounds.y).toBeGreaterThanOrEqual(0);
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(844);
    expect((await dialog.locator('iframe').boundingBox()).height).toBeGreaterThan(844 * 0.8);
    await expect(dialog.getByRole('link', { name: 'Előnézet külön lapon', exact: true })).toHaveAttribute('target', '_blank');
    await expect(dialog.getByRole('link', { name: 'PDF letöltése', exact: true })).toBeVisible();
    await page.screenshot({ path: 'tests/Browser/.runtime/loading-preview-mobile.png', fullPage: true });
    await dialog.getByRole('button', { name: 'Előnézet bezárása', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(page.getByRole('button', { name: 'Rakodólista (PDF)', exact: true })).toBeFocused();
});

test('hiding PDF settings gives the frame the sidebar width and preserves options on reopening', async ({ page }) => {
    await draft(page, 'E2E-PDF-Szélesség');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await page.getByRole('button', { name: 'Rakodólista (PDF)', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Rakodólista előnézete', exact: true });
    await dialog.getByRole('button', { name: 'Fekvő (A4)', exact: true }).click();
    await dialog.getByLabel('Cikkszámok (SKU)', { exact: true }).uncheck();
    const frame = dialog.locator('iframe');
    await expect(frame).toHaveAttribute('src', /show_sku=0/);
    const originalUrl = await frame.getAttribute('src');
    const originalWidth = (await frame.boundingBox()).width;
    const viewport = page.viewportSize();
    expect((await frame.boundingBox()).height).toBeGreaterThan(viewport.height * 0.85);
    const toolbar = await dialog.locator('header').boundingBox();
    expect(toolbar.height).toBeLessThanOrEqual(60);
    await expect(dialog.locator('footer')).toHaveCount(0);
    await page.screenshot({ path: 'tests/Browser/.runtime/loading-preview-desktop.png', fullPage: true });
    await dialog.getByRole('button', { name: 'Beállítások elrejtése', exact: true }).click();
    await expect(dialog.locator('aside')).not.toBeVisible();
    expect((await frame.boundingBox()).width).toBeGreaterThan(originalWidth + 250);
    const reopen = dialog.getByRole('button', { name: 'PDF testreszabása', exact: true });
    await expect(reopen).toHaveAttribute('aria-expanded', 'false');
    await reopen.click();
    await expect(dialog.locator('aside')).toBeVisible();
    await expect(dialog.getByLabel('Cikkszámok (SKU)', { exact: true })).not.toBeChecked();
    await expect(frame).toHaveAttribute('src', originalUrl);
    expect((await frame.boundingBox()).width).toBeCloseTo(originalWidth, 0);
});

test('closing a native PDF removes the frame and cancels pending option updates', async ({ page }) => {
    await draft(page, 'E2E-PDF-Bezárás');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    const trigger = page.getByRole('button', { name: 'Rakodólista (PDF)', exact: true });
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Rakodólista előnézete', exact: true });
    await expect(dialog.locator('iframe')).toHaveAttribute('src', /orientation=portrait/);
    await dialog.getByRole('button', { name: 'Fekvő (A4)', exact: true }).click();
    await page.keyboard.press('Escape');
    await expect(dialog.locator('iframe')).toHaveCount(0);
    await expect(trigger).toBeFocused();
    await trigger.click();
    await expect(dialog.locator('iframe')).toHaveAttribute('src', /orientation=landscape/);
    await expect(dialog.locator('iframe')).toHaveAttribute('src', /#zoom=page-width$/);
});

test('loading PDF settings update preview and download together and can be reset', async ({ page }) => {
    await draft(page, 'E2E-PDF-Beállítások');
    await page.getByRole('button', { name: 'Tervezet mentése', exact: true }).click();
    await page.getByRole('button', { name: 'Rakodólista (PDF)', exact: true }).click();
    const dialog=page.getByRole('dialog',{name:'Rakodólista előnézete',exact:true});
    const frame=dialog.locator('iframe');
    await expect(frame).toHaveAttribute('src', /orientation=portrait/);
    await dialog.getByRole('button',{name:'Fekvő (A4)',exact:true}).click();
    await dialog.getByLabel('Cikkszámok (SKU)',{exact:true}).uncheck();
    await dialog.getByLabel('Útvonal-összesítő',{exact:true}).uncheck();
    await dialog.getByLabel('Raktárcímek',{exact:true}).uncheck();
    await dialog.getByLabel('Szállítmány megjegyzése',{exact:true}).uncheck();
    await dialog.getByLabel('Rakodva – üres ellenőrző oszlop',{exact:true}).check();
    await dialog.getByLabel('Aláírási mezők',{exact:true}).check();
    await expect(frame).toHaveAttribute('src', /orientation=landscape/);
    const download=new URL(await dialog.getByRole('link',{name:'PDF letöltése',exact:true}).getAttribute('href'));
    const preview=new URL(await dialog.getByRole('link',{name:'Előnézet külön lapon',exact:true}).getAttribute('href'));
    for (const [key,value] of Object.entries({orientation:'landscape',show_route:'0',show_sku:'0',show_addresses:'0',show_note:'0',show_loading_column:'1',show_signatures:'1'})) {
        expect(download.searchParams.get(key)).toBe(value); expect(preview.searchParams.get(key)).toBe(value);
    }
    await expect(frame).toHaveAttribute('src', preview.toString() + '#zoom=page-width');
    expect(download.searchParams.has('preview')).toBe(false); expect(preview.searchParams.get('preview')).toBe('1');
    const pdf=await page.request.get(download.toString()); expect(pdf.ok()).toBe(true);
    expect((await pdf.body()).subarray(0, 5).toString()).toBe('%PDF-');
    await page.screenshot({path:'tests/Browser/.runtime/loading-pdf-settings.png',fullPage:true});
    await dialog.getByRole('button',{name:'Alapbeállítások visszaállítása',exact:true}).click();
    await expect(dialog.getByRole('button',{name:'Álló (A4)',exact:true})).toHaveAttribute('aria-pressed','true');
    await expect(dialog.getByLabel('Cikkszámok (SKU)',{exact:true})).toBeChecked();
    await expect(dialog.getByLabel('Rakodva – üres ellenőrző oszlop',{exact:true})).not.toBeChecked();
    await expect(frame).toHaveAttribute('src', /orientation=portrait/);
    const resetPdf=await page.request.get(await dialog.getByRole('link',{name:'PDF letöltése',exact:true}).getAttribute('href'));
    expect(resetPdf.ok()).toBe(true);
    expect((await resetPdf.body()).subarray(0, 5).toString()).toBe('%PDF-');
});
