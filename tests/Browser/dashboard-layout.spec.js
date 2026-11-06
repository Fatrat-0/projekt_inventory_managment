import { test, expect } from '@playwright/test';

const defaults = ['tasks', 'stock_summary', 'product_alerts', 'category_alerts', 'pending_transfers', 'recent_movements', 'activity'];
const ids = page => page.locator('[data-dashboard-panel]').evaluateAll(elements => elements.map(element => element.dataset.dashboardPanel));
const rows = page => page.locator('[data-layout-row]').evaluateAll(elements => elements.map(element => element.dataset.layoutRow));

async function login(page, email = 'browser@example.test') {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(/dashboard/);
}
async function reset(page) {
    await page.goto('/dashboard/customize');
    await page.getByRole('button', { name: 'Alapértelmezett elrendezés', exact: true }).click();
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/dashboard');
}

test('blocks can be toggled and reordered with keyboard, saved, and reopened in another browser session', async ({ page, browser }) => {
    await login(page);
    await reset(page);
    await page.getByRole('link', { name: 'Testreszabás', exact: true }).click();
    await page.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true }).uncheck();
    await page.getByRole('checkbox', { name: 'Kategóriahiányok megjelenítése', exact: true }).uncheck();
    const up = page.getByRole('button', { name: 'Készletmozgások diagramja feljebb', exact: true });
    await up.focus();
    await page.keyboard.press('Enter');
    expect((await rows(page))[5]).toBe('activity');
    await expect(up).toBeFocused();
    for (let index = 0; index < 5; index++) await up.click();
    expect((await rows(page))[0]).toBe('activity');
    await expect(up).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Készletmozgások diagramja lejjebb', exact: true })).toBeFocused();
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/dashboard');
    expect(await ids(page)).toEqual(['activity', 'tasks', 'stock_summary', 'pending_transfers', 'recent_movements']);
    await page.reload();
    expect((await ids(page))[0]).toBe('activity');
    await expect(page.locator('a[href="#category-alerts"]')).toHaveCount(0);
    const context = await browser.newContext();
    try {
        const other = await context.newPage();
        await login(other);
        expect(await ids(other)).toEqual(await ids(page));
    } finally { await context.close(); }
    await reset(page);
});

test('cancel keeps the saved layout, all-hidden has recovery, and reset restores every block', async ({ page }) => {
    await login(page);
    await reset(page);
    await page.goto('/dashboard/customize');
    await page.getByRole('button', { name: 'Feladatösszesítő lejjebb', exact: true }).click();
    await page.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true }).uncheck();
    await page.getByRole('link', { name: 'Mégse', exact: true }).click();
    expect(await ids(page)).toEqual(defaults);
    await page.goto('/dashboard/customize');
    for (const checkbox of await page.getByRole('checkbox').all()) await checkbox.uncheck();
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page.locator('[data-dashboard-empty]')).toBeVisible();
    expect(await ids(page)).toEqual([]);
    await page.getByRole('link', { name: 'Blokkok kiválasztása', exact: true }).click();
    await expect(page.getByRole('checkbox', { checked: true })).toHaveCount(0);
    await reset(page);
    expect(await ids(page)).toEqual(defaults);
});

test('stale tabs require reloading the newer preference and another account keeps its own layout', async ({ page, context, browser }) => {
    await login(page);
    await reset(page);
    await page.goto('/dashboard/customize');
    const stale = await context.newPage();
    await stale.goto('/dashboard/customize');
    await page.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true }).uncheck();
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await stale.getByRole('checkbox', { name: 'Kategóriahiányok megjelenítése', exact: true }).uncheck();
    const conflict = stale.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/dashboard/layout');
    await stale.getByRole('button', { name: 'Mentés', exact: true }).click();
    expect((await conflict).status()).toBe(409);
    await expect(stale.getByRole('alert')).toContainText('másik lapon');
    await expect(stale.getByRole('button', { name: 'Mentés', exact: true })).toBeDisabled();
    await expect(stale.getByRole('button', { name: 'Kategóriahiányok feljebb', exact: true })).toBeDisabled();
    await expect(stale.getByRole('button', { name: 'Alapértelmezett elrendezés', exact: true })).toBeDisabled();
    await expect(stale.locator('[data-layout-handle]:visible')).toHaveCount(0);
    await stale.getByRole('link', { name: 'Mentett elrendezés betöltése', exact: true }).click();
    await expect(stale.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true })).not.toBeChecked();
    await expect(stale.getByRole('checkbox', { name: 'Kategóriahiányok megjelenítése', exact: true })).toBeChecked();
    const adminContext = await browser.newContext();
    try {
        const admin = await adminContext.newPage();
        await login(admin, 'warehouse-admin@example.test');
        expect(await ids(admin)).toEqual(defaults);
    } finally { await adminContext.close(); }
    await stale.close();
    await reset(page);
});

test('customization preserves period and chart selection and works at phone and desktop widths', async ({ page }) => {
    await login(page);
    await reset(page);
    await page.getByLabel('Időszak', { exact: true }).selectOption('30');
    await expect(page).toHaveURL(url => url.searchParams.get('days') === '30');
    await page.getByRole('radio', { name: 'Vonaldiagram', exact: true }).check();
    await page.getByRole('link', { name: 'Testreszabás', exact: true }).click();
    await expect(page).toHaveURL(url => url.searchParams.get('days') === '30' && url.searchParams.get('chart') === 'line');
    for (const width of [320, 375, 768, 1280, 1440]) {
        await page.setViewportSize({ width, height: 740 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    }
    await page.setViewportSize({ width: 375, height: 740 });
    await page.screenshot({ path: 'tests/Browser/.runtime/dashboard-customization-mobile.png', fullPage: true });
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/dashboard' && url.searchParams.get('days') === '30' && url.searchParams.get('chart') === 'line');
    await expect(page.getByRole('radio', { name: 'Vonaldiagram', exact: true })).toBeChecked();
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/dashboard/customize');
    await page.screenshot({ path: 'tests/Browser/.runtime/dashboard-customization-desktop.png' });
});

test('visibility, native ordering and reset remain usable without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 320, height: 740 } });
    try {
        const page = await context.newPage();
        await login(page);
        await reset(page);
        await page.goto('/dashboard/customize');
        await expect(page.locator('[data-layout-handle]:visible')).toHaveCount(0);
        await page.getByRole('checkbox', { name: 'Kategóriahiányok megjelenítése', exact: true }).uncheck();
        await page.getByRole('button', { name: 'Készletmozgások diagramja feljebb', exact: true }).click();
        await expect(page).toHaveURL(url => url.pathname === '/dashboard/customize' && url.hash === '#layout-activity');
        expect((await rows(page))[5]).toBe('activity');
        await expect(page.getByRole('checkbox', { name: 'Kategóriahiányok megjelenítése', exact: true })).not.toBeChecked();
        await page.getByRole('button', { name: 'Mentés', exact: true }).click();
        expect(await ids(page)).not.toContain('category_alerts');
        await reset(page);
        expect(await ids(page)).toEqual(defaults);
    } finally { await context.close(); }
});

test('invalid order correction retains unchecked blocks and hidden pager errors remain visible', async ({ page }) => {
    await login(page);
    await reset(page);
    await page.goto('/dashboard/customize');
    for (const checkbox of await page.getByRole('checkbox').all()) await checkbox.uncheck();
    await page.route('**/dashboard/layout', route => {
        const body = new URLSearchParams(route.request().postData());
        body.delete('order[]');
        body.append('order[]', 'activity');
        return route.continue({ postData: body.toString() });
    });
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page).toHaveURL(url => url.pathname === '/dashboard/customize');
    await expect(page.getByRole('alert')).toBeVisible();
    await expect(page.getByRole('checkbox', { checked: true })).toHaveCount(0);
    expect((await rows(page))[0]).toBe('activity');
    await page.unroute('**/dashboard/layout');
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(page.locator('[data-dashboard-empty]')).toBeVisible();
    await page.goto('/dashboard?days=bad&movement_page=999');
    await expect(page.getByRole('alert')).toContainText('Az időszak nem érvényes.');
    await expect(page).toHaveURL(url => !url.searchParams.has('movement_page'));
    await reset(page);
});

test('restored customization keeps its draft and rapid repeated submits produce one save request', async ({ page }) => {
    await login(page);
    await reset(page);
    await page.goto('/dashboard/customize');
    await page.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true }).uncheck();
    await page.getByRole('button', { name: 'Készletmozgások diagramja feljebb', exact: true }).click();
    const draft = await rows(page);
    await page.evaluate(() => {
        window.Alpine.$data(document.querySelector('[x-data^="dashboardCustomization"]')).submitting = true;
        window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }));
    });
    await expect(page.getByRole('button', { name: 'Mentés', exact: true })).toBeEnabled();
    await expect(page.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true })).not.toBeChecked();
    expect(await rows(page)).toEqual(draft);
    let release;
    let completed;
    let requests = 0;
    const gate = new Promise(resolve => { release = resolve; });
    const finished = new Promise(resolve => { completed = resolve; });
    await page.route('**/dashboard/layout', async route => {
        requests++;
        try { await gate; await route.continue(); } finally { completed(); }
    });
    try {
        const disabled = await page.evaluate(async () => {
            const form = document.querySelector('form[action$="/dashboard/layout"]');
            form.requestSubmit(); form.requestSubmit();
            await window.Alpine.nextTick();
            return form.querySelector('button[type="submit"]').disabled;
        });
        await expect.poll(() => requests).toBe(1);
        expect(disabled).toBe(true);
        release();
        await expect(page).toHaveURL(url => url.pathname === '/dashboard');
        expect(requests).toBe(1);
        expect(await ids(page)).not.toContain('product_alerts');
    } finally { release(); if (requests) await finished; await page.unroute('**/dashboard/layout'); }
    await reset(page);
});

test('invalid revision requires loading saved preferences before the draft can be saved', async ({ page, context }) => {
    await login(page);
    await reset(page);
    await page.goto('/dashboard/customize');
    const stale = await context.newPage();
    await stale.goto('/dashboard/customize');
    await page.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true }).uncheck();
    await page.getByRole('button', { name: 'Mentés', exact: true }).click();
    await stale.locator('input[name="version"]').evaluate(input => { input.value = 'bad'; });
    await stale.getByRole('button', { name: 'Mentés', exact: true }).click();
    await expect(stale.getByText('A mentés verzióadata hiányos vagy hibás.', { exact: true })).toBeVisible();
    await expect(stale.getByRole('button', { name: 'Mentés', exact: true })).toBeDisabled();
    await expect(stale.getByRole('button', { name: 'Feladatösszesítő lejjebb', exact: true })).toBeDisabled();
    await stale.getByRole('link', { name: 'Mentett elrendezés betöltése', exact: true }).click();
    await expect(stale.getByRole('checkbox', { name: 'Termékhiányok megjelenítése', exact: true })).not.toBeChecked();
    await expect(stale.getByRole('button', { name: 'Mentés', exact: true })).toBeEnabled();
    await stale.close();
    await reset(page);
});

test('large text keeps customization labels clear of reorder buttons on a narrow screen', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 740 });
    await login(page);
    await reset(page);
    await page.goto('/dashboard/customize');
    await page.evaluate(() => { document.documentElement.style.fontSize = '24px'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const clear = await page.locator('[data-layout-row]').evaluateAll(elements => elements.every(row => {
        const range = document.createRange();
        range.selectNodeContents(row.querySelector('label > span'));
        const button = row.querySelector('button').getBoundingClientRect();
        return row.querySelector('button:last-child').getBoundingClientRect().right <= window.innerWidth
            && Array.from(range.getClientRects()).every(rect => rect.bottom <= button.top + 1 || rect.right <= button.left + 1);
    }));
    expect(clear).toBe(true);
    const headerClear = await page.locator('[data-app-topbar]').evaluate(header => {
        const range = document.createRange();
        range.selectNodeContents(header.querySelector('[aria-current="page"]'));
        const account = header.querySelector('[data-account-menu]').getBoundingClientRect();
        return Array.from(range.getClientRects()).every(rect => rect.right <= account.left + 1 && rect.bottom <= header.getBoundingClientRect().bottom);
    });
    expect(headerClear).toBe(true);
    await page.screenshot({ path: 'tests/Browser/.runtime/dashboard-customization-large-text.png', fullPage: true });
});
