# Projekt Ütemterv

- 2026-03-01: Fejlesztői környezet és adatbázis sémák
- 2026-03-08: Eloquent modellek és kapcsolatok
- 2026-03-15: Hitelesítés és jogosultságkezelés
- 2026-03-22: Törzsadatok I. (Kategóriák és Termékek)
- 2026-03-29: Törzsadatok II. (Raktárak és Partnerek)
- 2026-04-05: Készletmozgások alapjai
- 2026-04-12: Adatintegritás és MVP lezárása
- 2026-04-19: Raktárközi átmozgatások indítása
- 2026-04-26: Raktárközi átmozgatások jóváhagyása
- 2026-05-03: Vezérlőpult és riasztások
- 2026-05-10: Felhasználói felület finomítása
- 2026-05-17: Átfogó tesztelés
- 2026-05-24: Dokumentáció és hibajavítás

## Fejlesztői környezet és adatbázis sémák
A fejlesztői környezet beállítása, valamint az adatbázis táblák (felhasználók, termékek, raktárak, készletek) megtervezése migrációk segítségével[cite: 18, 545].

## Eloquent modellek és kapcsolatok
[cite_start]Az adatbázist kezelő Eloquent ORM modellek létrehozása, a kapcsolatok definiálása, és tesztadatok betöltése a gyorsabb fejlesztés érdekében[cite: 59, 83].

## Hitelesítés és jogosultságkezelés
[cite_start]A felhasználói beléptetés és a szerepkör-alapú hozzáférés-szabályozás (RBAC) implementálása a különböző felhasználói szintekhez (Admin, Raktárvezető, Raktáros)[cite: 23, 379].

## Törzsadatok I. (Kategóriák és Termékek)
[cite_start]A termékek és kategóriák rögzítéséhez, szerkesztéséhez és listázásához szükséges adminisztrációs űrlapok és logikák lefejlesztése[cite: 630].

## Törzsadatok II. (Raktárak és Partnerek)
[cite_start]A több raktár, illetve a vevők és beszállítók kezelését végző modulok elkészítése a megfelelő adatbázis kapcsolatokkal[cite: 18, 630].

## Készletmozgások alapjai
[cite_start]A készletkezelés legfontosabb tranzakcióinak (bevételezés, kiadás, selejtezés) és a készletnapló (Inventory Movements) automatikus rögzítésének lefejlesztése[cite: 34, 46].

## Adatintegritás és MVP lezárása
[cite_start]Az adatbázis tranzakciók véglegesítése a negatív készletek elkerülése érdekében, amivel a rendszer eléri a Minimum Viable Product (MVP) szintet[cite: 65, 422].

## Raktárközi átmozgatások indítása
[cite_start]A több lépcsős átmozgatási folyamat első felének elkészítése, ahol a forrásraktárból elinduló készlet "Úton lévő" (Pending) státuszba kerül[cite: 48, 733].

## Raktárközi átmozgatások jóváhagyása
[cite_start]A folyamat lezárása a célraktárban: a beérkező áruk átvétele, jóváhagyása (Approve) és a készletek végleges frissítése[cite: 735, 736].

## Vezérlőpult és riasztások
[cite_start]A belépés utáni irányítópult (Dashboard) kialakítása, amely összegzi a készletértéket, és riasztásokat jelenít meg a minimum szint alá eső termékekről[cite: 37, 296, 304].

## Felhasználói felület finomítása
[cite_start]A webes felületek továbbfejlesztése: dinamikus űrlapok, keresők és gyors vizuális visszajelzések implementálása az egyszerűbb használat érdekében[cite: 50, 51].

## Átfogó tesztelés
[cite_start]Funkcionális tesztek elvégzése a matematikai pontosság (pl. készletszámítás), a jogosultságok ellenőrzése, és a lehetséges biztonsági hibák kiszűrése céljából[cite: 971, 980].

## Dokumentáció és hibajavítás
A rendszer kódjának kommentálása, a végső hibajavítások elvégzése és a szakdolgozat fejlesztési fejezeteihez szükséges dokumentációk összekészítése.
