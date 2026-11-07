# Oldalsávos navigáció

A bejelentkezett felület közös elrendezése az AppLayout komponensben található. Nagy, legalább 1280 pixel széles ablakban 240 pixeles oldalsáv jelenik meg, amely az ablak magasságán belül önállóan görgethető. A kapcsológomb teljesen elrejti az oldalsávot, így a tartalom visszakapja a helyét. A böngésző helyi tárában az inventory.sidebar.collapsed kulcs tárolja a választást; letiltott vagy hibás tároló esetén is használható a kapcsoló.

Az AppNavigation a meglévő szerveroldali jogosultságokat követő csoportokat állítja össze. Az adminisztrátor és a manager kilenc menüpontot lát, a raktáros hatot. A kategória-, raktár- és felhasználókezelés hivatkozása csak az első két szerepkörnél jelenik meg. A menü nem helyettesíti a route middleware ellenőrzését, azon nem változtat.

- Áttekintés: Vezérlőpult.
- Készletkezelés: Készlet, Készletmozgások, Átmozgatások.
- Törzsadatok: Termékek, Kategóriák, Raktárak, Partnerek.
- Adminisztráció: Felhasználók.

A szállítmányok az Átmozgatások, a visszáru-minősítési munkalista a Készletmozgások alá tartozik. A menü a fő szakaszt jelöli aktívként az adatlapokon, szerkesztésnél és új rekord létrehozásánál is. Az aktuális oldal címe a munkaterület egyetlen H1 címsorában jelenik meg. A böngésző lapcíme tartalmazza az útvonal szakaszait; a felső sávban ezek nem ismétlődnek. Az űrlapok és adatlapok saját visszahivatkozása megőrzi a szűrt listakörnyezetet; a régi fejlécslotot használó termék- és felhasználói űrlapok külön szülőhivatkozást kapnak.

1280 pixel alatt a menü natív dialog elemben, a tartalom fölött nyílik meg. A háttér ilyenkor nem kezelhető és nem görgethető; a Tab és Shift+Tab a menün belül marad. Az Escape, a bezárógomb és a háttérre kattintás bezárja a menüt, a fókusz visszatér a kapcsolóhoz. Nagy képernyőre átméretezés bezárja a mobilmenüt és visszaállítja a görgetést. Gyors újranyitásnál a korábbi close esemény nem oldhatja fel az új modalitást. Visszaállított böngészőoldalon a menük bezáródnak, az űrlap adatai megmaradnak.

JavaScript nélkül a nagy képernyős menü alapból látható, kis képernyőn natív details vezérlő nyitja ugyanazokat a hivatkozásokat. A fiókmenü szintén natív details elem; minden méreten elérhető a Profil és a CSRF-védett POST Kijelentkezés. A tartalomra ugró hivatkozás billentyűzetes fókusznál válik láthatóvá.

Az oldalak saját keresői, szűrői, fő műveletei és tartalma a munkaterületen maradnak. A régi vízszintes főmenü és annak külön mobilváltozata megszűnt. A listák keskeny kijelzőn kártyákká rendeződnek; asztali táblázatnál a széles tartalom a táblázat régióján belül görgethető.

A tartalomra és a vezérlőpult paneljeire ugró hivatkozások célpontját nem takarja el a rögzített felső sáv. Ha átméretezés közben a fókusz az eltűnő asztali menüben volt, a menükapcsolóra kerül. A régi menü már nem használt nav-link, responsive-nav-link, dropdown és dropdown-link komponensei törölve lettek.

A nagy képernyős oldalsáv elrejtőgombja a saját fejlécében, a név mellett található. A felső sávban csak elrejtett oldalsáv esetén jelenik meg a visszanyitó gomb; mobilon ez továbbra is a menü megnyitására szolgál. Váltáskor a fókusz az éppen látható megfelelő vezérlőre kerül.

## Ellenőrzés

### Egységes listanézetek

A termékek, készletek, készletmozgások, átmozgatások, szállítmányok, partnerek, kategóriák, raktárak, minősítési munkalista és felhasználók listáján közös listing-view-toggle komponens választja a táblázatos vagy kártyás megjelenítést. A kapcsoló a felső, oldalhoz tartozó műveletek végén található. A gombok címkézettek, billentyűzettel használhatók, az aktív állapotot szín és aria-pressed jelzi. A vezérlőpult, űrlapok és adatlapok saját megjelenítése megmarad.

A közös listingView Alpine-komponens az inventory.<oldal>.view kulcsban tárolja a választást. Az oldalak beállítása független; a terméklista korábbi productViewMode list/kanban értéke első alkalommal továbbra is érvényes. Az oszlopbeállítás ettől független. Érvénytelen vagy hozzáférhetetlen tároló esetén az alapnézet működik. A szállítmányok alapból kártyásak, a többi lista táblázatos. A váltás nem indít lekérést és nem módosítja a szűrőket, keresőtervezetet vagy lapozást; a nyitott műveletmenük bezárulnak. Az üzleti műveletek mindkét nézetben ugyanazokat a szerveroldali végpontokat és kontextust használják.

768 pixel alatt minden listán kártyák láthatók, a kapcsoló elrejtve marad. Az átméretezés nem írja felül az asztali választást. A kártyarács a rendelkezésre álló szélességhez igazítja az oszlopszámot, az üres állapot teljes szélességű. Kártyanézetben a rendezési vezérlők is elérhetők; táblázatnál a rendezhető fejléc használható. JavaScript nélkül az alapnézet és a mobilos kártyák működnek, kapcsológombok nem láthatók. Új migráció vagy függőség nem szükséges.

A listing-views.spec.js mind a tíz listán ellenőrzi a nézetváltást, rekordok sorrendjét, választás megőrzését, mobilos elrendezést és a JavaScript nélküli működést. Külön vizsgálja a tároló tiltását, a terméklista régi beállítását, az oldalak függetlenségét, a kártyás rendezés szűrési kontextusát, az átmozgatás átvételét, az üres találati listákat és a 150%-os betűméretet. Futtatás: npm run test:browser -- listing-views.spec.js stock-views.spec.js.

### Táblázati oszlopok

Mind a tíz lista ugyanazt a táblázati oszlopválasztót használja. A műveleti oszlop fejlécében található ikonos gomb natív popover panelt nyit; az oszlopbeállító nem foglal helyet a felső műveletsávban. A készletmozgásoknál és szállítmányoknál nincs külön műveleti oszlop, ezért ott a mindig látható termék-, illetve hivatkozásfejlécben található. A jelölőnégyzetek azonnal, lekérés nélkül kapcsolják együtt a fejlécet, az adatsorokat és a táblázati összesítőt. A név vagy hivatkozás és a műveletek rögzítetten láthatók, így nem állítható elő üres vagy használhatatlan táblázat. Az üres találati sor colspan értéke a látható oszlopokhoz igazodik; a termékoldali összesítő felirata a cikkszám elrejtésekor is megmarad.

A ListingColumns a szerveroldali oszlopdefiníciókat tartalmazza; a közös listingView az inventory.<oldal>.columns kulcsban menti a beállítást. Csak ismert kulcsok logikai értékei érvényesek, a kötelező oszlopokat sérült beállítás sem rejtheti el. A terméklista régi productColumns beállítása új beállítás hiányában továbbra is érvényes. Az Alapbeállítások visszaállítása minden oszlopot megjelenít, és az új választást menti. Tiltott tároló esetén a kapcsolás működik, a következő betöltés az alapértékeket használja.

A panel Tab és Space billentyűkkel kezelhető, Escape, külső kattintás, nézetváltás vagy ablakméret-váltás bezárja. Rövid ablakban önállóan görgethető. Oszlopok kapcsolása és visszaállítása után a panel követi az elmozduló fejlécgombot; szükség esetén a táblázat vízszintesen odagörget, így a kapcsoló elérhető marad. Kártyanézetben és mobilon nincs oszlopválasztó; a kártyák tartalmát és a PDF beállításait nem változtatja. A rejtett oszlopra beállított rendezés megmarad. A minimumszerkesztőben beírt érték elrejtés és visszanyitás után is megmarad. JavaScript nélkül minden alapértelmezett oszlop látható, működés nélküli beállítógomb nincs.

A listing-columns.spec.js külön vizsgálja mind a tíz tábla oszlopainak együtt változását, az alaphelyzetet, a régi és hibás beállításokat, a minimum tervezetét, az üres sorok szélességét, a billentyűzetes használatot és a nagyított betűméretet. Futtatás: npm run test:browser -- listing-columns.spec.js listing-views.spec.js stock-views.spec.js.

### Egységes munkaterület és felső sáv

A listaoldalak és a vezérlőpult app-page konténere a teljes, oldalsáv mellett rendelkezésre álló szélességet használja, 640 pixel alatt 16, fölötte 24 pixeles belső oldaltávolsággal. Nincs 1280 pixeles tartalmi szélességkorlát. Elrejtett oldalsávnál a felszabaduló helyet is felveszi. A beviteli űrlapok és a vezérlőpult testreszabója megtartják a saját olvasható szélességüket. A közös page-toolbar komponens az oldalcímet, találatszámot, leírást és az adott oldal műveleteit rendezi el; a termékek és készletmozgások összetett keresős vezérlősávja ugyanezt a munkaterületet használja.

A globális gyorskereső a felső sáv jobb oldalán, a feladatjelző és a fiókmenü mellett található. Keskeny kijelzőn ikon, 1024 pixeltől szöveg és Ctrl+K jelzés is látható. A sáv alapmagassága 56 pixel, az oldalsáv fejléce ehhez igazodik. Nagyított betűméretnél a vezérlők több sorba törhetnek. A ResizeObserver az aktuális fejlécmagassághoz igazítja a horgonycélok távolságát, így a rögzített sáv nem takarja el a megnyitott panelt.

A listaoldalak és a vezérlőpult 16 pixeles függőleges térközt használnak. A helyi fejléc egyszer mutatja az oldal nevét, a találatszámot és az oldal műveleteit; az általános bevezető szövegek kikerültek, a készletfogalmakat és minősítési folyamatot tisztázó leírások megmaradtak. A szállítási fülek az oldalcím alá kerültek. A termék- és felhasználói űrlapok, valamint a profil korábbi 48 pixeles felső térköze 16 pixelre csökkent. A compact-layout.spec.js külön ellenőrzi az egyszeri címet, a kereső elhelyezését, a keskeny és nagyított nézeteket, a gyorsbillentyűket és a visszalépést.

A globális gyorskereső a felső sávból vagy Ctrl+K / Command+K billentyűvel nyílik, natív dialog elemként. A Tab a párbeszédablakon belül marad, a nyilak választanak, Enter megnyitja a kiválasztott találatot, Escape bezár. Kiválasztás nélküli Enter a teljes keresőoldalt nyitja. Bezáráskor visszatér a fókusz és helyreáll a korábbi görgetési állapot; az alatta lévő űrlap vázlata megmarad. Megnyitott mobilmenü mellett a gyorsbillentyű nem indít újabb modális ablakot.

A GET /search (app-search.index) hitelesített, ellenőrzött munkamenetben olvasható. Üres keresésnél az engedélyezett főoldalakat mutatja; szövegnél a megfelelő oldalak mellett legfeljebb nyolc terméket ad név és cikkszám alapján. Minden keresőszónak egyeznie kell, a magyar ékezetek és kis-/nagybetűk egységesen kezeltek MySQL-en és SQLite-on; a LIKE speciális jelei szó szerint kereshetők. További termékekhez a szövegre szűrt terméklista nyitható. A főoldalak az AppNavigation jogosultságai szerint kerülnek be; a kereső nem ad szerkesztési jogosultságot.

A kliens gépeléskor 200 ms várakozással keres, a korábbi lekérést megszakítja és az elavult választ eldobja. Bezárás megszakítja a folyamatban lévő kérést; újranyitás az aktuális keresőszöveget újra lekéri. Hálózati hibánál újrapróbálás és a teljes keresőoldal is elérhető. A /search HTML-változata JavaScript nélkül ugyanazokat a célokat biztosítja. Hibás HTML-keresés egyszeri átirányítással helyreáll, JSON-kérésnél 422-es mezőhiba érkezik. A válasz no-store, private fejlécet kap.

A feladatjelző a pending átmozgatások tételszámát (a szállítmányhoz tartozókat is), valamint a még feldolgozatlan vevői visszáruk tételszámát összegzi. A visszáru darabszáma külön jelenik meg; a teljesen feldolgozott visszáru nem számít nyitottnak. A jelvény 99 fölött 99+-t mutat, az akadálymentes megnevezés a teljes darabszámot tartalmazza. Az adat az oldal betöltésekor aktuális, külön értesítési vagy háttérpolling-rendszer nincs. A natív popover menü a megfelelő szűrt átmozgatási listát és minősítési munkalistát nyitja. JavaScript nélkül a böngésző középre helyezett menüje használható. A menü és a kereső megnyitása nem módosít üzleti adatot.

A globális terméktalálatok és a készletoldali termékhivatkozások valódi, olvasható termékadatlapot nyitnak a korábban üres products.show útvonal helyett. Az adatlap az alapadatokat és a minden raktárra összesített fizikai/kiadható/elkülönített készletet mutatja; innen a szerkesztés, termékre szűrt készletlista, mozgásnapló és szükség esetén nyitott minősítések érhetők el. Hiányzó termék 404-es választ ad.

Az AppWorkspaceTest az engedélyezett találatokat, magyar ékezeteket, szó szerinti keresést, eredménykorlátot, feladatszámlálást, adatok változatlanságát, termékadatlapot, hibás bemenetet és az oldalak közös eszközsávját ellenőrzi. A workspace.spec.js 1920 és 2560 pixeles munkaterületet, összecsukott oldalsávot, keskeny űrlapokat, billentyűzetet, keresési versenyhelyzetet, hibából helyreállást, 320 pixeles nagybetűs nézetet és JavaScript nélküli használatot is vizsgál. A MySQL-futtató a kereső ékezetes/szó szerinti találatait és a feladatszámokat is ellenőrzi. Új migráció és új npm-csomag nem szükséges.

Az AppNavigationTest a szerepkörönként engedélyezett, ténylegesen megnyitható menühivatkozásokat, az aktív főszakaszt, a böngésző lapcímét és az egyszeri oldalcímet, a felhasználói szövegek escapingjét és a POST kijelentkezést ellenőrzi. A navigation.spec.js a többméretes elrendezést, natív modalitást, billentyűzetes fókuszt, rövid képernyők menügörgetését, átméretezést, helyi tároló hibáját, JavaScript nélküli működést és az űrlapadatok megőrzését vizsgálja.

Az együttes böngészőfuttatás miatt a JavaScript nélküli szerkesztési teszt saját raktárat hoz létre a közös mintaraktár átnevezése helyett. A megállók egyediségének tesztje az aktuális célraktár-készletet vagy a húsz megállós korlátot meríti ki, ezért további tesztraktárak jelenlétében is ellenőrzi a hozzáadás letiltását.

```powershell
php artisan test --filter=AppNavigationTest
npm run test:browser -- navigation.spec.js
```
