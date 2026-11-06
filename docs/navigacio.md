# Oldalsávos navigáció

A bejelentkezett felület közös elrendezése az AppLayout komponensben található. Nagy, legalább 1280 pixel széles ablakban 240 pixeles oldalsáv jelenik meg, amely az ablak magasságán belül önállóan görgethető. A kapcsológomb teljesen elrejti az oldalsávot, így a tartalom visszakapja a helyét. A böngésző helyi tárában az inventory.sidebar.collapsed kulcs tárolja a választást; letiltott vagy hibás tároló esetén is használható a kapcsoló.

Az AppNavigation a meglévő szerveroldali jogosultságokat követő csoportokat állítja össze. Az adminisztrátor és a manager kilenc menüpontot lát, a raktáros hatot. A kategória-, raktár- és felhasználókezelés hivatkozása csak az első két szerepkörnél jelenik meg. A menü nem helyettesíti a route middleware ellenőrzését, azon nem változtat.

- Áttekintés: Vezérlőpult.
- Készletkezelés: Készlet, Készletmozgások, Átmozgatások.
- Törzsadatok: Termékek, Kategóriák, Raktárak, Partnerek.
- Adminisztráció: Felhasználók.

A szállítmányok az Átmozgatások, a visszáru-minősítési munkalista a Készletmozgások alá tartozik. A menü a fő szakaszt jelöli aktívként az adatlapokon, szerkesztésnél és új rekord létrehozásánál is. A felső sáv morzsaútvonala jelzi a pontosabb helyet, a szülőoldalak hivatkozásai megnyithatók. Mobilon az aktuális, utolsó elem marad látható.

1280 pixel alatt a menü natív dialog elemben, a tartalom fölött nyílik meg. A háttér ilyenkor nem kezelhető és nem görgethető; a Tab és Shift+Tab a menün belül marad. Az Escape, a bezárógomb és a háttérre kattintás bezárja a menüt, a fókusz visszatér a kapcsolóhoz. Nagy képernyőre átméretezés bezárja a mobilmenüt és visszaállítja a görgetést. Gyors újranyitásnál a korábbi close esemény nem oldhatja fel az új modalitást. Visszaállított böngészőoldalon a menük bezáródnak, az űrlap adatai megmaradnak.

JavaScript nélkül a nagy képernyős menü alapból látható, kis képernyőn natív details vezérlő nyitja ugyanazokat a hivatkozásokat. A fiókmenü szintén natív details elem; minden méreten elérhető a Profil és a CSRF-védett POST Kijelentkezés. A tartalomra ugró hivatkozás billentyűzetes fókusznál válik láthatóvá.

Az oldalak saját keresői, szűrői, fő műveletei és tartalma a munkaterületen maradnak. A régi vízszintes főmenü és annak külön mobilváltozata megszűnt. A készlet- és felhasználótáblák keskeny munkaterületen vízszintesen görgethetők.

A tartalomra és a vezérlőpult paneljeire ugró hivatkozások célpontját nem takarja el a rögzített felső sáv. Ha átméretezés közben a fókusz az eltűnő asztali menüben volt, a menükapcsolóra kerül. A régi menü már nem használt nav-link, responsive-nav-link, dropdown és dropdown-link komponensei törölve lettek.

A nagy képernyős oldalsáv elrejtőgombja a saját fejlécében, a név mellett található. A felső sávban csak elrejtett oldalsáv esetén jelenik meg a visszanyitó gomb; mobilon ez továbbra is a menü megnyitására szolgál. Váltáskor a fókusz az éppen látható megfelelő vezérlőre kerül.

## Ellenőrzés

Az AppNavigationTest a szerepkörönként engedélyezett, ténylegesen megnyitható menühivatkozásokat, az aktív főszakaszt és a morzsaútvonalakat, a felhasználói szövegek escapingjét és a POST kijelentkezést ellenőrzi. A navigation.spec.js a többméretes elrendezést, natív modalitást, billentyűzetes fókuszt, rövid képernyők menügörgetését, átméretezést, helyi tároló hibáját, JavaScript nélküli működést és az űrlapadatok megőrzését vizsgálja.

Az együttes böngészőfuttatás miatt a JavaScript nélküli szerkesztési teszt saját raktárat hoz létre a közös mintaraktár átnevezése helyett. A megállók egyediségének tesztje az aktuális célraktár-készletet vagy a húsz megállós korlátot meríti ki, ezért további tesztraktárak jelenlétében is ellenőrzi a hozzáadás letiltását.

```powershell
php artisan test --filter=AppNavigationTest
npm run test:browser -- navigation.spec.js
```
