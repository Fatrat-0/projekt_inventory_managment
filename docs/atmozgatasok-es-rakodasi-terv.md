# Átmozgatások és megállónkénti rakodási terv

## Műveleti áttekintő és gyorsszűrés

Az átmozgatások és szállítmányok listáján kattintható állapotkártyák mutatják az összes és az egyes státuszok darabszámát. A számlálók a teljes találathalmazból, minden egyéb alkalmazott szűrőt figyelembe véve készülnek; a kiválasztott státusz nem nullázza le a többi státusz számlálóját. A kártyák a megadott kereső- és szűrőmezők aktuális értékeivel szűrnek, és visszalépnek az első oldalra.

Mindkét nézet dátumtartományt és Ma / Elmúlt 7 nap / Elmúlt 30 nap / Ez a hónap gyorsszűrőket kapott. A zárónap teljes egészében beleszámít. Átmozgatásnál az indítás (`created_at`), szállítmánynál a terv létrehozása a szűrt időpont, nem az átvétel vagy a későbbi indítás. A hét és hónap naptári napokat jelent az alkalmazás időzónájában; az elmúlt 7 nap a mai és az előző 6 nap. A kiválasztott konkrét dátumok megmaradnak visszalépéskor.

Az `ATM-000123` átmozgatás- és `SZALL-000123` szállítmányazonosítók teljes számként, kis- és nagybetűtől függetlenül kereshetők; rövid, például `ATM-123` alak is elfogadott. Az átmozgatáslistában a szállítmányazonosító az adott szállítmány összes kapcsolódó tételét adja vissza. A szöveges több szavas ÉS keresés és a literális speciális karakterek továbbra is működnek. Túl nagy azonosítót nem alakítunk túlcsorduló egész számmá.

A szállítmánylistában induló raktár, célraktár és termék is szűrhető. Termék és célraktár együttes megadásakor ugyanannak a megállónak kell megfelelnie mindkét feltételnek. A szöveges keresés a célraktárak, termékek és SKU-k neveiben is keres. A lista létrehozásra, hivatkozásra vagy állapotra rendezhető, lapmérete 15/25/50/100. A kártyákon az átvett megállók aránya és úton lévő szállítmánynál a következő célállomás szerepel.

A listaállapot a szállítmány létrehozásán, szerkesztésén, indításán, átvételén és visszavonásán keresztül is megmarad. A titkosított kontextusból kizárólag a megengedett listaszűrők és rendezési paraméterek kerülhetnek vissza egy helyi listacímbe; sérült, túl hosszú vagy tömbként küldött kontextus a szűretlen listára vezet. A bővítés nem módosít adatbázissémát vagy készletkezelést.

32 új PHP-eset és 4 böngészőteszt ellenőrzi a számlálók szűrését és lapozástól való függetlenségét, dátumhatárokat, azonosítókeresést, hibás paramétereket, rendezést, termék+célraktár feltételt, folyamatjelzőt, listaállapotot és a mobilnézetet. Az időszak- és azonosítólogika közös ListingDates és ListingNumber segédosztályba került a két új nézet számára.

Az átmozgatás- és szállítmánylista felső keresője a készletmozgásokkal közös termékjavaslat-komponenst használja. Gépelés közben legfeljebb 8 teljes terméknév és SKU jelenik meg, több szóra, ékezetektől és kis-/nagybetűtől függetlenül szűrve. Kattintás vagy nyíllal kijelölt javaslaton Enter pontos product_id szűrést alkalmaz; a szöveges keresést és az oldalszámot törli, a többi mező aktuális értékét megőrzi. Kijelölés nélkül az Enter a szöveges vagy azonosítókeresést küldi be. Escape és Tab bezárja a listát. További 6 böngészőteszt ellenőrzi ezeket a viselkedéseket mindkét oldalon, mobilnézetben is.

## Felület

Az Átmozgatások menü két nézetet tartalmaz: az önálló és szállítmányhoz kapcsolt tételeket listázó Átmozgatások nézetet, valamint a Szállítmányok és rakodás nézetet.

Az átmozgatások lapozhatók, termékre, forrásra, célra és állapotra szűrhetők. A több szavas keresés minden szót megkövetel a terméknévben, SKU-ban, raktárnevekben vagy szállítmányhivatkozásban; a `%`, `_` és `!` karaktereket literálisan kezeli. A dátum, termék, mennyiség és állapot rendezhető, azonos értékeknél az azonosító a másodlagos rendezés. Az úton lévő mennyiséget a teljes szűrt találathalmazból számolja, nem csak az aktuális lapból.

Az új önálló átmozgatásnál termék- és raktárautocomplete, tájékoztató forráskészlet, mezőhibák és dupla beküldés elleni védelem segíti a munkát. A forráskészlet indításkor csökken; a célkészlet átvételkor nő. A részletezőoldal és a szállítólevél elérhető. A listaállapot titkosított, korlátozott kulcskészletű kontextussal megmarad létrehozás és visszatérés után; külső URL nem adható visszatérési célként.

## Tervezet és rakodási sorrend

Egy szállítmánynak egy induló raktára és legfeljebb 20 különböző célraktár-megállója lehet. Egy megállóhoz legfeljebb 50 termék tartozhat; ugyanaz a termék különböző megállóknál megengedett, egy megállón belül egyszer szerepelhet. Az induló raktár nem lehet célállomás.

A célraktár-választó a másik megállónál már kiválasztott raktárt letiltja, a saját aktuális választást megtartja. Célállomás módosításakor vagy megálló törlésekor a felszabaduló raktár ismét választható. Ha már nincs hely új, különböző célraktárnak, a megálló hozzáadása letiltódik. A szerver és az adatbázis továbbra is külön ellenőrzi az egyediséget; a kliensoldali tiltás nem helyettesíti ezt.

A megállók fel/le gombokkal rendezhetők, termékek és megállók hozzáadhatók vagy eltávolíthatók. A felrakodási sorrend a lerakodási sorrend fordítottja: a legutolsó célállomás áruja kerül először a kamion belső részébe, az első megállóé utoljára, az ajtó mellé. A felület élő előnézetet, a mentett terv kamionelejét és hátsó ajtaját jelölő csoportosított ábrát ad. A PDF külön feltünteti az útvonalat, felrakodási sorrendet, SKU-t és mennyiséget.

A megoldás hátsó ajtós kirakodáshoz ad megállónkénti sorrendtervet. A térbeli elhelyezés, tömeg, raklaphely, tengelyterhelés és térképes útvonal-optimalizálás nem része ennek a változatnak. A megállósorrendet a felhasználó határozza meg.

A részletezőoldal Rakodólista (PDF) gombja közel teljes képernyős, natív modális előnézeti ablakot nyit. Egyetlen tömör felső eszközsor tartalmazza a beállítássáv kapcsolóját, külön lapos megnyitást, letöltést és bezárást; külön lábléc és állandó súgósor nem vesz el helyet a PDF-től. A PDF-et iframe-ben a böngésző beépített PDF-olvasója jeleníti meg, a termékoldali leltárívhez hasonlóan. Az olvasó saját nagyítási és nyomtatási lehetőségei elérhetők; nincs canvas alapú raszterezés. A kezdeti nagyítás az oldalszélességhez igazodik, ha a böngésző támogatja. A mobilos vagy beágyazást nem támogató böngészőkhöz külön lapos megnyitás és letöltés is elérhető. Bezáráskor az iframe eltávolításra kerül és a fókusz visszatér a megnyitógombra. A tesztek a beágyazás URL-jét, a beállítások egyezőségét, bezárást/újranyitást, mobilos elrendezést és a valódi PDF-válaszokat ellenőrzik; a böngésző saját PDF-olvasójának belső felületét nem vezérlik. Az előnézeti végpont preview=1 esetén inline PDF-et, alapértelmezésben letölthető mellékletet ad, azonos hitelesítési és szerepkörfeltételekkel. Az előnézet nem változtat készletet.

Az előnézet beállítássávjában álló/fekvő A4 választható, valamint kapcsolható az útvonal-összesítő, SKU-oszlop, raktárcímek, szállítmány-megjegyzés, kézzel pipálható, üres Rakodva-oszlop és aláírási mezők megjelenítése. A felrakodási sorrend, célraktárnevek, terméknevek és mennyiségek mindig megmaradnak. Az előnézet, külön lapos megnyitás és letöltés közös, szerveroldalon validált opciókat használ. Gyors módosításoknál 200 ms késleltetés után a legutolsó beállítás URL-je kerül a PDF-olvasóba. A beállítások egy gombbal visszaállíthatók; a teljes beállítássáv elrejthető és visszanyitható. Asztali nézetben a PDF szélesebb, mobilon magasabb helyet kap; a kiválasztott opciók megmaradnak. Fekvő tájolásnál tömörebb tördelés készül, az aláírások az utolsó megálló blokkjával maradnak együtt.

Tervezet mentésekor nincs készletfoglalás és készletváltozás. Két tervezet ugyanazt a készletet tervezheti felhasználni, ezért a rendelkezésre állást indításkor ismét ellenőrizzük. A tervezet szerkesztése verzióellenőrzést használ: elavult űrlap nem írhatja felül az újabb változatot, és elavult tervverzió nem indítható el.

## Indítás, átvétel, visszavonás

Állapotok: `draft`, `in_transit`, `completed`, `cancelled`.

- Indítás: a teljes szállítmány egy tranzakcióban indul. Minden tételhez a meglévő InventoryService készít egy pending átmozgatást, levonva a forráskészletet. Az összes célállomás együttes termékigényét is figyelembe veszi. Részleges siker nincs: bármelyik tétel hibájánál a teljes művelet visszagördül.
- Átvétel: mindig a legelső át nem vett megálló következik, annak összes tételével egy tranzakcióban. A korábbi megállók átvétele nélkül a későbbiek nem vehetők át. Az utolsó megálló átvétele teljesített állapotba teszi a szállítmányt. A végrehajtót és az átvétel idejét megőrizzük.
- Visszavonás: tervezetnél nincs készlethatás. Úton lévő szállítmánynál kizárólag az át nem vett tételeket adja vissza a forrásnak; a már átvettek a célraktárban maradnak. A felület a fizikai visszaérkezés rögzítésére kéri a felhasználót. Teljesített szállítmány nem vonható vissza.
- A szállítmány indítása, egy megálló átvétele és a visszavonás ismételten beküldve nem végez második készletműveletet. Külön új tervezet külön műveletnek számít.
- Az elindított vagy lezárt terv útvonala és tételei nem szerkeszthetők. A szállítmányhoz tartozó átmozgatásokat közvetlenül sem lehet egyenként lezárni vagy visszavonni; a szállítmányfolyamatot kell használni.

Minden végpont az alkalmazás meglévő admin/manager/worker szerepköréhez kötött. A szállítmányhoz nem tartozó megállóazonosító 404-et ad. A kliens által küldött státuszt, végrehajtót, megállópozíciót és átmozgatás-azonosítót nem fogadjuk el üzleti adatként.

## Zárolás és ismételt beküldés

A szállítmány állapotváltozásai először a szállítmány sorát zárolják, majd a termékeket növekvő azonosító szerint. A termékzárak megegyeznek a bevételezések és önálló átmozgatások által használt zárral. A stop teljes átvétele és a fennmaradó rakomány visszavonása is atomikus, készlettúlcsordulásnál visszagördül. Tranzakciós újrapróbálás segíti az adatbázis által észlelt deadlock kezelését.

Az önálló átmozgatási űrlap kötelező UUID beküldési kulcsot használ. A felhasználó+kulcs egyedisége és a normalizált üzleti adatok SHA-256 lenyomata megakadályozza az azonos kérés ismételt levonását. Azonos kulcs eltérő adatokkal hibát eredményez; sikertelen művelet a kulcsfoglalást is visszagörgeti. A szolgáltatás belső hívásainál a kulcs opcionális; szállítmánynál a szállítmány állapotzára garantálja az egyszeri indítást.

## Telepítés és bemutató

Új migráció: `2026_10_28_170000_create_shipments`. Négy új táblát hoz létre: shipments, shipment_stops, shipment_items, inventory_transfer_requests. A meglévő készleteket és átmozgatásokat nem módosítja.

```powershell
php artisan migrate
npm run build
php artisan db:seed --class=ShipmentDemoSeeder
```

Az alapseeder végén is lefut a ShipmentDemoSeeder. Az alap bemutató-felhasználót, termékeket és raktárakat használja egy három megállós `DEMO-RAKODAS-001` tervezethez. Újrafuttatva a meglévő példát nem módosítja, nem indítja el, és nem változtat készletet. Meglévő adatokhoz nem szükséges migrate:fresh vagy teljes újraseedelés.

## Ellenőrzés

```powershell
php artisan test
npm run test:browser
php tests/Concurrency/run.php
```

A PHP-tesztek vizsgálják a tervezet és a sorrend helyességét, verzióütközést, készletet változtató műveletek atomikusságát, teljes igényt, ismételt kéréseket, átvételi sorrendet, részleges visszavonást, túlcsordulást, idegen megállót, jogosultságokat, szűrést/rendezést/lapozást, kontextust, PDF-generálást és az additív seedert.

A böngészőtesztek valós űrlapokkal járják végig a három megálló létrehozását, átrendezését, újranyitását, indítását, átvételét és részleges visszavonását; a hibás útvonalat és a mobilnézetet is ellenőrzik. A PDF-et a böngésző hitelesített HTTP-munkamenetével kérik le, és tényleges PDF-adatot ellenőriznek; a natív letöltéskezelő működése nem része ennek a tesztnek.

Külön regressziós eset vizsgálja a terméksor hozzáadását és törlését, a megmaradó termék mennyiségének megőrzését, az induló raktár cseréjekor az azzal megegyező célállomás kiürítését, továbbá a megálló törlése utáni mentés és újranyitás helyességét.

A külön MySQL/InnoDB-futtató 19 kétfolyamatos esetet tesztel, köztük az önálló átmozgatás beküldési kulcsát, a szállítmány dupla indítását, dupla átvételt, átvétel-visszavonás versenyhelyzetet, dupla visszavonást, közös készletért versengő szállítmányokat és szállítmányindítással versengő kiadást. Kizárólag a saját, véletlen nevű tesztadatbázisában dolgozik, amelyet végül eltávolít.
