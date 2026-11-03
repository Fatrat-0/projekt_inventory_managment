import { test, expect } from '@playwright/test';

async function create(page, name, { type = 'customer', email = '', phone = '' } = {}) {
    await page.goto('/partners/create');
    await page.getByLabel('Név / Cégnév', { exact: true }).fill(name);
    await page.getByLabel('Partner típusa', { exact: true }).selectOption(type);
    await page.getByLabel('E-mail', { exact: true }).fill(email);
    await page.getByLabel('Telefon', { exact: true }).fill(phone);
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    if (new URL(page.url()).pathname === '/partners/create') {
        await page.getByLabel('Átnéztem az egyezéseket, külön partnerként mentem.', { exact: true }).check();
        await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    }
    await expect(page).toHaveURL(url => url.pathname === '/partners');
    await page.goto('/partners?search='+encodeURIComponent(name));
    let rows = page.locator('tr[data-partner-id]').filter({ has: page.getByRole('link', { name, exact: true }) });
    if (email) rows = rows.filter({ hasText: email });
    return rows.first().getAttribute('data-partner-id');
}

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
});

test('partner suggestions distinguish same names by contacts and editing clears the exact selection', async ({ page }) => {
    const name = 'P-SEARCH Árviz Kft.';
    await create(page, name, { email: 'first@example.test', phone: '+36 20 111 1111' });
    const second = await create(page, name, { type: 'supplier', email: 'second@example.test', phone: '+36 20 222 2222' });
    await page.goto('/partners');
    const search = page.getByRole('combobox', { name: 'Partnerek keresése', exact: true });
    await search.fill('arviz second');
    const list = page.getByRole('listbox', { name: 'Partnerjavaslatok', exact: true });
    await expect(list.getByRole('option')).toHaveCount(1);
    await search.press('ArrowDown'); await search.press('Enter');
    await expect(page).toHaveURL(url => url.searchParams.get('partner_id') === second);
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(1);
    await expect(page.locator('tr[data-partner-id]')).toContainText('second@example.test');
    await search.fill('P-SEARCH'); await search.press('Escape'); await search.press('Enter');
    await expect(page).toHaveURL(url => !url.searchParams.get('partner_id') && url.searchParams.get('search') === 'P-SEARCH');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(2);
});

test('type and contact filters refresh without Enter and combine with current search text', async ({ page }) => {
    await create(page, 'P-FILTER Complete', { email: 'complete@example.test', phone: '12345' });
    await create(page, 'P-FILTER Supplier', { type: 'supplier', email: 'supplier@example.test' });
    const missing = await create(page, 'P-FILTER Missing');
    await page.goto('/partners');
    await page.getByRole('combobox', { name: 'Partnerek keresése', exact: true }).fill('P-FILTER');
    await page.getByLabel('Partner típusa', { exact: true }).selectOption('customer');
    await expect(page).toHaveURL(url => url.searchParams.get('search') === 'P-FILTER');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(2);
    await page.getByLabel('Elérhetőség', { exact: true }).selectOption('missing');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(1);
    await expect(page.locator('tr[data-partner-id]')).toHaveAttribute('data-partner-id', missing);
    await page.getByLabel('Elérhetőség', { exact: true }).selectOption('complete');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(1);
    await expect(page.locator('tr[data-partner-id]')).toContainText('P-FILTER Complete');
    await page.getByRole('link', { name: 'Szűrők törlése', exact: true }).click();
    await expect(page.getByLabel('Elérhetőség', { exact: true })).toHaveValue('');
    await expect(page.getByLabel('Partner típusa', { exact: true })).toHaveValue('');
});

test('create edit cancel and validation correction preserve list state and optional contact clearing', async ({ page }) => {
    await page.goto('/partners?search=P-FORM&type=supplier&per_page=25&sort_by=phone&sort_dir=desc');
    await page.getByRole('link', { name: '+ Új partner', exact: true }).click();
    await expect(page.getByLabel('Partner típusa', { exact: true })).toHaveValue('supplier');
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('search') === 'P-FORM' && url.searchParams.get('per_page') === '25');
    await page.getByRole('link', { name: '+ Új partner', exact: true }).click();
    await page.getByLabel('Név / Cégnév', { exact: true }).fill('P-FORM Partner');
    await page.getByLabel('E-mail', { exact: true }).fill('form@example.test');
    await page.getByLabel('Telefon', { exact: true }).fill('+36 30 123 4567');
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/partners' && url.searchParams.get('search') === 'P-FORM' && url.searchParams.get('sort_dir') === 'desc');
    await page.getByRole('link', { name: 'P-FORM Partner', exact: true }).click();
    const form = page.locator('form').filter({ has: page.locator('[name="name"]') });
    await page.getByLabel('E-mail', { exact: true }).fill('NOT-AN-EMAIL');
    await form.evaluate(el => el.noValidate = true);
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByLabel('E-mail', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Telefon', { exact: true })).toHaveValue('+36 30 123 4567');
    await page.getByLabel('E-mail', { exact: true }).fill('fixed@example.test');
    await page.getByLabel('Telefon', { exact: true }).fill('');
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/partners' && url.searchParams.get('type') === 'supplier' && url.searchParams.get('sort_by') === 'phone' && url.searchParams.get('per_page') === '25');
    await expect(page.locator('tr[data-partner-id]')).toContainText('fixed@example.test');
    await expect(page.locator('tr[data-partner-id]')).toContainText('Nincs megadva');
});

test('desktop column headers sort each field and submit pending search while keeping page size', async ({ page }) => {
    await create(page, 'P-SORT Vevő', { email: 'z@example.test', phone: '999' });
    await create(page, 'P-SORT Beszállító', { type: 'supplier', email: 'a@example.test', phone: '111' });
    await page.goto('/partners?per_page=25');
    await page.getByRole('combobox', { name: 'Partnerek keresése', exact: true }).fill('P-SORT');
    await page.keyboard.press('Escape');
    for (const [label, key] of [['Típus', 'type'], ['E-mail', 'email'], ['Telefon', 'phone'], ['Név / Cégnév', 'name']]) {
        const header = page.getByRole('columnheader', { name: new RegExp('^'+label) });
        await header.getByRole('link').click();
        await expect(page).toHaveURL(url => url.searchParams.get('search') === 'P-SORT' && url.searchParams.get('sort_by') === key && url.searchParams.get('sort_dir') === 'asc' && url.searchParams.get('per_page') === '25');
        await expect(header).toHaveAttribute('aria-sort', 'ascending');
        await header.getByRole('link').click();
        await expect(header).toHaveAttribute('aria-sort', 'descending');
    }
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(2);
    await expect(page.getByLabel('Rendezés', { exact: true })).not.toBeVisible();
});

test('pagination size and out-of-range recovery keep filters and never duplicate rows', async ({ page }) => {
    await page.goto('/partners');
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    for (let i = 0; i < 17; i++) {
        const response = await page.request.post('/partners', { headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, form: { name: 'P-PAGE '+String(i).padStart(2, '0'), type: 'customer' } });
        expect(response.ok(), await response.text()).toBe(true);
    }
    await page.goto('/partners?search=P-PAGE&per_page=15');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(15);
    const ids = await page.locator('tr[data-partner-id]').evaluateAll(rows => rows.map(row => row.dataset.partnerId));
    await page.getByRole('link', { name: '2. oldal', exact: true }).click();
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(2);
    const next = await page.locator('tr[data-partner-id]').evaluateAll(rows => rows.map(row => row.dataset.partnerId));
    expect(next.filter(id => ids.includes(id))).toEqual([]);
    await page.getByLabel('Találatok oldalanként', { exact: true }).selectOption('25');
    await expect(page).toHaveURL(url => !url.searchParams.has('page') && url.searchParams.get('search') === 'P-PAGE');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(17);
    await page.goto('/partners?search=P-PAGE&per_page=15&page=999');
    await expect(page).toHaveURL(url => url.searchParams.get('page') === '2');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(2);
});

test('partner menus support keyboard cancel confirmed deletion and exact filter cleanup', async ({ page }) => {
    const id = await create(page, 'P-DELETE Partner');
    await page.goto('/partners?partner_id='+id);
    const trigger = page.getByRole('button', { name: 'Műveletek: P-DELETE Partner', exact: true });
    await trigger.focus(); await page.keyboard.press('ArrowDown');
    const menu = page.getByRole('menu', { name: 'P-DELETE Partner műveletei', exact: true });
    await expect(menu.getByRole('menuitem', { name: 'Szerkesztés', exact: true })).toBeFocused();
    await page.keyboard.press('End');
    await expect(menu.getByRole('menuitem', { name: 'Törlés', exact: true })).toBeFocused();
    page.once('dialog', dialog => dialog.dismiss()); await page.keyboard.press('Enter');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(1);
    await page.keyboard.press('Escape'); await expect(menu).not.toBeVisible();
    await trigger.click();
    page.once('dialog', dialog => dialog.accept());
    await menu.getByRole('menuitem', { name: 'Törlés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/partners' && !url.searchParams.has('partner_id'));
    await expect(page.locator('tr[data-partner-id="'+id+'"]')).toHaveCount(0);
});

test('mobile cards form suggestions and action menus fit the viewport and retain list context', async ({ page }) => {
    const name = 'P-MOBILE Hosszú partnernév magyar ékezetekkel és további szavakkal';
    const id = await create(page, name, { type: 'supplier', email: 'mobile.partner@example.test', phone: '+36 (1) 234-5678' });
    await page.goto('/partners?partner_id='+id+'&type=supplier&per_page=25');
    await page.screenshot({ path: 'tests/Browser/.runtime/partners-desktop.png', fullPage: true });
    await page.getByRole('button', { name: 'Műveletek: '+name, exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('menu')).toHaveCount(0);
    const card = page.locator('article[data-partner-id="'+id+'"]');
    await expect(card).toBeVisible(); await expect(card).toContainText('mobile.partner@example.test');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/partners-mobile.png', fullPage: true });
    await card.getByRole('button', { name: 'Műveletek: '+name, exact: true }).click();
    const menu = page.getByRole('menu', { name: name+' műveletei', exact: true });
    const box = await menu.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(390);
    await menu.getByRole('menuitem', { name: 'Szerkesztés', exact: true }).click();
    await expect(page.getByLabel('Név / Cégnév', { exact: true })).toHaveValue(name);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/partner-form-mobile.png', fullPage: true });
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('partner_id') === id && url.searchParams.get('per_page') === '25');
    const search = page.getByRole('combobox', { name: 'Partnerek keresése', exact: true });
    await search.fill('P-MOBILE'); await search.press('ArrowDown'); await search.press('Enter');
    await expect(page).toHaveURL(url => url.searchParams.get('partner_id') === id);
});

test('restored partner form unlocks saving and keeps entered data', async ({ page }) => {
    await page.goto('/partners/create');
    await page.getByLabel('Név / Cégnév', { exact: true }).fill('P-RESTORE Partner');
    const form = page.locator('form').filter({ has: page.locator('[name="name"]') });
    const button = form.locator('button[type="submit"]');
    await form.evaluate(el => { window.Alpine.$data(el).submitting = true; });
    await expect(button).toBeDisabled();
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    await expect(button).toBeEnabled();
    await expect(page.getByLabel('Név / Cégnév', { exact: true })).toHaveValue('P-RESTORE Partner');
    await button.click();
    await expect(page).toHaveURL(url => url.pathname === '/partners');
});

test('maximum length unbroken names and contacts fit a narrow mobile screen', async ({ page }) => {
    const name = 'P-LONG-'+ 'A'.repeat(93);
    const email = 'a'.repeat(60)+'@'+'b'.repeat(30)+'.test';
    const id = await create(page, name, { email, phone: '+1234567890123456789' });
    await page.setViewportSize({ width: 320, height: 700 });
    await page.goto('/partners?partner_id='+id);
    await expect(page.locator('article[data-partner-id="'+id+'"]')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const search = page.getByRole('combobox', { name: 'Partnerek keresése', exact: true });
    await search.fill('P-LONG');
    await expect(page.getByRole('listbox', { name: 'Partnerjavaslatok', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.keyboard.press('Escape');
    await page.locator('article[data-partner-id="'+id+'"]' ).getByRole('link', { name, exact: true }).click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test('archive cancellation, archive filtering and restoration preserve the original partner', async ({ page }) => {
    const name = 'P-ARCHIVE Partner';
    const id = await create(page, name, { type: 'supplier', email: 'archive@example.test', phone: '123456' });
    await page.goto('/partners?search=P-ARCHIVE&type=supplier&per_page=25');
    const trigger = page.getByRole('button', { name: 'Műveletek: '+name, exact: true });
    const menu = page.getByRole('menu', { name: name+' műveletei', exact: true });
    await trigger.click();
    page.once('dialog', dialog => dialog.dismiss());
    await menu.getByRole('menuitem', { name: 'Archiválás', exact: true }).click();
    await expect(page.locator('tr[data-partner-id="'+id+'"]')).toHaveCount(1);
    page.once('dialog', dialog => dialog.accept());
    await menu.getByRole('menuitem', { name: 'Archiválás', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('type') === 'supplier' && url.searchParams.get('per_page') === '25');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(0);
    const search = page.getByRole('combobox', { name: 'Partnerek keresése', exact: true });
    await search.fill('P-ARCHIVE');
    await expect(page.getByRole('listbox', { name: 'Partnerjavaslatok', exact: true }).getByRole('option')).toHaveCount(0);
    await search.press('Escape');
    await page.getByLabel('Állapot', { exact: true }).selectOption('archived');
    const row = page.locator('tr[data-partner-id="'+id+'"]');
    await expect(row).toContainText('Archivált');
    await expect(row).toContainText('archive@example.test');
    await page.getByLabel('Állapot', { exact: true }).selectOption('all');
    await expect(row).toBeVisible();
    await trigger.click();
    await menu.getByRole('menuitem', { name: 'Visszaállítás', exact: true }).click();
    await expect(row).not.toContainText('Archivált');
    await expect(row).toContainText('123456');
    await expect(page.getByLabel('Állapot', { exact: true })).toHaveValue('all');
});

test('archived form can be edited and restored on mobile without losing list context', async ({ page }) => {
    const name = 'P-ARCH-EDIT Partner';
    const id = await create(page, name);
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const response = await page.request.patch('/partners/'+id+'/archive', { headers: { 'X-CSRF-TOKEN': token }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/partners?search=P-ARCH-EDIT&status=archived&per_page=25');
    await page.locator('article[data-partner-id="'+id+'"]' ).getByRole('link', { name, exact: true }).click();
    await expect(page.getByText('Archivált partner. Az aktív partnerlistában nem jelenik meg.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Átnéztem az egyezéseket, külön partnerként mentem.', { exact: true })).not.toBeVisible();
    await page.getByLabel('Telefon', { exact: true }).fill('987654');
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('status') === 'archived' && url.searchParams.get('per_page') === '25');
    await expect(page.locator('article[data-partner-id="'+id+'"]')).toContainText('Archivált');
    await page.getByRole('link', { name, exact: true }).click();
    await page.screenshot({ path: 'tests/Browser/.runtime/partner-archived-mobile.png', fullPage: true });
    await page.getByRole('button', { name: 'Visszaállítás', exact: true }).click();
    await expect(page.getByLabel('Állapot', { exact: true })).toHaveValue('archived');
    await expect(page.locator('article[data-partner-id]')).toHaveCount(0);
    await page.getByLabel('Állapot', { exact: true }).selectOption('active');
    await expect(page.locator('article[data-partner-id="'+id+'"]')).toContainText('987654');
});

test('live duplicate warning includes archived matches and resets acknowledgement when identity fields change', async ({ page }) => {
    const activeName = 'P-DUP-A Aktív';
    const oldName = 'P-DUP-B Régi';
    await create(page, activeName, { email: 'dup-active@example.test' });
    const archived = await create(page, oldName, { email: 'dup-old@example.test' });
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    await page.request.patch('/partners/'+archived+'/archive', { headers: { 'X-CSRF-TOKEN': token }, maxRedirects: 0 });
    await page.goto('/partners/create');
    await page.getByLabel('Név / Cégnév', { exact: true }).fill(activeName);
    const region = page.getByRole('region', { name: 'Lehetséges duplikációk', exact: true });
    await expect(region.getByRole('link', { name: new RegExp('^'+activeName) })).toBeVisible();
    const acknowledged = page.getByLabel('Átnéztem az egyezéseket, külön partnerként mentem.', { exact: true });
    await acknowledged.check();
    await page.getByLabel('E-mail', { exact: true }).fill('dup-old@example.test');
    await expect(region.getByRole('link')).toHaveCount(2);
    await expect(acknowledged).not.toBeChecked();
    await expect(region).toContainText('Archivált');
    await expect(region.getByRole('link').first()).toHaveAttribute('target', '_blank');
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(/\/partners\/create$/);
    await page.screenshot({ path: 'tests/Browser/.runtime/partner-duplicate-desktop.png', fullPage: true });
    await acknowledged.check();
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/partners');
    await page.goto('/partners?search=P-DUP-A');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(2);
});

test('without JavaScript the server warns before writing and accepts explicit duplicate confirmation', async ({ page, browser }) => {
    const name = 'P-NOJS Partner';
    await create(page, name, { email: 'nojs@example.test' });
    const context = await browser.newContext({ javaScriptEnabled: false, storageState: await page.context().storageState(), baseURL: new URL(page.url()).origin });
    try {
        const plain = await context.newPage();
        await plain.goto('/partners/create');
        await plain.getByLabel('Név / Cégnév', { exact: true }).fill(name);
        await plain.getByRole('button', { name: 'Partner mentése', exact: true }).click();
        await expect(plain).toHaveURL(/\/partners\/create/);
        const region = plain.getByRole('region', { name: 'Lehetséges duplikációk', exact: true });
        await expect(region.getByRole('link')).toContainText(name);
        await plain.getByLabel('Átnéztem az egyezéseket, külön partnerként mentem.', { exact: true }).check();
        await plain.getByRole('button', { name: 'Partner mentése', exact: true }).click();
        await expect(plain).toHaveURL(url => url.pathname === '/partners');
        await plain.goto('/partners?search=P-NOJS');
        await expect(plain.locator('tr[data-partner-id]')).toHaveCount(2);
    } finally { await context.close(); }
});

test('a slow previous duplicate response cannot overwrite the new input', async ({ page }) => {
    let sawOld;
    const oldStarted = new Promise(resolve => { sawOld = resolve; });
    await page.route('**/partners/duplicates?**', async route => {
        const name = new URL(route.request().url()).searchParams.get('name');
        if (name === 'OLD') { sawOld(); await new Promise(resolve => setTimeout(resolve, 500)); }
        await route.fulfill({ json: { matches: [{ id: name === 'OLD' ? 1 : 2, name, email: null, archived: false, same_name: true, same_email: false, edit_url: '/partners/1/edit' }], total: 1, token: 'a'.repeat(64) } });
    });
    await page.goto('/partners/create');
    await page.getByLabel('Név / Cégnév', { exact: true }).fill('OLD');
    await oldStarted;
    await page.getByLabel('Név / Cégnév', { exact: true }).fill('NEW');
    const region = page.getByRole('region', { name: 'Lehetséges duplikációk', exact: true });
    await expect(region.getByRole('link')).toHaveText('NEW · #2');
    await page.waitForTimeout(650);
    await expect(region.getByRole('link')).toHaveText('NEW · #2');
});

test('failed duplicate lookup allows a retry and leaves server-side confirmation available', async ({ page }) => {
    const name = 'P-RETRY Partner';
    await create(page, name);
    let requests = 0;
    await page.route('**/partners/duplicates?**', route => ++requests === 1 ? route.abort('failed') : route.continue());
    await page.goto('/partners/create');
    await page.getByLabel('Név / Cégnév', { exact: true }).fill(name);
    const region = page.getByRole('region', { name: 'Lehetséges duplikációk', exact: true });
    await expect(region).toContainText('Az ellenőrzés nem sikerült.');
    await region.getByRole('button', { name: 'Újrapróbálás', exact: true }).click();
    await expect(region.getByRole('link')).toContainText(name);
    await expect(page.getByLabel('Átnéztem az egyezéseket, külön partnerként mentem.', { exact: true })).toBeVisible();
    await page.setViewportSize({ width: 320, height: 700 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/partner-duplicate-mobile.png', fullPage: true });
});

test('saving during a pending duplicate lookup warns without losing entered fields or list context', async ({ page }) => {
    const name = 'P-PENDING Partner';
    await create(page, name);
    await page.goto('/partners?search=P-PENDING&status=all&type=supplier&per_page=25');
    await page.getByRole('link', { name: '+ Új partner', exact: true }).click();
    let started;
    const pending = new Promise(resolve => { started = resolve; });
    await page.route('**/partners/duplicates?**', async route => {
        started();
        await new Promise(resolve => setTimeout(resolve, 700));
        await route.abort();
    });
    await page.getByLabel('Név / Cégnév', { exact: true }).fill(name);
    await page.getByLabel('Telefon', { exact: true }).fill('765432');
    await pending;
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    const region = page.getByRole('region', { name: 'Lehetséges duplikációk', exact: true });
    await expect(region).toContainText('A folytatáshoz erősítsd meg');
    await expect(page.getByLabel('Telefon', { exact: true })).toHaveValue('765432');
    await expect(page.getByLabel('Partner típusa', { exact: true })).toHaveValue('supplier');
    await page.getByLabel('Átnéztem az egyezéseket, külön partnerként mentem.', { exact: true }).check();
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/partners' && url.searchParams.get('status') === 'all' && url.searchParams.get('type') === 'supplier' && url.searchParams.get('per_page') === '25');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(1);
    await expect(page.locator('tr[data-partner-id]')).toContainText('765432');
});

test('a candidate archived after acknowledgement triggers a fresh warning instead of silently saving', async ({ page }) => {
    const name = 'P-STALE Partner';
    const id = await create(page, name);
    await page.goto('/partners/create');
    await page.getByLabel('Név / Cégnév', { exact: true }).fill(name);
    const region = page.getByRole('region', { name: 'Lehetséges duplikációk', exact: true });
    await expect(region.getByRole('link')).toContainText(name);
    const confirmation = page.getByLabel('Átnéztem az egyezéseket, külön partnerként mentem.', { exact: true });
    await confirmation.check();
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const response = await page.request.patch('/partners/'+id+'/archive', { headers: { 'X-CSRF-TOKEN': token }, maxRedirects: 0 });
    expect(response.status()).toBe(302);
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(region).toContainText('A folytatáshoz erősítsd meg');
    await expect(region).toContainText('Archivált');
    await expect(confirmation).not.toBeChecked();
    await confirmation.check();
    await page.getByRole('button', { name: 'Partner mentése', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/partners');
    await page.goto('/partners?search=P-STALE&status=all');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(2);
});

test('without JavaScript email-only matches show their reason, contacts and full result count', async ({ page, browser }) => {
    const email = 'nojs-many@example.test';
    for (let i = 0; i < 6; i++) await create(page, 'P-NOJS-MANY '+i, { email });
    const context = await browser.newContext({ javaScriptEnabled: false, storageState: await page.context().storageState(), baseURL: new URL(page.url()).origin });
    try {
        const plain = await context.newPage();
        await plain.goto('/partners/create');
        await plain.getByLabel('Név / Cégnév', { exact: true }).fill('P-NOJS-MANY Új');
        await plain.getByLabel('E-mail', { exact: true }).fill(email);
        await plain.getByRole('button', { name: 'Partner mentése', exact: true }).click();
        const region = plain.getByRole('region', { name: 'Lehetséges duplikációk', exact: true });
        await expect(region.getByRole('link')).toHaveCount(5);
        await expect(region.getByText(email, { exact: true })).toHaveCount(5);
        await expect(region.getByText('Egyező e-mail', { exact: true })).toHaveCount(5);
        await expect(region).toContainText('Az első 5 találat látható, összesen 6 egyezésből.');
        await expect(region.getByText('Egyező név', { exact: true })).toHaveCount(0);
    } finally { await context.close(); }
});

test('contact completeness distinguishes email-only phone-only empty and complete dual-role partners', async ({ page }) => {
    await create(page, 'P-CONTACT-EDGE E-mail', { type: 'both', email: 'contact-edge-only@example.test' });
    await create(page, 'P-CONTACT-EDGE Telefon', { type: 'both', phone: '123' });
    await create(page, 'P-CONTACT-EDGE Üres', { type: 'both' });
    const complete = await create(page, 'P-CONTACT-EDGE Teljes', { type: 'both', email: 'contact-edge-full@example.test', phone: '0' });
    await page.goto('/partners?search=P-CONTACT-EDGE&type=supplier');
    await page.getByLabel('Elérhetőség', { exact: true }).selectOption('complete');
    await expect(page.getByRole('option', { name: 'E-mail és telefon is megadva', exact: true })).toBeAttached();
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(1);
    await expect(page.locator('tr[data-partner-id]')).toHaveAttribute('data-partner-id', complete);
    await page.getByLabel('Elérhetőség', { exact: true }).selectOption('missing');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(3);
    await page.getByLabel('Partner típusa', { exact: true }).selectOption('customer');
    await expect(page.locator('tr[data-partner-id]')).toHaveCount(3);
    await page.setViewportSize({ width: 320, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await expect(page.locator('article[data-partner-id]')).toHaveCount(3);
});
