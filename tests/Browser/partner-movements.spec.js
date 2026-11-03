import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
});

async function partner(page, name, type = 'both', details = {}) {
    await page.goto('/partners/create');
    await page.getByLabel('Név / Cégnév', { exact: true }).fill(name);
    await page.getByLabel('Partner típusa', { exact: true }).selectOption(type);
    for (const [label, value] of Object.entries(details)) await page.getByLabel(label, { exact: true }).fill(value);
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/partners');
    await page.goto('/partners?search='+encodeURIComponent(name));
    return page.locator('tr[data-partner-id]').getAttribute('data-partner-id');
}

async function movement(page) {
    await page.goto('/movements/create');
    await page.getByRole('combobox', { name: 'Termék', exact: true }).fill('ALPHA');
    await page.getByRole('option', { name: 'Alpha teszttermék (ALPHA-001)', exact: true }).click();
    await page.getByRole('combobox', { name: 'Raktár', exact: true }).fill('Teszt');
    await page.getByRole('option', { name: 'Teszt raktár · Test', exact: true }).click();
}

async function choosePartner(page, text) {
    await page.getByRole('combobox', { name: 'Partner', exact: true }).fill(text);
    const list = page.getByRole('listbox', { name: 'Partnertalálatok', exact: true });
    await expect(list.getByRole('option')).toHaveCount(1);
    await list.getByRole('option').click();
}

test('new partner details survive editing and dual roles appear in both filters on mobile', async ({ page }) => {
    const name = 'PM-DETAIL Partner';
    const fields = { 'Kapcsolattartó': 'Minta Judit', 'Ország': 'Magyarország', 'Irányítószám': '0012',
        'Település': 'Győr', 'Utca, házszám': 'Bemutató köz 12.', 'Belső megjegyzés': 'Áruátvétel: 8–14\nMásodik sor <script>szöveg</script>' };
    const id = await partner(page, name, 'both', fields);
    for (const role of ['customer', 'supplier', 'both']) {
        await page.goto('/partners?partner_id='+id+'&type='+role);
        await expect(page.locator('tr[data-partner-id]')).toHaveCount(1);
    }
    await page.goto('/partners?search='+encodeURIComponent('Judit Győr'));
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(1);
    await page.getByRole('link', { name, exact: true }).filter({ visible: true }).click();
    for (const [label, value] of Object.entries(fields)) await expect(page.getByLabel(label, { exact: true })).toHaveValue(value);
    await page.setViewportSize({ width: 320, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/partner-details-mobile.png', fullPage: true });
    for (const label of Object.keys(fields)) await page.getByLabel(label, { exact: true }).fill('');
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/partners');
    await page.goto('/partners/'+id+'/edit');
    for (const label of Object.keys(fields)) await expect(page.getByLabel(label, { exact: true })).toHaveValue('');
});

test('partner autocomplete follows movement direction and keeps dual-role selections', async ({ page }) => {
    const supplier = await partner(page, 'PM-CHOICE Beszállító', 'supplier', { 'Kapcsolattartó': 'Kiss Béla', 'E-mail': 'choice-supplier@example.test' });
    const customer = await partner(page, 'PM-CHOICE Vevő', 'customer');
    const both = await partner(page, 'PM-CHOICE Mindkettő', 'both');
    const archived = await partner(page, 'PM-CHOICE Archivált', 'both');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    expect((await page.request.patch('/partners/'+archived+'/archive', { headers: { 'X-CSRF-TOKEN': csrf }, maxRedirects: 0 })).status()).toBe(302);
    await movement(page);
    const input = page.getByRole('combobox', { name: 'Partner', exact: true });
    await input.fill('PM-CHOICE');
    const list = page.getByRole('listbox', { name: 'Partnertalálatok', exact: true });
    await expect(list.getByRole('option')).toHaveCount(2);
    await expect(list).not.toContainText('Archivált');
    await choosePartner(page, 'Kiss Béla');
    await expect(page.locator('input[name="partner_id"]')).toHaveValue(supplier);
    await page.getByLabel('Mozgás típusa', { exact: true }).selectOption('out');
    await expect(page.locator('input[name="partner_id"]')).toHaveValue('');
    await expect(input).toHaveValue('');
    await choosePartner(page, 'PM-CHOICE Vevő');
    await expect(page.locator('input[name="partner_id"]')).toHaveValue(customer);
    await choosePartner(page, 'PM-CHOICE Mindkettő');
    await page.getByLabel('Mozgás típusa', { exact: true }).selectOption('in');
    await expect(page.locator('input[name="partner_id"]')).toHaveValue(both);
    await input.fill('nem létező partner');
    await expect(page.locator('input[name="partner_id"]')).toHaveValue('');
    expect(await input.evaluate(el => el.checkValidity())).toBe(false);
    await page.getByRole('button', { name: 'Partner törlése a mezőből', exact: true }).click();
    expect(await input.evaluate(el => el.checkValidity())).toBe(true);
});

test('movement links and partner counters lead to the filtered history and PDF retains the partner filter', async ({ page }) => {
    const name = 'PM-HISTORY Partner';
    const id = await partner(page, name, 'both', { 'Belső megjegyzés': 'BELSŐ-ADAT' });
    await movement(page);
    await choosePartner(page, name);
    await page.getByLabel('Mennyiség (db)', { exact: true }).fill('3');
    await page.getByLabel('Bizonylatszám vagy megjegyzés (opcionális)').fill('PM-HISTORY-REF');
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/movements');
    await page.goto('/partners/'+id+'/edit');
    await expect(page.getByRole('link', { name: 'Bevételezések (1)', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Kiadások (0)', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Készletmozgások (1)', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('partner_id') === id);
    await expect(page.locator('tbody tr')).toHaveCount(1);
    await expect(page.locator('tbody tr')).toContainText('Partner: '+name);
    await page.getByRole('button', { name: 'Napló (PDF)' }).click();
    const dialog = page.getByRole('dialog', { name: 'Mozgásnapló PDF beállításai' });
    await expect(dialog.getByLabel('Partner neve a rögzítéskor')).toBeChecked();
    const preview = dialog.getByRole('link', { name: 'Előnézet megnyitása külön lapon' });
    await expect(preview).toHaveAttribute('href', new RegExp('partner_id='+id));
    await dialog.getByLabel('Partner neve a rögzítéskor').uncheck();
    await expect(preview).toHaveAttribute('href', /show_partner=0/);
    await dialog.getByRole('button', { name: 'Bezárás', exact: true }).click();
    await page.locator('tbody tr').getByRole('link', { name: /^MOZ-/ }).click();
    await expect(page.getByText('Partner a rögzítéskor', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name, exact: true })).toBeVisible();
    await expect(page.getByText('BELSŐ-ADAT', { exact: true })).toHaveCount(0);
    await page.screenshot({ path: 'tests/Browser/.runtime/partner-movement-detail.png', fullPage: true });
});

test('archiving a selected partner before saving rejects the movement and preserves other fields', async ({ page }) => {
    const name = 'PM-STALE Partner';
    const id = await partner(page, name);
    await movement(page);
    await choosePartner(page, name);
    await page.getByLabel('Mennyiség (db)', { exact: true }).fill('4');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    await page.request.patch('/partners/'+id+'/archive', { headers: { 'X-CSRF-TOKEN': csrf }, maxRedirects: 0 });
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page.locator('#movement-partner-error')).toContainText('Válassz aktív');
    await expect(page.getByLabel('Mennyiség (db)', { exact: true })).toHaveValue('4');
    await expect(page.locator('input[name="product_id"]')).toHaveValue('1');
    await expect(page.locator('input[name="partner_id"]')).toHaveValue('');
    await page.getByRole('button', { name: 'Partner törlése a mezőből', exact: true }).click();
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/movements');
    await page.goto('/partners/'+id+'/edit');
    await expect(page.getByRole('link', { name: 'Készletmozgások (0)', exact: true })).toBeVisible();
});

test('linked partner deletion gives an explanation and archival preserves movement access', async ({ page }) => {
    const name = 'PM-DELETE Partner';
    const id = await partner(page, name);
    await movement(page);
    await choosePartner(page, name);
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/movements');
    await page.goto('/partners?partner_id='+id+'&status=all');
    await page.getByRole('button', { name: 'Műveletek: '+name, exact: true }).click();
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('menuitem', { name: 'Törlés', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Használd az archiválást');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(1);
    await page.getByRole('button', { name: 'Műveletek: '+name, exact: true }).click();
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('menuitem', { name: 'Archiválás', exact: true }).click();
    await expect(page.locator('tr[data-partner-id="'+id+'"]')).toContainText('Archivált');
    await page.getByRole('link', { name, exact: true }).filter({ visible: true }).click();
    await page.getByRole('link', { name: 'Készletmozgások (1)', exact: true }).click();
    await expect(page.locator('tbody tr')).toHaveCount(1);
});

test('changing a selected partner role in another tab is checked again at save', async ({ page }) => {
    const name = 'PM-ROLE-CHANGE Partner';
    const id = await partner(page, name, 'supplier');
    await movement(page);
    await choosePartner(page, name);
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const changed = await page.request.put('/partners/'+id, { headers: { 'X-CSRF-TOKEN': csrf }, form: { name, type: 'customer' }, maxRedirects: 0 });
    expect(changed.status()).toBe(302);
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page.locator('#movement-partner-error')).toContainText('Válassz aktív');
    await expect(page.locator('input[name="partner_id"]')).toHaveValue('');
    await page.getByRole('button', { name: 'Partner törlése a mezőből', exact: true }).click();
    await page.getByLabel('Mozgás típusa', { exact: true }).selectOption('out');
    await choosePartner(page, name);
    await page.getByRole('button', { name: 'Mozgás rögzítése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/movements');
    await page.goto('/partners/'+id+'/edit');
    await expect(page.getByRole('link', { name: 'Bevételezések (0)', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Kiadások (1)', exact: true })).toBeVisible();
});

for (const path of ['/movements/create', '/transfers/create']) {
    test('restoring '+path+' unlocks submitting, refreshes stock and preserves the request key', async ({ page }) => {
        let stockRequests = 0;
        await page.route('**/movements/stock?**', route => route.fulfill({ json: { quantity: ++stockRequests === 1 ? 20 : 7 } }));
        await page.goto(path);
        await page.getByRole('combobox', { name: 'Termék', exact: true }).fill('ALPHA');
        await page.getByRole('option', { name: 'Alpha teszttermék (ALPHA-001)', exact: true }).click();
        await page.getByRole('combobox', { name: path.includes('transfers') ? 'Forrásraktár' : 'Raktár', exact: true }).fill('Teszt');
        await page.getByRole('option', { name: 'Teszt raktár · Test', exact: true }).click();
        await expect(page.getByRole('status').filter({ hasText: 'Jelenlegi készlet' })).toContainText('20 db');
        await page.getByLabel('Mennyiség (db)', { exact: true }).fill('3');
        const key = await page.locator('input[name="request_key"]').inputValue();
        const form = page.locator('form[x-data^="movementForm"]');
        const submit = form.locator('button').last();
        await form.evaluate(el => { window.Alpine.$data(el).submitting = true; });
        await expect(submit).toBeDisabled();
        await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
        await expect(submit).toBeEnabled();
        await expect(page.getByRole('status').filter({ hasText: 'Jelenlegi készlet' })).toContainText('7 db');
        await expect(page.getByLabel('Mennyiség (db)', { exact: true })).toHaveValue('3');
        await expect(page.locator('input[name="request_key"]')).toHaveValue(key);
        await expect(page.locator('input[name="product_id"]')).toHaveValue('1');
    });
}
