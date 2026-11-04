import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
});

const csrf = page => page.locator('meta[name="csrf-token"]').getAttribute('content');
async function stock(page) { return (await (await page.request.get('/movements/stock?product_id=1&warehouse_id=1')).json()).quantity; }
async function original(page, type = 'out', quantity = 5, partner = '') {
    const before = await stock(page);
    const reference = 'RETURN-BROWSER-'+randomUUID();
    const response = await page.request.post('/movements', { headers: { 'X-CSRF-TOKEN': await csrf(page) },
        form: { request_key: randomUUID(), product_id: '1', warehouse_id: '1', type, quantity: String(quantity), reference, partner_id: partner }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    await page.goto('/movements?search='+reference);
    await page.locator('tbody').getByRole('link', { name: /^MOZ-/ }).click();
    return { id: new URL(page.url()).pathname.split('/').pop(), before };
}
async function openReturn(page) { await page.getByRole('link', { name: 'Visszáru rögzítése', exact: true }).click(); }
async function fillReturn(page, quantity, reason = 'Teszt visszáru') {
    await page.getByLabel('Visszáru mennyisége (db)', { exact: true }).fill(String(quantity));
    await page.getByLabel('Visszáru indoka', { exact: true }).fill(reason);
}
async function submit(page) { await page.getByRole('button', { name: 'Visszáru rögzítése', exact: true }).click(); }

test('partial then full customer returns restore stock and connect both directions of history', async ({ page }) => {
    const source = await original(page);
    await openReturn(page);
    await expect(page.getByLabel('Visszáru mennyisége (db)', { exact: true })).toHaveAttribute('max', '5');
    await fillReturn(page, 2, 'Részleges visszavétel');
    await submit(page);
    const relation = page.getByRole('region', { name: 'Visszáru kapcsolata', exact: true });
    await expect(relation).toContainText('Részleges visszavétel');
    expect(await stock(page)).toBe(source.before - 3);
    await relation.getByRole('link').click();
    await expect(page).toHaveURL(url => url.pathname === '/movements/'+source.id);
    await expect(page.getByRole('region', { name: 'Visszáruk', exact: true })).toContainText('Még visszaküldhető: 3 db');
    await openReturn(page);
    await fillReturn(page, 3, 'A maradék visszavétele');
    await submit(page);
    expect(await stock(page)).toBe(source.before);
    await page.getByRole('region', { name: 'Visszáru kapcsolata', exact: true }).getByRole('link').click();
    await expect(page.getByRole('link', { name: 'Visszáru rögzítése', exact: true })).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Visszáruk', exact: true }).locator('article')).toHaveCount(2);
    await page.goto('/movements/'+source.id+'/return');
    await expect(page.getByText('Az eredeti mennyiséget már teljes egészében visszaküldték.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Visszáru rögzítése', exact: true })).toHaveCount(0);
});

test('supplier return supports an archived partner and appears in filtered history and PDF', async ({ page }) => {
    const name = 'RETURN-SUPPLIER Partner';
    await page.goto('/partners/create');
    await page.getByLabel('Név / Cégnév', { exact: true }).fill(name);
    await page.getByLabel('Partner típusa', { exact: true }).selectOption('supplier');
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/partners');
    await page.goto('/partners?search='+name);
    const partnerId = await page.locator('tr[data-partner-id]').getAttribute('data-partner-id');
    const source = await original(page, 'in', 4, partnerId);
    await page.request.patch('/partners/'+partnerId+'/archive', { headers: { 'X-CSRF-TOKEN': await csrf(page) }, maxRedirects: 0 });
    await openReturn(page);
    await expect(page.getByText('· Archivált', { exact: true })).toBeVisible();
    await fillReturn(page, 3, 'Beszállítói visszáru teszt');
    await submit(page);
    expect(await stock(page)).toBe(source.before + 1);
    await page.goto('/movements?kind=return&partner_id='+partnerId);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody tr')).toContainText('Visszáru · MOZ-');
    await expect(page.locator('tbody tr')).toContainText('Beszállítói visszáru teszt');
    await page.getByRole('button', { name: 'Napló (PDF)' }).click();
    const dialog = page.getByRole('dialog', { name: 'Mozgásnapló PDF beállításai' });
    const url = await dialog.getByRole('link', { name: 'Előnézet megnyitása külön lapon' }).getAttribute('href');
    expect(new URL(url).searchParams.get('kind')).toBe('return');
    const pdf = await page.request.get(url);
    expect(pdf.headers()['content-type']).toContain('application/pdf');
    expect((await pdf.body()).subarray(0, 4).toString()).toBe('%PDF');
});

test('a stale open form cannot exceed the remaining quantity and preserves entered data', async ({ page }) => {
    const source = await original(page);
    await openReturn(page);
    await fillReturn(page, 3, 'Megőrzendő indok');
    const key = await page.locator('input[name="request_key"]').inputValue();
    const other = await page.request.post('/movements/'+source.id+'/return', { headers: { 'X-CSRF-TOKEN': await csrf(page) },
        form: { request_key: randomUUID(), quantity: '4', return_reason: 'Másik raktáros már visszavette' }, maxRedirects: 0 });
    expect(other.status()).toBe(302);
    await submit(page);
    await expect(page.locator('#return-quantity-error')).toContainText('legfeljebb 1 db');
    await expect(page.getByLabel('Visszáru indoka', { exact: true })).toHaveValue('Megőrzendő indok');
    await expect(page.locator('input[name="request_key"]')).toHaveValue(key);
    expect(await stock(page)).toBe(source.before - 1);
    await fillReturn(page, 1, 'Megőrzendő indok');
    await submit(page);
    expect(await stock(page)).toBe(source.before);
});

test('replaying the original return form reuses the first return without changing stock twice', async ({ page }) => {
    const source = await original(page, 'out', 2);
    await openReturn(page);
    await fillReturn(page, 2);
    const key = await page.locator('input[name="request_key"]').inputValue();
    const first = await page.request.post('/movements/'+source.id+'/return', { headers: { 'X-CSRF-TOKEN': await csrf(page) },
        form: { request_key: key, quantity: '2', return_reason: 'Teszt visszáru' }, maxRedirects: 0 });
    expect(first.status()).toBe(302);
    const firstLocation = new URL(first.headers().location).pathname;
    await submit(page);
    await expect(page).toHaveURL(url => url.pathname === firstLocation);
    expect(await stock(page)).toBe(source.before);
    await page.goto('/movements/'+source.id);
    await expect(page.getByRole('region', { name: 'Visszáruk', exact: true }).locator('article')).toHaveCount(1);
});

test('changed replay offers a fresh form with a new key and preserved list context', async ({ page }) => {
    const source = await original(page);
    await page.goto('/movements?kind=regular&product_id=1&per_page=25');
    await page.locator('tbody a[href*="/movements/'+source.id+'?"]').first().click();
    await openReturn(page);
    const oldKey = await page.locator('input[name="request_key"]').inputValue();
    await page.request.post('/movements/'+source.id+'/return', { headers: { 'X-CSRF-TOKEN': await csrf(page) },
        form: { request_key: oldKey, quantity: '1', return_reason: 'Első indok' }, maxRedirects: 0 });
    await fillReturn(page, 2, 'Második indok');
    await submit(page);
    await expect(page.getByRole('alert')).toContainText('más adatokkal');
    await page.getByRole('link', { name: 'Új visszáru-űrlap indítása', exact: true }).click();
    expect(await page.locator('input[name="request_key"]').inputValue()).not.toBe(oldKey);
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    await page.getByRole('link', { name: '← Készletmozgások', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('kind') === 'regular' && url.searchParams.get('per_page') === '25');
});

test('return form works without JavaScript and fits a narrow mobile screen', async ({ page, browser }) => {
    const source = await original(page, 'out', 1);
    const context = await browser.newContext({ javaScriptEnabled: false, storageState: await page.context().storageState(), baseURL: new URL(page.url()).origin, viewport: { width: 320, height: 800 } });
    try {
        const plain = await context.newPage();
        await plain.goto('/movements/'+source.id+'/return');
        await expect(plain.getByRole('button', { name: 'Frissítés', exact: true, includeHidden: true })).not.toBeVisible();
        await fillReturn(plain, 1, 'Visszáru JavaScript nélkül');
        expect(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await plain.screenshot({ path: 'tests/Browser/.runtime/return-form-mobile.png', fullPage: true });
        await submit(plain);
        await expect(plain.getByRole('region', { name: 'Visszáru kapcsolata', exact: true })).toContainText('Visszáru JavaScript nélkül');
        await plain.screenshot({ path: 'tests/Browser/.runtime/return-detail-mobile.png', fullPage: true });
        expect(await stock(plain)).toBe(source.before);
    } finally { await context.close(); }
});

test('restored return form unlocks saving and keeps the original key and entered values', async ({ page }) => {
    await original(page);
    await openReturn(page);
    await fillReturn(page, 2, 'Visszaállított űrlap');
    const key = await page.locator('input[name="request_key"]').inputValue();
    const form = page.locator('form[x-data^="movementForm"]');
    await form.evaluate(el => { window.Alpine.$data(el).submitting = true; });
    await expect(form.locator('button[type="submit"]')).toBeDisabled();
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await expect(form.locator('button[type="submit"]')).toBeEnabled();
    await expect(page.locator('input[name="request_key"]')).toHaveValue(key);
    await expect(page.getByLabel('Visszáru indoka', { exact: true })).toHaveValue('Visszaállított űrlap');
    await submit(page);
    await expect(page.getByRole('region', { name: 'Visszáru kapcsolata', exact: true })).toBeVisible();
});

test('out-of-range return history recovers to its last page and still allows navigation', async ({ page }) => {
    const source = await original(page, 'in', 12);
    const token = await csrf(page);
    for (let i = 0; i < 11; i++) {
        const response = await page.request.post('/movements/'+source.id+'/return', {
            headers: { 'X-CSRF-TOKEN': token }, form: { request_key: randomUUID(), quantity: '1', return_reason: 'Lapozási próba '+i }, maxRedirects: 0,
        });
        expect(response.status()).toBe(302);
    }
    await page.goto('/movements/'+source.id+'?returns_page=999');
    await expect(page).toHaveURL(url => url.searchParams.get('returns_page') === '2');
    const history = page.getByRole('region', { name: 'Visszáruk', exact: true });
    await expect(history.locator('article')).toHaveCount(1);
    await history.getByRole('link', { name: '1. oldal', exact: true }).click();
    await expect(history.locator('article')).toHaveCount(10);
    await expect(history).toContainText('Még visszaküldhető: 1 db');
});
