# Átmozgatások 2.0 – rendezési ablak stabilitása (5/C.2.1)

## Bizonyított hibák a korábbi lenyílóban

A hibát módosítatlan stílusokkal is sikerült reprodukálni Chrome 157.0.8103.79 alatt. A felhasználó képernyőképének piros háttere kizárólag DevToolsban végzett szemléltetés volt.

A minimális lépések: rendező megnyitása → elsődleges select fókuszálása → kattintás a panel üres felső belső részére. A Playwright `page.on('crash')` és a CDP `Target.targetCrashed` eseménye rendererösszeomlást jelzett: `errorCode = -2147483645 = 0x80000003 = STATUS_BREAKPOINT`. Nem hibás navigáció vagy HTTP 500 történt; a minimális, instrumentáció nélküli próbában normál JavaScript-kivétel sem előzte meg a hibát.

A megfigyelt eseménysor: `focusout SELECT → MAIN` → a `MAIN` a `details` elemen kívül van → a régi `focusout` kezelő meghívja a `close()` függvényt → a fókuszváltás alatt elrejti a `details` tartalmát → a renderer összeomlik. Kontrollként ugyanazon DOM/CSS mellett kizárólag a `close()` működését kikapcsolva 100 azonos kattintás és fókuszváltás hibamentes volt. Ez a kiváltó eseményláncot elkülöníti; a Chromium belső assertjének pontos oka nem került külön elemzésre.

Két további alkalmazásoldali hiba is bizonyított:

- Az Alpine `$el` az aktuális direktíva eleme. Az `addSecondary()` gombjáról hívott `positionPanel()` esetében ez `BUTTON`, így az `open`-ellenőrzés tévesen visszatér. A panel 277 px magas maradt, miközben a tartalom 409 px-re nőtt. A másodlagos mező és a sticky lábléc 16 px-en fedte egymást, az eltávolítógomb a panel alja alá került.
- Eltávolítás után az `x-if` által megszüntetett gomb kontextusából végzett késleltetett `$refs`-keresés `Cannot read properties of undefined (reading 'focus')` JavaScript-hibát okozott.

## Javítás

Mind az öt lista ugyanazt a közös, natív `<dialog>` alapú komponenst használja, az alkalmazás gyorskeresőjének és munkafolyamat-párbeszédeinek mintáját követve. A szűrősorban a kompakt, alkalmazott sorrendet mutató Rendezés gomb maradt. Az ablak legfeljebb 560 px széles, kisebb kijelzőn a viewporthoz igazodik.

A fejléc és lábléc `shrink-0` flex-elem. A középső rész `min-height: 0` és saját `overflow-y: auto` beállítást kapott. Nincs sticky lábléc, kézi pixelpozicionálás, ablakgörgetésre vagy átméretezésre lefutó újrapozicionálás. A lábléc a tartalom görgetési területén kívül, önálló helyen jelenik meg. Kis magasságnál kizárólag a középső rész görgethető.

Az ablak nem záródik fókuszvesztésre vagy általános click-away eseményre. A belső üres területre kattintás nem alkalmaz, nem navigál és nem zár. Háttérkattintás csak akkor zárhat, ha a cél maga a dialog, a kattintás koordinátája pedig ténylegesen annak téglalapján kívül van. A Mégse, Escape és bezárógomb elveti a szerkesztett állapotot. A natív modal letiltja a háttér kezelését; a Tab/Shift+Tab az ablakban marad, a fókusz bezáráskor visszatér a nyitógombra.

A szerkesztett mezők és az alkalmazott `params` különállnak. A névvel rendelkező rejtett GET-mezők csak az alkalmazott állapotot követik; a draft selectek névtelenek. Az Alkalmazás átmásolja a választott egy/két szintet, megvárja a reaktív mezők frissítését, majd beküldi a meglévő szűrőűrlapot. Az eredeti szerveroldali rendezés, keresés, szűrés, lapméret, lapozás és jogosultsági hatókör változatlan. JavaScript nélkül natív űrlapos tartalék elérhető, dupla paraméterbeküldés nélkül.

Az eltávolítást követő fókuszkezelés az `x-if` módosítása előtt rögzített DOM-referenciákat használ. A bezárási takarítás idempotens: egy későn érkező natív `close` esemény nem zárja vagy oldja fel a gyorsan újranyitott ablakot.

## Gyorsítótáras visszalépés

Külön, valódi BFCache-próba további állapothibát bizonyított: a mennyiség szerint növekvő A dokumentumból a prioritás szerint csökkenő B dokumentumba történő alkalmazás után a böngészős Vissza művelet `pageshow.persisted === true` eseménnyel állította vissza A-t. Az URL, a lista és az összefoglaló A-t mutatta, de a JavaScript-paraméterek, a rejtett GET-mezők és az újranyitott választó B értékeit tartották meg.

A Playwright alapból letiltja a BFCache-t; a kontrollhoz ezt a launch flaget külön Chrome-példányban eltávolítottuk, és a tényleges parancssort is ellenőriztük. A javítás kizárólag gyorsítótáras visszatéréskor állítja vissza a paramétereket és a szerkesztett mezőket az adott dokumentum eredeti, szerveroldalon validált `applied` állapotára. A párbeszédablak, fókusz és görgetészárolás is rendezett állapotba kerül. Nincs új URL-feldolgozó vagy szerveroldali rendezési szabály.

## Érintett fájlok és tesztek

- `resources/js/components/listing-sort.js`
- `resources/views/components/listing-sort.blade.php`
- `tests/Browser/module-list-sorting.spec.js`
- `tests/Browser/shipment-planning.spec.js`

A célzott Playwright-tesztek mind az öt listát ellenőrzik 1440×900, 1024×768, 768×800, 375×667 és 320×568 méreten, továbbá 320×360-as nézetben. Nem csupán a viewporton belüli jelenlétet mérik: a teljes mezőnek a középső görgetőterületen belül kell lennie, a body alja nem nyúlhat bele a lábléc területébe, a mező közepét `elementFromPoint()` hit-testnek és tényleges kattintási próbának kell elérnie. A legutolsó eltávolítógomb is ilyen ellenőrzést kap.

A tesztek kezelik a négy rendezési mezőt, az egy/két szintet, hozzáadást/eltávolítást, Mégse/Escape-et, alkalmazást, URL-visszatöltést, billentyűzetes fókuszt, valamint ismételt üres belső kattintásokat. Külön figyelik a renderercrash, JavaScript-/konzolhiba és HTTP 5xx eseményeket.

A BFCache-regresszió ténylegesen bekapcsolt böngésző-gyorsítótárral ellenőrzi a visszatérést, a négy rejtett paramétert, az összefoglalót, az újranyitott választókat és a következő szűrőbeküldést.

Nem készül képernyőkép-csomag vagy ZIP. Az új tesztben a korábbi opcionális képgenerálás is kivezetésre került; a javítás mérési és interakciós bizonyítékokra támaszkodik.

## Végső eredmény (2026-11-18)

| Ellenőrzés | Eredmény |
| --- | --- |
| Kapcsolódó Laravel-tesztek, memóriabeli SQLite-on | 29 sikeres teszt, 750 ellenőrzés; 9,04 s |
| Végleges célzott Playwright | 14 sikeres teszt; 1,9 perc |
| Végleges teljes Playwright | 258 sikeres teszt; 18,1 perc |
| Vite build | Sikeres, 95 modul |

A teljes böngészős futásban a kilenc rendezési/modalteszt és a valódi BFCache-próba is sikeres. A belső kattintási stresszteszt 12 hozzáadás/eltávolítási ciklusban 72 belső kattintást ellenőriz, renderercrash, JavaScript-/konzolhiba vagy HTTP 5xx nélkül. Nem maradt hibás vagy kihagyott teszt, illetve a javított állapoton reprodukálható rendezési összeomlás vagy tartalomátfedés.

A teljes regresszió közben egy korai DOM-kiolvasásból eredő tesztidőzítési hiba is előjött: a lapozó két reszponzív linkváltozatát a friss dokumentum CSS-betöltése előtt ugyanaz a szigorú lokátor találta meg. A teszt most megvárja a betöltést, külön bizonyítja, hogy pontosan egy változat látható, és mindkét DOM-linkben megmaradnak a rendezési paraméterek. A lapozó alkalmazáskódja változatlan.

A szerveroldali rendezési, készletkezelési és jogosultsági logika nem módosult. Nem készült migráció. A helyi Laragon-adatbázis nem került törlésre vagy újramigrálásra; a tesztek elkülönített ideiglenes adatbázist használtak. Nem történt commit, push vagy ágváltás.

Az ellenőrzés a projekt Chrome-környezetében történt. A Chromium belső assertjét külön nem javítottuk vagy elemeztük; az alkalmazásoldali kiváltó eseményláncot távolítottuk el és ellenőriztük újra.
