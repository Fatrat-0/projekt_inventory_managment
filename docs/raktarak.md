# Raktárak kezelése

A Raktárak oldal adminisztrátorok és vezetők számára érhető el. A raktárosok továbbra is a készlet-, termék-, mozgás- és szállítmányfelületeken választanak raktárt; a raktártörzset nem módosíthatják.

## Keresés és áttekintés

A keresés több szót kezel ÉS kapcsolattal: minden szónak szerepelnie kell a raktár nevében vagy címében. A `%`, `_` és `!` karaktereket literálisan kezeli. Készlettel rendelkező vagy készlet nélküli raktárakra is lehet szűrni. A készletszűrő módosítása azonnal frissíti a listát, megőrzi a keresést, rendezést és lapméretet, az oldalszámot pedig visszaállítja az elsőre. A név, cím, készleten lévő termékfajták száma és az összkészlet rendezhető; azonos értékeknél a raktárazonosító ad stabil sorrendet. A lapméret 15/25/50/100.

A termékfajták száma kizárólag a pozitív készletű termékeket számolja. Az összkészlet az adott raktár készletsorainak összege, az úton lévő áru nélkül. A Termékek és Mozgások hivatkozások az adott raktárra szűrt nézetet nyitják. Asztali nézetben táblázat, mobilon kártyák jelennek meg, külön mobilos rendezésválasztóval.

A kereső legfeljebb 8 raktárnevet és címet ajánl fel, több szóra és ékezetektől függetlenül. A javaslatok a többi listával közös `listingSearch` komponenst használják. Kattintás vagy kijelölt találaton Enter pontos `warehouse_id` szűrést alkalmaz, a szöveges keresést és lapozást törli, a többi mező aktuális értékét megtartja. Kijelölés nélkül az Enter szöveges keresést indít; Escape és Tab bezárja a listát. Új szöveg beírása törli a korábbi pontos raktárszűrést. A kiválasztott raktár külön eltávolítható szűrőként jelenik meg, és a visszatérési kontextusban is megmarad.

A műveleti oszlop egyetlen hárompontos gombot tartalmaz. A menüben Szerkesztés, Termékek, Mozgások és Törlés érhető el; a raktár neve közvetlenül a szerkesztést nyitja. A menü natív automatikus popoverként a táblázat görgethető kerete fölött jelenik meg, a rendelkezésre álló hely alapján föl- vagy lefelé nyílik. Nyílbillentyűk, Home/End, Escape és Tab kezelése biztosított; kívülre kattintva bezárul. A törlés megerősítést kér. A tiltott törlés letiltott menüpontként, magyarázattal jelenik meg.

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
