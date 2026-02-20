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
A fejlesztői környezet beállítása, valamint az adatbázis táblák (felhasználók, termékek, raktárak, készletek) megtervezése migrációk segítségével.

## Eloquent modellek és kapcsolatok
Az adatbázist kezelő Eloquent ORM modellek létrehozása, a kapcsolatok definiálása, és tesztadatok betöltése a gyorsabb fejlesztés érdekében.

## Hitelesítés és jogosultságkezelés
A felhasználói beléptetés és a szerepkör-alapú hozzáférés-szabályozás (RBAC) implementálása a különböző felhasználói szintekhez (Admin, Raktárvezető, Raktáros).

## Törzsadatok I. (Kategóriák és Termékek)
A termékek és kategóriák rögzítéséhez, szerkesztéséhez és listázásához szükséges adminisztrációs űrlapok és logikák lefejlesztése.

## Törzsadatok II. (Raktárak és Partnerek)
A több raktár, illetve a vevők és beszállítók kezelését végző modulok elkészítése a megfelelő adatbázis kapcsolatokkal.

## Készletmozgások alapjai
A készletkezelés legfontosabb tranzakcióinak (bevételezés, kiadás, selejtezés) és a készletnapló (Inventory Movements) automatikus rögzítésének lefejlesztése.

## Adatintegritás és MVP lezárása
Az adatbázis tranzakciók véglegesítése a negatív készletek elkerülése érdekében, amivel a rendszer eléri a Minimum Viable Product (MVP) szintet.

## Raktárközi átmozgatások indítása
A több lépcsős átmozgatási folyamat első felének elkészítése, ahol a forrásraktárból elinduló készlet "Úton lévő" (Pending) státuszba kerül.

## Raktárközi átmozgatások jóváhagyása
A folyamat lezárása a célraktárban: a beérkező áruk átvétele, jóváhagyása (Approve) és a készletek végleges frissítése.

## Vezérlőpult és riasztások
A belépés utáni irányítópult (Dashboard) kialakítása, amely összegzi a készletértéket, és riasztásokat jelenít meg a minimum szint alá eső termékekről.

## Felhasználói felület finomítása
A webes felületek továbbfejlesztése: dinamikus űrlapok, keresők és gyors vizuális visszajelzések implementálása az egyszerűbb használat érdekében.

## Átfogó tesztelés
Funkcionális tesztek elvégzése a matematikai pontosság (pl. készletszámítás), a jogosultságok ellenőrzése, és a lehetséges biztonsági hibák kiszűrése céljából.

## Dokumentáció és hibajavítás
A rendszer kódjának kommentálása, a végső hibajavítások elvégzése és a szakdolgozat fejlesztési fejezeteihez szükséges dokumentációk összekészítése.
