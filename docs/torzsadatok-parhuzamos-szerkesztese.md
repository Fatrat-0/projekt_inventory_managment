# Törzsadatok párhuzamos szerkesztése

A termékek, partnerek, raktárak és kategóriák szerkesztői az adatlap megnyitásakor kapott `lock_version` értéket küldik vissza. Az új migráció a meglévő rekordoknál is 1-re állítja a kezdeti verziót. Telepítéskor futtatni kell:

```powershell
php artisan migrate
```

## Ütközés és helyreállítás

A `MasterDataEditor` tranzakcióban, zároló aktuális olvasásból hasonlítja össze a mentett és beküldött verziót. Minden sikeres szerkesztés eggyel növeli a verziót, akkor is, ha a mezők változatlanok. Ez nem függ az időbélyeg másodperces felbontásától. A kategóriahierarchia közös zárolása és körkörösség-ellenőrzése megmarad.

Eltéréskor sem az adatlap, sem a verzió nem módosul. JSON-kérésnél HTTP 409 és `lock_version` mezőhiba érkezik. HTML-kérésnél a szerver a megfelelő GET szerkesztőoldalra irányít vissza, megőrzi a saját mezőértékeket és az eredeti verziót, valamint a partner-, kategória- és raktárlista visszatérési környezetét.

A figyelmeztetés az eltérő mezők jelenleg mentett értékeit mutatja meg; a partner archiválási állapota külön is látszik. A hosszú értékek panelje görgethető. A felhasználó:

- betöltheti az aktuális adatokat a saját módosításai helyett; vagy
- összehasonlíthatja az adatokat, szükség szerint javíthatja a saját űrlapját, majd az átnézést jelölőnégyzettel megerősítve menthet.

Az utóbbi esetben az átnézett változat verzióját is ellenőrizzük. Ha közben újabb mentés történik, ismét ütközés keletkezik; az új adatokat újra át kell nézni, a jelölőnégyzet nincs automatikusan elfogadva. Ez JavaScript nélkül is működik. A saját űrlap összes mezőértéke kerül mentésre; automatikus mezőnkénti összefésülést nem végzünk.

Egy más mezőre kapott validációs hiba és a partner duplikációs figyelmeztetése sem emelheti meg csendben az eredeti verziót. Hiányzó, hibás vagy megerősítetlen átnézési adatot a szerver elutasít. A duplikáció jóváhagyása és a párhuzamos szerkesztés jóváhagyása külön ellenőrzés.

A partner archiválása és visszaállítása is növeli a verziót; az azonos állapot ismételt kérése nem. Ha a szerkesztés előbb befejeződik, az archiválás a friss adatokon történik. Ha az archiválás az első, a régi szerkesztőűrlap ütközést kap. A partner törzsadatainak mentése önmagában nem állítja vissza az archivált állapotot.

Két külön termék egyidejű cikkszám-módosításánál az adatbázis egyediségi megkötése az utolsó védelmi vonal. A későn észlelt cikkszámütközés validációs hibává alakul, a vesztes mentés verziója és egyéb adatai visszagördülnek.

## Hatókör és ellenőrzés

A védelem az alkalmazás fenti szerkesztési végpontjaira és a partner állapotváltásaira vonatkozik. Készletmozgás létrehozása nem emeli a termék törzsadat-verzióját: a készletet külön tranzakciós szabályok védik. A már törölt rekord továbbra is 404 választ ad. Közvetlen SQL, Query Builder vagy seeder által végzett törzsadat-módosításnál az interaktív szerkesztés védelme csak akkor marad teljes, ha az író a verziót is megfelelően növeli; az új üzleti módosításokat ezen a szolgáltatáson keresztül kell vezetni.

Az automatizált tesztek két felhasználó régi űrlapjait, ugyanazon másodpercen belüli mentéseket, ismét elavult jóváhagyást, hibás adatokat, mezőmegőrzést, archiválást és a duplikációs figyelmeztetést ellenőrzik. A böngészőtesztek mind a négy szerkesztőoldalt, a 320–1440 pixel közötti megjelenítést és a JavaScript nélküli folyamatot is kipróbálják. A saját, eldobható adatbázison futó MySQL-ellenőrzések külön folyamatokból versenyeztetik a mentéseket, külső REPEATABLE READ pillanatképből is.
