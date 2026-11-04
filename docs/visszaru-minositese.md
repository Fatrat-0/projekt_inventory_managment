# Visszáru minősítése és selejtezése

## Működés

Az új vevői visszáru fizikai bevételezése automatikusan elkülönített készletet növel. Vizsgálat nélkül nem adható ki, nem küldhető vissza beszállítónak, nem mozgatható másik raktárba és nem indítható vele szállítmány. A korábban rögzített visszárukat a migráció nem alakítja át: szabad készletként maradnak meg.

A visszáru adatlapján pozitív egész mennyiséggel, legfeljebb 500 karakteres kötelező indokkal rögzíthető:

- **Felszabadítás:** a minősített mennyiség kiadhatóvá válik; a fizikai készlet változatlan.
- **Selejtezés:** a minősített mennyiséget az elkülönített és a fizikai készletből is levonjuk. Külön kiadási készletmozgás keletkezik, az eredeti visszáruhoz kapcsolva. Ebből új visszáru nem indítható.

Részleges felszabadítás és selejtezés kombinálható. A műveletek összes mennyisége nem haladhatja meg az adott visszáru eredetileg elkülönített mennyiségét; egy másik visszáru elkülönített darabjai nem használhatók fel helyette. A már rögzített minősítés nem szerkeszthető és nem törölhető a felületről. Az eredeti mozgás és visszáru mennyisége, indoka és készletpillanatképe változatlan marad.

A meglévő **admin, manager és worker** szerepkör egyaránt minősíthet. A végrehajtó a bejelentkezett felhasználó; beküldött felhasználó-, termék-, raktár- és egyenlegmezőkkel nem írható felül. Vendégnek bejelentkezés szükséges. Számlázás, pénzvisszatérítés és javítási munkalap nem része ennek a folyamatnak.

A minősítési naplóhoz kapcsolódó felhasználói fiók törlése érthető hibával leáll az adminisztrációs és a saját profil oldalon is. Sikertelen törlés nem jelentkezteti ki a felhasználót.

## Készletek és naplózás

A `stocks.quantity` fizikai mennyiség, a `quarantined_quantity` ennek az elkülönített része. A kiadható mennyiség a különbségük. Minden alkalmazásbeli készletírás ugyanazt az `InventoryService`-t és terméksorzárat használja. Az invariáns:

```text
0 ≤ elkülönített ≤ fizikai ≤ 2 147 483 647
kiadható = fizikai − elkülönített
```

A készletállapot és hiányjelzés kiadható mennyiséggel számol. A fizikai összkészletben és a leltárban az elkülönített áru továbbra is szerepel, mert a raktárban található. A leltár vak nézetében készletmennyiség nem jelenik meg.

A `return_quality_actions` eseménynapló tárolja a forrás visszárut, műveletet, mennyiséget, indokot, végrehajtót, időpontot, fizikai és elkülönített előtte/utána egyenlegeket, valamint selejtezésnél az új készletmozgás azonosítóját. A kapcsolódó történeti rekordokat idegen kulcsok védik a törléstől.

## Tranzakció és ismételt beküldés

A készletírás, audit és selejtezési készletmozgás egyetlen tranzakció. Bármely hiba visszagörgeti mindet. A terméksor zárolása után a forrás és korábbi minősítések zároló aktuális olvasása friss mennyiséghatárt ad, külső REPEATABLE READ tranzakció esetén is.

A korábbi minősítések lekérdezése az aktuálisan beszúrt esemény azonosítója előtt véget ér. A közös termékzár miatt ugyanahhoz a visszáruhoz minden korábbi esemény kisebb azonosítót kapott. Ez a felső határ megakadályozza, hogy az indexbejárás egy másik visszáru folyamatban lévő beszúrására is várjon, miközben az ugyanarra a beküldési kulcsra vár. Ezt a korábban időszakos holtpontot öt ismétlés ellenőrzi a MySQL-futtatóban.

Különböző felhasználóknál is közös a még minősíthető mennyiség: az egymást túllépő elfogadás és selejtezés közül csak az egyik sikerülhet, az összesen megengedett részminősítések viszont mind rögzülhetnek. Egy másik visszáru bevételezése és a korábbi visszáru elfogadása ugyanazon termék készletét sorosan, egymás eredményét megtartva módosítja. Ezeket külön MySQL-versenyhelyzetek ellenőrzik.

A beküldés felhasználónként egyedi UUID-t kap. Azonos kulcs és normalizált üzleti adatok ismétlése a korábbi eredményt adja vissza, a teljes mennyiség minősítése után is. Ugyanaz a kulcs más visszáruhoz, mennyiséghez, művelethez vagy indokhoz nem használható fel. Sikertelen művelet kulcsa újrapróbálható. Az indok eleji/végi üres karaktereit eltávolítjuk; a `0` önmagában érvényes indok.

## Telepítés és bemutatóadat

```powershell
php artisan migrate
php artisan db:seed --class=ReturnQualityDemoSeeder
```

A seeder meglévő `worker@raktar.hu` felhasználót igényel. Saját `DEMO-MINOSEG` terméket, „Bemutató raktár – Minősítés” raktárat és külön kategóriát hoz létre. Valós szolgáltatáshívásokkal rögzít 12 db bevételezést, 8 db kiadást, 8 db vevői visszárut, 3 db felszabadítást és 2 db selejtezést. Végeredmény: **10 db fizikai, 3 db elkülönített, 7 db kiadható**. A 8 db-os minimum miatt a kiadható készlet hiányjelzése is bemutatható.

Az egész példa egyetlen tranzakcióban készül, rögzített beküldési kulcsokkal. Befejezett példa ismételt CLI-futtatása nem duplikál műveletet és nem állít vissza későbbi készletváltozást, átnevezett terméket, cikkszámot, raktárat, kategóriát vagy módosított felhasználói e-mail-címet. Más termék és korábbi történet változatlan marad. A seeder egymás utáni futtatásra készült.

## Ellenőrzés

```powershell
php artisan test --filter=ReturnQuality
php artisan test
npm run test:browser -- return-quality.spec.js movement-returns.spec.js
npm run build
php tests/Concurrency/run.php
```

A célzott PHP-tesztek a részleges és teljes minősítést, kiadható készlet védelmét normál kiadásnál, beszállítói visszárunál, átmozgatásnál és többmegállós szállítmánynál, az auditot, ismételt beküldést, hibás és hamisított adatokat, jogosultságokat, visszagörgetést, történeti adatok megőrzését és az ismételhető bemutatóadatot ellenőrzik. A MySQL párhuzamossági futtató saját véletlen nevű tesztadatbázist használ; az alkalmazás adatbázisát nem módosítja.

Tíz minősítési MySQL-eset egészíti ki a korábbi 26-ot: közös minősítési keret; azonos kulcsú ismétlés; azonos kulcs eltérő művelettel; keret régi külső pillanatképből; háromszoros ismétlés régi pillanatképből; selejtezés és nem kiadható áru kiadásának versenye; felszabadítás és kiadás versenye; azonos kulcs két külön termékhez, külső pillanatképből; első minősítés és felhasználótörlés versenye normál és külső REPEATABLE READ tranzakcióból. A böngészőtesztek részleges felszabadítást/selejtezést, közvetlen API-kísérletet, ismétlést, elavult űrlapot és mobil, JavaScript nélküli működést is vizsgálnak.

A minősítés megosztott felhasználósorzárat tart a tranzakció végéig. Időközben törölt végrehajtót szabályos validációs hiba utasít el. Ha az első minősítés éppen a törlés előellenőrzése és a DELETE közé kerül, az idegen kulcs megvédi a naplót; az adatbázis-hibát aktuális olvasás alapján a fiók megtartását jelző validációvá alakítjuk. Más adatbázis-hibát ez a szűk visszaellenőrzés nem nyel el.

A „Művelet jellege” listaszűrőben a **Minősítésre váró visszáruk** csak a még részben elkülönített tételeket mutatja; a **Selejtezések** a külön kiadási mozgásokat. A listán és a PDF-ben a selejtezés indoka is megjelenik és kereshető. A készletlekérdezés `quantity` mezője kiadható mennyiséget ad, mellette külön `physical_quantity` és `quarantined_quantity` mezővel.
