# Készletlista

A `/stock` oldal egy sora egy termék egy raktárban nyilvántartott készletét jelenti. Ugyanaz a termék több raktárban több soron szerepelhet. A nulla fizikai mennyiségű készletsorok alapból látszanak; készletsor nélküli termékek nem keletkeztetnek mesterséges raktári sorokat. Új migráció, seeder vagy npm-csomag nem szükséges.

## Keresés és szűrés

A szöveges keresés minden szavát megköveteli. A szavak eltérő mezőkben is szerepelhetnek: terméknév, cikkszám, közvetlen kategórianév, raktárnév vagy raktárcím. A `%`, `_` és `!` jelek szó szerint kereshetők. A kereső fölötti böngészős súgó ezt röviden ismerteti.

Gépeléskor a közös listing-search komponens legfeljebb nyolc termékjavaslatot mutat. Csak a raktár-, kategória- és készletállapot-szűrésnek megfelelő készletsorral rendelkező termékeket ajánlja, termékenként egyszer. A javaslat kiválasztása pontos product_id szűrést alkalmaz és törli a szabad szöveges keresést. A mező szerkesztése törli a korábbi pontos azonosítót; Enter kijelölt javaslat nélkül szöveges keresést indít. A termékjavaslatok könnyű, név–SKU–kategória referenciaadatok; a készletsorok és kapcsolataik lapozva töltődnek be.

A kategóriaszűrés az összes alkategóriát is tartalmazza. A CategoryTree a ciklikus régi adatokat is véges bejárással kezeli. A raktár-, termék-, kategória-, állapot- és szöveges szűrés együtt alkalmazható. Az aktív szűrők külön eltávolíthatók; az összes szűrő törlése megtartja a rendezést és az oldalméretet.

| Állapot | Feltétel |
| --- | --- |
| Minden készletsor | Nulla fizikai készlet is szerepel |
| Helyi minimum alatt | Kiadható mennyiség < helyi minimum |
| Nincs kiadható készlet | Kiadható mennyiség = 0; teljes elkülönítés esetén is |
| Van kiadható készlet | Kiadható mennyiség > 0 |
| Elkülönített készlettel | Elkülönített mennyiség > 0 |
| Nulla fizikai készlet | Fizikai mennyiség = 0 |
| Minimumot elérő, kiadható | Pozitív kiadható mennyiség, legalább a helyi minimumon |

Itt minden minimumfeltétel a stocks.min_quantity mezőt jelenti. A globális termékminimum és a kategória minimumai nem helyettesítik. A 0 helyi minimum nem okoz minimum alatti riasztást; pontosan a minimumon nincs hiány. A hiány a kiadható, nem a fizikai mennyiség alapján számolódik.

## Összesítők, rendezés és lapozás

A fizikai, kiadható és elkülönített darabszám, a helyi minimum alatti sorok száma, a különböző termékek és raktárak száma minden szűrt sorból számolódik, nem csak az aktuális oldalból. A fizikai készlet a kiadható és elkülönített összege; az úton lévő áru még nincs a célraktár készletében. A kártyák a megfelelő készletállapotra mutatnak.

Asztali nézetben az oszlopfejlécek rendeznek: termék, cikkszám, raktár, fizikai készlet, kiadható készlet, elkülönített készlet és helyi minimum. Az aktív fejléc nyíllal és aria-sort értékkel jelzi az irányt. Azonos értékeknél a készletsor azonosítója ugyanabba az irányba rendez, így nincs átfedés a lapok között. A fejléc normál kattintása a keresőbe már beírt, még el nem küldött szöveget is figyelembe veszi. A natív hivatkozás új lapon és JavaScript nélkül is használható.

Az alapértelmezett oldalméret 25, választható 10, 25, 50 és 100. A lapozó minden érvényes szűrőt és rendezést megőriz. Szűrés, rendezés és oldalméret-váltás az első lapról indul. Törölt vagy módosított adat után a túl nagy oldalszám az utolsó elérhető lapra tér vissza, a mentési visszajelzés és hibák megtartásával. Hibás HTML-paraméter esetén egyszeri visszaállítás és érthető üzenet jelenik meg; JSON-kérésnél mezőszintű 422-es hiba érkezik.

## Minimumszerkesztés és navigáció

Adminisztrátor és manager a sor végén lévő ⋮ műveletmenü Helyi minimum módosítása pontjával nyitja meg a helyi minimum szerkesztőjét; raktáros csak olvassa. Az előző minimumérték és a termékzár továbbra is védi a mentést az egymásra írástól. A mennyiség változatlan marad. Az űrlap kétszeri gyors elküldését a felület is megakadályozza; az elavult mentést a szerver visszautasítja.

A StockListContext titkosított, korlátozott és újra validált tokenben őrzi meg a keresést, szűrést, rendezést, oldalméretet és lapot. Minimum mentésekor ugyanoda térünk vissza; ha a sor kiesett az utolsó lapról, az oldalszám rendeződik. Hibás token nem hozhat létre külső átirányítást. Régi, token nélküli űrlap esetén a korábbi raktárra szűrt visszatérés működik.

A sor végi jelzés valódi ⋮ műveletmenü, nem közvetlen mozgásnapló-hivatkozás. A menü a Készletmozgások, Új készletmozgás és Termék szerkesztése lehetőségeket, adminisztrátornál és managernél a Helyi minimum módosítása lehetőséget is tartalmazza. Az új mozgás űrlapján a termék és raktár validált GET-paraméterből előre kiválasztott, a korábbi hibás beküldés adatai elsőbbséget élveznek. A megnyitás nem módosít készletet. A minimum szerkesztőjén a Mégse mentés nélkül visszazár; mentés után a korábbi listakörnyezet marad meg.

Közvetlen készletsor-törlés és mennyiség-felülírás nincs a menüben: a készletmennyiséget naplózott bevételezés vagy kiadás változtatja, a termék törlése külön törzsadatművelet. Ez az Odoo készletkimutatásának megközelítését követi: annak riportnézetében a közvetlen sor-törlés letiltott, a mennyiségi eltérés és nullázás leltárkorrekcióként, megőrzött mozgástörténettel kezelhető. Forrás: https://github.com/odoo/odoo/blob/19.0/addons/stock/views/stock_quant_views.xml és https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/warehouses_storage/inventory_management/count_products.html .

A terméknév a termék adatlapjára, a raktárnév a raktárra szűrt készletlistára, a mozgásikon az adott termék és raktár közös mozgásnaplójára mutat. Az elkülönített mennyiség a megfelelő nyitott minősítési munkalistára vezet.

## Mobil és ellenőrzés

Asztali képernyőn a felső műveletsáv két ikonos gombjával váltható a táblázatos és a többoszlopos kártyanézet. Az aktív gomb kiemelt és aria-pressed jelzést kap; a választást a böngésző inventory.stock.view kulcsa tárolja, más oldalak nézetbeállításától függetlenül. Letiltott vagy sérült helyi tároló mellett is működik a váltás. Kártyanézetben a rendezés ugyanabból az összecsukható választóból érhető el, mint mobilon. A váltás nem indít lekérést: megmaradnak a szűrők, a lap, a keresőbe még el nem küldött szöveg és a minimumszerkesztő mentetlen értéke. A Mégse mindkét nézet közös minimumtervezetét visszaállítja; a nyitott műveletmenü nézetváltáskor bezárul.

768 pixel alatt mindig kártyák láthatók, a nézetkapcsoló elrejtve marad. A mobilra átméretezés nem írja felül az asztali választást, nagyobb képernyőre visszatérve az áll vissza. JavaScript nélkül asztalon táblázat, mobilon kártyák jelennek meg, működés nélküli kapcsológombok nélkül.

768 pixel alatt külön készletkártyák jelennek meg; a rendezés natív, összecsukható választóból érhető el ugyanazokkal a rendezőhivatkozásokkal. Az összesítők és a kártyák mennyiségei a rendelkezésre álló helyhez és betűmérethez igazodnak. Asztali táblázatnál a vízszintes görgetés a táblázat régióján belül marad. A részletes szűrők natív details elemek. A sor műveletmenüje natív popover, JavaScript nélkül is használható; a minimum szerkesztője ilyenkor egy validált edit_minimum paraméterrel, ugyanazon a szűrt listán nyílik meg. Az oldalmérethez külön alkalmazógomb jelenik meg JavaScript nélkül.

```powershell
php artisan test --filter="StockListingTest|DashboardReplenishmentTest"
npm run test:browser -- stock.spec.js stock-views.spec.js dashboard-replenishment.spec.js navigation.spec.js
php tests/Concurrency/run.php
npm run build
```

A StockListingTest külön ellenőrzi az állapotokat és határértékeket, összetett és szó szerinti keresést, kategóriabejárást, minden rendezési irányt, teljes szűrt összesítőket, javaslatokat, lapozást, hibás paramétereket, mentési kontextust, kiürülő utolsó lapot, sérült korábbi űrlapadatot, jogosultságokat és a készlet változatlanságát. A böngészőtesztek tényleges gépelést, javaslatválasztást, fejlécből rendezést, lapozást, minimumszerkesztési ütközést, minősítési hivatkozást és JavaScript nélküli mobilhasználatot is vizsgálnak. A MySQL-futtató a készletlista állapotait, összesítőit, numerikus rendezéseit és a szó szerinti/category keresést függetlenül számolt eredményekkel veti össze.
