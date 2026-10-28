# Készletmozgások: ismételt beküldés védelme és részletek

## Működés

Az új mozgás űrlapja véletlen UUID-t kap `request_key` néven. A HTTP-végpont ezt kötelezően ellenőrzi. Hibás űrlapbeküldéskor ugyanaz a kulcs megmarad; új űrlap új kulcsot kap.

A szolgáltatás a végrehajtó felhasználó és a kisbetűsre egységesített kulcs együttesét adatbázis-egyediséggel védi. A kulcsfoglalás, a készletváltozás, a mozgás és a hozzákapcsolás egy tranzakció. Két párhuzamos kérésnél az egyedi index dönti el, melyik foglalhatja le a kulcsot.

- Azonos felhasználó, kulcs és üzleti adatok: az eredeti mozgást adja vissza, új készletváltozás nélkül.
- Azonos kulcs, eltérő üzleti adatok: validációs hiba. Új művelethez új űrlapot kell nyitni.
- Új kulcs: különálló művelet akkor is, ha a tartalma azonos egy korábbival.
- Sikertelen tranzakció: a kulcsfoglalás is visszagördül, ezért ugyanazzal a kulccsal újra lehet próbálni.

Eltérő tartalommal megismételt beküldés után az űrlap „Új készletmozgás indítása” hivatkozást kínál: ez új beküldési kulcsot kér, és megőrzi a lista visszatérési szűréseit.

Az üzleti adatok normalizált SHA-256 lenyomata tartalmazza a terméket, raktárt, végrehajtót, típust, mennyiséget és hivatkozást. A numerikus azonosítók és mennyiség egységes egész értékek, az üres hivatkozás null. A hivatkozás `0` értéke megmarad. A listaállapot és a CSRF-token nem része az üzleti lenyomatnak.

A belső `InventoryService::recordMovement($data, $requestKey)` hívásnál a kulcs opcionális a meglévő programozott hívásokhoz. Új HTTP-végpontból kötelező hitelesített végrehajtót, ellenőrzött adatokat és beküldési kulcsot átadni. Ez a védelem a bevételezési/kiadási végpontra vonatkozik; az átmozgatások már meglévő státuszátmenet-védelme külön mechanizmus.

## Mozgásrészletek

A napló terméknevére vagy `MOZ-000123` alakú azonosítójára kattintva nyílik meg a részletezőoldal. A jogosultsági szabály ugyanaz, mint a mozgáslistán. Visszalépéskor a lista szűrései, rendezése és oldalszáma megmarad.

A termék, SKU, raktár, végrehajtó, dátum, hivatkozás és előjeles mennyiség mellett az új mozgások `stock_before` és `stock_after` mezői a tranzakcióban számolt, rögzítéskori raktári egyenlegeket mutatják. A kliens nem adhatja meg őket. Későbbi készletművelet nem írja át a korábbi egyenlegeket. A termék/raktár/felhasználó neve a kapcsolódó törzsadat jelenlegi neve; nem történeti névpillanatkép.

A migráció nem próbálja visszaszámolni a régi mozgásokat. Azoknál az egyenlegek null értékűek, és a felület jelzi a történeti adat hiányát. A demonstrációs seeder és a böngészőtesztek korábbi naplósorai is ilyen, közvetlenül létrehozott történeti tételek.

## Telepítés és tesztek

Új migráció: `2026_10_28_120000_add_movement_audit_and_requests`. Két nullable mezőt ad az `inventory_movements` táblához, és létrehozza az `inventory_movement_requests` táblát. Nincs történeti adatfelülírás. Az egyedi naplószám a rekordazonosítóból képződik; nem garantál megszakítás nélküli sorszámsort.

```powershell
php artisan migrate
npm run build
php artisan test
npm run test:browser
php tests/Concurrency/run.php
```

A telepítés előtt megnyitott régi űrlapokat frissíteni kell, hogy megkapják az új beküldési kulcsot.

16 új PHP-teszteset ellenőrzi az ismétlést, eltérő tartalmat, felhasználónkénti elkülönítést, új kulccsal végzett külön műveletet, normalizálást, visszagörgetést és újrapróbálást, hiányzó/hibás kulcsot, szerveroldali készletegyenlegeket, történeti adathiányt, HTML-escape-et, visszatérést, új űrlap indítását ütközés után és jogosultságokat.

A böngészőcsomag új esete ugyanazt a beküldést először HTTP-kéréssel, majd az eredeti űrlappal ismétli meg; ellenőrzi az egyszeri készletváltozást, az egyetlen naplósort, a részleteket és a visszatérést.

A külön MySQL-futtató 3 új, kétfolyamatos esettel bővült: ugyanazon kérés párhuzamos ismétlése, azonos kulcs eltérő mennyiséggel, valamint azonos kulcs eltérő termékekkel. A korábbi 8 eset továbbra is fut. Minden eset külön tesztadatbázisban történik.

## Korlátok

A védelem ugyanazon felhasználó ugyanazon beküldési azonosítójának ismétlését kezeli. Két külön megnyitott űrlap külön kulccsal két önálló műveletet jelent. Az üzleti bizonylatszám nem egyedi: ugyanahhoz a bizonylathoz több tétel is tartozhat.

A kulcsokat a kapcsolódó mozgásokkal együtt megőrizzük. Későbbi archiválásnál a megőrzési szabályt és az ismétlések kezelési időtartamát együtt kell megtervezni. A részletezőoldal megtekintésre szolgál; korrekciós/ellenmozgás-funkció nem része ennek a változtatásnak.

## Seederes bemutató adatok

Az alap `DatabaseSeeder` végén a `MovementAuditDemoSeeder` is lefut. Meglévő adatbázisban az új példák önállóan, az adatbázis újraépítése nélkül betölthetők:

```powershell
php artisan db:seed --class=MovementAuditDemoSeeder
```

A `worker@raktar.hu` meglévő tesztfelhasználót használja; ennek hiányában még adatbeszúrás előtt hibát jelez. Külön „Bemutató raktár – Audit” és „Bemutató termékek – Audit” törzsadatokat készít, két `DEMO-AUDIT-` SKU-val. A korábbi termékek készleteit és mozgásait nem módosítja.

A példák ugyanazt az InventoryService szolgáltatást használják, mint az alkalmazás. Minden mozgáshoz rögzítéskori készletegyenleg és beküldési rekord tartozik. Az állandó beküldési kulcsok miatt a külön seeder újrafuttatása nem készít újabb mozgásokat, és nem módosít újra készletet. Minden példánál rögtön megismétel egy sikeres beküldést is, az eredeti mozgást visszakapva.

Friss bemutatóadatok esetén:

| Hivatkozás | Művelet | Készlet előtte → utána |
|---|---|---|
| DEMO-AUDIT-001 | Csavar nyitó bevételezés | 0 → 20 |
| DEMO-AUDIT-002 | Csavar részleges kiadás | 20 → 15 |
| DEMO-AUDIT-003 | Csavar utánpótlás | 15 → 23 |
| DEMO-AUDIT-004 | Kesztyű nyitó bevételezés | 0 → 10 |
| DEMO-AUDIT-005 | Kesztyű teljes kiadás | 10 → 0 |

A mozgáslistán `DEMO-AUDIT` kereséssel megtalálhatók. A korábbi 45 demonstrációs naplósor továbbra is megmarad rögzített egyenleg nélkül, így a történeti adathiány jelzése is bemutatható.

Három új `MovementAuditSeederTest` eset ellenőrzi a teljes alapseedelésbe integrálást, az egyenlegeket és beküldési rekordokat, az ismételt futtatás változatlanságát, valamint a más termékekhez tartozó készletek és naplók megőrzését.

## Az alap seeder újrafuttatása

A `php artisan db:seed` ismételten is használható. A teljes DatabaseSeeder tranzakcióban fut; hiba esetén a teljes új feltöltés visszagördül.

A meglévő felhasználók (beleértve a jelszavukat és szerepkörüket), termékek, készletek és naplóbejegyzések megmaradnak. A seeder a hiányzó bemutatóadatokat adja hozzá, nem állítja vissza a készleteket a katalógus nyitóértékeire. Nyitókészlet csak az adott futásban újonnan létrehozott termékekhez készül. Meglévő átmozgatás státuszát sem írja vissza a demonstráció eredeti állapotára.

Az egyeztetési kulcsok: felhasználói e-mail, raktárnév, kategórianév és szülő, partner e-mail, termék-SKU, történeti naplónál hivatkozás+termék+raktár, átmozgatásnál termék+forrás+cél+mennyiség+felhasználó. Az ismételt futtatás ezek változatlansága mellett nem duplikál. A kulcsnak használt mezők átnevezése esetén az eredeti név hiányzó bemutatóadatnak számíthat; a meglévő átnevezett adatot akkor sem írja felül. A teljes alapseeder CLI-ből egymás után futtatásra készült, párhuzamos alapseedeléshez nincs külön globális zár.

A `DatabaseSeederRepeatTest` teszteli a két teljes futás közötti teljes adatbázis-pillanatkép egyezését, a módosított termék/készlet/jelszó/szerepkör/átmozgatás megőrzését és egy szándékos SQL-hiba miatti teljes visszagörgetést.

Az újonnan létrehozott bemutató-átmozgatások az `InventoryService` indítási és lezárási műveleteit használják. Az úton lévő mennyiség már nem szerepel a forrásraktári készletben; átvételkor a célraktárba, visszavonáskor a forrásba kerül. Két további teszteset ellenőrzi a teljes mennyiség megőrzését mindkét lezárásnál és az ezt követő újraseedelés változatlanságát. A korábban közvetlenül létrehozott átmozgatásokat és készleteket a seeder nem javítja visszamenőleg, mert azok azóta megváltozhattak.
