import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('warehouse-admin@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});
test('transfer identifier, quick dates and status cards retain exact results and detail context', async ({ page }) => {
    await page.goto('/transfers/create');
    for (const [name,query,option] of [['Termék','ALPHA','Alpha teszttermék (ALPHA-001)'],['Forrásraktár','Teszt raktár','Teszt raktár · Test'],['Célraktár','Északi','Északi tesztraktár · Bemutató célállomás']]) {
        await page.getByRole('combobox',{name,exact:true}).fill(query);
        await page.getByRole('option',{name:option,exact:true}).click();
    }
    await page.getByRole('button',{name:'Átmozgatás indítása',exact:true}).click();
    const number=(await page.getByRole('status').textContent()).match(/ATM-\d+/)[0];
    await page.goto('/transfers?search='+number.toLowerCase()+'&per_page=25&page=2');
    await page.getByRole('group',{name:'Gyors dátumszűrők'}).getByRole('link',{name:'Elmúlt 7 nap',exact:true}).click();
    const states=page.getByRole('navigation',{name:'Állapotgyorsszűrők'});
    await states.getByRole('link',{name:'Úton 1',exact:true}).click();
    expect(new URL(page.url()).searchParams.has('page')).toBe(false);
    await expect(page.getByRole('link',{name:'Alpha teszttermék',exact:true})).toHaveCount(1);
    await page.getByRole('link',{name:'Alpha teszttermék',exact:true}).click();
    await expect(page.getByRole('heading',{name:number,exact:true})).toBeVisible();
    await page.getByRole('link',{name:'← Átmozgatások',exact:true}).click();
    const params=new URL(page.url()).searchParams;
    expect(params.get('search')).toBe(number.toLowerCase()); expect(params.get('date_from')).toBeTruthy(); expect(params.get('status')).toBe('pending');
    await states.getByRole('link',{name:'Megérkezett 0',exact:true}).click();
    await expect(page.getByRole('status').filter({hasText:'Nincs a feltételeknek megfelelő átmozgatás.'})).toBeVisible();
    await expect(states.getByRole('link',{name:'Úton 1',exact:true})).toBeVisible();
});
