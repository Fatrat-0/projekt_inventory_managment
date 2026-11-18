# Átmozgatási listák keresési javaslatai

Az Igények, Fuvarok, Kitárolás, Átvétel és Betárolás keresője a meglévő közös `listing-search` megjelenítését használja, szerverről lekért javaslatokkal. Gépelés után 200 ms késleltetéssel legfeljebb nyolc találat jelenik meg. A terméknév/azonosító mellett cikkszám, hivatkozás vagy útvonal segíti a választást.

A javaslat kattintással vagy a fel/le nyilak és Enter használatával választható. Kiválasztáskor az adott azonosítóra/cikkszámra indul a meglévő szöveges keresés. Ez nem közvetlen adatlapmegnyitás vagy új egyedi-ID-szűrő: egy fuvar több célmegállója, illetve egy termék több átvételi tétele is megjelenhet. Enter kijelölt javaslat nélkül a beírt szövegre keres; Escape és Tab bezárja a listát.

A beküldés megtartja az aktuális keresési űrlap szűrőit, a két rendezési szintet és a lapméretet, majd az első találati oldalra lép. A javaslatok az összes jogosult, szűrt rekord közül érkeznek, nem csak a látható találati oldalról.

## Meglévő végpontok használata

Mind az öt lista saját GET-címe `suggestions=1` és `search=...` paraméterrel, `Accept: application/json` fejléc mellett ad javaslatot. A válasz `items` és `has_more` mezőkből áll; minden elem `key`, `value`, `name`, `detail` adatot tartalmaz. A `key` egyedi rekordazonosító a biztonságos rendereléshez, a `value` a meglévő kereső által értelmezhető szöveg.

Az eredeti Gate/policy és szűrési lekérdezés marad érvényben. A lekérés legfeljebb kilenc rekordot olvas; a kilencedik csak a további találatok jelzésére szolgál. Üres keresésre üres lista, hibás paraméterekre JSON-validációs válasz érkezik. A válasz `private, no-store`, és nem módosít készletet, foglalást vagy más üzleti adatot.

## Kliensoldali védelem

A függő kérést új bevitel, törlés, bezárás vagy szűrőváltás megszakítja. A kérés sorszáma és az aktuális űrlap lenyomata külön ellenőrzött, így megszakíthatatlan vagy későn visszaérkező régi válasz sem alkalmazható. A listában nincs HTML-ként értelmezett szerveradat; a szövegek `x-text` megjelenítést kapnak.

A kompozíciós bevitel külön kezelt. A befejezetlen bevitel alatti beküldést a form capture fázisában tiltjuk le, a szülő betöltési jelzésének aktiválása előtt; a billentyűeseményt és az operációs rendszer kompozíciós befejezését nem blokkoljuk. Ezt célzott próba igazolja: a Szűrés gomb nem marad letiltva, és a kész szöveggel a keresés folytatható. Hálózati hiba esetén a kereső megtartja a beírt szöveget és a normál Enteres keresési lehetőséget. A meglévő, helyi adatokkal működő keresők változatlan módot használnak; a távoli javaslatkereső csak a fenti öt listán aktív.

## Ellenőrzés

- 35 kapcsolódó Laravel-teszt sikeres, 893 ellenőrzéssel, memóriabeli SQLite-adatbázison.
- A végleges 30 célzott Playwright-teszt sikeres: az öt új kereső, az eredeti statikus keresők, a rendezési párbeszéd/BFCache és az átvételi-betárolási folyamatok is ellenőrzöttek.
- A Vite build sikeres, 96 modul.

A böngészős próbák fedik a frissen szerkesztett szűrők megőrzését, lapozás alaphelyzetbe állítását, billentyűzetes választást, szabad szöveges Entert, törlést, Escape/Tab bezárást, ismétlődő keresési értékek egyedi renderelését, hálózati hibát és megszakítás nélkül későn visszaérkező válaszok kizárását. A mobilos javaslatok tényleges kattinthatóságát és a kompozíció alatti beküldési védelmet is vizsgálják.

A rendezési teszt segédjében az Alkalmazás után az új fődokumentum navigációját és betöltését kell megvárni; az azonos rendezési mezőt tartalmazó régi URL egyezése önmagában nem elegendő. A korábban észlelt ilyen tesztidőzítési hiba javult, az irányokra és szűrőkre vonatkozó ellenőrzések megtartásával.

Nem készült adatbázismigráció, képcsomag vagy ZIP. A helyi Laragon-adatbázis üzleti adatait a tesztfuttatások nem módosítják. A teljes alkalmazás tesztkészlete ebben a lépésben nem futott újra; a fenti eredmények célzott ellenőrzések.
