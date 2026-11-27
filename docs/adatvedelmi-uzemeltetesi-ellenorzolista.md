# Élesítési adatvédelmi és biztonsági ellenőrzőlista

Ellenőrzési segédlet a 9/F telepítéshez. A konkrét környezetben felelőst és ellenőrzés dátumát kell rögzíteni. Az ellenőrzést a staging környezettel kezdd, elkülönített, fiktív adatokkal.

## Alkalmazás és hozzáférés

- [ ] Az alkalmazás `production` környezetben, kikapcsolt `APP_DEBUG` értékkel fut. A Vite fejlesztői kiszolgálót, a tesztfuttatót és a hibakereső eszközöket az éles HTTP-belépési pont nem szolgálja ki.
- [ ] A webgyökér a Laravel `public/` könyvtára. A repo, a környezeti fájlok, a `storage` privát része, a naplók, a tesztartefaktumok és a mentések nem publikusak.
- [ ] Az `APP_KEY`, DB- és SMTP-hitelesítő adatok elkülönítve, szűk hozzáféréssel tároltak. Dokumentált a kulcsok cseréje és a titkosított cookie-kra, munkamenetekre és mentésekre gyakorolt hatás.
- [ ] Az alkalmazás DB-fiókjának jogosultságai a normál futáshoz szükséges műveletekre korlátozottak. A migráláshoz és a mentéshez külön hitelesítő adatok kezelhetők.
- [ ] A korábbi, esetleg önregisztrált fiókokat hiteles szervezeti lista alapján vizsgálták felül, különösen a magas jogosultságú és tagság nélküli fiókokat. A bemutatóseederek és ismert tesztjelszavak nem kerültek az éles rendszerbe.
- [ ] A 9/A.4 átállás előtt ellenőrizték a mentést, majd karbantartási ablakban futtatták a migrációt. A `deploy.sh` futtatja a migrációt. A karbantartási módot és a régi háttérfolyamatok leállítását az üzemeltető is ellenőrzi. A tagságok azonosítóit, számát és eredeti szerepköreit ellenőrizték, mielőtt több szerepkört osztottak ki. [Migráció és visszagörgetés](felhasznaloi-hozzaferes.md)
- [ ] A szervezet jóváhagyta a globális és helyi jogosultságok kiosztását, az adminisztrátori fiókok kezelését és az `inventory:create-admin` parancs szerveroldali hozzáférését.
- [ ] A függőségeket rendszeresen vizsgálják a jóváhagyott Composer- és npm-folyamattal. Rögzített a javítások, rollback és sürgős sérülékenységek kezelése.

## HTTPS, munkamenet és levelezés

- [ ] Az `APP_URL` a kanonikus HTTPS-cím. A szerver és a megbízható proxy az engedélyezett hostokat használja, a nem HTTPS-bejövő kérések átirányítása és a tanúsítvány frissítése ellenőrzött.
- [ ] A session cookie `Secure` és `HttpOnly`, a domain és útvonal kellően szűk. A `SameSite` érték a tényleges integrációkkal kompatibilis. A CSRF-cookie kliensoldali olvashatósága a Laravel/XSRF működéséhez kötött. A megjegyzett belépést, a tétlenségi időt és a munkamenet-titkosítást a szervezet jóváhagyta.
- [ ] A webes gyorsítótár nem tárol személyhez kötött oldalakat, PDF-eket vagy védett JSON-válaszokat. A proxy és a CDN továbbviszi a privát és `no-store` fejléceket.
- [ ] A levélküldés ellenőrzött SMTP- vagy más kézbesítő csatornán történik. Hitelesítési levélhez a `log` és a naplóba visszaeső konfiguráció kerülendő. Tesztelje a meghívót, az újraküldést, a lejárt és felhasznált linket, az inaktiválást és a rossz levélszolgáltatói állapot kezelését. Ellenőrizze a kanonikus linket és a címzettet.
- [ ] A próba igazolja, hogy egy visszavont munkamenet a védett API-t és PDF-et sem éri el. Rögzített a közös munkaállomások kijelentkezési és böngészőprofil-kezelési rendje.

## Naplók, dokumentumok, mentések

- [ ] A logcsatornák és rotáció meghatározottak. A Laravel, proxy, SMTP, SQL-nyomkövetés, APM és hibajelentés mellőzi a jelszavakat, reset tokeneket, meghívólinkeket, sütiket, munkamenet-azonosítókat, teljes érzékeny kérésadatokat és hitelesítő adatokat. A reset URL útvonala és a lekérdezési paraméterek szűrése a webes hozzáférési naplókra is kiterjed.
- [ ] A Dompdf ideiglenes könyvtára, betűkészlet-gyorsítótára és az esetleges exportkönyvtár nem publikus. A mentett PDF-ek hozzáférése üzleti cél szerint szűkített. A 9/E új exportjainál külön felülvizsgálandó a késleltetett generálás és állománytárolás.
- [ ] Van titkosított, korlátozott körből elérhető és a működő adatbázistól elkülönített biztonsági mentés. Jóváhagyott a rotáció, a hozzáférési napló és a törlési folyamat.
- [ ] A visszaállítást izolált környezetben kipróbálták. A visszaállított régi fiókállapotokat, jogosultságokat, jelszólinkeket és elvégzett helyesbítéseket egyeztetik az aktuális állapottal a rendszer újbóli megnyitása előtt.
- [ ] A szervezet adatkezelésenként meghatározta a célokat, jogalapokat, címzetteket és megőrzési időket vagy kritériumokat. A közzétett tájékoztató és az érintetti eljárás ezekhez igazodik. [Adatvédelmi audit és megőrzési mátrix](adatvedelmi-audit.md)

## Incidens és helyreállítás

- [ ] Kijelölték a bejelentési csatornát, az adatkezelői felelőst és a feldolgozói értesítés rendjét. Rögzítik az észlelés és tudomásszerzés idejét, a tényeket, a lehetséges címzetteket és hatást, az intézkedést és az értékelés eredményét.
- [ ] Gyakorolták a hozzáférés visszavonását, a tokenek és kulcsok érintettségének értékelését, a naplóbizonyíték elkülönített megőrzését és a tévesen megosztott dokumentum kezelését.
- [ ] Az adatkezelő értékeli az érintettekre jelentett kockázatot. A 33. cikk szerint a bejelentés indokolatlan késedelem nélkül, lehetőség szerint a tudomásszerzéstől számított 72 órán belül történik, ha a kivétel feltételei nem állnak fenn. Magas kockázat esetén az érintettek 34. cikk szerinti tájékoztatását is elbírálja. Az incidensek dokumentálása külön feladat. [GDPR 33–34. cikk](https://eur-lex.europa.eu/legal-content/HU/TXT/?uri=CELEX%3A32016R0679) · [NAIH incidenskezelés](https://www.naih.hu/tudnivalok-az-adatvedelmi-incidensek-kezeleserol)
