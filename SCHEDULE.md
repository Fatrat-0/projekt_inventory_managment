# Projekt Ütemterv: Inventory Management Application

- 2026-02-15: Projekt Setup és Laragon környezet (1. hét) - [FOLYAMATBAN]
- 2026-02-22: Adatbázis Tervezés és Migrációk (2. hét)
- 2026-03-01: Modellek, Kapcsolatok és Tesztadatok (3. hét)
- 2026-03-08: Biztonság és Autentikáció (RBAC) (4. hét)
- 2026-03-15: Törzsadatok I. (Kategóriák és Termékek CRUD) (5. hét)
- 2026-03-22: Törzsadatok II. (Raktárak és Partnerek) (6. hét)
- 2026-03-29: Készletmozgások és Proof of Concept (PoC) (7. hét)
- 2026-04-05: Raktárközi Átmozgatások Indítása (8. hét)
- 2026-04-12: Raktárközi Átmozgatások Jóváhagyása (9. hét)
- 2026-04-19: Vezérlőpult (Dashboard) és Riasztások (10. hét)
- 2026-04-26: Admin Panel és Adatintegritás (11. hét)
- 2026-05-03: UX/UI Finomhangolás és Hibakezelés (12. hét)
- 2026-05-10: Minimum Viable Product (MVP) és Dokumentáció (13. hét)

## Projekt Setup és Laragon környezet (1. hét)
A fejlesztői környezet (Laragon) beállítása, a lokális webszerver és a MariaDB adatbázis elindítása[cite: 540, 541]. [cite_start]A Laravel keretrendszer inicializálása és a fejlesztői eszközök (VS Code) konfigurálása.

## Adatbázis Tervezés és Migrációk (2. hét)
A relációs adatbázis sémájának leprogramozása Laravel migrációk segítségével. A táblák (felhasználók, termékek, raktárak, készletek, tranzakciók) és azok mezőinek, kényszereinek pontos megtervezése a specifikáció alapján.

## Modellek, Kapcsolatok és Tesztadatok (3. hét)
Az adatbázist kezelő Eloquent ORM modellek létrehozása. Az adatbázis kapcsolatok (1:N, N:M) definiálása az adatintegritás érdekében. Tesztadatok (Seeders/Factories) betöltése a gyorsabb fejlesztéshez.

## Biztonság és Autentikáció (RBAC) (4. hét)
A Laravel Breeze csomag beállítása a biztonságos felhasználói beléptetéshez. A Szerepkör alapú hozzáférés-szabályozás (Role-Based Access Control) implementálása Middleware-ekkel, elkülönítve az Admin, Raktárvezető és Raktáros szinteket.

## Törzsadatok I. (Kategóriák és Termékek CRUD) (5. hét)
A terméktörzs és a kategóriák rögzítéséhez, szerkesztéséhez és listázásához szükséges adminisztrációs űrlapok lefejlesztése. Alapvető validációs szabályok beállítása (pl. egyedi cikkszám).

## Törzsadatok II. (Raktárak és Partnerek) (6. hét)
A több raktár (Multi-warehouse) rendszer alapjainak lerakása. A vevők és beszállítók adatainak kezelését végző CRUD modulok elkészítése.

## Készletmozgások és Proof of Concept (PoC) (7. hét)
A rendszer legfontosabb üzleti logikájának, a tranzakció-alapú készletváltozásoknak (bevételezés, kiadás) a leprogramozása[cite: 633, 654]. [cite_start]A műveletek automatikus rögzítése a megmásíthatatlan készletnaplóba (Inventory_Movements). A rendszer bemutatása PoC fázisban.

## Raktárközi Átmozgatások Indítása (8. hét)
A raktárközi átmozgatás logikájának első fele: a folyamat indítása a forrásraktárból, a készlet zárolása és "Úton lévő" (Pending) státuszba helyezése a hiányok elkerülése végett.

## Raktárközi Átmozgatások Jóváhagyása (9. hét)
A kétlépcsős folyamat lezárása: az áru átvétele a célraktárban. A rendszer véglegesíti a tranzakciót (Completed státusz) és hozzáadja a mennyiséget a célraktár aktuális készletéhez.

## Vezérlőpult (Dashboard) és Riasztások (10. hét)
A belépés utáni Vezérlőpult kialakítása metrika kártyákkal (összkészlet értéke, folyamatban lévő tranzakciók)[cite: 298]. [cite_start]A minimum szint alá eső termékek monitorozása és a figyelmeztető lista (Low Stock Alerts) vizuális megjelenítése.

## Admin Panel és Adatintegritás (11. hét)
Az Adminisztrátorok számára a felhasználók és jogosultságok menedzselését végző felületek véglegesítése. Az adatbázis tranzakciók (DB::transaction) és zárolások (Pessimistic Locking) implementálása a konkurens adatmódosítások (Race Condition) elkerülésére.

## UX/UI Finomhangolás és Hibakezelés (12. hét)
A Tailwind CSS és Alpine.js alapú felületek ergonómiájának és reszponzivitásának tökéletesítése. Dinamikus elemek, AJAX keresők és gyors vizuális visszajelzések (Toast üzenetek, inline validációk) finomhangolása.

## Minimum Viable Product (MVP) és Dokumentáció (13. hét)
Az elkészült rendszer átfogó funkcionális és biztonsági tesztelése (különös tekintettel a negatív készletek elleni védelemre és a készlet-matematikára). A forráskód rendezése és a szakdolgozat fejlesztési fejezeteihez szükséges dokumentációk befejezése.
