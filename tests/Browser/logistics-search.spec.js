import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email',{exact:true}).fill('browser@example.test');
    await page.getByLabel('Password',{exact:true}).fill('browser-test-password');
    await page.getByLabel('Password',{exact:true}).press('Enter');
    await expect(page).toHaveURL(/dashboard/);
});

for (const config of [
    { path:'/transfers', label:'Átmozgatások keresése', id:'transfer-search', origin:'from_warehouse_id', originLabel:'Forrásraktár', status:'pending', prefix:'ATM' },
    { path:'/shipments', label:'Szállítmányok keresése', id:'shipment-search', origin:'origin_warehouse_id', originLabel:'Induló raktár', status:'draft', prefix:'SZALL' },
]) {
    test(config.path+' search suggestions select an exact product and keep current filter edits', async ({ page }) => {
        await page.goto(config.path+'?search=old&product_id=2&status='+config.status+'&per_page=25&page=2&sort_by=created_at&sort_dir=asc&date_from=2020-01-01&date_to=2099-12-31');
        await page.getByLabel(config.originLabel,{exact:true}).selectOption('1');
        await page.getByLabel('Állapot',{exact:true}).selectOption('completed');
        await page.getByLabel('Dátumtól',{exact:true}).fill('2021-01-01');
        const search=page.getByRole('combobox',{name:config.label,exact:true});
        await search.fill('alpha');
        const suggestions=page.getByRole('listbox',{name:'Kereső termékjavaslatai',exact:true});
        const option=suggestions.getByRole('option',{name:'Alpha teszttermék ALPHA-001',exact:true});
        await expect(option).toBeVisible();
        await expect(suggestions.getByRole('option')).toHaveCount(1);
        await page.screenshot({path:'tests/Browser/.runtime/'+config.id+'-suggestions.png',fullPage:true});
        await option.click();
        await expect(search).toHaveValue('');
        const params=new URL(page.url()).searchParams;
        for (const [key,value] of Object.entries({product_id:'1',[config.origin]:'1',status:'completed',per_page:'25',sort_by:'created_at',sort_dir:'asc',date_from:'2021-01-01',date_to:'2099-12-31'})) expect(params.get(key)).toBe(value);
        expect(params.has('search')).toBe(false); expect(params.has('page')).toBe(false);
        await expect(page.getByRole('combobox',{name:'Termék',exact:true})).toHaveValue('Alpha teszttermék (ALPHA-001)');
    });

    test(config.path+' suggestions work with accents, multiple words, keyboard choice and mobile layout', async ({ page }) => {
        await page.setViewportSize({width:390,height:844});
        await page.goto(config.path);
        const search=page.getByRole('combobox',{name:config.label,exact:true});
        await search.fill('ekezetes');
        const suggestions=page.getByRole('listbox',{name:'Kereső termékjavaslatai',exact:true});
        await expect(suggestions.getByRole('option')).toHaveCount(8);
        const bounds=await suggestions.boundingBox();
        expect(bounds.x).toBeGreaterThanOrEqual(0); expect(bounds.x+bounds.width).toBeLessThanOrEqual(390);
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
        await search.fill('ekezetes DEMO-003');
        await expect(suggestions.getByRole('option')).toHaveCount(1);
        await search.press('ArrowDown');
        await expect(search).toHaveAttribute('aria-activedescendant',config.id+'-option-0');
        await search.press('Escape');
        await expect(search).toHaveAttribute('aria-expanded','false');
        await expect(search).toHaveValue('ekezetes DEMO-003');
        await search.press('ArrowDown');
        await search.press('Enter');
        await expect(search).toHaveValue('');
        expect(new URL(page.url()).searchParams.get('product_id')).toBe('5');
    });

    test(config.path+' Enter without a selected suggestion preserves free text and identifier search', async ({ page }) => {
        await page.goto(config.path);
        const search=page.getByRole('combobox',{name:config.label,exact:true});
        await search.fill('Alpha');
        await expect(search).toHaveAttribute('aria-expanded','true');
        await search.press('Enter');
        expect(new URL(page.url()).searchParams.get('search')).toBe('Alpha');
        expect(new URL(page.url()).searchParams.get('product_id')).toBe('');
        await search.click();
        await search.press('Tab');
        await expect(search).toHaveAttribute('aria-expanded','false');
        await search.fill(config.prefix+'-000001');
        await expect(page.getByText('Nincs egyező termékjavaslat. A megadott szövegre továbbra is kereshetsz.')).toBeVisible();
        expect(await search.evaluate(input=>input.checkValidity())).toBe(true);
        await search.press('Enter');
        expect(new URL(page.url()).searchParams.get('search')).toBe(config.prefix+'-000001');
        await expect(page.getByRole('alert')).toHaveCount(0);
    });
}
