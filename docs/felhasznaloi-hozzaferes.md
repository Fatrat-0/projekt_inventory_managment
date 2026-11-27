# Felhasználói hozzáférés és átmozgatási jogosultságok

A teljes HTTP-jogosultsági ellenőrzést, a személyesadat-leltárt és a megőrzési döntéseket az [adatvédelmi audit](adatvedelmi-audit.md) részletezi.

A nyilvános regisztráció megszűnt. A **Felhasználók** oldalt aktív adminisztrátor éri el a saját jelszava beállítása után. Az összesítők a teljes rendszer aktív, inaktív és összes fiókját, valamint az aktív, raktári tagság nélküli nem adminisztrátori fiókokat számolják. A mutatók a megfelelő listát nyitják meg. A név és e-mail alapján használható keresés globális szerepkörrel, állapottal és raktári tagsággal kombinálható. A rendezés a teljes szűrt listára vonatkozik.

Az adatlapon megtekinthető a fiók állapota, globális szerepköre, valamennyi raktári szerepköre és a jogosultsági előzmény. Szerkesztéskor a jelölőnégyzetekkel raktáranként egy vagy több helyi szerepkör választható. A tagság megszüntetéséhez az **Eltávolítás** művelet használható. A megerősítés külön mutatja az új és megszűnő tagságokat, a hozzáadott és eltávolított szerepköröket, valamint a tényleges jogosultságváltozást. Ütközéskor a rendszer megmutatja a friss adatokat, megőrzi a szerkesztett űrlapot és új megerősítést kér.

## Fiókkiadás és hozzáférés visszavonása

Az új fiók alapértelmezett szerepköre raktáros. Kézbesítő levelezőnél az adminisztrátor egyszer használható, alapbeállítás szerint 60 percig érvényes jelszóbeállító linket küldhet. A kézbesítés sikertelensége esetén a már létrehozott fiók adatlapjáról újra próbálható a küldés. A nyilvános jelszóemlékeztető minden szabályos e-mail-címre egységes visszajelzést ad, és csak aktív fiókra küld linket. Kézbesítés nélküli környezetben az adminisztrátor ideiglenes jelszót adhat, amelyet a felhasználó az első belépéskor köteles lecserélni. Az ideiglenes jelszót biztonságos csatornán kell átadni. A csere előtt csak a jelszóbeállító felület és a kijelentkezés érhető el.

Az inaktiválás, a szerepkör és a tagság módosítása, valamint az e-mail-cím helyesbítése visszavonja a korábbi munkameneteket, a megjegyzett belépést és a meglévő jelszó-visszaállító tokent. E-mail-cím módosításakor a régi címhez tartozó token és a cím korábbi hitelesítése is érvényét veszti. Saját profilban név és e-mail módosítható. Saját e-mail-helyesbítésnél az aktuális munkamenet új azonosítót és hozzáférési verziót kap. A már megnyitott megerősítés és az UUID-val ismételt üzleti kérés szerveroldali ellenőrzésen megy át. Az időben előbb, érvényes jogosultsággal lezárt művelet, a meglévő készlet, foglalás, fuvar, igény és audit megmarad. A visszavonás után új belépés szükséges. Sikeresen letöltött dokumentum példányát az alkalmazás nem tudja visszavonni.

Az adminisztrátorok állapot- és szerepkörváltása az aktív adminisztrátori fiókok azonos sorrendű zárolásával történik. A tranzakció a zárolt állapotból számolja a használható adminisztrátorokat. Ehhez aktív fiók, adminisztrátori szerepkör és lezárt első jelszóbeállítás kell. Az utolsó használható adminisztrátort nem lehet inaktiválni vagy lefokozni. A függő jelszóbeállítású fiók csak saját jelszava beállítása után számít használhatónak. Ekkor a fiók szerkesztési verziója is megváltozik. A saját fiókot másik adminisztrátor inaktiválhatja.

A helyesbítés törli az előző `email_verified_at` értéket. A jelenlegi `User` modellben a Laravel általános e-mail-hitelesítési kötelezettsége nincs bekapcsolva. A linkes fiókkiadás a fiókhoz rendelt postafiók elérését használja az első jelszó beállításához.

Az e-mail-cím módosításához a mentést végző felhasználó jelenlegi jelszava szükséges a saját profilon és az adminisztrátori űrlapon egyaránt. Az opcionális e-mail-hitelesítés naplózó levelezővel nem küld hivatkozást. A már kiküldött e-mail-hitelesítő link az aktuális címre ellenőriz.

## Jogosultsági mátrix

| Globális szerepkör | Közös jogosultságok |
| --- | --- |
| Raktáros (`worker`) | Közös termék- és partnertörzs, készletlista, vezérlőpult, készletmozgások és a közös leltár- és mozgási PDF-ek. A raktári munkafolyamatokat az alábbi helyi szabályok szűrik. |
| Raktárvezető (`manager`) | A raktáros közös jogosultságain felül kategória- és raktártörzs, raktári statisztika és leltár PDF, minimumkészlet, alaprajz és tárolórendszer konfigurálása. A helyi munkafolyamatokhoz ugyanolyan tagság kell, mint a raktárosnak. |
| Adminisztrátor (`admin`) | Felhasználókezelés és hozzáférés minden raktárhoz. A raktári tagság nem szűkíti a globális hozzáférését. Új helyi szerepkör adminisztrátori fiókhoz ezért nem adható. |

A meglévő helyi tagságok előléptetéskor megőrizhetők. Lefokozáskor ezek a megtartott tagságok határozzák meg a helyi jogosultságot. A szerepkörváltás önmagában nem töröl vagy hoz létre tagságot.

Ugyanabban a raktárban a szerepkörök jogosultságai összeadódnak. A **Kitároló + Átvevő** jogosít igény benyújtására, kitárolásra, manuális kiadásra, forrásoldali fuvar megtekintésére, átvételre, betárolásra, belső áthelyezésre és a terhelésadatok megtekintésére. Az igényelbírálás, készletfoglalás, feladatkiadás, fuvartervezés, feladás és a terhelés rögzítése továbbra is felügyelői jog. A **Felügyelő + Kitároló** kombinációban a kitárolási jogosultság egyszer szerepel. A felület megőrzi a külön kiválasztott szerepköröket, ezért a felügyelői szerepkör későbbi levétele után a kitárolói feladatok megmaradnak. Másik raktár jogosultságai minden esetben külön érvényesülnek.

A felhasználómentés `memberships` listájában raktáranként egy `warehouse_id` és egy legalább egy szerepkörkódot tartalmazó `roles` lista adható meg. A teljes lista kifejezett beküldésekor a kihagyott tagságok megszűnnek. Az önálló név- vagy e-mail-módosítás a `memberships` mező elhagyásával megtartja az aktuális tagságokat. A böngészős űrlap a beküldés végén külön mezőt küld, így a kéréshatár miatt csonkolt űrlap nem távolíthat el jogosultságot.

A közös készlet- és mozgási listák, az alaprajz és a kapacitásadatok olvasása a meglévő globális szabályok szerint elérhető. A manuális bevételezés felvétele, a visszáru rögzítése és a minősítés szintén közös jogosultsági kör. Az ezek után induló raktári feladatokhoz külön helyi jogosultság kell. Az alábbi lista a célhoz kötött műveleteket mutatja. A döntésnél mindig az adott művelethez tartozó forrás- vagy célraktár számít.

| Művelet | Helyi szerepkör |
| --- | --- |
| Új átmozgatási igény létrehozása | Igénylő (`requester`), kitároló, átvevő vagy felügyelő legalább az egyik érintett raktárban. A saját igény megtekinthető, függő állapotban visszavonható. |
| Más által indított igény megtekintése | Forrásoldali kitároló vagy felügyelő, illetve céloldali átvevő vagy felügyelő. |
| Jóváhagyás, elutasítás, készletfoglalás és -feloldás, kitárolási feladat kiadása | Forrásoldali felügyelő (`supervisor`). |
| Kitárolási feladat futtatása, manuális készletkiadás (OUT) | Forrásoldali kitároló (`dispatch_operator`) vagy felügyelő. |
| Új fuvar tervezése és feladása | Forrásoldali felügyelő. A kitároló a forrásoldali fuvaradatokhoz olvasási jogot kaphat. |
| Járműlista és járműtörzs | A forrásoldali kitároló vagy felügyelő olvashatja az aktív járművek listáját és a saját forrásraktári fuvarok kapcsolatait. A járműtörzset csak adminisztrátor szerkesztheti. |
| Fuvar átvétele és az átvett áru betárolása, illetve manuális bevétel betárolása | Céloldali átvevő (`receiving_operator`) vagy felügyelő. |
| Belső áthelyezés és közvetlen tárhelyre helyezés vagy levétel | Az érintett raktár kitárolója, átvevője vagy felügyelője. |
| Rekeszterhelés ellenőrzése | Az érintett üzemi raktári szerepkör megtekinthet. Az ellenőrzést a helyi felügyelő vagy az adminisztrátor könyvelheti. |

Raktári tagság nélküli raktáros vagy raktárvezető a globális felületeket és a saját korábbi igényeit elérheti. További tagság nélkül nem nyújthat be új igényt, nem kezelhet más által kiadott raktári feladatot és nem végezhet helyi szerepkörhöz kötött készletműveletet. A dokumentumok és közvetlen JSON-végpontok a megfelelő üzleti jogosultságot kérik.

## Az átmozgatási folyamatok

A régi önálló átmozgatás indításkor vonja le a forráskészletet. A régi többmegállós fuvar tervezete külön készül, a készlet a feladáskor csökken. A régi fuvar átvétele egy megálló teljes rakományát könyveli az útvonal sorrendjében. A visszavonás a még át nem vett mennyiséget a forrásra teszi vissza. Az új folyamat igényből indul, és külön kezeli a jóváhagyást, összekészítést, fuvarfeladást és akár a részleges átvételt.

| Régi művelet | Raktári jogosultság aktív felhasználóknál |
| --- | --- |
| Önálló átmozgatás indítása és visszavonása | A forrásraktár felügyelője vagy adminisztrátor |
| Forráshelyek lekérdezése (Smart Picking) | A forrásraktár kitárolója vagy felügyelője, illetve adminisztrátor |
| Önálló átmozgatás átvétele | A célraktár átvevője, felügyelője vagy adminisztrátor |
| Önálló adatlap, lista és szállítólevél | Forrásoldali kitároló vagy felügyelő, céloldali átvevő vagy felügyelő, illetve adminisztrátor |
| Régi fuvar tervezése, feladása és visszavonása | A forrásraktár felügyelője vagy adminisztrátor. A forrás átírásához mindkét forrásraktárban kell jogosultság. |
| Régi fuvar teljes adatlapja és rakodási PDF-je | A forrásraktár kitárolója vagy felügyelője, illetve adminisztrátor |
| Régi fuvar egy megállójának megtekintése és átvétele | A megálló célraktárának átvevője, felügyelője vagy adminisztrátor. A tisztán céloldali szerepkör csak a hozzá tartozó, már feladott megállókat látja. |

Az új igény- és fuvarfolyamat raktári szabályai továbbra is érvényesek. Az új fuvarok műveletei a hozzájuk tartozó végpontokon hajthatók végre. Az indításhoz tartozó korábbi célrekesz-foglalást és készletkönyvelést a már meglévő szolgáltatások végzik.

## Audit és személyes adatok

A jogosultsági napló a fiók és a műveletet végző felhasználó azonosítóját, a művelet idejét és típusát, raktáranként az előző és az új helyi szerepkörkészletet, illetve a helyesbített mezők nevét rögzíti. A korábbi egyetlen szerepkört tároló események változatlan adatokból olvashatók. A naplózott események a fiókkiadás, a szerkesztés, az inaktiválás, az újraaktiválás és az adminisztrátori jelszólink küldése. A jogosultságmentés és az auditbejegyzés közös tranzakcióban fut. A szerepkörök puszta átrendezése változatlan mentésnek számít. Az audit nem tárol jelszót, hasht, tokent, munkamenet-azonosítót, a helyesbítés régi vagy új személyes értékét, illetve teljes kérésadatokat. Felhasználói PDF-export nincs.

A név helyesbítése a felhasználói rekordot érinti. A korábban eltárolt névpillanatképek és letöltött bizonylatok megőrzik a korábbi értéket. A felhasználói azonosító alapján aktuális nevet betöltő nézetek a helyesbített nevet mutatják. A névpillanatképek kezelését, az anonimizálást, a megőrzési időket és az érintetti kérelmek folyamatát a 9/A.3 audit rendezi.

## Korábbi fiókok felülvizsgálata

A régi táblában és naplóban nem áll rendelkezésre megbízható jelző, hogy egy adott fiókot a korábbi nyilvános regisztrációban vagy más forrásból hoztak létre. Az új jogosultsági napló a bevezetéstől számítva használható. Élesítés előtt az üzemeltető az aktív lista, a globális szerepkör és a tagság szerinti szűrések segítségével egyeztesse a fiókokat a hiteles szervezeti névsorral és a raktári felelősökkel. A már szükségtelen fiók inaktiválható, a túl széles jogosultság pontosítható. A jelenlegi fiókok állapota automatikusan nem változik.

## Telepítés

A `warehouse_memberships` továbbra is egyedi (`user_id`, `warehouse_id`) tagságot tárol, változatlan azonosítóval és időbélyegekkel. A `warehouse_membership_roles` táblában a tagság idegen kulcsa mellett szerepel a négy engedélyezett szerepkör egyike. A `(warehouse_membership_id, role)` pár egyedi, a `(role, warehouse_membership_id)` index szerepkör szerinti keresést támogat. Tagság eltávolításakor a szerepkör-hozzárendelések is megszűnnek. A fiók- és raktárhivatkozások törlési védelme megmarad. A listák egzisztenciafeltételt használnak, ezért egy raktár vagy fiók több egyező szerepkör esetén is egyszer jelenik meg.

1. Készíts ellenőrzött mentést, futtasd a `php artisan migrate:status` parancsot, és jegyezd fel a tagságok számát. Az átállás idejére állítsd le a webes és háttérírásokat, valamint a régi kódot futtató folyamatokat.
2. Az új kiadásból futtasd a `php artisan migrate` parancsot. Éles automatizálásban a mentés és a migráció ellenőrzése után használd a `--force` kapcsolót. A 9/A.4 migráció elkezdi az új táblát feltölteni az eredeti szerepkörökkel, majd eltávolítja a korábbi `role` oszlopot. A telepítéshez a korábbi 9/A migrációk is kellenek. A jelenlegi fiókok és üzleti rekordok megmaradnak.
3. Az első többes hozzárendelés előtt ellenőrizd, hogy a tagságok száma változatlan, és minden migrált tagsághoz az eredeti egyetlen szerepkör tartozik. Próbálj ki egy raktári szerepkörrel védett oldalt és adminisztrátori szerkesztést. Sikeres ellenőrzés után indíthatók az új kódot futtató folyamatok és újranyitható az alkalmazás.
4. MySQL alatt az egyes sémamódosítások külön véglegesedhetnek. Megszakadás esetén az üzemeltető a mentés és a tényleges séma ellenőrzése után ugyanazzal a kiadással folytathatja a migrációt. A visszagörgetés csak akkor engedélyezett, ha minden tagsághoz pontosan egy szerepkör tartozik. Ha a 9/A.4 a legutóbb alkalmazott migráció, ez a `php artisan migrate:rollback --step=1` paranccsal végezhető el. Többszerepkörös adaton a régi séma nem tudja megőrizni az összes hozzárendelést, ezért a migráció leáll. Ilyenkor előre javítás vagy a mentésből végzett ellenőrzött visszaállítás alkalmazható a közben keletkezett adatok figyelembevételével.

A linkes fiókkiadáshoz állíts be ténylegesen kézbesítő levelezőt és publikus, HTTPS-es alkalmazáscímet. A helyi `log` levelezővel az ideiglenes jelszavas út érhető el. A korábban nyilvános bemutatójelszavakkal telepített környezetben tekintsd át az érintett fiókokat és jelszavakat.

A 9/A.4 migrációs próba az eredeti négy szerepkör mindegyikénél ellenőrzi a tagsági azonosítót, időbélyegeket és a pontosan egy hozzárendelést SQLite és MySQL alatt. A konkurenciateszt két azonos verzióból indított szerkesztésnél egyetlen mentést és auditbejegyzést enged. A jogosultság részleges visszavonása után a már elindított, de még könyveletlen kiadás, kitárolás, fuvarfeladás vagy átvétel elévült jogosultsággal elutasítást kap. A megmaradt szerepkör a következő belépéskor használható. [SQLite adatmegőrzési próba](../tests/Feature/WarehouseMembershipMigrationTest.php) · [MySQL konkurenciatesztek](../tests/Concurrency/run.php)

Új telepítésen az első adminisztrátort a szerveren az `inventory:create-admin` paranccsal hozd létre. A parancs akkor is használható, ha csak függő jelszóbeállítású vagy inaktív adminisztrátori fiókok maradtak. A bemutató-seeder helyi és tesztkörnyezethez tartozik.

A fióklétrehozásnál az e-mail-cím egyedi. Megszakadt kapcsolat után ugyanazzal a címmel végzett ismételt beküldés esetén keresd meg a már létrejött fiókot a listán. Levelezési hiba után az adatlapon kérhetsz új küldést. A sikeres újraküldés lecseréli a korábbi tokent. Az újraküldésre a jelszó-visszaállítási várakozási idő vonatkozik.
