# Vezérlőpult

A `/dashboard` oldal minden raktár készletéről és nyitott feladatairól ad áttekintést. Az oldal megnyitása, az időszakváltás és a frissítés nem módosít üzleti adatot. A személyes elrendezés beállításait és telepítési igényeit külön fejezet ismerteti alább.

## Aktuális állapot és feladatok

- **Termékminimum alatt:** a termékenkénti, minden raktárra összesített kiadható készletet hasonlítjuk a saját minimumhoz, ennek hiányában a közvetlen kategória termékenkénti minimumához. A saját 0 felülbírálja a kategória értékét. Null minimum esetén nincs riasztás, pontosan a minimumon sincs hiány. Készletsor nélküli termék is lehet hiányos.
- **Kategóriahiány:** kizárólag a közvetlenül besorolt termékek kiadható készlete adódik össze, minden raktárból. A gyermekágak nem számítanak bele; a termék saját minimuma nem változtatja meg a kategória összesített minimumát. Pozitív minimumú üres kategória is hiányos.
- **Minősítésre váró visszáruk:** a meglévő minősítési munkalista szabályai szerint még feldolgozatlan visszáruk tételszáma, darabszáma és legkorábbi beérkezése. A tételszám és a darabszám külön érték.
- **Úton lévő átmozgatások:** csak `pending` tételek, a szállítmányokhoz tartozó átmozgatásokkal együtt. A szállítmányok darabszámát külön nem adjuk újra a készlethez.
- **Szállítmányok:** az úton lévő és a tervezet állapot külön jelenik meg. A hivatkozások a megfelelő állapotra szűrt listát nyitják.

A készletösszesítő a fizikai, ebből kiadható, ebből elkülönített, valamint a külön úton lévő mennyiséget mutatja. A fizikai készlet a kiadható és az elkülönített összege. Az átmozgatás indításakor az áru már lekerül a forrásraktárból; a célraktárba átvételkor kerül. Az úton lévő mennyiséget ezért nem vonjuk le még egyszer, és nem adjuk hozzá a raktári fizikai készlethez.

A négy lista – termékhiány, kategóriahiány, átvételre váró átmozgatás és legutóbbi készletmozgás – alapból három-három tételt mutat. Egymástól függetlenül nyithatók a Több megjelenítése vezérlővel, majd visszacsukhatók a Kevesebb megjelenítése vezérlővel. A vezérlő a lista alatt, a görgethető területen kívül helyezkedik el. Kibontva a panel legfeljebb tíz tételnyi magas lehet, de a képernyőmagasság 70%-a is felső korlát; a további tételek a panelen belül görgethetők. A magasság a sorok tényleges méretéhez és a képernyőhöz igazodik. JavaScript nélkül a natív details elem nyitható/csukható, és ugyanúgy érvényesül a 70%-os görgethető magasságkorlát és az alsó vezérlő. A közös megjelenítést a dashboard-list komponens biztosítja.

Listánként legfeljebb 25 tétel töltődik be. Ennél több találatnál külön termék-, kategória-, átmozgatás- és mozgáslapozás jelenik meg, így minden találat elérhető, a vezérlőpult mégsem tölt korlátlan számú sort. A lapozás megtartja a másik három lista oldalszámát, az időszakot és a diagramtípust, majd a megfelelő panelre ugrik. Az explicit lapozott lista kibontva nyílik meg, ha háromnál több sora van. Az időszakváltás és a Frissítés megtartja az oldalszámokat; ha közben megszűnik egy oldal, az utolsó érvényes oldalra térünk vissza. Hibás oldalszám HTML-ben az első oldalra vezet hibaüzenettel, JSON-ban mezőhibát ad.

Az összesítő számlálók a teljes találathalmazt fedik le. Mind a négy lista fejlécéből megnyitható a kapcsolódó oldal: 768 pixeltől külön hivatkozás is megjelenik a jobb oldalon, kisebb képernyőn a teljes fejléc a cím hivatkozásának kattintható területe. A natív hivatkozások billentyűzettel és JavaScript nélkül is működnek. A termékhiányok a minimum alatti termékekhez, az átvételre váró tételek a legrégebbivel kezdődő nyitott átmozgatásokhoz, a készletmozgások a teljes naplóhoz vezetnek. A kategóriafejléc adminisztrátornak és managernek az általános kategórialistát, raktárosnak a terméklistát nyitja. Egy kategória termékhivatkozása az egész ágat megnyitja, ezt a felirat külön jelzi.

A hiánylisták a legnagyobb darabszámú hiányt mutatják először. Az átvételi lista a legrégebben indított, még nyitott tételekkel kezdődik; a megérkezett és visszavont tételek kimaradnak. A szállítmányhoz tartozó tétel a szállítmány adatlapjára vezet, ahol a megálló vehető át. A készletmozgások a legfrissebbtől haladnak visszafelé, a jövőbeli időbélyegű bejegyzések nélkül. Külön megnevezést kap a bevételezés, kiadás, vevői/beszállítói visszáru és selejtezés. Minden listánál az azonosító rendezi az egyébként egyező sorokat.

A panelcímek világosszürke fejlécsávon, erősebb alsó elválasztóval és a listaelemeknél nagyobb, sötét betűkkel jelennek meg. A hiányok számlálója külön jelvény, mobilon a navigációs nyíl kör alakú keretet kap. Így az egyoszlopos nézetben is megkülönböztethető a fejléc az első adatsortól; a sorok fehér háttere és hivatkozásai megmaradnak.

## Napi használatot segítő finomítások

A hiánylisták után az átvételi és legutóbbi mozgáslisták következnek, majd a diagram. Ez mobilon előrébb hozza a napi feladatokat. A sorrend minden képernyőméreten és a dokumentumban is azonos, ezért a billentyűzetes navigáció a megjelenést követi.

A termékhiányok között a nulla összesített kiadható készlet külön „Nincs kiadható készlet” jelzést kap. Ez készletsor nélküli és teljesen elkülönített terméknél is megjelenik; másik raktárban elérhető pozitív készlet esetén nem. A jelzés a meglévő minimum alatti listát egészíti ki, új riasztási feltételt nem vezet be.

Az átvételi sorok és a legrégebbi nyitott visszáru várakozási ideje a vezérlőpult asOf pillanatához képest jelenik meg, a pontos dátum mellett. A kijelzés a Frissítéssel frissül, az időszakváltás csak a diagramot módosítja. A WaitingDuration az eltelt teljes perceket, órákat, majd 24 órás napokat számolja; egy percen belül külön szöveg jelenik meg. Az éjfél és a nyári/téli óraátállítás nem kerekít naptári napra. Jövőbeli kezdőidőpontnál „Jövőbeli időpont – ellenőrzendő” jelenik meg, negatív időtartam helyett. Határidő hiányában a várakozást nem nevezzük késésnek.

A dashboard-text stílus szavaknál tördel, de a szóköz nélküli hosszú neveket és azonosítókat is a panelen belül tartja. A diagram dátumtengelye mindkét időszaknál azonos magasságú: hét napnál minden nap, harminc napnál a kezdő, középső és záró nap jelölése látható. A váltás ezért az oldal alján sem vesz el egy sornyi helyet.

## Időszak és frissítés

A 7 vagy 30 napos mozgásösszesítő a mai nappal együtt vett naptári napokat mutatja, az alkalmazás időzónájában. Az időszak kezdete inkluzív, a jövőbeli bejegyzések kimaradnak. A hiányzó napok értéke nulla. Az alapértelmezett oszlopnézet mellett vonaldiagram is választható: külön, közös nulláról induló skálán jelenik meg a bejövő és a kimenő sorozat. A kimenő vonal szaggatott, az azonos értékű sorozatok is megkülönböztethetők. A napi pontokat egyenes szakaszok kötik össze, simítás nélkül. Az időszak és a diagramtípus az URL-ben tárolódik; a váltás és a Frissítés mindkét választást megőrzi. A váltás nem indít horgonynavigációt, és megőrzi a görgetési helyzetet; JavaScript nélkül a Mutat gomb használható. Hibás paraméternél az érvényes másik beállítást megtartjuk.

A grafikon műveletszámot, a fölötte lévő összesítők külön bejövő és kimenő darabszámot mutatnak. A napi részletek szöveges táblázatban is elérhetők, mindkét nézet azonos adatokat használ. A vonaldiagram szerveroldalon előállított SVG, új JavaScript-csomag nélkül.

Mindkét diagramon a nap teljes sávja hivatkozás. Rámutatáskor vagy billentyűzetes fókusznál a dátum, a bejövő/kimenő műveletszám és a bejövő/kimenő darabszám jelenik meg. A nullás napok is elérhetők. A súgó a diagram széleihez és a látható képernyőhöz igazodik; az Escape bezárja, a súgó fölé is átvihető az egér. A nyilak a napok között mozgatják a fókuszt, a Home/End az első/utolsó napra lép. Kattintás vagy Enter az adott nap kezdő és záró dátumára szűrt mozgásnaplót nyitja. Érintőképernyőn a nap közvetlenül megnyitható; a Napi részletek dátumai szintén hivatkozások. JavaScript nélkül a natív hivatkozás és a böngészős cím-súgó továbbra is működik.

Az összesítő a készletmozgás-naplót dolgozza fel: visszárut és selejtezést is számol, a készlet felszabadítását és a külön átmozgatási bizonylatokat nem keveri bele. A nyitott feladatok és a készletösszesítő az időszakváltástól függetlenül az aktuális állapotot mutatják. A legutóbbi mozgások előnézete szintén nem időszakszűrt.

A diagram típusát két kompakt ikon közül lehet kiválasztani. Az aktív ikon kiemelt, a natív választógombok képernyőolvasóval megnevezettek, billentyűzettel is használhatók; az ikonok fölött böngészős súgó jelenik meg. JavaScripttel a stílusváltás helyben történik, hálózati kérés nélkül. Az időszakváltás csak a diagram adatait és napi részleteit tölti újra: a vezérlők, a görgetés és a kibontott vezérlőpult-listák megmaradnak. A nézet és az időszak az URL-ben, valamint a frissítési és lapozási hivatkozásokban is követi a választást. Gyors időszakváltásnál a korábbi kérést megszakítjuk; elavult válasz nem írhatja felül az újabbat. Sikertelen lekérésnél megmarad a korábbi diagram, az időszakválasztó visszaáll, és újrapróbálható hibaüzenet jelenik meg. A Mutat gomb csak JavaScript nélkül jelenik meg, ekkor mindkét választás ezzel alkalmazható.

A Frissítés hivatkozás megtartja az időszakot és a diagram típusát. A válasz `no-store, private` fejlécet kap; a böngésző visszaállított oldalának `pageshow` eseménye friss lekérést indít. Hibás HTML-paraméternél egyszeri átirányítással a hibás mező alapértékére térünk vissza, JSON-kérésnél 422-es mezőhiba érkezik.

## Személyes elrendezés

A sorok elején lévő fogantyúval egérrel és érintőképernyőn is áthúzhatók a blokkok. A képernyő szélén automatikus görgetés segíti a hosszabb lista rendezését; a fogantyún kívüli érintés továbbra is az oldalt görgeti. Az Escape, az ablak fókuszának elvesztése vagy megszakadó érintés visszaállítja a húzás előtti sorrendet. Húzás közben a Mentés és a többi rendezővezérlő átmenetileg tiltott. A jelölőnégyzetek állapota áthelyezéskor megmarad. Az átrendezést a SortableJS biztosítja, a csomag az npm-függőségek és a Vite-build része. A fel/le gombok billentyűzettel és JavaScript nélkül is használhatók; utóbbi esetben a húzófogantyúk nem jelennek meg.

A Testreszabás hivatkozás külön beállítóoldalt nyit. A feladatösszesítő, készletösszesítő, termékhiányok, kategóriahiányok, átvételek, legutóbbi mozgások és diagram külön kapcsolható, fel/le gombokkal rendezhető. Nagy képernyőn a listák két oszlopban, a teljes szélességű összesítők és diagram saját sorban jelennek meg. A sorrend a dokumentum valódi sorrendje, ezért mobilon és billentyűzettel is következetes.

A mentés a felhasználó users.dashboard_layout JSON-mezőjét és külön dashboard_layout_version számlálóját módosítja. Másik fiókot, készletet vagy jogosultságot nem érint; a következő bejelentkezés és másik böngésző is ugyanazt az elrendezést kapja. Mentés előtt a sorrend és a jelölések csak a szerkesztőben változnak; a Mégse elveti őket. Az Alapértelmezett elrendezés mind a hét blokkot visszaállítja, majd a Mentés alkalmazza. JavaScript nélkül a fel/le és alaphelyzet gombok azonnal mentenek, ezt a felület jelzi.

A PATCH /dashboard/layout kizárólag a hitelesített felhasználó saját beállítását módosítja, CSRF-védelemmel. A hét engedélyezett blokkazonosító teljes, ismétlés nélküli sorrendjét és a látható részhalmazt fogadja el. A mentés egyetlen, az előző verzióra feltételes UPDATE: elavult böngészőfül esetén 409-es válasz érkezik, a frissebb elrendezés felülírása nélkül. A szerkesztő ilyenkor külön hivatkozással kínálja a mentett beállítások betöltését.

Minden blokk elrejthető; az üres állapotból továbbra is elérhető a blokkok kiválasztása. Rejtett listához tartozó oldalszámot eltávolítunk az URL-ből, így nem ugrunk nem létező panelre. Az időszak és diagramtípus a szerkesztésből visszatérve is megmarad. A rejtett kategóriablokkra mutató összesítő a beállítás megfelelő sorához vezet. Ismeretlen vagy hibás tárolt beállításból csak az engedélyezett blokkok kerülhetnek a megjelenítésbe; újonnan bevezetett blokkok a meglévő sorrend végére kerülnek.

A 2026_11_06_010000_add_dashboard_layout_to_users migráció két beállításmezőt ad a users táblához. A DashboardLayoutTest a validációt, fiókok elkülönítését, ütközésvédelmet, visszaállítást és üres állapotot ellenőrzi; a dashboard-layout.spec.js a tényleges átrendezést, másik munkamenetet, két böngészőfület, telefonos elrendezést és JavaScript nélküli használatot is vizsgálja. A dashboard-drag.spec.js egérrel és valódi böngészős érintési eseményekkel ellenőrzi az áthúzást, automatikus görgetést, megszakítást, jelölések megőrzését, mentést és a Mégse működését.

A hibás űrlap utáni vázlat helyreállítása különválik a tárolt elrendezés későbbi bővítésétől: hiányos sorrend esetén sem kapcsoljuk vissza az érvényesen kikapcsolt blokkokat. Más űrlap korábbi adatai nem kerülnek át ide. Hibás vagy hiányzó mentési verzió esetén a mentett elrendezés kifejezett újratöltése szükséges; a szerkesztő nem ad automatikusan friss verziót egy régi vázlathoz. Ütközéskor a módosító vezérlők is le vannak tiltva a betöltésig.

A lapozás normalizáló átirányításai továbbviszik a hibákat és a sikeres mentés visszajelzését. A böngészőtesztek a gyors ismételt elküldést, a visszaállított oldal vázlatának megőrzését és a 320 pixeles nézetet 150%-os alapbetűmérettel is ellenőrzik. A MySQL-futtató két valódi párhuzamos beállításmentést vizsgál normál és már megnyitott ismételhető olvasási tranzakcióban is; mindkét esetben egyetlen mentés nyerhet, profil- vagy készletmódosítás nélkül.

## Jogosultság és tesztek

A vezérlőpult a meglévő bejelentkezési és ellenőrzési szabályokat használja. A kategóriaminimum szerkesztése és a kategóriakezelés hivatkozása adminisztrátornak és managernek látszik; a raktáros a terméklistához és a számára elérhető műveletekhez jut.

A `DashboardOverview` adatbázisoldali összesítéseket és korlátozott, előre betöltött kapcsolatokat használ. A termékek, kategóriák és készletek teljes kapcsolati fáját nem tölti memóriába. A lekérdezések egy olvasási tranzakción belül futnak.

Ellenőrzés:

```powershell
php artisan test --filter=DashboardOverviewTest
npm run test:browser -- dashboard.spec.js quality-queue.spec.js
php tests/Concurrency/run.php
```

A tesztek az üres állapotokat, null/0 minimumot, pontos határértékeket, elkülönített készletet, több raktár összegzését, közvetlen kategóriatagságot, teljes számlálókat és korlátozott előnézeteket, dátumhatárokat, jövőbeli adatokat, bizonylattípusokat, jogosultságokat és adatváltozatlanságot vizsgálnak. Böngészőben 320–1440 pixel között, JavaScript nélkül, időszakváltással és visszaállított oldallal is ellenőrizzük a felületet. A MySQL-futtató saját eldobható adatbázisán külön összeveti a vezérlőpult SQL-eredményeit a terméklistával és függetlenül számolt elvárt összesítésekkel.
