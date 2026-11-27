import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

async function login(page, email, password, remember = false, first = false) {
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    if (remember) await page.getByLabel('Remember me').check();
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).toHaveURL(first ? /\/elso-bejelentkezes$/ : /\/dashboard$/);
}

test('admin can revoke open and remembered sessions and reactivation requires a new login', async ({ page, browser }) => {
    await login(page, 'warehouse-admin@example.test', 'browser-test-password');
    await page.goto('/users/create');
    const name = 'Próba hozzáférés';
    const email = 'access-'+randomUUID()+'@example.test';
    const temporary = 'test-only-access-password';
    const password = 'replacement-access-password';
    await page.locator('[name=name]').fill(name);
    await page.locator('[name=email]').fill(email);
    await page.getByRole('radio', { name: 'Ideiglenes jelszó' }).check();
    await page.locator('[name=password]').fill(temporary);
    await page.locator('[name=password_confirmation]').fill(temporary);
    await page.getByRole('button', { name: 'Felhasználó Létrehozása' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Mentés megerősítése' }).click();
    await expect(page).toHaveURL(/\/users$/);
    const account = page.locator('[data-user-id]:visible').filter({ hasText: email });
    await expect(account.getByText('Aktív', { exact: true })).toBeVisible();

    const worker = await browser.newContext();
    const secondDevice = await browser.newContext();
    try {
        const workerPage = await worker.newPage();
        await login(workerPage, email, temporary, false, true);
        expect((await worker.request.get('/stock', { maxRedirects: 0 })).headers().location).toMatch(/\/elso-bejelentkezes$/);
        await workerPage.locator('[name=current_password]').fill(temporary);
        await workerPage.locator('[name=password]').fill(password);
        await workerPage.locator('[name=password_confirmation]').fill(password);
        await workerPage.getByRole('button', { name: 'Jelszó mentése' }).click();
        await expect(workerPage).toHaveURL(/\/dashboard$/);
        await worker.clearCookies();
        await login(workerPage, email, password, true);
        await login(await secondDevice.newPage(), email, password);
        const oldSession = await worker.storageState();
        const rememberCookies = oldSession.cookies.filter(cookie => cookie.name.startsWith('remember_web_'));
        expect(rememberCookies).toHaveLength(1);
        expect((await worker.request.get('/users')).status()).toBe(403);

        await account.getByRole('button', { name: /^Műveletek:/ }).click();
        await account.getByRole('menuitem', { name: 'Inaktiválás', exact: true }).click();
        const inactivation = page.getByRole('dialog', { name: 'Inaktiválás', exact: true });
        await expect(inactivation).toContainText(email);
        await expect(inactivation).toContainText('Raktáros');
        await expect(inactivation).toContainText('a meglévő munkamenetek megszűnnek');
        await inactivation.getByRole('button', { name: 'Fiók inaktiválása' }).click();
        await expect(page).toHaveURL(/\/users\/\d+$/);
        await expect(page.getByText('A fiókot közben módosították. Nézd át az aktuális adatokat.', { exact: true })).toBeVisible();
        await expect(page.getByText('Aktív', { exact: true })).toBeVisible();
        await page.getByRole('link', { name: '← Felhasználók', exact: true }).click();
        await account.getByRole('button', { name: /^Műveletek:/ }).click();
        await account.getByRole('menuitem', { name: 'Inaktiválás', exact: true }).click();
        await inactivation.getByRole('button', { name: 'Fiók inaktiválása' }).click();
        await expect(account.getByText('Inaktív', { exact: true })).toBeVisible();
        await workerPage.reload();
        await expect(workerPage).toHaveURL(/\/login$/);

        await account.getByRole('button', { name: /^Műveletek:/ }).click();
        await account.getByRole('menuitem', { name: 'Újraaktiválás', exact: true }).click();
        const reactivation = page.getByRole('dialog', { name: 'Újraaktiválás', exact: true });
        await expect(reactivation).toContainText('A helyi raktári műveletekhez tagság szükséges');
        await reactivation.getByRole('button', { name: 'Fiók újraaktiválása' }).click();
        await expect(account.getByText('Aktív', { exact: true })).toBeVisible();
        const oldDevice = await secondDevice.request.get('/dashboard', { maxRedirects: 0 });
        expect(oldDevice.headers().location).toMatch(/\/login$/);
        const stale = await browser.newContext({ storageState: { cookies: rememberCookies, origins: [] } });
        try {
            const result = await stale.request.get('/dashboard', { maxRedirects: 0 });
            expect(result.headers().location).toMatch(/\/login$/);
        } finally {
            await stale.close();
        }
        await login(workerPage, email, password, true);
        await expect(workerPage.getByRole('heading', { name: 'Vezérlőpult', exact: true })).toBeVisible();
    } finally {
        await Promise.all([worker.close(), secondDevice.close()]);
    }
});
