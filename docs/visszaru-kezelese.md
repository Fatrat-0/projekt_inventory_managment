# Visszáru kezelése

Az eredeti készletmozgás adatlapjáról indítható visszáru. Egy kiadás visszáruja növeli, egy bevételezés visszáruja csökkenti ugyanannak a terméknek ugyanabban a raktárban a készletét. Az eredeti naplóbejegyzés megmarad; új, ellentétes irányú mozgás készül `return_of_id` kapcsolattal. Telepítés: `php artisan migrate`.

## Üzleti szabályok

- A termék, raktár, partner és irány az eredeti tételből származik. A végrehajtó a bejelentkezett felhasználó. Ezeket POST-mezővel nem lehet felülírni.
- A mennyiség pozitív egész, legfeljebb 2 147 483 647, és nem lehet több az eredeti mennyiség mínusz az összes korábbi visszáru mennyisége értéknél. Több részleges visszáru összege sem lépheti túl az eredetit.
- Kötelező az indok (legfeljebb 500 karakter), opcionális a bizonylatszám/megjegyzés (legfeljebb 255 karakter). A `0` szöveg megmarad. Az indok a PDF-be is bekerülhet, ezért üzleti bizonylathoz illő szöveg legyen.
- Beszállítói visszaküldéskor a jelenlegi kiadható készletnek is elegendőnek kell lennie. Vevői visszavétel nem lépheti túl a fizikai készletoszlop tartományát.
- Archivált vagy időközben más szerepű partnerrel is visszaküldhető a korábbi ügylet. Új normál készletmozgásnál továbbra is az aktív, megfelelő szerepű partner választható.
- Az új visszáru a partner aktuális nevének pillanatképét tárolja. Az eredeti mozgás partnerneve és adatai nem változnak.
- Korábbi, partner és készletpillanatkép nélküli normál mozgás is visszaküldhető; a hiányzó történeti adatot nem rekonstruáljuk. Az új tétel előtte/utána egyenlege a tényleges aktuális készletből számolódik.
- Visszáruból további visszáru nem indítható. Ez az első változat az eredeti normál mozgásokhoz és az eredeti raktárhoz kötődik; nem változtatja meg a szállítmányok vagy raktárközi átmozgatások életciklusát.
- Az új bejövő vevői visszáru elkülönített készletbe kerül; csak minősítés után válik kiadhatóvá. Részleges felszabadítás és selejtezés is rögzíthető, lásd: `visszaru-minositese.md`. A korábbi visszáruk szabad készletét a migráció megőrzi.
- A meglévő admin, manager és worker szerepkör használhatja. Vendégnek hitelesítés, ismeretlen szerepkörnek 403, hiányzó rekordnak 404 jár.

## Tranzakció és ismételt beküldés

Az `InventoryService::recordReturn()` ugyanazt a készletírási mechanizmust használja, mint a normál mozgások. A zárolási sorrend: beküldési kulcs foglalása → termék → eredeti mozgás és korábbi visszáruk → partner, ha van → készlet. A korábbi visszáruk mennyisége zároló olvasásból származik, így külső REPEATABLE READ tranzakcióban sem támaszkodik régi összesítésre. A terméksor közös zárolása a más készletműveletekkel való versenyzést is sorosítja.

A mennyiségi ellenőrzés, készletmódosítás, új mozgás és beküldési foglalás egy tranzakcióban történik. Bármelyik hiba mindent visszagörget; sikertelen beküldés kulcsa újrapróbálható. Az eredeti mozgást idegen kulcs védi a törléstől, ha visszáru tartozik hozzá.

A visszáru a meglévő `inventory_movement_requests` táblában foglal kulcsot. A hash tartalmazza a `return` műveletjelölést, az eredeti mozgás azonosítóját, végrehajtót, mennyiséget, indokot és hivatkozást. Ugyanaz a felhasználó/kulcs/üzleti tartalom ugyanazt az eredményt adja vissza, teljes visszaküldés után is. Más tartalom, más eredeti tétel vagy normál mozgás nem használhatja fel ugyanazt a kulcsot. A normál mozgások korábbi hash-formátuma változatlan.

Az ismételt beküldés korábbi eredményét megosztott zároló olvasással kérjük vissza. Ez külső REPEATABLE READ tranzakcióból is friss adatot olvas, de nem próbálja kizárólagos zárrá alakítani a duplikált kulcs miatti megosztott zárat. Három egyidejű, külső tranzakcióból érkező ismétlésnél a korábbi `FOR UPDATE` olvasás holtpontot okozott; ezt külön MySQL-regressziós eset reprodukálja.

## Felület, keresés és PDF

Az eredeti adatlapon látszik a visszaküldött és a még visszaküldhető mennyiség, valamint a kapcsolódó visszáruk története (10 bejegyzés oldalanként). Túl nagy oldalszám az utolsó elérhető oldalra irányít, a listaállapot megőrzésével. Teljes visszaküldés után új visszáru nem indítható. A visszáru adatlapján az eredeti mozgásra mutató hivatkozás és az indok szerepel.

Az űrlap a friss készletet is lekéri, a megadott mennyiséget és indokot hiba után megőrzi. A Mégse és az eredeti adatlapra visszalépés megőrzi a titkosított listaállapotot. Az űrlap JavaScript nélkül is beküldhető. Böngészőből visszaállítva a Mentés újra engedélyeződik, a beküldési kulcs megmarad.

A mozgáslistában külön jelzés és eredeti mozgásazonosító különbözteti meg a visszárut. A „Művelet jellege” szűrő összes műveletet, normál mozgást, visszárut, minősítésre váró visszárut vagy selejtezést választ. A szöveges keresés az indokban is keres, továbbra is literális `%`, `_` és `!` karakterekkel. A `kind` szűrő megmarad a dátumgyorsszűrőkben, a visszatérési kontextusban és a PDF-exportban.

A PDF a visszáru jelzést és az eredeti mozgás számát a meglévő oszlopokban mutatja. Az indok a hivatkozás/megjegyzés bekapcsolásakor szerepel. Ha a bizonylatszám, indok és partnernév együtt túl hosszú lenne a táblázatsorhoz, az indok teljes szövege külön részletező blokkba kerül, a sorból a visszáru azonosítója hivatkozik rá. A visszáruk beleszámítanak a fizikai bevételezés/kiadás összesítésébe, a készletirányuknak megfelelően; ezek a számok nem értékesítési vagy beszerzési forgalmat jelentenek.

## Bemutatóadat és tesztek

```powershell
php artisan db:seed --class=MovementReturnDemoSeeder
php artisan test --filter=MovementReturnTest
npm run test:browser -- movement-returns.spec.js
php tests/Concurrency/run.php
```

A külön seeder meglévő `worker@raktar.hu` bemutatófelhasználót igényel; a teljes `DatabaseSeeder` is meghívja. Elkülönített `DEMO-VISSZARU` terméket, raktárat és két partnert készít. 12 db beszállítás, 5 db vevői kiadás, 3 db beszállítói visszaküldés, majd 2 és 3 db vevői visszavétel keletkezik, 9 db végeredménnyel. Az egész példa egyetlen tranzakció, rögzített kulcsokkal. Befejezett példa ismételt futtatása nem változtat készletet, és megőrzi a felhasználó módosításait. A seeder egymás utáni CLI-futtatásra készült.

A PHP-tesztek a két irányt, részleges/teljes és összesített korlátot, stale űrlapot, készlethiányt/túlcsordulást, bemeneti hibákat, hamisított örökölt mezőket, idempotenciát, kulcsütközéseket, több felhasználót, archivált partnert, régi adatokat, jogosultságot, teljes visszagörgetést, FK-védelmet, lapozást, keresést/PDF-et és az ismételhető seedet vizsgálják. A böngészőtesztek az elkülönített SQLite alkalmazásban járják végig a rögzítést, kapcsolódó előzményeket, hibajavítást, ismételt beküldést, listaállapotot, mobil- és JavaScript nélküli működést.

A MySQL-futtató saját, véletlen nevű adatbázisban, külön PHP-folyamatokkal ellenőrzi a valódi InnoDB-zárakat. A hét visszáru-eset: közös mennyiségi keret külön kulcsokkal; azonos kulcsú ismétlés; azonos kulcs eltérő mennyiséggel; beszállítói visszáru és normál kiadás versenye; mennyiségi keret és ismétlés régi külső REPEATABLE READ pillanatképpel; végül három egyidejű ismétlés ugyanilyen külső tranzakcióból. Ellenőrzi a készletet, az audit-egyenlegeket, a mozgások és beküldési foglalások számát is. A tesztadatbázist a futás végén eltávolítja; az alkalmazás adatbázisán nem módosít adatot.
