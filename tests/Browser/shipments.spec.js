import { test, expect } from '@playwright/test';
import { legacyDraft, shipmentCommand as command } from './shipment-fixtures';

async function quantity(page, product = 1, warehouse = 1) {
    const response = await page.request.get(`/movements/stock?product_id=${product}&warehouse_id=${warehouse}`);
    expect(response.ok()).toBe(true);
    return (await response.json()).quantity;
}
const defaultStops = (quantity = 1) => [[2, 1], [3, 2], [4, 1]].map(([warehouse_id, product_id]) => ({ warehouse_id, items: [{ product_id, quantity }] }));
async function draft(page, reference, excessive = false) {
    const path = await legacyDraft(page, reference, 1, defaultStops(excessive ? 999 : 1), { vehicle_label: 'E2E-123' });
    await page.goto(path);
    return path;
}
async function start(page, path, expected = 302) {
    await command(page, 'POST', path+'/start', { version: 1 }, expected);
    await page.reload();
}
async function receive(page, path, position) {
    const stop = await page.locator('[data-legacy-stop-id]').nth(position).getAttribute('data-legacy-stop-id');
    await command(page, 'PATCH', path+`/stops/${stop}/receive`, {}, 302);
    await page.reload();
}
test.beforeEach(async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});

test('saved legacy route retains reverse loading order and an authenticated loading PDF', async ({ page }) => {
    const before = await quantity(page);
    const route = defaultStops();
    [route[0], route[1]] = [route[1], route[0]];
    await page.goto(await legacyDraft(page, 'E2E-Rakodási sorrend', 1, route));
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
    await expect(page.getByRole('link', { name: 'Terv szerkesztése', exact: true })).toHaveCount(0);
});

test('shipment can start once and be received stop by stop', async ({ page }) => {
    const before = await quantity(page);
    const targetBefore = await quantity(page, 1, 2);
    const path = await draft(page, 'E2E-Teljesített útvonal');
    await start(page, path);
    await expect(page.getByText('Úton', { exact: true })).toBeVisible();
    expect(await quantity(page)).toBe(before - 2);
    await command(page, 'POST', path+'/start', { version: 1 }, 302);
    expect(await quantity(page)).toBe(before - 2);
    await expect(page.getByRole('link', { name: 'Terv szerkesztése', exact: true })).toHaveCount(0);
    for (let position = 0; position < 3; position++) await receive(page, path, position);
    await expect(page.getByText('Teljesítve', { exact: true })).toBeVisible();
    expect(await quantity(page, 1, 2)).toBe(targetBefore + 1);
    await expect(page.getByRole('button', { name: 'Megálló átvétele', exact: true })).toHaveCount(0);
});

test('insufficient shipment stock keeps the entire shipment as a draft', async ({ page }) => {
    const before = await quantity(page);
    const path = await draft(page, 'E2E-Nincs elegendő készlet', true);
    const rejected = await command(page, 'POST', path+'/start', { version: 1 }, 422);
    expect((await rejected.json()).message).toContain('Nincs elegendő készlet');
    await page.reload();
    await expect(page.getByText('Tervezet', { exact: true })).toBeVisible();
    expect(await quantity(page)).toBe(before);
});

test('partial cancellation returns the remaining cargo and leaves received stock at the destination', async ({ page }) => {
    const before = await quantity(page);
    const targetBefore = await quantity(page, 1, 2);
    const zetaBefore = await quantity(page, 2, 1);
    const path = await draft(page, 'E2E-Részleges visszavonás');
    await start(page, path);
    await receive(page, path, 0);
    await command(page, 'PATCH', path+'/cancel', {}, 302);
    await page.reload();
    await expect(page.getByText('Visszavonva', { exact: true })).toBeVisible();
    expect(await quantity(page)).toBe(before - 1);
    expect(await quantity(page, 1, 2)).toBe(targetBefore + 1);
    expect(await quantity(page, 2, 1)).toBe(zetaBefore);
});

test('legacy shipment HTTP validation still rejects duplicate destinations', async ({ page }) => {
    const stops = defaultStops();
    stops[1].warehouse_id = stops[0].warehouse_id;
    const response = await command(page, 'POST', '/shipments', {
        reference: 'E2E-Hibás útvonal', origin_warehouse_id: 1, stops,
    }, 422);
    expect(await response.text()).toContain('stops');
});

test('loading PDF preview fits mobile and its close button restores the details page', async ({ page }) => {
    await draft(page, 'E2E-Mobil PDF-előnézet');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: 'Rakodólista (PDF)', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Rakodólista előnézete', exact: true });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Fekvő (A4)', exact: true }).click();
    await dialog.getByLabel('Cikkszámok (SKU)', { exact: true }).uncheck();
    const expandedFrame = await dialog.locator('iframe').boundingBox();
    await dialog.locator('aside').getByRole('button', { name: 'Beállítások elrejtése', exact: true }).click();
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
    const collapse = dialog.locator('aside').getByRole('button', { name: 'Beállítások elrejtése', exact: true });
    const reopen = dialog.locator('header').getByRole('button', { name: 'PDF testreszabása', exact: true });
    await expect(reopen).toBeHidden();
    await collapse.press('Enter');
    await expect(dialog.locator('aside')).not.toBeVisible();
    expect((await frame.boundingBox()).width).toBeGreaterThan(originalWidth + 250);
    await expect(reopen).toHaveAttribute('aria-expanded', 'false');
    await expect(reopen).toBeFocused();
    await reopen.press('Enter');
    await expect(dialog.locator('aside')).toBeVisible();
    await expect(collapse).toBeFocused();
    await expect(dialog.getByLabel('Cikkszámok (SKU)', { exact: true })).not.toBeChecked();
    await expect(frame).toHaveAttribute('src', originalUrl);
    expect((await frame.boundingBox()).width).toBeCloseTo(originalWidth, 0);
});

test('closing a native PDF removes the frame and cancels pending option updates', async ({ page }) => {
    await draft(page, 'E2E-PDF-Bezárás');
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
