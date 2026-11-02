# Partnerek

A Partnerek oldal a vevők és beszállítók önálló törzsadat-nyilvántartása. A jelenlegi adatmodellben nincs kapcsolatuk készletmozgással, átmozgatással vagy szállítmánnyal. Az archiválás nullable, indexelt `archived_at` mezőt ad hozzá: telepítéskor `php artisan migrate` szükséges. A meglévő partnerek aktívak maradnak, újraseedelés nem szükséges.

## Lista és keresés

A névben, e-mailben és telefonszámban több szóval lehet keresni: minden szónak valamelyik mezőben szerepelnie kell. A `%`, `_` és `!` literális keresőkarakter. A közös keresőkomponens gépeléskor legfeljebb nyolc, ékezetfüggetlenül kereshető javaslatot ad. A név mellett az elérhetőségek és az ID segítenek az azonos nevű partnerek megkülönböztetésében. A javaslat pontos partnerazonosítóra szűr, a kereső átírása megszünteti ezt a pontos szűrést. A javaslatválasztó billentyűzettel, Escape-pel és Tab-bal is használható.

A vevő/beszállító szűrő és az elérhetőségszűrő automatikusan frissít, az éppen beírt keresőszöveg megtartásával. A Hiányos elérhetőség legalább egy hiányzó mezőt jelent; a Mindkét elérhetőség megadva mindkét mezőt megköveteli. NULL, üres és szóközös régi érték is hiánynak számít, a `0` szöveg nem. Minden szűrő ÉS kapcsolatban működik, a hiányos mezők VAGY feltétele nem kerülheti meg a többi szűrőt.

Asztalon a név, típus, e-mail és telefon oszlopfejléce rendez, az irányt nyíl és `aria-sort` jelzi. Az egyszerű kattintás a még be nem küldött mezőértékeket is figyelembe veszi; módosítóbillentyűvel a szokásos hivatkozásnyitás működik. A típusrendezés a magyar feliratokat követi: növekvő sorrendben Beszállító, majd Vevő. Az üres elérhetőségek mindkét irányban a lista végére kerülnek. A stabil másodlagos rendezési kulcs az ID.

Mobilon kártyák és rendezésválasztók jelennek meg. A lapméret 15/25/50/100, a vezérlő a lista alatt található; a szűrés és rendezés az első oldalra állítja a listát. Elavult oldalszám az utolsó érvényes oldalra irányít. Hibás HTML-listaparaméter alapnézetre áll vissza érthető hibaüzenettel, JSON-nál 422-es mezőhibát ad.

## Létrehozás, szerkesztés, törlés

A névre vagy a hárompontos menü Szerkesztés elemére kattintva közös űrlap nyílik. A név legfeljebb 100 karakter, a típus kötelezően vevő vagy beszállító. Az opcionális e-mail legfeljebb 100 karakter és formailag érvényes cím; nincs DNS-lekérdezés. Az opcionális telefon szabad formátumú, legfeljebb 20 karakteres szöveg, így előhívó és mellék is tárolható. Minden szöveges mező szerveroldali típusellenőrzést is kap. Az üres opcionális mezők NULL-ra állnak, a kontaktadatok külön törölhetők. A partner ID-ját a route határozza meg, nem az űrlap tetszőleges mezője.

Azonos nevek és közös e-mail-címek továbbra is megengedettek, az egyezések áttekintésének megerősítésével. Az e-mail-cím kattintható `mailto:` hivatkozás, URI-kódolt címrésszel; ez önmagában nem küld levelet. A hiányzó mezők Nincs megadva jelzést kapnak. A nevek, elérhetőségek és javaslatok kimenete escape-elt.

Beszállítóra/vevőre szűrt listából új partnert nyitva az adott típus előre kiválasztott. A szűrés, rendezés, lapméret és lapozás létrehozás, szerkesztés, mentés és megszakítás során korlátozott méretű, titkosított és whitelistes kontextusban megmarad. Külső visszatérési URL nem fogadható el. Törléshez megerősítés szükséges; siker után a törölt pontos partnerazonosító és az oldalszám eltűnik, a többi feltétel megmarad. Nem létező rekord szerkesztése/módosítása/törlése 404-et ad.

A Mentés gomb védi a felületet az ismételt beküldéstől; a visszaállított böngészőűrlapon újra engedélyeződik, a mezők tartalmának elvesztése nélkül. Ez nem szerveroldali idempotenciagarancia. A jelenlegi önálló partnerrekord az archiválás mellett továbbra is véglegesen törölhető, külön megerősítéssel; nincs bizonylat-törlésvédelmi kapcsolat.

## Archiválás és visszaállítás

Az Állapot szűrő alapértéke Aktív partnerek; választható Archivált partnerek vagy Összes állapot. A keresőjavaslatok ugyanezt az állapotkört követik, de a többi listaszűrő nem szűkíti őket. A pontos partnerszűrés chipje akkor is megmarad, ha a választott állapot miatt a rekord éppen nem látszik.

A műveleti menüben az aktív rekord archiválható, az archivált visszaállítható. Archiváláskor megerősítés szükséges; archivált rekordnál jelzés látszik a listában, mobilkártyán és az űrlapon. Az űrlapon külön visszaállítási gomb is van. A rekord szerkeszthető marad, a szokásos adatmentés nem állítja vissza automatikusan.

Mindkét állapotváltás tranzakcióban zárolja a partnert. Az ismételt, azonos irányú kérés nem változtatja meg az időbélyegeket. Az ID, létrehozási idő és kontaktadatok megmaradnak. A névjegyzék állapota és többi szűrője megmarad a visszatérési kontextusban, a pontos azonosító és oldalszám törlődik. Új partner mindig aktívan jön létre, függetlenül a kiinduló listaszűrőtől. Az archiválási mező nem állítható a közönséges létrehozó/szerkesztő payloadon keresztül.

Nincs globális Eloquent-szűrő: a seeder meglévő archivált partnert is megtalál, nem hozza létre ismét és nem állítja vissza. A lista állapotszűrése kifejezett. A jelenlegi jogosultságok vonatkoznak az új PATCH-végpontokra is.

## Lehetséges duplikációk

Gépeléskor 350 ms késleltetéssel ellenőrizzük a nevet és e-mailt. Az egyezés teljes, normalizált értékre vonatkozik: névnél a szóközök összenyomása és Unicode-kisbetűsítés, e-mailnél külső szóközök levágása és kisbetűsítés. Nem részszavas vagy ékezetelhagyásos hasonlósági keresés. Üres mező nem egyezés. Név VAGY e-mail egyezés elegendő, ugyanaz a rekord egyszer szerepel. A szerkesztett partner kimarad, az archivált partnerek viszont részt vesznek az ellenőrzésben.

Legfeljebb öt találat látszik, a teljes találatszámmal, az e-mail-címmel, az egyezés okával és archivált jelzéssel; ezek JavaScript nélkül is megjelennek. A meglévő rekord új lapon nyitható meg, így a kitöltött űrlap megmarad. A felhasználó jelölőnégyzettel megerősítheti az egyezések áttekintését, majd külön rekordként menthet. Nincs automatikus összevonás vagy egyediségkényszer.

A szerver mentés előtt is ellenőriz. JavaScript nélkül, hálózati hiba vagy még folyamatban lévő kérés esetén is figyelmeztető űrlapra tér vissza, adatírás nélkül. Megerősítés után lehet menteni. A HMAC a normalizált névhez/e-mailhez, a létrehozás/szerkesztés hatóköréhez és a teljes aktuális egyezéslistához kötött; változott mező, új találat vagy archiválási állapot esetén új megerősítés kell. Ez figyelmeztető munkafolyamat, nem párhuzamos írásokat kizáró egyediségellenőrzés.

A korábbi AJAX-kérés megszakad, és sorszámvédelem is megakadályozza, hogy lassú válasz felülírja az új mezőállapotot. Hiba esetén újrapróbálás és a szerveroldali út is rendelkezésre áll. A GET-ellenőrzések hitelesítéshez/szerepkörhöz kötöttek, percenként 60 kérésre korlátozottak és `no-store` választ adnak. A normalizálás PHP-ban történik, hogy MySQL és SQLite alatt azonos legyen a viselkedés; a jelenlegi megoldás a partnerrekordok átvizsgálásával dolgozik, nagy névjegyzéknél normalizált, indexelt keresőmezőkre érdemes váltani.

## Jogosultság és tesztek

A meglévő hozzáférés megmarad: admin, manager és worker végigviheti a teljes partnerkezelést. Vendég bejelentkezési oldalra kerül, ismeretlen szerepkör 403-at kap. A nézet minden engedélyezett szerepkörnél ugyanazokat a műveleteket kínálja.

```powershell
php artisan test --filter=PartnerManagementTest
php artisan test --filter=PartnerArchiveAndDuplicatesTest
npm run test:browser -- partners.spec.js
```

A PHP-tesztek kombinált keresést és szűrést, literális karaktereket, hiányzó kontaktadatokat, stabil rendezést és lapozást, írási validációt, opcionális mezők törlését, kontextust, szerepköröket, XSS-kimenetvédelmet és 404-es eseteket ellenőriznek. A böngészőtesztek az elkülönített SQLite alkalmazást használják: keresőjavaslatok, automatikus szűrés, űrlaphiba javítása, minden rendezési oszlop, lapozás, billentyűzetes menü, törlés megszakítása/megerősítése, 320/390 pixeles mobilnézet és visszaállított űrlap. A felhasználó Laragon-adatbázisát nem módosítják.

Az archiválás tesztjei az állapotszűrést, az ismételt állapotváltást, a jogosultságokat és az újraseedelés utáni megőrzést is vizsgálják. A duplikációjelzés ellenőrzése kiterjed a normalizálásra, az archivált egyezésekre, az öt találatos megjelenítési korlátra, a hamis vagy elavult megerősítésre, a közben módosított/törölt egyezésekre és az egyéb mezőhiba után megőrzött érvényes megerősítésre. Böngészőben a lassú és hibás válasz, a folyamatban lévő ellenőrzés közbeni mentés, az időközben archivált egyezés és a JavaScript nélküli munkafolyamat is tesztelt.
