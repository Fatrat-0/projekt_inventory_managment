# Partnerek

A Partnerek oldal a vevők és beszállítók önálló törzsadat-nyilvántartása. A jelenlegi adatmodellben nincs kapcsolatuk készletmozgással, átmozgatással vagy szállítmánnyal. A fejlesztés nem ad új mezőt, migrációt vagy seeder-követelményt.

## Lista és keresés

A névben, e-mailben és telefonszámban több szóval lehet keresni: minden szónak valamelyik mezőben szerepelnie kell. A `%`, `_` és `!` literális keresőkarakter. A közös keresőkomponens gépeléskor legfeljebb nyolc, ékezetfüggetlenül kereshető javaslatot ad. A név mellett az elérhetőségek és az ID segítenek az azonos nevű partnerek megkülönböztetésében. A javaslat pontos partnerazonosítóra szűr, a kereső átírása megszünteti ezt a pontos szűrést. A javaslatválasztó billentyűzettel, Escape-pel és Tab-bal is használható.

A vevő/beszállító szűrő és az elérhetőségszűrő automatikusan frissít, az éppen beírt keresőszöveg megtartásával. A Hiányos elérhetőség legalább egy hiányzó mezőt jelent; a Mindkét elérhetőség megadva mindkét mezőt megköveteli. NULL, üres és szóközös régi érték is hiánynak számít, a `0` szöveg nem. Minden szűrő ÉS kapcsolatban működik, a hiányos mezők VAGY feltétele nem kerülheti meg a többi szűrőt.

Asztalon a név, típus, e-mail és telefon oszlopfejléce rendez, az irányt nyíl és `aria-sort` jelzi. Az egyszerű kattintás a még be nem küldött mezőértékeket is figyelembe veszi; módosítóbillentyűvel a szokásos hivatkozásnyitás működik. A típusrendezés a magyar feliratokat követi: növekvő sorrendben Beszállító, majd Vevő. Az üres elérhetőségek mindkét irányban a lista végére kerülnek. A stabil másodlagos rendezési kulcs az ID.

Mobilon kártyák és rendezésválasztók jelennek meg. A lapméret 15/25/50/100, a vezérlő a lista alatt található; a szűrés és rendezés az első oldalra állítja a listát. Elavult oldalszám az utolsó érvényes oldalra irányít. Hibás HTML-listaparaméter alapnézetre áll vissza érthető hibaüzenettel, JSON-nál 422-es mezőhibát ad.

## Létrehozás, szerkesztés, törlés

A névre vagy a hárompontos menü Szerkesztés elemére kattintva közös űrlap nyílik. A név legfeljebb 100 karakter, a típus kötelezően vevő vagy beszállító. Az opcionális e-mail legfeljebb 100 karakter és formailag érvényes cím; nincs DNS-lekérdezés. Az opcionális telefon szabad formátumú, legfeljebb 20 karakteres szöveg, így előhívó és mellék is tárolható. Minden szöveges mező szerveroldali típusellenőrzést is kap. Az üres opcionális mezők NULL-ra állnak, a kontaktadatok külön törölhetők. A partner ID-ját a route határozza meg, nem az űrlap tetszőleges mezője.

Azonos nevek és közös e-mail-címek továbbra is megengedettek. Az e-mail-cím kattintható `mailto:` hivatkozás, URI-kódolt címrésszel; ez önmagában nem küld levelet. A hiányzó mezők Nincs megadva jelzést kapnak. A nevek, elérhetőségek és javaslatok kimenete escape-elt.

Beszállítóra/vevőre szűrt listából új partnert nyitva az adott típus előre kiválasztott. A szűrés, rendezés, lapméret és lapozás létrehozás, szerkesztés, mentés és megszakítás során korlátozott méretű, titkosított és whitelistes kontextusban megmarad. Külső visszatérési URL nem fogadható el. Törléshez megerősítés szükséges; siker után a törölt pontos partnerazonosító és az oldalszám eltűnik, a többi feltétel megmarad. Nem létező rekord szerkesztése/módosítása/törlése 404-et ad.

A Mentés gomb védi a felületet az ismételt beküldéstől; a visszaállított böngészőűrlapon újra engedélyeződik, a mezők tartalmának elvesztése nélkül. Ez nem szerveroldali idempotenciagarancia. A jelenlegi önálló partnerrekord közvetlenül törölhető; nincs alkalmazott archiválási vagy bizonylat-törlésvédelmi szabály.

## Jogosultság és tesztek

A meglévő hozzáférés megmarad: admin, manager és worker végigviheti a teljes partnerkezelést. Vendég bejelentkezési oldalra kerül, ismeretlen szerepkör 403-at kap. A nézet minden engedélyezett szerepkörnél ugyanazokat a műveleteket kínálja.

```powershell
php artisan test --filter=PartnerManagementTest
npm run test:browser -- partners.spec.js
```

A PHP-tesztek kombinált keresést és szűrést, literális karaktereket, hiányzó kontaktadatokat, stabil rendezést és lapozást, írási validációt, opcionális mezők törlését, kontextust, szerepköröket, XSS-kimenetvédelmet és 404-es eseteket ellenőriznek. A böngészőtesztek az elkülönített SQLite alkalmazást használják: keresőjavaslatok, automatikus szűrés, űrlaphiba javítása, minden rendezési oszlop, lapozás, billentyűzetes menü, törlés megszakítása/megerősítése, 320/390 pixeles mobilnézet és visszaállított űrlap. A felhasználó Laragon-adatbázisát nem módosítják.
