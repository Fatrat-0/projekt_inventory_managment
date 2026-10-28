# Készletmozgások felülete

A lista és a PDF ugyanazt a `MovementListing` lekérdezést használja. A PDF minden szűrt találatot tartalmaz; az oldalméret és az aktuális oldalszám nem korlátozza az exportot.

- Többszavas keresés: terméknév, SKU és bizonylatszám/megjegyzés. Minden keresőszónak egyeznie kell legalább az egyik mezőben. A `%`, `_` és `!` karakterek nem keresési helyettesítő karakterek.
- Kombinálható termék-, raktár-, felhasználó-, mozgástípus- és dátumszűrők. A dátumhatárok az alkalmazás által használt időzónában értendők; a zárónap teljes egészében benne van a tartományban.
- Rendezés mindkét irányban dátum, típus, termék, raktár, mennyiség és rögzítő szerint. Egyezés esetén a rekordazonosító stabil másodlagos rendezést biztosít.
- A bevételezés/kiadás összesítése minden szűrt találatot tartalmaz, nem csak az aktuális oldalt. A mennyiség rendezése az előjel nélküli darabszám alapján történik, az irányt a típus jelöli.
- 10/25/50/100 találat oldalanként. Szűrés és rendezés az első oldaltól indul; a lapozó megőrzi a paramétereket.
- A szűrők a „Szűrés alkalmazása” gombbal együtt érvényesíthetők, így dátumtartomány is megadható köztes oldalfrissítések nélkül.
- A készletlekérdező végpont ugyanazt a hitelesítést és szerepkörvédelmet használja, mint a készletmozgások. Nem módosít adatot, és `no-store` gyorsítótár-utasítást küld.
- A rögzítőűrlap kizárólag tájékoztatásra használja a lekért készletet. Mentéskor továbbra is az InventoryService tranzakciós ellenőrzése dönt. Gyors választásváltásnál egy korábbi, később beérkező válasz nem írhatja felül az új választás adatait.
- A bizonylatszám/megjegyzés opcionális, legfeljebb 255 karakter. A mentésgomb a küldés idejére letiltódik; ez nem helyettesít szerveroldali kérésazonosító-alapú deduplikációt.

## Tesztelés

```powershell
php artisan test --filter=MovementListingTest
php artisan test
npm run build
```

30 új funkcionális teszteset: összetett szűrés és PDF-egyezés, szó szerinti keresőkarakterek, teljes zárónap, többoldalas összesítés és export, 6 rendezési mező mindkét irányban, hibás paraméterek, dátumok és tájolás, készletlekérdezés változatlansága és jogosultságai, üres állapot, mentett hivatkozás és HTML-escape.

Böngészőben ellenőrzött: listázás, irányszűrés, PDF-beállítóablak, raktári készletlekérés és hiányjelzés, mobilméretű rögzítőűrlap. A beépített böngésző PDF-panelje üres maradt; a PDF-generálás automatizáltan ellenőrzött, és külön lapon megnyitható előnézet is elérhető.

## Kereshető termékválasztó és visszatérés

A termék- és raktárválasztás automatikus találatlistás keresővel működik az új mozgás űrlapján; a listaszűrő termékválasztója ugyanazt a komponenst használja. Gépeléskor közvetlenül a mező alatt jelennek meg a találatok, külön választómező nélkül. Terméknév/SKU és raktárnév/hely alapján keres, ékezetektől és kis-/nagybetűktől függetlenül. Kattintás vagy nyilak és Enter választ, Escape bezár, a törlésgomb kiüríti a mezőt. A kiválasztott szöveg átírásakor a korábbi azonosító törlődik: tetszőleges beírt szöveg nem menthető érvényes választásként. A találatok jelenleg a böngészőbe betöltött törzsadatokból szűrődnek; nagyon nagy állománynál később szerveroldali keresőre cserélhetők.

Az új mozgás hivatkozása titkosított, hitelesített listaállapotot visz tovább. A Mégse, a visszalépési hivatkozás és a sikeres mentés visszaállítja a keresést, szűrést, rendezést, oldalméretet és oldalszámot. A visszatérés kizárólag a helyi mozgáslistára mutathat; hibás vagy módosított állapotjelző esetén a szűretlen listára tér vissza.

A mezők alatt külön hibaüzenetek jelennek meg, piros kerettel, `aria-invalid` és `aria-describedby` kapcsolattal. A felső összesítő is megmaradt.

## Chrome böngészőtesztek

Előfeltételek: telepített Google Chrome, PHP CLI SQLite-kiterjesztéssel, Composer-függőségek és Node.js.

```powershell
npm install
npm run build
npm run test:browser
```

A Playwright fejlesztői függőségként került a projektbe. A futtató a telepített Chrome-ot használja saját, láthatatlan tesztprofilban, és 127.0.0.1:8019 címen indít tesztszervert. Ha a port foglalt, leáll, és nem használja az ott futó alkalmazást. A futás végén a saját PHP-szerverfolyamatát leállítja.

A tesztek a `tests/Browser/.runtime/database.sqlite` fájlt használják külön tesztfelhasználóval, munkamenetekkel, alkalmazáskulccsal, naplókkal és fordított sablonokkal. Minden futás elején kizárólag ezt az adatbázist állítják alaphelyzetbe. A migráció előtt ellenőrzik a SQLite-meghajtót és a fájl pontos útvonalát; gyorsítótárazott alkalmazáskonfigurációval nem indulnak el. A Laragon MySQL-adatbázisát nem módosítják.

Nyolc böngészős eset:

1. Gépelés közbeni termékjavaslatok, billentyűzetes választás, az elavult azonosító törlése és érvénytelen szöveg elutasítása.
2. Gyors választásváltás: késve érkező régi készletválasz nem írhatja felül az új készletet.
3. Sikertelen készletlekérdezés és sikeres újrapróbálás.
4. A lista beállításainak megőrzése Mégse és sikeres mentés után.
5. Elégtelen készlet mezőhöz kapcsolt hibajelzéssel és a bevitt értékek megőrzésével.
6. Az első beküldés letiltja a mentésgombot, az ismételt submit eseményt a kliens elutasítja. A teszt az első navigációt visszatartja, hogy az állapot ellenőrizhető legyen. Ez továbbra sem szerveroldali deduplikáció.

7. Raktárjavaslatok hely alapján, billentyűzetes választás, Escape és mezőtörlés.
8. Automatikus termékjavaslatok a listaszűrőben és a szűrés megszüntetése.

A PHP-csomag három további tesztet kapott a visszatérési állapotra, a módosított állapotjelző elutasítására és a mezőhibák kapcsolataira. A böngészőtesztek külön futnak a PHPUnit-csomagtól.
