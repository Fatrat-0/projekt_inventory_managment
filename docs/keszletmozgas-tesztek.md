# Készletmozgások és átmozgatások tesztelése

## Üzleti szabályok

- Bevételezéskor a megadott raktár készlete nő, kiadáskor csökken.
- Kiadáskor és átmozgatás indításakor nincs negatív készlet. A teljes rendelkezésre álló mennyiség felhasználható.
- Csak pozitív egész mennyiség fogadható el, legfeljebb 2 147 483 647. A keletkező készlet sem lépheti túl az adatbázisoszlop tartományát.
- Az átmozgatás indítása levon a forrásból, és `pending` rekordot készít. A cél csak átvételkor kapja meg az árut.
- Átvétel: `pending -> completed`. Visszavonás: `pending -> cancelled`, a mennyiség visszakerül a forrásba. Végleges állapotból nincs újabb átmenet.
- Átmozgatás alatt a raktári készletek és a függőben lévő szállítmányok összege állandó.
- A végrehajtó felhasználót a bejelentkezett munkamenet adja, a kliens nem választhat másik felhasználót.
- A jelenlegi jogosultságok szerint admin, manager és worker is rögzíthet mozgást és kezelhet átmozgatást.

## Megvalósítás és tranzakciók

Az alkalmazás készletváltoztatásait az `App\Services\InventoryService` végzi. A vezérlők ellenőrzött adatokat adnak át. Új hívási hely esetén is szükséges a bemenet ellenőrzése; a szolgáltatás nem helyettesíti a HTTP-validációt.

Minden művelet közös tranzakcióba foglalja a készlet és a mozgásnapló vagy átmozgatási rekord módosítását. A korábbi InventoryMovementObserver megszűnt: a modell közvetlen létrehozása önmagában nem módosít készletet. A demonstrációs seeder eleve kikapcsolt modell-eseményekkel, külön készlet- és naplóadatokat hoz létre, ezért a működése nem változik.

A készletzárolási sorrend: termék, majd átmozgatás (ha van), majd készlet. A beküldési kulccsal ellátott bevételezés/kiadás ezt megelőzően egyedi indexszel lefoglalja a beküldést; az ismétlések részletes működését a `keszletmozgas-biztonsag-es-reszletek.md` írja le. A terméksor `FOR UPDATE` zárolása a még nem létező készletsor létrehozását is sorosítja. Így nem csak a meglévő készletsorokra támaszkodunk. Az átmozgatás állapotát a tranzakción belül, frissen zárolt rekordból ellenőrizzük; egy korábban betöltött modell állapota nem mérvadó.

Ez egyszerű, termékenként sorosított megoldás: eltérő termékek párhuzamosan kezelhetők, ugyanazon termék különböző raktári műveletei is egymásra várnak. Nagy forgalomnál ennek teljesítményét mérni kell. A tranzakció holtpont esetén legfeljebb három próbálkozást tesz.

## Automatikus funkcionális tesztek

```powershell
php artisan test --filter=InventoryWorkflowTest
php artisan test
```

Az `InventoryWorkflowTest` 29 tesztesete ellenőrzi:

- bevételezés, részleges és teljes kiadás, új készletsor létrehozása;
- a nem érintett raktár változatlansága és a napló szerzője;
- hiányzó készlet, elégtelen mennyiség, hibás mennyiségek és idegen kulcsok;
- hibás mozgástípus és azonos forrás-/célraktár elutasítása;
- átmozgatás életciklusa, meglévő célkészlet növelése, ismételt műveletek tiltása;
- készletmegmaradás és túlcsordulás elleni ellenőrzés;
- engedélyezett szerepkörök, vendég és ismeretlen szerepkör elutasítása;
- elavult modellpéldányból végzett ismételt átvétel elutasítása;
- HTML-űrlap hibajelzése és a bevitt értékek megőrzése a munkamenetben;
- visszagörgetés mesterséges mentési hiba esetén, mind a négy műveletnél.

A visszagörgetési tesztek Eloquent-eseményben, a készletmódosítás és a napló/státusz mentése után dobnak kivételt. Az előtte és utána lekért teljes készlet- és átmozgatási állapotot összehasonlítják.

Az alap PHPUnit-konfiguráció memóriabeli SQLite-ot használ. Ez a tesztcsomag önmagában nem bizonyítja a MySQL sorzárolásait.

## Valódi MySQL-párhuzamossági tesztek

```powershell
php tests/Concurrency/run.php
```

Feltételek: futó helyi MySQL, InnoDB táblák, elérhető PHP CLI és a `.env` MySQL-kapcsolatával olyan felhasználó, amely létrehozhat és törölhet tesztadatbázist. A folyamatokat a Composerben már meglévő Symfony Process indítja.

A futtató minden alkalommal saját `inventory_race_test_<16 véletlen hexadecimális karakter>` adatbázist készít. Kizárólag ebben migrál és hoz létre tesztadatokat. A végén kizárólag az adott futásban létrehozott adatbázist törli. Az alkalmazás adatbázisán nem futtat migrációt, ürítést vagy készletmódosítást. A futtató folyamat kényszerített leállításakor a tesztadatbázis megmaradhat.

Minden esetben két külön PHP-folyamat és adatbázis-kapcsolat fut. A szülőfolyamat kezdetben zárolja a terméket; mindkét dolgozó jelzi az indulást, és a teszt ellenőrzi, hogy a zárolás feloldásáig egyik sem fejeződött be. Ezután elengedi a zárat, és ellenőrzi a műveletek eredményét, az egyenlegeket és a naplókat.

Nyolc forgatókönyv:

1. Két egyidejű, 7 darabos kiadás 10 darabos készletből.
2. Két egyidejű átmozgatásindítás ugyanebből a készletből.
3. Két bevételezés egy még nem létező raktári készletsorba.
4. Ugyanazon átmozgatás kétszeri átvétele.
5. Ugyanazon átmozgatás egyidejű átvétele és visszavonása.
6. Két külön átmozgatás érkezése ugyanabba a még nem létező célkészletbe.
7. Kiadás és átmozgatásindítás ugyanabból a forráskészletből.
8. Ugyanazon átmozgatás kétszeri visszavonása.

A szkript bármely hibánál nem nulla kilépési kóddal fejeződik be. A külön MySQL-tesztek nem részei az alap `php artisan test` futásnak. Ezek célzott helyességi tesztek, nem terhelésmérés és nem általános hibamentességi bizonyítékok.

## Még külön vizsgálandó területek

- Nagy adatmennyiség és tartós párhuzamos terhelés teljesítménye.
- Ugyanazon bevételezési/kiadási POST-kérés újraküldését már védi a beküldési kulcs; részletek: `keszletmozgas-biztonsag-es-reszletek.md`. Két külön kulcs továbbra is két külön művelet.
- Közvetlen adatbázis-írás vagy a szolgáltatást megkerülő későbbi kód nem részesül automatikusan a szolgáltatás zárolási védelmében.
- Százalékos kódlefedettséghez Xdebug vagy PCOV szükséges; a tesztek száma nem kódlefedettségi százalék.
