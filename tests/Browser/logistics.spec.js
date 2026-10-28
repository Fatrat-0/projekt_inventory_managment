import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    page.on('dialog', dialog => dialog.accept());
    await page.goto('/login');
    await page.getByLabel('Email', { exact: true }).fill('browser@example.test');
    await page.getByLabel('Password', { exact: true }).fill('browser-test-password');
    await page.getByLabel('Password', { exact: true }).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});
async function fillShipment(page, reference) {
    await page.getByLabel('Szállítmány hivatkozása', { exact: true }).fill(reference);
    await page.getByRole('combobox', { name: 'Induló raktár', exact: true }).fill('Teszt raktár');
    await page.getByRole('listbox', { name: 'Raktártalálatok', exact: true }).getByRole('option', { name: 'Teszt raktár', exact: true }).click();
    const stop=page.locator('fieldset').first();
    await stop.getByLabel('Célraktár', { exact: true }).selectOption('2');
    await stop.getByLabel('Termék', { exact: true }).selectOption('1');
    await stop.getByLabel('Mennyiség (db)', { exact: true }).fill('1');
}
async function createShipment(page, reference, secondStop = false) {
    await page.goto('/shipments/create'); await fillShipment(page,reference);
    if (secondStop) {
        await page.getByRole('button',{name:'+ Megálló hozzáadása',exact:true}).click();
        const stop=page.locator('fieldset').nth(1);
        await stop.getByLabel('Célraktár',{exact:true}).selectOption('3');
        await stop.getByLabel('Termék',{exact:true}).selectOption('2');
    }
    await page.getByRole('button',{name:'Tervezet mentése',exact:true}).click();
    await expect(page).toHaveURL(/\/shipments\/\d+$/);
}

test('shipment status cards preserve filters, reset page and show receipt progress and next destination', async ({ page }) => {
    await createShipment(page,'E2E-LISTA-A');
    await createShipment(page,'E2E-LISTA-B',true);
    await page.getByRole('button',{name:'Szállítmány indítása',exact:true}).click();
    await page.goto('/shipments?search=E2E-LISTA&per_page=25&page=2');
    const states=page.getByRole('navigation',{name:'Állapotgyorsszűrők'});
    await expect(states.getByRole('link',{name:'Összes 2',exact:true})).toBeVisible();
    await expect(states.getByRole('link',{name:'Tervezet 1',exact:true})).toBeVisible();
    await states.getByRole('link',{name:'Úton 1',exact:true}).click();
    const params=new URL(page.url()).searchParams;
    expect(params.get('status')).toBe('in_transit'); expect(params.get('search')).toBe('E2E-LISTA'); expect(params.get('per_page')).toBe('25'); expect(params.has('page')).toBe(false);
    await expect(page.getByRole('progressbar',{name:'Átvett megállók'})).toHaveAttribute('aria-valuenow','0');
    await page.getByRole('link').filter({has:page.getByRole('heading',{name:'E2E-LISTA-B',exact:true})}).click();
    await page.getByRole('button',{name:'Megálló átvétele',exact:true}).click();
    await page.getByRole('link',{name:'← Szállítmányok',exact:true}).click();
    await expect(page.getByRole('progressbar',{name:'Átvett megállók'})).toHaveAttribute('aria-valuenow','1');
    await expect(page.getByText('Következő: Déli tesztraktár',{exact:true})).toBeVisible();
    await page.screenshot({path:'tests/Browser/.runtime/shipment-operation-overview.png',fullPage:true});
});

test('shipment list context survives create cancellation, saving, editing and return', async ({ page }) => {
    const url='/shipments?search=E2E-KONTEXT&status=draft&sort_by=reference&sort_dir=asc&per_page=25&page=2&date_from=2020-01-01&date_to=2099-12-31';
    await page.goto(url);
    await page.getByRole('link',{name:'+ Új rakodási terv',exact:true}).click();
    await page.getByRole('link',{name:'Mégse',exact:true}).click();
    expect(new URL(page.url()).searchParams.get('page')).toBe('2');
    await page.getByRole('link',{name:'+ Új rakodási terv',exact:true}).click();
    await fillShipment(page,'E2E-KONTEXT');
    await page.getByRole('button',{name:'Tervezet mentése',exact:true}).click();
    await page.getByRole('link',{name:'Terv szerkesztése',exact:true}).click();
    await page.getByLabel('Megjegyzés (opcionális)').fill('A szűrt listából szerkesztve.');
    await page.getByRole('button',{name:'Tervezet mentése',exact:true}).click();
    await page.getByRole('link',{name:'← Szállítmányok',exact:true}).click();
    const returned=new URL(page.url()).searchParams;
    for (const [key,value] of new URL(url,'http://localhost').searchParams) expect(returned.get(key)).toBe(value);
});

test('shipment quick dates submit pending filter edits and custom dates fit mobile', async ({ page }) => {
    await createShipment(page,'E2E-DATUM');
    await page.setViewportSize({width:390,height:844});
    await page.goto('/shipments?search=previous&sort_by=reference&sort_dir=asc&per_page=25&page=2');
    await page.getByRole('combobox',{name:'Szállítmányok keresése'}).fill('E2E-DATUM');
    await page.getByLabel('Induló raktár',{exact:true}).selectOption('1');
    await page.getByLabel('Célraktár',{exact:true}).selectOption('2');
    await page.getByRole('combobox',{name:'Termék',exact:true}).fill('ALPHA');
    await page.getByRole('listbox',{name:'Terméktalálatok',exact:true}).getByRole('option',{name:'Alpha teszttermék (ALPHA-001)',exact:true}).click();
    const presets=page.getByRole('group',{name:'Gyors dátumszűrők'});
    await presets.getByRole('link',{name:'Elmúlt 7 nap',exact:true}).click();
    const params=new URL(page.url()).searchParams;
    for (const [key,value] of Object.entries({search:'E2E-DATUM',origin_warehouse_id:'1',destination_warehouse_id:'2',product_id:'1',sort_by:'reference',sort_dir:'asc',per_page:'25'})) expect(params.get(key)).toBe(value);
    expect(params.has('page')).toBe(false);
    await expect(presets.getByRole('link',{name:'Elmúlt 7 nap',exact:true})).toHaveAttribute('aria-current','true');
    await expect(page.getByRole('heading',{name:'E2E-DATUM',exact:true})).toBeVisible();
    await page.getByLabel('Dátumtól',{exact:true}).fill('2020-01-01');
    await page.getByLabel('Dátumig (beleértve)',{exact:true}).fill('2020-01-02');
    await page.getByRole('button',{name:'Szűrés alkalmazása',exact:true}).click();
    await expect(page.getByText('Nincs a feltételeknek megfelelő szállítmány.',{exact:true})).toBeVisible();
    await expect(presets.locator('[aria-current]')).toHaveCount(0);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.screenshot({path:'tests/Browser/.runtime/shipment-overview-mobile.png',fullPage:true});
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
    await expect(page.getByText('Nincs a feltételeknek megfelelő átmozgatás.',{exact:true})).toBeVisible();
    await expect(states.getByRole('link',{name:'Úton 1',exact:true})).toBeVisible();
});
