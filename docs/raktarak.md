# Raktárak kezelése

A Raktárak oldal adminisztrátorok és vezetők számára érhető el. A raktárosok továbbra is a készlet-, termék-, mozgás- és szállítmányfelületeken választanak raktárt; a raktártörzset nem módosíthatják.

## Keresés és áttekintés

A keresés több szót kezel ÉS kapcsolattal: minden szónak szerepelnie kell a raktár nevében vagy címében. A `%`, `_` és `!` karaktereket literálisan kezeli. Készlettel rendelkező vagy készlet nélküli raktárakra is lehet szűrni. A készletszűrő módosítása azonnal frissíti a listát, megőrzi a keresést, rendezést és lapméretet, az oldalszámot pedig visszaállítja az elsőre. A név, cím, készleten lévő termékfajták száma és az összkészlet rendezhető; azonos értékeknél a raktárazonosító ad stabil sorrendet. A lapméret 15/25/50/100.

A termékfajták száma kizárólag a pozitív készletű termékeket számolja. Az összkészlet az adott raktár készletsorainak összege, az úton lévő áru nélkül. A Termékek és Mozgások hivatkozások az adott raktárra szűrt nézetet nyitják. Asztali nézetben táblázat, mobilon kártyák jelennek meg, külön mobilos rendezésválasztóval.

A termékfajták száma asztali és mobilnézetben is gyorslink a raktárra szűrt terméklistához, név szerinti növekvő rendezéssel. Ugyanaz a pozitív készletfeltétel érvényes a számlálóra és a céloldalra: nulla készlet, csak másik raktárban lévő vagy még úton lévő áru nem kerül bele. A nulla érték is megnyitható, ekkor üres terméklista látható. A raktárlista keresése, oldalszáma és készletszűrése nem kerül át termékszűrőként. A termékoldalon a mennyiségek továbbra is az összes raktár készletét mutatják, a meglévő összkészlet-jelölés szerint.

A kereső legfeljebb 8 raktárnevet és címet ajánl fel, több szóra és ékezetektől függetlenül. A javaslatok a többi listával közös `listingSearch` komponenst használják. Kattintás vagy kijelölt találaton Enter pontos `warehouse_id` szűrést alkalmaz, a szöveges keresést és lapozást törli, a többi mező aktuális értékét megtartja. Kijelölés nélkül az Enter szöveges keresést indít; Escape és Tab bezárja a listát. Új szöveg beírása törli a korábbi pontos raktárszűrést. A kiválasztott raktár külön eltávolítható szűrőként jelenik meg, és a visszatérési kontextusban is megmarad.

A műveleti oszlop egyetlen hárompontos gombot tartalmaz. A menüben Szerkesztés, Termékek, Mozgások és Törlés érhető el; a raktár neve az adatlapot nyitja. A menü natív automatikus popoverként a táblázat görgethető kerete fölött jelenik meg, a rendelkezésre álló hely alapján föl- vagy lefelé nyílik. Nyílbillentyűk, Home/End, Escape és Tab kezelése biztosított; kívülre kattintva bezárul. A törlés megerősítést kér. A tiltott törlés letiltott menüpontként, magyarázattal jelenik meg.

## Raktáradatlap és helyi készlet

A raktáradatlap a raktártörzzsel azonos admin/manager jogosultságot használja. A helyi készlet és a pozitív készletű termékfajták száma mellett külön szerepelnek a pending állapotú beérkező és kimenő átmozgatások. Az összesítők a teljes raktárra vonatkoznak, a helyi terméklista szűrésétől függetlenül. A kimenő úton lévő áru már levonódott a forrás készletéből: itt nem vonjuk le még egyszer. A beérkező csak átvételkor kerül a helyi készletbe. Lezárt, visszavont vagy csak tervezett szállítmány nem számít úton lévő tételnek.

A terméklista kizárólag a megnyitott raktár készletsorait mutatja, alapértelmezetten pozitív mennyiséggel. Nulla vagy minden helyi készletsor is választható; készletsor nélkül a termék nem kerül a listába. A keresés terméknévre és SKU-ra, több szóval, literális speciális karakterekkel működik. A javaslatok csak a raktárhoz tartozó termékekből készülnek. A pontos termékszűrő sem választhat másik raktár kizárólagos termékét. A route-ban szereplő raktárt queryparaméter nem írhatja felül.

A készlet- és lapméretválasztó automatikusan frissít. Asztalon a helyi készletlista Termék, Cikkszám és Helyi készlet oszlopfejlécei rendeznek, ismételt kattintással irányt váltva. Az aktív rendezést nyíl és aria-sort jelzi. A kattintás a keresőben még be nem küldött szöveget és a többi aktuális szűrőt is megtartja, a lapozást az első oldalra állítja. Mobilon a termékkártyákhoz rendezés- és irányválasztó marad. A lista 15/25/50/100 sorral lapozható. A készletszűrők törlése megőrzi a rendezést és lapméretet. Minden terméknél az adott termékre és raktárra szűrt mozgásnapló nyitható meg.

A beérkező/kimenő előnézet a legutóbbi öt tételt mutatja, a teljes mennyiségek és tételszámok korlátozás nélkül számolódnak. Az Összes hivatkozás a megfelelő pending átmozgatáslistát nyitja. Szállítmányhoz kapcsolt tétel a szállítmány adatlapjára vezet, ahol a megálló teljes átvétele végezhető el. Az adatlap a legutóbbi hat helyi készletmozgást is megmutatja, teljes naplóhivatkozással.

Az adatlap megnyitása nem változtat készletet vagy bizonylatot. A raktárlista kontextusa a részleteken keresztül megmarad. Az adatlapról indított szerkesztés és annak megszakítása/mentése a helyi készlet szűrését és lapozását is megőrzi külön, titkosított és whitelistes kontextusban. Hibás készletszűrés HTML-ben érthető hibaüzenettel visszaáll az alapnézetre, JSON-ban mezőszintű 422-t ad. Az adatlaphoz nem szükséges új migráció vagy seeder.

Ha a kért helyi készletoldal már nem létezik (például közben csökkent a találatok száma), az adatlap az utolsó érvényes oldalra irányít, a szűrés, rendezés, lapméret és raktárlista-kontextus megtartásával. Nulla találatnál az első oldal az érvényes üres nézet.

## Létrehozás és szerkesztés

A név legfeljebb 100, a cím legfeljebb 255 karakter; mindkettő kötelező szöveges adat. A szerkesztés a meglévő azonosítót és a kapcsolódó készleteket, bizonylatokat megőrzi. Hibás beküldés után a mezők értékei és mezőhivatkozásos hibák megjelennek. A felület ismételt beküldési események ellen letiltja a mentést; ez nem szerveroldali idempotenciakulcs. Azonos nevű raktárakat a jelenlegi adatmodell megenged.

A szűrés, rendezés és lapozás létrehozás, mentés, szerkesztés és megszakítás után is megmarad. A kontextus titkosított, méretkorlátos és csak engedélyezett helyi listaparamétereket tartalmazhat; külső visszatérési URL-t nem fogadunk el.

Sikeres törlés után a pontos raktárszűrés törlődik és az első oldalra térünk vissza; a keresés, készletszűrés, rendezés és lapméret megmarad. Így a törölt raktár azonosítója nem marad a listacímben. Hibás vagy elavult HTML-listahivatkozás esetén a szűretlen listára térünk vissza érthető hibaüzenettel; JSON-kéréseknél a mezőszintű 422-es válasz megmarad.

## Törlésvédelem

Csak olyan raktár törölhető, amelyhez sem készletsor, sem készletmozgás, sem forrás- vagy céloldali átmozgatás, sem induló vagy célraktárként szállítmány nem kapcsolódik. A nulla mennyiségű készletsor is védett. A használatban lévő raktáraknál a Törlés menüpont letiltott; közvetlen DELETE kérés sem kerülheti meg a védelmet.

A szerver tranzakcióban zárolja a raktárt, ellenőrzi a kapcsolatokat és töröl. Az adatbázis idegen kulcsai továbbra is védenek a későn létrejövő kapcsolatok ellen; az ilyen ütközés érthető validációs hibává alakul. Nem törlünk készletet vagy történeti bizonylatot a raktárral együtt.

## Ellenőrzés

```powershell
php artisan test --filter=WarehouseManagementTest
npm run test:browser -- warehouses.spec.js
```

A PHP-tesztek keresést, literális karaktereket, aggregátumokat, nulla készletet, rendezést, lapozást, paramétervalidációt, adatmódosítást, kontextust, jogosultságokat és minden kapcsolattípus törlésvédelmét ellenőrzik. Külön eset vált ki tényleges idegenkulcs-hibát a törlés előtti modell-eseményben, és ellenőrzi a visszagördülést. Ez nem kétfolyamatos MySQL-versenyhelyzetteszt.

A böngészőtesztek kizárólag az elkülönített SQLite tesztalkalmazást használják. Az adminisztrátorfiók csak ennek előkészítőjében szerepel, nem az alkalmazás seederében. A tesztek végigjárják a keresést, szűrést, létrehozást/szerkesztést, hibajavítást, törlést, lapozást, rendezést és a mobilos nézetet. Új migráció vagy a bemutatóadatok újraseedelése nem szükséges.
