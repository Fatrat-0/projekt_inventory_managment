# Kategóriakezelés

Az adminisztrátorok és a managerek kereshető, lapozható listán kezelik a kategóriákat. A keresés több szót kapcsol össze ÉS feltétellel a kategória és közvetlen szülője nevében; a `%`, `_` és `!` karakter literális. Gépeléskor a közös keresőkomponens legfeljebb nyolc javaslatot ad, ékezetfüggetlen kereséssel. A javaslat pontos kategóriaazonosítót választ, azonos nevű kategóriáknál a szülő és az ID segít. Új szöveg gépelése megszünteti a korábbi pontos szűrést.

Fő- és alkategóriákra lehet szűrni. Asztalon az oszlopfejléc, mobilon a rendezésválasztó rendez; az aktuális, még be nem küldött keresőszöveg és a lapméret is megmarad. A név és a két minimum rendezhető, a stabil másodlagos kulcs a kategóriaazonosító. A hiányzó minimum mindkét irányban a beállított értékek után jelenik meg. A lapméret 15/25/50/100; a már nem létező oldalszám az utolsó érvényes oldalra irányít. Hibás HTML-szűrők az alaplistára vezetnek hibaüzenettel, JSON-kérésnél 422 jár.

A teljes kategóriaútvonal látható a listában és a szülőválasztóban. A Termékek az ágban menüpont a meglévő terméklista kategóriaszűrőjét nyitja, amely a teljes leszármazotti ágat tartalmazza. A listában jelzett termék- és alkategóriaszám ezzel szemben kizárólag a közvetlen kapcsolatok darabszáma.

## Hierarchia és törlés

A saját kategória és összes leszármazottja nem választható szülőnek. A szerver ezt közvetlen PUT-kérésnél is ellenőrzi, tranzakción belül: a hierarchia aktuális sorait azonosítósorrendben zárolja, majd ellenőrzi és menti a módosítást. A szülő teljes ősláncát végigjárja: már korábban létrejött körkörös ághoz sem lehet új vagy meglévő kategóriát kapcsolni. A hibás kapcsolat megszüntetése továbbra is megengedett. A kategória létrehozása és törlése ugyanezt a zárolási sorrendet használja. Ez az alkalmazás kezelővégpontjainak védelme, nem adatbázis-szintű körtiltó constraint. A megjelenítési segéd egy korábban keletkezett körnél is véges bejárást használ.

Terméket vagy alkategóriát tartalmazó kategória nem törölhető a kezelőfelületen, illetve a DELETE-végponton. A gyermekeket és termékeket előbb át kell sorolni. A FK-védelem által jelzett késői termékkapcsolat érthető validációs hibává alakul, a tranzakció visszagördül. Az adatbázis meglévő `nullOnDelete` szülőkapcsolata nem változik: közvetlen modell-/adatbázistörlésnél továbbra is érvényes, a korábbi hierarchiateszt ezt külön ellenőrzi.

## Minimumok és visszatérés

A név legfeljebb 100 karakter, az opcionális minimumok 0 és 2147483647 közötti egész számok. Az üres minimum NULL, a 0 külön beállított érték. A jelenlegi üzleti számítás változatlan: a termékenkénti alapérték a közvetlen kategóriából jön, ha nincs termékszintű felülbírálás; nem öröklődik végig az ősökből. A kategóriaminimum a közvetlen termékek minden raktárból összesített készletére vonatkozik, nem a teljes leszármazotti ágra. Az űrlap ezt mindkét mezőnél jelzi.

Létrehozás, mentés és megszakítás megőrzi a lista szűrését, rendezését és lapozását, korlátozott méretű, titkosított, engedélyezett kulcsokat tartalmazó kontextussal. Külső visszatérési URL-t nem fogad el. Törlés után a pontos kategóriaszűrő és a lapozás törlődik, a többi feltétel megmarad. A Mentés gomb megakadályozza az ismételt beküldést; a böngésző visszaállított űrlapján a `pageshow` esemény visszaengedi a mentést, a mezők tartalmának törlése nélkül. Azonos nevek továbbra is megengedettek. A régi, üres `show` végpont és a két duplikált űrlapsablon megszűnt.

## Ellenőrzés

```powershell
php artisan test --filter=CategoryManagementTest
npm run test:browser -- categories.spec.js
```

A PHP-tesztek keresést, típus- és pontos szűrést, rendezést, lapozást, számlálókat, hierarchiautakat, körtiltást, validációt, törlésvédelmet, visszagördülést, kontextust és jogosultságokat ellenőriznek. A zárolási sorrendhez nem készült új kétfolyamatos MySQL-versenyhelyzetteszt. A böngészőtesztek elkülönített SQLite adatbázison futnak: javaslatválasztás, űrlaphiba javítása, szülőválasztás, rendezés, billentyűzetes menü, törlés megszakítása és mobilnézet. Nincs új migráció vagy seeder.
