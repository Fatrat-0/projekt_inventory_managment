# Rekeszterhelés utólagos ellenőrzése

A mérés mindig egy **konkrét elhelyezési rekord** teljes megmaradt árumennyiségére vonatkozik. Azonos termékből ugyanabban a rekeszben több ilyen rekord is lehet. Részleges kézi kiadás, átmozgatási igényhez tartozó kitárolás és belső áthelyezés után a rekord megőrizheti a korábbi terhelést, amíg meg nem mérik.

## Végpontok a mérési felülethez

Az útvonalak közös előtagja `/warehouses/{warehouse}/load-verifications`. A JSON-válaszhoz a kliens küldje az `Accept: application/json` fejlécet. A `POST` kérésekhez szükséges a bejelentkezett munkamenet és annak CSRF-tokenje.

| Művelet | Útvonal | Eredmény |
|---|---|---|
| Ellenőrzésre váró tételek | `GET /pending` | Lapozott lista, tételenként revízióval, elhelyezkedéssel, aktuális mennyiséggel és nyilvántartott terheléssel. |
| Tétel részletei | `GET /pending/{placement}` | A konkrét elhelyezés aktuális állapota és a `can_confirm` jelzés. |
| Előnézet | `POST /pending/{placement}/preview` | `can_verify`, az egyes ellenőrzések eredménye és a szabad kapacitás mérés előtti és utáni értéke. Írás nélkül, a részletes bontás a `capacity_comparison` mezőben. |
| Véglegesítés | `POST /pending/{placement}` | Elfogadott mérésnél `201`. Ha a mérés sérti a rekesz szabályait, `422`, mezőszintű hibákkal és az elutasított mérés naplóbejegyzésével. |
| Napló | `GET /history` | Lapozott, a rögzítéskori rekeszkódot és megnevezéseket is tartalmazó mérési előzmények. |

A lista és az előzmények opcionálisan szűrhetők `location_id` és `product_id` alapján. Az előzmények `placement_id` alapján is szűrhetők. Lapozási paraméterek a `page` és a `per_page` (1–100, alapérték 25).

Az előnézet és a véglegesítés a részletekből kapott változatlan `revision` értéket, a teljes megmaradt tétel `cargo_weight_kg` értékét és az egész `pallet_slots` értéket várja. A tömeg pozitív, legfeljebb három tizedesjegyű kilogrammérték. A `pallet_slots: 0` kifejezetten nem raklapos tételt jelent. Az ismeretlen raklaphelyigénynél a mérést még el kell végezni. Opcionálisan küldhető `reason` és `note`. A darabszámot a szerver határozza meg. Ha egy nyitott eltéréshez tartozó tétel teljesen kikerült a rekeszből, helyszíni ellenőrzés után a lezárás adatai `0 kg` és `0` raklaphely.

A véglegesítéshez szükséges egy `operation_key` UUID. Változatlan kérés újbóli elküldésekor ugyanaz az elfogadott vagy elutasított eredmény érkezik vissza. Másik méréshez új UUID kell. Elavult revízióra `409` érkezik. Ilyenkor a kliens frissítse az elhelyezés adatait, és kérje a mérés ellenőrzését az aktuális mennyiségre.

A helyi kitárolási és átvételi operátor megtekintheti az adatokat és kérhet előnézetet. Véglegesíteni a helyi felügyelő és a globális adminisztrátor jogosult.

## Aktuális állapot és mérési előzmények

A részletek és a függő lista a tétel `status` mezőjében `pending`, `verified` vagy `discrepancy_open` állapotot adnak vissza. A `recorded_weight_grams` és a `recorded_pallet_slots` az elhelyezési rekord aktuális adatai. A `discrepancy.measurement` az utolsó kapacitáseltérést dokumentáló mérés, annak darabszámával, végrehajtójával és időpontjával. A `measurement_current: false` jelzi, hogy az elhelyezés azóta megváltozott. A `discrepancy.violations` a megsértett szabályok kódjait, a `next_action` és a `next_action_label` a szükséges következő lépést adja meg. A `placement_present: false` azt jelenti, hogy az elhelyezési rekord már megszűnt. Egy ilyen nyitott eset továbbra is szerepel a függő listán.

A kapacitáseltérést dokumentáló véglegesítés `422` választ ad `discrepancy_open: true` mezővel, a megőrzött `verification` eseménnyel és az ellenőrzési hibákkal. A kliens ezért frissítse a tételt és a kapacitásadatokat. Az UUID ismétlése mindig az eredeti válasz tartalmát adja vissza. Az aktuális állapot a részletek vagy az előzmények végpontjáról kérhető le.

A korábbi mérések `capacity_violations` kódokat és az előzmény végpontján `resolution` objektumot kapnak, ha időközben sikeres volt a rendezés. A lezárás módja `remeasured` újramérésnél, illetve `confirmed_empty` a teljes eltávolítás helyszíni igazolásánál. A sikeres naplórekord `resolves_verification_id` mezője az eltérést dokumentáló legutóbbi eseményre mutat.

## Kapacitásvédelem és tehermentesítés

A véglegesítés ismét ellenőrzi a rekesz, a polcszint és az állvány terhelését, az élő célrekeszfoglalásokkal együtt. A mérési művelet csak az adott elhelyezési rekord terhelését és ellenőrzési jelzését érinti, valamint a mérési naplóba ír. A dokumentált eltérésnél az érvényes kapacitási összesítés egyetlen tételként számol a nyilvántartott és a mért terhelés közül a nagyobbal. A rekesz minden nyitott eltérésnél tiltott célhely. Ha a mérés a szint vagy az állvány korlátját sérti, az érintett közös kapacitás is tiltott. A Smart Putaway elutasított helyet és konkrét indokot ad, az érintett hely szabad kapacitása nem jelenik meg pozitívként.

Mennyiségváltozás után a régi mérés történeti adat. A korlátozás megmarad, és a bizonytalan terhelés miatt a közös szintet és állványt is érinti. A készlet továbbra is kivehető, vagy a mozgatott rakomány tényleges adataival megfelelő, külön kapacitású célhelyre helyezhető. Az áthelyezés céloldalán minden normál ellenőrzés érvényes. A már létrejött célfoglalás megmarad, de az érintett régi átmozgatási folyamat céloldali fizikai elhelyezése az eltérés rendezéséig sikertelen.

Minden eltérést külön kell rendezni. Az aktuális tétel ismételt mérése a rekesz és az alkalmazandó közös korlátok ellenőrzésével zárja le. Teljes eltávolításnál külön 0 kg / 0 raklaphely helyszíni visszaigazolás szükséges. Ez kizárólag az eltávolított tételhez tartozó eltérést zárja le. Az újramérés és a lezárás visszakereshető az eredeti eltérés mellett.

A 7/A.1 migráció a meglévő naplóhoz adja a szabálykódokat és a lezáró esemény hivatkozását. A 7/A során elmentett, felismert kapacitássértő auditok is érvényesek. A szabálykód nélküli korábbi eseményeket a korabeli ellenőrzési üzenetek alapján értelmezzük. A történeti naplóbejegyzések változatlanul megmaradnak.

## Ellenőrzési munkafelület

A Raktárak modul felső sávjában a **Terhelésellenőrzések** művelet nyitja meg a raktár listáját. A rekesz adatlapjáról a rekeszre szűrt lista vagy az adott tétel munkalapja érhető el. A lista alapértelmezésben a teendőket mutatja, a nyitott eltérésekkel az elején. Az **Előzmények** állapot a meglévő auditnaplót jeleníti meg.

Az előnézet szerveroldali `measurement` adataiból készül a helyszíni megerősítés. A `capacity_comparison` korlátonként mutatja a névleges határt, a figyelembe vett terhelést, az előjeles számított maradékot és az új elhelyezésre felhasználható kapacitást a mérés előtt és a rögzítés várható hatásaként. Nyitott eltérés miatt a felhasználható érték nulla lehet pozitív számított maradék mellett is. A dokumentált eltérés rögzítésekor egy rövid értesítés jelenik meg, az eltérés állapotát a tartós összefoglaló mutatja. Az aktuális rekesz-, szint- és állványkapacitás a részletek `capacity` objektumából érkezik. Elavult revíziónál a munkalap frissíti a szerverállapotot, megtartja a beírt mérési értékeket, és új előnézetet kér. Bizonytalan mentés esetén ugyanazzal a tartalommal és műveleti azonosítóval próbálható újra a beküldés.

A munkafelülethez a 7/A és 7/A.1 migráció futtatása szükséges. A 7/B nem ad új migrációt.
