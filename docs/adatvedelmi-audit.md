# Adatvédelmi és hozzáférésbiztonsági audit

Ez a 9/A.3 forráskód- és tesztalapú műszaki auditja. Kiinduló állapot a `feature/felhasznaloi-hozzaferes-biztonsag` ág `a48709b` HEAD-je és a munkakönyvtárban lévő 9/A módosítások. A kérések izolált tesztadatbázisokon, fiktív szereplőkkel futnak. Az éles hálózat, szolgáltatói szerződések, naplógyűjtők és mentések tényleges működését az üzemeltető ellenőrzi.

## Követelmények és felelősségek

| Terület | Forráskódban ellenőrizhető | Konfigurációs és adatkezelői döntés |
| --- | --- | --- |
| GDPR 5., 6. és 25. cikk | Mezők, célhoz kötött nézetek, mentési alapértékek, hozzáférési szabályok, naplózás és export | Kezelési cél és jogalap műveletenként, szükségesség, hozzáférési jóváhagyás és megőrzés |
| GDPR 12–14. cikk | Milyen adat gyűlik és milyen felületen jelenhet meg | Valós tájékoztató jóváhagyása, közlése és frissítése, a közvetett adatforrások feltüntetése |
| GDPR 15–21. cikk | Adatlapi helyesbítés és célzottan visszakereshető rekordok | Azonosítás, jogosultságok és kivételek elbírálása, címzettek tájékoztatása, esetkezelés |
| GDPR 30. és 32. cikk | A technikai adatleltár és a védelemből ellenőrizhető intézkedések | A 30. cikk alkalmazhatósága, nyilvántartás vezetése, kockázatalapú védelem és helyreállítás |
| GDPR 33–34. cikk | Naplók és hozzáférés-visszavonás az incidens kezeléséhez | Értékelés, dokumentálás, hatósági bejelentés és az érintettek tájékoztatása a feltételek szerint |

A 30. cikk létszámhoz kötött kivétele feltételes. Többek között az adatkezelés rendszeressége és kockázata is számít. A technikai tesztek az alkalmazás ellenőrzött viselkedését igazolják. Az adatkezelések jogszerűségéhez a szervezet tényleges folyamatai és döntései szükségesek. [GDPR hivatalos magyar szöveg](https://eur-lex.europa.eu/legal-content/HU/TXT/?uri=CELEX%3A32016R0679) · [NAIH érintetti jogok](https://naih.hu/erintetti-jogok)

## Személyesadat-leltár

A termékkód, darabszám, kategória és szabványos tárolóhely jellemzően üzleti adat. Egyéni vállalkozó neve vagy címe, személyhez rendelt jármű rendszáma, illetve egy személyhez kapcsolható szabad szöveg már személyes adat lehet. A táblázat a forráskódból megállapítható adatmozgásokat írja le.

| Adatkategória | Tárolás helye | Felhasználás célja | Hozzáférők | Exportálás | Megőrzés és törlés | Technikai védelem | Fennmaradó kockázat | Üzemeltetői döntés |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Munkatársi fiók | `users`, név, e-mail, állapot, szerepkör és felületbeállítások | Belépés, név megjelenítése, fiókkezelés | Saját profil és admin. Az üzemi nézetek a végrehajtó nevét mutathatják. | Nincs fiókexport. Egyes műveleti PDF-ek végrehajtónevet tartalmaznak. | Inaktiválás megőrzi. A helyesbítés az aktuális rekordot érinti. | Auth, admin kapu, verziózás, e-mail-módosításhoz jelenlegi jelszó | A név tovább élhet előzményben és korábbi dokumentumban | Fiókadatok szükségessége és kivezetése |
| Helyi szerep és jogosultsági audit | `warehouse_memberships`, `warehouse_membership_roles`, `user_access_events` és a fiók hozzáférési verziója | Engedélyezés és változások visszakövetése | Admin, saját jogosultság alapján üzemi funkciók | Célzott üzemeltetői lekérdezés | Eseményeknek nincs automatikus lejárata | Raktári policyk, tranzakció, raktárankénti előző és új szerepkörkészlet, azonosító-alapú audit | Az audit személyhez kapcsolható, szerepkörökből szervezeti információ következtethető ki | Audit célja, olvasói, megőrzése |
| Hitelesítési adatok | `users.password`, `remember_token`, `password_reset_tokens`, hitelesítési cookie-k | Bejelentkezés, helyreállítás, eszköz megjegyzése | Auth-kód, szűken jogosított üzemeltetők és a token címzettje | A titkokat ki kell hagyni az exportból | A jelszólink alapból 60 percig használható, felhasználáskor vagy visszavonáskor érvénytelenedik. A lejárt DB-sor külön takarítható. | Laravel hash, titkosított webes cookie-k, egyszeri reset, korlátozás, tiltott flash mezők | Tartalékmentés, proxy napló vagy hibás levelező érzékeny lehet | HTTPS, cookie-, token- és kulcskezelés |
| Munkamenet és kapcsolat adatai | Alapértelmezett DB-driverrel `sessions` sorban felhasználóazonosító, IP, user-agent, payload, időpont. Cache-driver a bejelentkezési és kéréskorlátozási kulcsokra. | Munkamenet és biztonsági korlátozás | Auth-kód, üzemeltető, a telepítés szerint infrastruktúra-szolgáltató | Érintetti kérelemnél célzott részlet, session-azonosító nélkül | Alapértelmezett tétlenség 120 perc. Laravel takarítás és fizikai törlés driverfüggő. | Munkamenet megújítása, verzió-ellenőrzés, CSRF | IP és böngészőadat akkor is hozzáférhető lehet az infrastruktúrában, ha az alkalmazásban a session lejárt | Driver, titkosítás, takarítás, naplószabályok |
| Készletmozgás és minősítés | `inventory_movements`, `return_quality_actions`, forrássorok és ismételt beküldések nyilvántartása | Könyvelés, ellenőrzés, sértetlen visszáru-lánc | Globális készletkezelő szerepkörök az üzemi naplóban, helyi jogosultság a körülhatárolt kiadási művelethez | Mozgásnapló PDF, célzott kérelem | Üzleti rekordok és hivatkozások megmaradnak | Tranzakció és audit, escape-elt megjelenítés, PDF-oszlopbeállítás | Az operátor neve, partner neve, indok és megjegyzés exportálható | Belső láthatóság, bizonylatként kezelt rekordok és indokolt megőrzés |
| Átmozgatás, kitárolás, fuvar, átvétel | `inventory_transfers`, `transfer_intents` és eseményei, `shipment_*`, kitárolási és foglalási táblák, részben JSON-pillanatképek | Útvonal, végrehajtó és jóváhagyó követése | Ügyelethez kötött forrás- vagy céloldal, saját igény, admin | Régi szállítólevél és rakodási PDF, egyes dokumentumok megjegyzést tartalmaznak | Nincs általános lejárat | Policy, az összetartozó erőforrások ellenőrzése, hozzáféréssel ismételhető művelet | Jármű-azonosító és szabad szöveg természetes személyhez kapcsolódhat | Szükséges mezők, bizonylatok és címzettek |
| Belső áthelyezés, kézi betárolás, mérés | `warehouse_relocations`, `manual_receipt_putaways`, `warehouse_load_verifications` aktorazonosító és névpillanatkép, továbbá árumozgási és mérési adatok | Felelősség és biztonsági ellenőrzés visszakövetése | Az adott helyi műveletre jogosult szerepkörök és admin | Meglévő képernyők és jogosult JSON-válasz, célzott kérelem | Események megmaradnak | Helyi policy, kapacitás- és verzióvédelem, rejtett kéréshash a mérés válaszában | Helyesbített név mellett a korábbi pillanatkép megmarad | Mérési feljegyzések és névpillanatképek szükségessége |
| Partnerkapcsolattartók | `partners` név, kapcsolattartó, e-mail, telefon, ország, irányítószám, település, cím, megjegyzés | Beszállító és vevő azonosítása, ügyintézés | Globális készletkezelő szerepkörök, partnerlista és keresési javaslatok | Mozgásnaplóban partner neve, célzott kérelemben szükséges személyes mezők | Archiválás megőrzi. A történeti partnernév külön mezőben élhet. | Auth, validáció, Blade escape, tömeges export nélkül | A teljes címtár a közös munkakörből és az előtöltött javaslatokból is elérhető | Kinek kell név, telefon, cím és e-mail a megjelenítéshez és kereséshez |
| Szabad szöveg | Mozgás és visszáru, igény, fuvar, jármű, mérés, címek és megjegyzések. Audit JSON és értesített címzettek. | Üzleti indok, azonosító, rendkívüli esemény feljegyzése | Az adott üzemi rekord jogosult olvasói | PDF-ben a választott opciótól és dokumentumtípustól függően | A kapcsolódó rekord sorsától függ | Hosszkorlát, escape-elt megjelenítés, indokolt filterfeliratok | Szükségtelen személyes vagy különleges adat kerülhet a szövegbe | Adatrögzítési útmutató, felülvizsgálat, kitakarási eljárás |
| Böngészőállapot | `localStorage` nézet, oszlopok, oldalsáv. `sessionStorage` bizonytalan igénybeküldés és helyreállítás, ideértve az indokot és megjegyzést. | Kezelhetőség és az elveszett válasz biztonságos újrapróbálása | Ugyanaz a böngészőprofil és weberedet | Nincs normál export | A helyreállítás állapotától és böngészőtől függ. A localStorage felületbeállítások tartósak. | Felhasználóhoz kötött helyreállítási kulcs, üzleti idempotencia. A jelszómezők nem kerülnek ide. | Megosztott vagy visszaállított böngészőprofil, egy eredeten futó kód, valamint a böngésző előzményei | Közös gépek kijelentkezési és profilkezelési rendje |
| Naplók, mentések, kézbesítés | Laravel logcsatornák, web/proxy/SMTP-naplók, DB-mentések, hibás háttérfeladatok, ha használatban vannak | Hibakeresés, kézbesítés és visszaállítás | Kijelölt üzemeltetők és tényleges szolgáltatók | Csak szűrt incidens- vagy kérelemkivonat | Csatorna-, mentési és szolgáltatófüggő | Érzékeny levelek blokkolása naplózó mailer esetén, éles debug kikapcsolása telepítési feladat | URL- és kérésnapló, jelszólink, exception-környezet, visszaállított régi mentés | Szűrés, hozzáférés, mentési életciklus és feldolgozói szerződés |

Források a kódban: `database/migrations`, `UserAccess`, `EnsureActiveSession`, `PartnerController`, `InventoryMovementController`, `ManualReceiptPutawayService`, `WarehouseRelocationService`, `WarehouseLoadVerificationService`, `ShipmentDispatchService`, `ShipmentReceivingReader`, `config/session.php`, `config/logging.php`, `resources/js/components/transfer-intent-create.js`.

## Hozzáférési megfigyelések

| Szereplő vagy művelet | Az alkalmazás határa |
| --- | --- |
| Vendég, inaktív fiók, első jelszóra váró fiók | Vendégnél belépés vagy JSON 401. Inaktív vagy visszavont munkamenetet a szerver érvénytelenít. Függő fióknál csak a jelszóbeállítás, jelszó mentése és kijelentkezés használható a belépés után. |
| Tagság nélküli raktáros | Vezérlőpult, készlet, termék, partner, kapcsolattartói mezők, mozgásnapló, általános alaprajz és tárhelykapacitás. Saját igény elérhető, mások célhoz kötött raktári feladataihoz helyi hozzáférés kell. |
| Tagság nélküli raktárvezető | A közös nézeteken felül kategória-, minimum-, raktár- és tárolórendszer-adminisztráció, raktári statisztika, raktári leltár és statisztikai PDF. |
| Manuális készletkezelés | A közös készletkezelő szerepkör tagság nélkül is rögzíthet manuális IN-t, visszárut és minősítést. A manuális OUT forrásoldali kitárolót vagy felügyelőt kér. Levétel és elhelyezés, belső áthelyezés, betárolás és mérési könyvelés a saját policyját követi. |
| Igénylő | Érintett raktárban benyújtás és saját igény követése. A kitároló-, felügyelő- és átvevői jogkörök ettől elkülönülnek. |
| Kitároló, átvevő, felügyelő, több raktár | A fuvar, megálló, kitárolás, átvétel és mérés jogosultsága műveletenként az adott raktárhoz kötött. A két raktárhoz rendelt felhasználónál mindkét tagság a maga helyén érvényesül. |
| Adminisztrátor | A felhasználói adatlap és audit adminisztrátori. A globális adminisztrátor saját jelszó beállítása után minden raktárt elér. |
| Gyorskereső | A menüben bejárható oldalak és terméknév/SKU. A felhasználói és partnerkapcsolattartói rekordok itt nem jelennek meg. A partnerlista és a mozgásűrlap saját partnerjavaslatai külön felületek. |

A globális IN, visszáru, minősítés, raktárvezetői törzsadat-szerkesztés és közös riportok dokumentált üzleti döntések. Ha egy éles szervezet raktáronkénti elkülönítést igényel, elsőként a kapcsolattartói elérhetőségek és a teljes mozgásnapló olvasói körét vizsgálja felül. A manuális IN, visszáru és minősítés raktári korlátozása a meglévő munkamegosztást is megváltoztatná. Ekkor a riportokat, keresési javaslatokat, összesítőket és a közvetlen véglegesítési útvonalakat együtt kell szűkíteni. [A műveletek és policyk részletes mátrixa](felhasznaloi-hozzaferes.md) · [HTTP-regressziók](../tests/Feature/PrivacyAuditTest.php)

A 9/A.4 egy raktári tagsághoz több helyi szerepkört enged. A kitároló és átvevő szerepkörök külön felügyelői jogosultság nélkül kombinálhatók. A migráció a korábbi tagságokra pontosan az eredeti szerepkört viszi át. A tranzakciók zárolt, aktuális jogosultságot ellenőriznek. Az adminisztrátornak továbbra is munkakör alapján kell kiválasztania, majd időszakosan felülvizsgálnia a szükséges szerepköröket. [Jogosultsági mátrix és migráció](felhasznaloi-hozzaferes.md)

## Történeti rekordok és helyesbítés

| Rekordcsoport | Személyhez kapcsoló mező és adatbázis-kapcsolat |
| --- | --- |
| `inventory_movements`, `inventory_transfers`, `shipments`, `shipment_stops` | `user_id`, illetve `received_by` a `users` táblára. A régi ismétlési sorok, `inventory_movement_requests` és `inventory_transfer_requests` ugyancsak felhasználóra hivatkoznak. |
| `warehouse_memberships`, `user_access_events`, `return_quality_actions` | Felhasználó és adott esetben végrehajtó `RESTRICT` kapcsolattal. A jogosultsági audit az érintett és az aktor azonosítóját is őrzi. |
| `transfer_intents`, `transfer_workflow_events`, `transfer_stock_reservations`, `warehouse_transfer_tasks`, `warehouse_transfer_allocations` | Igénylő, aktor, foglaló, feloldó, létrehozó és végrehajtó szerep. Személyre hivatkozó, törlést korlátozó idegen kulcsok. |
| `shipment_intent_assignments`, `shipment_plan_operations`, `shipment_dispatches`, `shipment_receipts`, `shipment_receipt_putaways`, `shipment_assignment_cargos`, `shipment_cargo_confirmations`, `vehicle_operations` | Hozzárendelő, leválasztó, megerősítő vagy aktor azonosító, törlést korlátozó idegen kulccsal. A fuvarfeladás JSON-jában rögzítéskori név is található. |
| `manual_receipt_putaways`, `warehouse_relocations`, `warehouse_load_verifications` | Rögzítéskori név és aktorazonosító. A kézi betárolás aktora `RESTRICT` kapcsolat. A belső áthelyezés és a mérés aktora numerikus pillanatkép, `users` idegen kulcs nélkül. |
| `sessions`, `password_reset_tokens` | A DB-munkamenet opcionális indexelt felhasználóazonosítót tartalmaz. A jelszótoken az e-mail-címhez kötött. Ezek és a böngészők adatai külön életciklusúak. |

- A készletmozgások, régi átmozgatások, fuvarok és megállók végrehajtó mezői közvetlenül hivatkozhatnak a fiókra. A fuvarigények, foglalások, kitárolások, jármű- és fuvareseti műveletek, átvételek, betárolások, jogosultsági események és azokhoz tartozó ismétlési táblák további aktor- vagy felhasználói azonosítókat tartanak meg. Több kapcsolat `RESTRICT` vagy alapértelmezett adatbázis-korlátozással védett. Egyes időszakos hivatkozások és munkamenetek eltérő kapcsolati szabályt használnak.
- A mozgásnapló, a visszáru- és minősítési lista, valamint több átvételi és jogosultsági előzmény a kapcsolt fiók aktuális nevét olvassa. A manuális betárolás, belső mozgatás és rekeszterhelés-ellenőrzés saját `actor_name` mezőt használ. A fuvarfeladás JSON-pillanatképe tartalmazhatja a végrehajtó akkori nevét, a készletmozgás pedig a partner akkori nevét tárolja.
- A helyesbített fióknév az aktuális kapcsolatokon megjelenik. A rögzített pillanatképeket és a már kiadott bizonylatokat a rutin profilmentés megőrzi. Ha a pillanatkép pontossága vitatott, a szervezet helyesbítő bejegyzést vagy a név láthatóságának korlátozását mérlegelheti a történeti és jogi követelmények alapján.
- Jóváhagyott későbbi minimizálásnál előbb válasszák szét a megőrzendő készlet- és rendelési adatot az azonosításhoz már szükségtelen mezőktől. Használható azonosítóval hivatkozott nyugalmi fiók, szűk hozzáférésű külön megfeleltetési adat vagy szükségtelen névpillanatkép megszüntetése. A könyvelési, mennyiségi és kapcsolati invariánsokat migrációs próbával és visszaállítási teszttel kell ellenőrizni. A visszakapcsolható azonosító álnevesítés. Anonimizálásról akkor lehet beszélni, ha a helyreállítható megfeleltetés és a reális közvetett azonosítási lehetőség is megszűnt. A mentések és a korábbi exportok is számítanak.

## Adatmegőrzési mátrix

| Adatok | Jelenlegi lejárat és tárolás | A szervezet döntése vagy vizsgálata |
| --- | --- | --- |
| Aktív és inaktív fiók, raktári tagság | Alkalmazás által kezelt aktív állapot. Az inaktív sor és a megmaradó tagság automatikus törlés nélkül megmarad. | Hozzáférés rendszeres felülvizsgálata, a személyes mezők kivezetése és megőrzött szerep- vagy fiókhivatkozás szükségessége |
| Jogosultsági audit | Nincs általános lejárat | Ellenőrzési és jogérvényesítési cél, olvasói kör, kitakarás és megőrzés |
| Készletmozgások és minősítések | Nincs automatikus törlés | A konkrét rekord üzleti vagy esetleges számviteli/bizonylati szerepe, mezőszintű szükségesség |
| Átmozgatások, fuvarok és átvétel | Nincs automatikus törlés | A készlet- és szállítási történet megőrzése, személyes mezők köre és a konkrét dokumentummegőrzési követelmény |
| Mérési és betárolási előzmények | Nincs automatikus törlés | Biztonsági és minőségi visszakövethetőség, pillanatképek felülvizsgálata |
| Partnerkapcsolattartók | Archiválhatók, automatikus lejárat nélkül | Kapcsolat megszűnése, rendszeres aktualizálás, szükségtelen adatok eltávolítása |
| Alkalmazás- és infrastruktúranaplók | Csatornafüggő. A `daily` megőrzése konfigurálható, a `single` önmagában nem ad rotációt. | Külön rotáció, kimeneti szűrők, hozzáférés és incidens miatti megőrzés |
| Munkamenetek | Használhatóság alapból 120 perc tétlenség, a fizikailag tárolt sor takarítása driverfüggő. A `remember me` cookie külön életciklusú. | Tétlenség, megosztott eszközök, megjegyzett belépés, DB/Redis tisztítása |
| Jelszó-visszaállítási token | Alapból 60 perc érvényesség, egyszeri használat. Lejárt sorok maradhatnak, ha a token-takarítás nem fut. | Takarítás és naplók védelme. Az üzleti adatok megőrzését ettől külön döntik el. |
| Generált dokumentum | A PDF-végpont a dokumentumot a válaszban adja vissza. A kliens által mentett példány tovább tárolható. | Címzettek, tárolás, megosztás, szükséges példányok és bizonylati követelmények |
| Adatbázismentések | A repo telepítője nem definiál mentést vagy lejáratot | Ciklus, titkosítás, elkülönítés, rotáció, dokumentált visszaállítás és helyesbítések újraalkalmazása |

## Érintetti kérelmek kezelése

1. Az adatkezelő az egyeztetett csatornán rögzíti a beérkezést, a kérelem típusát és hatókörét. Ahol kétség merül fel, arányos személyazonosság-ellenőrzést végez, például már hitelesített fiókon vagy igazolt szervezeti csatornán keresztül. A kérelemhez általában elegendő igazolásnál több okmányadatot nem kell begyűjteni.
2. Kijelölt ügyintéző a szükséges belső azonosítót azonosítja. A lekérdezés a fiókra, tagságokra, auditokra, aktorazonosítós üzleti rekordokra és az indokolt névpillanatképekre szorítkozik. Partnerkapcsolattartónál a partnerrekord és szükség szerint a kapcsolódó pillanatképek a kiindulópont. A teljes adatbázismentés helyett engedélyezett, célzott kivonat készül.
3. A másolatot más személyek adatai, üzleti titkok és hitelesítési adatok szempontjából ellenőrzik. A hozzáféréshez az érintett saját adatai és a GDPR szerinti kontextus tartozik. Adathordozhatóságnál az automatizált, hozzájárulásra vagy szerződésre épülő, az érintett által megadott adatok körét külön kell elbírálni. Az átadható kivonatot titkosított vagy más megfelelően védett csatornán adják át.
4. Helyesbítés a felhasználói profilból, az adminisztrátori adatlapról, vagy partner esetén az engedélyezett törzsadat-szerkesztéssel kezdhető. Törlést és korlátozást az adatkezelő célonként bírál el. Jogszabályi kötelezettség, jogi igény és más alkalmazható kivétel esetén az érintett tájékoztatást kap a megtartásról. A korlátozás megszervezhető dokumentált műveleti és hozzáférési korlátozással. A fiók inaktiválása önmagában csak a belépést állítja le. Tiltakozásnál az alkalmazandó jogalap és az elbírálási szabály dönt.
5. Az adatkezelő dokumentálja a döntést, a végrehajtást, a válasz idejét és a szükséges, címzetteknek adott helyesbítési, törlési vagy korlátozási értesítéseket a 19. cikk alapján. A 12. cikk szerinti válasz főszabály szerint egy hónapon belül esedékes. Indokolt hosszabbításról az első hónapon belül szólni kell. Elutasításnál szerepeljen az indok és a panasz vagy jogorvoslat módja. [GDPR 12–21. cikk](https://eur-lex.europa.eu/legal-content/HU/TXT/?uri=CELEX%3A32016R0679) · [NAIH érintetti jogok](https://naih.hu/erintetti-jogok)

## PDF-ek és a 9/E fejlesztési követelményei

| Meglévő PDF | Mindkét válaszmód jogosultsága | Személyes adattal összefüggő mezők |
| --- | --- | --- |
| Termékoldali leltárív | Globális készletkezelő szerepkör | Jellemzően termék, raktár, árumennyiség és üres aláírásmező. Szűrőszöveg tartalmazhat nevet. |
| Raktári leltárív | Adminisztrátor vagy raktárvezető | Raktárnév és cím, opcionális megjegyzés jellegű szűrőmetaadat és aláírásmező. A telephely természetes személyhez kapcsolhatósága helyzetfüggő. |
| Raktári statisztika | Adminisztrátor vagy raktárvezető | Összesítések, időpont és telephely. |
| Készletmozgási napló | Globális készletkezelő szerepkör | Végrehajtó neve, rögzítéskori partnernév, hivatkozás, visszáru- és minősítési indok. A kikapcsolt mezők személyes szűrőértékeit is takarja a szerver. |
| Régi szállítólevél | Jogosult forrás- vagy céloldal, illetve admin | Tétel, telephely, árumennyiség és bizonylati adatok. |
| Rakodási dokumentum | Jogosult forrásoldal, illetve admin | Útvonal, jármű, telephely és opcionális megjegyzés. A céloldali megálló hozzáférése önmagában nem nyitja meg a rakodási PDF-et. |

Minden PDF-végpontnál azonos jogosultság kell az előnézethez és a letöltéshez. A válasz `Cache-Control: private, no-store` fejlécet kap. A szerver a jogosultsággal elérhető rekordokra alkalmazza a szűrést. A PDF a HTTP-válaszhoz készül. A Dompdf ideiglenes könyvtárának és betűtípus-gyorsítótárának védelmét az üzemeltető ellenőrzi. A már letöltött vagy megnyitott példányokat a gyorsítótár tiltása sem vonja vissza.

A 9/E-ben minden új exporthoz kell szerveroldali policy, erőforráshoz kötött lekérdezés, egyező előnézet és letöltés, engedélyezett szűrők, minimális alapértelmezett személyes mezők, privát gyorsítótár-beállítás és teszt vendégre, más raktárra, megváltozott azonosítóra és visszavont hozzáférésre. A fejléc, lábléc, szűrő-összesítő, fájlnév és esetleges háttérmunka ugyanazt az adattakarékosságot alkalmazza. Aszinkron exportnál külön döntés kell a tárolási helyre, lejáratra és letöltési újraellenőrzésre.

## A technikai ellenőrzés összefoglalása

| Ellenőrzés | Megfigyelés a forráskódban és tesztekben |
| --- | --- |
| Jelszó és belépés | Laravel hashed cast, a tényleges algoritmust az alkalmazás hashing konfigurációja adja. Bejelentkezési korlátozás címre és IP-re, resetkérés és véglegesítés korlátozás, külön korlát jelszócserére és -megerősítésre. A nyilvános resetkérés szabályos címeknél egységes visszajelzést ad. Ez tartalmi egységesítés, a kézbesítés ideje nincs egységesítve. |
| E-mail és helyreállítás | A cím átírása módosítja a helyreállítási célcímet. Saját és adminisztrátori űrlapon a mentést végző felhasználó jelenlegi jelszava kell. A régi, illetve átadott címhez tartozó tokenek törlődnek. Az opcionális e-mail-hitelesítés csak kézbesítő levelezőt használ és a cím hashét az aktuális zárolt fiókon is ellenőrzi. Általános e-mail-hitelesítési kötelezettség továbbra sincs. Az admin helyreállító Artisan-parancs szerveroldali. |
| Munkamenet és jogosultság | Belépés és jelszócsere esetén munkamenetmegújítás, aktív/verzió ellenőrzés, megjegyzett token forgatása, utolsó használható admin védelme. A kritikus készlet- és fuvartranzakciók jogosultságát friss adatokból ellenőrzi a rendszer. A saját profil és az adminisztrátori fióknézetek HTTP-válaszai `private, no-store` fejlécet küldenek. |
| CSRF, XSS, SQL, tömeges mezőmentés | Az állapotváltoztató műveletek web middleware alatt futnak, Blade alapból escape-eli a szöveget, JSON-adatokhoz `@js`/Laravel JS szerializálás használatos. A vizsgált dinamikus keresések paraméterkötöttek, a rendezési kulcsok engedélylistásak, a fiók mentése validált mezőkből dolgozik. Új nézetben a nyers HTML és új SQL-rendezési mező külön felülvizsgálandó. |
| Titkok, naplók és hibaválasz | A hitelesítési mezők nem üzemi JSON- vagy auditmezők, a jelszó és reset token ki vannak zárva a validációs flashből. A log mailer normál esetben naplózhatná a teljes üzenetet. A reset és az opcionális hitelesítés ezt a csatornát elutasítja. A proxy-, SQL-, SMTP-, hibajelentési és mentési naplózás külön telepítési kontroll. |
| Böngésző és harmadik fél | A korábbi automatikus betűtípus-kéréseket kivettük. A felület rendszerbetűt használ. A konfigurált munkamenet-cookie és az `XSRF-TOKEN` mellett megjegyzett belépéskor külön `remember_web_...` cookie jöhet létre. A kezelőfelület helyi beállításai és az átmozgatási űrlap helyreállítási adatai szerepelnek a böngészőtárolókban. A megosztott gép rendje üzemeltetési döntés. A vizsgált alkalmazáskódban nincs analitikai tag és kész adatvédelmi tájékoztató. A telepítéskor hozzáadott komponenseket a tényleges cookie- és adattovábbítási felméréshez külön ellenőrizni kell. |
| Bemutatóadatok és mentés | A `DatabaseSeeder` futása helyi és tesztkörnyezethez kötött. A különálló demóseederek külön meghívhatók, ezért az éles telepítési rend a seed parancsokat is kizárja. A tesztfiókok, SQLite-fájlok és versenyteszt-adatbázisok külön vannak a működő Laragon-adatbázistól. Az éles mentési és helyreállítási folyamatot a repo nem automatizálja. |

A vizsgált változtatásokhoz nincs új migráció. A szélesebb globális partner-, készlet- és mozgásnézetek, a szabad szöveg, a közös munkaállomások, a kézbesítés, a naplózás és a mentés maradó kockázataihoz üzemeltetői vagy termékdöntés tartozik. A GDPR 30. cikk szerinti esetleges nyilvántartást ez a technikai leltár előkészíti. A jóváhagyott helyi szabályzat és a telepítés ellenőrzése a szervezet feladata.

### A 9/A.3 érintett fájljai

- PDF és útvonalak. [ExportController](../app/Http/Controllers/ExportController.php) · [Webes útvonalak](../routes/web.php)
- Hitelesítés és fiók-helyesbítés. [Hitelesítési útvonalak](../routes/auth.php) · [EmailVerificationNotificationController](../app/Http/Controllers/Auth/EmailVerificationNotificationController.php) · [VerifyEmailController](../app/Http/Controllers/Auth/VerifyEmailController.php) · [ProfileUpdateRequest](../app/Http/Requests/ProfileUpdateRequest.php) · [ProfileController](../app/Http/Controllers/ProfileController.php) · [UserController](../app/Http/Controllers/UserController.php)
- Felület. [Profilűrlap](../resources/views/profile/partials/update-profile-information-form.blade.php) · [Felhasználói űrlap](../resources/views/users/create.blade.php) · [Űrlap JavaScript](../resources/js/components/user-form.js) · [Belső elrendezés](../resources/views/layouts/app.blade.php) · [Vendégelrendezés](../resources/views/layouts/guest.blade.php) · [Kezdőoldal](../resources/views/welcome.blade.php) · [Tailwind-beállítás](../tailwind.config.js)
- Regressziós tesztek. [Adatvédelmi HTTP-mátrix](../tests/Feature/PrivacyAuditTest.php) · [Profil](../tests/Feature/ProfileTest.php) · [Felhasználókezelés](../tests/Feature/UserManagementTest.php) · [Böngészős fiókkezelés](../tests/Browser/user-management.spec.js)
- Dokumentáció. [Hozzáférések](felhasznaloi-hozzaferes.md) · [Tájékoztató](adatkezelesi-tajekoztato-tervezet.md) · [Ellenőrzőlista](adatvedelmi-uzemeltetesi-ellenorzolista.md)

### Ellenőrzési eredmények

- Laravel. 38 célzott teszt és a teljes, 1263 tesztes csomag sikeres. A teljes futás 13 497 állítást ellenőrzött.
- Playwright. Mindhárom célzott fiókkezelési teszt sikeres. A teljes csomagban 309 teszt elsőre sikerült. A mozgásnapló oszlopválasztója egy átméretezés utáni próbán időtúllépést jelzett, majd az automatikus ismétlés és három külön célzott futás sikerült.
- MySQL. Mind a 237 konkurenciaellenőrzés sikeres volt, az elkülönített tesztadatbázist a tesztfuttató eltávolította.
- A frontend összeállítása és a `git diff --check` sikeres volt. A 9/A.3 javításaihoz nincs új migráció.

### Javasolt commitcsoportok a 9/A ághoz

1. **Hozzáférésbiztonság és régi átmozgatási folyamatok jogosultságai**. Fiókállapot és munkamenet, regisztráció lezárása, régi fuvarok és készletműveletek policyjai, kapcsolódó migráció és tesztek.
2. **Felhasználókezelés, raktári tagságok és auditnapló**. Adminisztrátori képernyők, fiókkiadás, jogosultság-kezelés, az utolsó használható adminisztrátor védelme, 9/A.2 és 9/A.2.1 tesztek.
3. **Adatvédelmi audit, e-mail-védelem és PDF-adattakarékosság**. A 9/A.3 javításai és regressziói, az üzemeltetési és érintetti dokumentáció.

A több fázist érintő fájloknál a commitokhoz funkció szerint érdemes kiválasztani a változtatásokat. Minden csoporthoz kerüljön a hozzá tartozó validáció és teszt.

[Adatkezelési tájékoztató-tervezet](adatkezelesi-tajekoztato-tervezet.md) · [Üzemeltetési ellenőrzőlista](adatvedelmi-uzemeltetesi-ellenorzolista.md)
