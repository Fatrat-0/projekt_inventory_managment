# Átmozgatások és megállónkénti rakodási terv

## Felület

Az Átmozgatások menü két nézetet tartalmaz: az önálló és szállítmányhoz kapcsolt tételeket listázó Átmozgatások nézetet, valamint a Szállítmányok és rakodás nézetet.

Az átmozgatások lapozhatók, termékre, forrásra, célra és állapotra szűrhetők. A több szavas keresés minden szót megkövetel a terméknévben, SKU-ban, raktárnevekben vagy szállítmányhivatkozásban; a `%`, `_` és `!` karaktereket literálisan kezeli. A dátum, termék, mennyiség és állapot rendezhető, azonos értékeknél az azonosító a másodlagos rendezés. Az úton lévő mennyiséget a teljes szűrt találathalmazból számolja, nem csak az aktuális lapból.

Az új önálló átmozgatásnál termék- és raktárautocomplete, tájékoztató forráskészlet, mezőhibák és dupla beküldés elleni védelem segíti a munkát. A forráskészlet indításkor csökken; a célkészlet átvételkor nő. A részletezőoldal és a szállítólevél elérhető. A listaállapot titkosított, korlátozott kulcskészletű kontextussal megmarad létrehozás és visszatérés után; külső URL nem adható visszatérési célként.

## Tervezet és rakodási sorrend

Egy szállítmánynak egy induló raktára és legfeljebb 20 különböző célraktár-megállója lehet. Egy megállóhoz legfeljebb 50 termék tartozhat; ugyanaz a termék különböző megállóknál megengedett, egy megállón belül egyszer szerepelhet. Az induló raktár nem lehet célállomás.

A megállók fel/le gombokkal rendezhetők, termékek és megállók hozzáadhatók vagy eltávolíthatók. A felrakodási sorrend a lerakodási sorrend fordítottja: a legutolsó célállomás áruja kerül először a kamion belső részébe, az első megállóé utoljára, az ajtó mellé. A felület élő előnézetet, a mentett terv kamionelejét és hátsó ajtaját jelölő csoportosított ábrát ad. A PDF külön feltünteti az útvonalat, felrakodási sorrendet, SKU-t és mennyiséget.

A megoldás hátsó ajtós kirakodáshoz ad megállónkénti sorrendtervet. A térbeli elhelyezés, tömeg, raklaphely, tengelyterhelés és térképes útvonal-optimalizálás nem része ennek a változatnak. A megállósorrendet a felhasználó határozza meg.

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
