# Minősítési munkalista

## Cél és elérés

A munkalista a minősítésre bevételezett vevői visszáruk napi feldolgozását segíti. A készletmozgás naplója továbbra is az eredeti mozgás mennyiségét mutatja, a munkalistán külön látszik a beérkezett, felszabadított, selejtezett és még feldolgozandó mennyiség.

Például 8 db visszáru és 5 db felszabadítás után a naplóban 8 db bevételezés, a munkalistán 3 db hátralévő minősítés szerepel. Az állapot a rögzített minősítésekből számolódik; nincs külön szerkeszthető állapotmező.

A `quality-returns.index` GET-útvonal a `/quality-returns` oldalt nyitja meg. A meglévő admin, manager és worker szerepkör használhatja.

## Szűrés és visszatérés

Az alapnézet a nyitott tételeket mutatja. A státuszszűrő a nyitott, még el nem kezdett, részben minősített, lezárt vagy összes tétel között vált. Termék, raktár, partner és szöveges keresés szűkíti az eredményt. A mennyiségek és az állapot a korábbi felszabadítások és selejtezések összege alapján jelennek meg. A munkalista nem változtat készletet; a feldolgozás a meglévő visszáru-adatlapon történik.

A keresés minden szavának szerepelnie kell a termék nevében/cikkszámában, a hivatkozásban, a visszáru vagy minősítés indokában, illetve a partner rögzítéskori vagy jelenlegi nevében. A MOZ-azonosító pontos találatot is ad. A `%`, `_` és `!` karakterek literálisan kereshetők. A kereső termékjavaslatának kiválasztása pontos termékszűrést állít be; új szöveg gépelése JavaScripttel törli ezt a pontos szűrést.

Alapból a legrégebbi nyitott beérkezések vannak elöl, azonos időpontnál azonosító szerinti stabil sorrenddel. Asztali nézetben a hét oszlopfejléc, mobilon külön rendezésválasztó használható. Az oldalankénti méret 10/25/50/100 lehet, alapból 25. A túl nagy oldalszám az utolsó oldalra irányít a szűrők megtartásával.

Érvénytelen vagy időközben megszűnt HTML-szűrő esetén az alap munkalistára térünk vissza érthető hibaüzenettel, ugyanarra a hibás URL-re visszairányítás nélkül. JSON-kérésnél a mezőnkénti 422-es validációs válasz megmarad.

A minősítés adatlapjára titkosított listakontextus kerül. A visszalépés, részleges vagy teljes feldolgozás és validációs hiba megőrzi a munkalista keresését, szűrőit, rendezését és oldalszámát. Az összesítő kártyák az állapotszűrő kivételével a lista szűrési tartományát használják; a lezárt tételek nem számítanak bele a nyitott darabszámba vagy legkorábbi beérkezésbe.

A listakontextus Unicode-karaktereit tömören tároljuk a titkosítás előtt. Így a maximális, 200 karakteres emojikeresés is belefér a 4096 bájtos korlátba; a korábban készített érvényes tokenek változatlanul olvashatók.

Böngészői gyorsítótárból visszaállított munkalista újratölti az aktuális adatokat, az URL-ben lévő szűrők megőrzésével. Ez megakadályozza, hogy egy közben teljesen minősített visszáru a visszalépés után továbbra is nyitottnak látszódjon.

A vezérlőpult megmutatja a minősítésre váró visszáruk számát, a teljes hátralévő darabszámot és a legkorábbi nyitott beérkezést. Nulla feladatnál egyértelmű üres állapot jelenik meg. A mutató akkor is látható, ha az elérhető készlet egyébként nem okoz készlethiány-riasztást.

Az elkülönített mennyiségre kattintva a készletnézetekből közvetlenül megnyitható a megfelelő nyitott munkalista:

- Termék összesítése: az adott termék minden raktárban.
- Termék raktárankénti bontása és helyi készletsor: az adott termék az adott raktárban.
- Raktár összesítése: az adott raktár minden terméke.

A mozgáslista eszköztárán is elérhető a munkalista. A fő navigáció nem bővül új menüponttal. Az oldalon lévő hivatkozások a meglévő termék-, raktár- és mozgásnézetek használatát is megőrzik.

## Megjelenítési javítás

A mozgáslista a `0` hivatkozást érvényes szövegként mutatja. Csak a null vagy üres hivatkozás jelenik meg gondolatjelként.

## Ellenőrzés

```powershell
php artisan test
npm run test:browser
npm run build
```

A regressziós ellenőrzésekben a számított állapotokat és mennyiségeket, a részleges feldolgozást és lezárást, a szűrt összesítőket, a rendezést és lapozást, a listába visszatérést, a készletből nyíló termék/raktár szűréseket, a nulla vezérlőpulti állapotot, a szerepköröket, valamint a mobil és JavaScript nélküli működést kell vizsgálni. A munkalista és a kapcsolódó GET-hivatkozások nem hozhatnak létre új készletmozgást vagy minősítési eseményt.

A hosszú szöveges és nagy mennyiségű böngészős eset 100 karakteres egybefüggő termék- és partnernevet, 50 karakteres cikkszámot és 2 147 483 647 db mennyiséget ellenőriz 320, 375, 767, 768, 1024 és 1440 pixel szélességnél. A táblázat a saját területén görgethető; rejtett oszlopfelirata sem okozhat oldalszintű vízszintes túllógást.

A `php tests/Concurrency/run.php` a 36 készletkezelési versenyhelyzet után az elkülönített MySQL-adatbázis meglévő visszárutételein is ellenőrzi a munkalista lekérdezéseit. Az öt állapotszűrőt, mind a hét rendezést két irányban, a mennyiségi összesítéseket és a dashboardmutatót PHP-ban külön kiszámított elvárásokkal veti össze. A lekérdezések előtti/utáni pillanatkép igazolja, hogy a készlet és a napló változatlan marad.
