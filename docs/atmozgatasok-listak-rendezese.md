# Átmozgatások 2.0 – egységes listarendezés (5/C.2)

Az itt dokumentált eredeti lenyíló rendezőt az [5/C.2.1 stabilitási javítás](atmozgatasok-rendezesi-ablak-stabilitas.md) középre igazított párbeszédablakra cserélte. A szerveroldali rendezési szerződés és mezők megmaradtak.

## Hatókör és felület

Az Igények, Fuvarok, Kitárolás, Átvétel és Betárolás listája közös, lenyitható **Rendezés** vezérlőt használ. A megjelenítési oszlopok nem azonosak a rendezési mezőkkel. A fejlécek leíró szövegek, rendezési linkek, nyilak és `aria-sort` nélkül.

| Lista | Táblázatfejlécek | Alapértelmezés |
| --- | --- | --- |
| Igények | Igény és termék; Útvonal; Mennyiség; Állapot és prioritás; Időpontok | Létrehozva ↓ |
| Fuvarok | Fuvar és hivatkozás; Induló raktár; Időpontok; Jármű és állapot; Előkészítés | Létrehozva ↓ |
| Kitárolás | Igény és termék; Útvonal; Mennyiségek; Állapot és prioritás | Feladat azonosítója ↓ |
| Átvétel | Fuvar és hivatkozás; Útvonal; Mennyiségek; Állapot és feladás | Fuvarazonosító ↓ |
| Betárolás | Termék és átvétel; Célraktár és időpont; Mennyiségek; Állapot | Átvételi tétel ↓ |

A kereső és szűrők mellett megmaradó összefoglaló az alkalmazott sorrendet mutatja. A panelben egy kötelező elsődleges szempont, annak iránya, valamint opcionális másodlagos szempont és külön irány állítható be. Az alkalmazatlan módosítások bezáráskor visszaállnak. Második szempont hozzáadható és eltávolítható; ha az elsődlegest az aktuális másodlagosra cseréljük, a másodlagos törlődik. Ugyanaz a mező nem alkalmazható kétszer.

A natív `details` vezérlő Enterrel és Space-szel nyitható, Escape-pel bezárható és visszaadja a fókuszt a nyitógombnak. A panel mobilon is ugyanaz; a böngészőablak határaihoz igazodik és szükség esetén belül görgethető. JavaScript nélkül natív GET-űrlappal mindkét szint használható, a duplikált mezőt a szerver kiszűri. Az adatlapokat továbbra is a sor azonosítójára/terméknevére kattintva lehet megnyitni.

## Megtartott rendezési mezők

| Lista | Választható szempontok |
| --- | --- |
| Igények | Igényazonosító, Termék, Forrásraktár, Célraktár, Mennyiség, Állapot, Prioritás, Létrehozva, Határidő |
| Fuvarok | Fuvarazonosító, Hivatkozás, Induló raktár, Tervezett indulás, Jármű, Állapot, Létrehozva |
| Kitárolás | Feladat azonosítója, Igényazonosító, Termék, Forrásraktár, Célraktár, Hátralévő, Állapot, Prioritás |
| Átvétel | Fuvarazonosító, Fuvarhivatkozás, Forrásraktár, Célraktár, Tételszám, Még átvehető, Átvételi állapot, Tényleges feladás |
| Betárolás | Átvételi tétel, Termék, Célraktár, Átvett, Betárolt, Betárolásra vár, Átvétel időpontja, Állapot |

## Szerveroldali szerződés

GET-paraméterek: `sort_by`, `sort_dir`, opcionálisan `then_by`, `then_dir`. A korábbi egyszintű URL-ek működnek. Az irány kizárólag `asc` vagy `desc`, a mező kizárólag a lista saját engedélyezett mezője lehet. A közös `App\Support\ListingSort` adja a validációs szabályokat, magyar üzeneteket, biztonságos normalizálást és az egy/két rendezési szintet. Üres vagy hiányzó másodlagosnál nincs második felhasználói szempont; annak hiányzó iránya `asc`.

A lekérdezés először a jogosultsági és keresési/szűrési feltételeket alkalmazza, majd a teljes találati halmazt rendezi, végül lapoz. Az elsődleges után a másodlagos SQL-kifejezés következik, végül az egyedi azonosító a korábbi elsődleges iránnyal. Átvételnél fuvarazonosító szerinti elsődleges rendezés esetén az útvonal pozíciója a felhasználó által megadott szintek után, az egyedi megállóazonosító előtt marad meg. Nincs kliensoldali találatrendezés.

A mennyiségek numerikus adatbázisértékek, a dátumok eredeti időbélyegek. Hiányzó igényhatáridő, tervezett indulás, létrehozási idő és üres járműadat mindkét irányban az ismert adatok után kerül. A további rendezhető kapcsolatok és feladási/átvételi dátumok a listákba kerülés feltételei és az adatbázis-korlátok alapján kötelezőek.

Üzleti sorrendek növekvő irányban (csökkenőben fordítva):

- Prioritás: Normál → Sürgős → Kritikus.
- Igény: Jóváhagyásra vár → Jóváhagyva → Elutasítva → Visszavonva.
- Fuvar: Tervezet → Úton → Teljesítve → Visszavonva.
- Kitárolás: Várakozik → Folyamatban → Kitárolva → Visszavonva.
- Átvétel: Átvételre vár → Részben átvéve → Teljesen átvéve.
- Betárolás: Betárolásra vár → Részben betárolva → Teljesen betárolva.

Új rendezés alkalmazásakor nincs `page` mező az űrlapban, így az első oldal nyílik meg. A keresés, a szűrők, a kapcsolt átvétel-/fuvarszűrők és a lapméret megmaradnak. A lapozó URL-jei mindkét rendezési szintet továbbviszik, közvetlen URL-megnyitás és böngészős visszalépés ugyanazt az állapotot tölti vissza. Az egyedi döntetlenfeloldás azonos adatállapot mellett stabil lapozást ad; közben más felhasználó által beszúrt/törölt rekordok miatt az offsetalapú lapozás eredménye továbbra is változhat.

## Tényleges többszintű példa

Igények: **Prioritás ↓, Határidő ↑**:

`/atmozgatasok?sort_by=priority&sort_dir=desc&then_by=requested_arrival_at&then_dir=asc&per_page=2`

Az ennek megfelelő SQL-rendezés lényege, még a `LIMIT`/`OFFSET` alkalmazása előtt:

```sql
ORDER BY CASE priority WHEN 'normal' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END DESC,
         requested_arrival_at IS NULL ASC,
         requested_arrival_at ASC,
         transfer_intents.id DESC
```

Előbb a kritikus igények, majd a sürgősek, végül a normál igények jönnek. Azonos prioritáson belül a korábbi határidő előrébb kerül, a hiányzó határidő utoljára. Azonos prioritás és határidő esetén az azonosító csökkenő sorrendje dönt. A tesztek eltérő prioritású, azonos/eltérő és hiányzó határidejű rekordokkal, több találati oldalon igazolják ezt. Az összes megadott mező egyezése esetén is determinisztikus az eredmény.

A `TransferIntentPagesTest` tényleges héttételes próbájának elvárt és ellenőrzött sorrendje (3 találat/oldal, aktív keresés és raktár-/állapotszűrés):

| Sorszám | Tesztrekord | Prioritás | Határidő (UTC) |
| --- | --- | --- | --- |
| 1 | criticalEarly | Kritikus | 2029-01-01 10:00 |
| 2 | criticalLate | Kritikus | 2030-01-01 10:00 |
| 3 | urgentTie | Sürgős | 2029-01-01 10:00 |
| 4 | urgentEarly | Sürgős | 2029-01-01 10:00 |
| 5 | urgentLate | Sürgős | 2030-01-01 10:00 |
| 6 | urgentUnknown | Sürgős | Nincs megadva |
| 7 | normal | Normál | 2029-01-01 10:00 |

Az `urgentTie` később létrehozott, nagyobb azonosítójú rekord, ezért kerül az azonos határidejű `urgentEarly` elé. A külön, jogosultsági hatókörön kívüli kritikus igény a listából kimarad. A Playwright további héttételes példája ugyanezt 2 találat/oldallal, négy oldalon és fordított rendezési irányokkal is végigjárja.

## Érintett komponensek és ellenőrzések

- Közös felület: `resources/views/components/listing-sort.blade.php`, `resources/js/components/listing-sort.js`, regisztrálás: `resources/js/app.js`.
- Az öt lista `index.blade.php` nézete használja a komponenst; a korábbi kizárólag hozzájuk készült fejléc-link komponens kivezetve. Más oldalak meglévő mobilrendezője megmarad.
- Backend: `ListingSort`, `TransferIntentPageController`, `ShipmentPlanPageController`, `ShipmentPlanReader`, `WarehousePickingPageController`, `WarehouseReceivingPageController`, `ShipmentReceivingReader`.
- Laravel: `ListingSortTest`, `TransferIntentPagesTest`, `ShipmentPlanningPagesTest`, `WarehouseTaskSortingTest`; az utóbbi a készlet-, foglalás-, összekészítés-, tranzit- és átvételi adatok változatlanságát is összehasonlítja.
- Playwright: `module-list-sorting.spec.js`, valamint az új vezérlőre átvezetett fuvartervezési mobilteszt.
- Lapozásbiztos tesztadat-előkészítés: `transfer-intents.spec.js`, `transfer-picking.spec.js`, `transfer-packaging.spec.js`, `transfer-putaway.spec.js`. A létrehozott raktár azonosítóját mindegyik segéd a saját névre szűrt listából olvassa ki, nem feltételezi, hogy a rekord az első 15 találatban szerepel.
- Külön SQLite- és izolált MySQL-ellenőrzés, teljes Laravel- és böngészős regresszió; képernyőszélességek: 1440, 1024, 768, 375 és 320 px.

Az első teljes böngészős futás két tesztsegédben azonosította az első oldaltól függő azonosító-kiolvasást: 253 teszt sikeres volt, kettő időtúllépéssel végződött. A mentés megtörtént, az új raktár a lapozás miatt nem volt az első oldalon. A két hibás és két további, azonos mintát követő segéd célzott keresést kapott; időkorlát-emelés vagy alkalmazásbeli lapozásmódosítás nem történt. A javított állapoton célzott, majd újabb teljes futás ellenőrzi a tesztkészletet.

Nem változik a készletkönyvelés, a foglalás, a munkafolyamat vagy a jogosultsági hatókör. Nem szükséges migráció. A teljes alkalmazás további listáin nem találtunk ugyanezzel a több külön rendezőgombot egyetlen fejlécbe helyező kialakítással érintett táblázatot.

A böngészős ellenőrzés a projekt meglévő Chrome-konfigurációját használja. Firefox és Safari külön ellenőrzése nem része ennek a futásnak.

## Végső ellenőrzési eredmények (2026-11-18)

| Ellenőrzés | Eredmény |
| --- | --- |
| Teljes Laravel-csomag, SQLite memóriabeli adatbázison | 1144 sikeres teszt, 10 824 ellenőrzés; 136,42 s |
| Célzott listarendezési és jogosultsági Laravel-csomag | 29 sikeres teszt, 750 ellenőrzés |
| Öt kapcsolódó Feature-osztály izolált MySQL-adatbázison | 25 sikeres teszt, 743 ellenőrzés; 51,464 s |
| Teljes MySQL-konkurenciaellenőrzés | 189 sikeres ellenőrzés |
| Végső célzott Playwright-csomag | 14 sikeres teszt; 1,9 perc |
| Javítások utáni teljes Playwright-csomag | 255 sikeres teszt; 19,5 perc |
| Vite build | Sikeres, 95 modul |

A Laravel-csomag a korábbi 1133-ról 1144 tesztre bővült: 4 új közös normalizálási Unit-teszt és 7 új többszintű rendezési Feature-teszt készült. A legutóbbi 252 böngészős esethez 3 új rendezési teszt került; a korábbi 3 rendezési teszt és az érintett regressziók az új felülethez igazodtak. Teszt kivezetésére nem volt szükség.

Az 53 képernyőkép az öt lista öt szélességű zárt és kétszintes rendezővel megnyitott nézetét, két külön egyszintű nézetet és a héttételes prioritás–határidő példát tartalmazza. A panelek képernyőn belüli elhelyezkedését, az alkalmazógomb teljes láthatóságát és a vízszintes túlcsordulás hiányát automatizált ellenőrzések is igazolják. A képek és az index külső átadási csomagban vannak, nem generált repository-forrásként.

A helyi Laragon-adatbázist egyik teszt sem törölte vagy migrálta újra. A tesztfuttatók ideiglenes SQLite-/MySQL-adatbázisokat használtak, amelyek takarítása megtörtént. Nem történt commit, push, merge vagy ágváltás.
