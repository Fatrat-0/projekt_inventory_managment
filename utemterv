# Projekt Ütemterv: Inventory Management Application
**Időtartam:** 13 hét
**Cél:** A teljes raktárkezelő rendszer (MVP + haladó funkciók) fejlesztése és tesztelése.

## Mérföldkövek és Időbeosztás

| Hét | Fejlesztési Fázis | Fő Feladatok és Funkciók | Státusz |
| :--- | :--- | :--- | :--- |
| **1.** | **Környezet & Adatbázis alapok** | [cite_start]Fejlesztői környezet (Docker/Laravel Sail) felállítása[cite: 544, 545]. [cite_start]Adatbázis sémák (Migrations) megtervezése a specifikáció alapján (Users, Products, Warehouses, Stocks stb.)[cite: 242, 248, 250, 256]. | Tervezve |
| **2.** | **Modellek & Kapcsolatok** | [cite_start]Eloquent modellek létrehozása[cite: 216, 217]. [cite_start]Adatbázis kapcsolatok (1:N, N:M) beállítása[cite: 273, 277]. Tesztadatok (Seeders/Factories) generálása. | Tervezve |
| **3.** | **Hitelesítés & Jogosultságok** | [cite_start]Laravel Breeze telepítése a beléptetéshez[cite: 374]. [cite_start]Szerepkör alapú jogosultságkezelés (RBAC) implementálása (Admin, Manager, Warehouseman) Middleware-ekkel[cite: 379, 380, 381, 382, 383]. | Tervezve |
| **4.** | **Törzsadatok I. (CRUD)** | [cite_start]Kategóriák és Termékek (Products) kezelésének fejlesztése[cite: 630, 698]. [cite_start]Alapvető Blade nézetek és Tailwind CSS formázások elkészítése[cite: 516, 517]. | Tervezve |
| **5.** | **Törzsadatok II. (CRUD)** | [cite_start]Raktárak (Warehouses) és Partnerek (Suppliers, Customers) kezelésének fejlesztése[cite: 630]. [cite_start]Űrlapok validációja (FormRequests)[cite: 128]. | Tervezve |
| **6.** | **Készletmozgások (Tranzakciók)** | [cite_start]A legfontosabb üzleti logika: Bevételezés, Kiadás, Selejtezés funkciók[cite: 654, 712]. [cite_start]Készletnapló (Inventory_Movements) rögzítésének lefejlesztése[cite: 716]. | Tervezve |
| **7.** | **Adatintegritás & MVP Zárás** | [cite_start]Adatbázis tranzakciók (DB::transaction) és zárolások (Pessimistic Locking) implementálása a "Race Conditions" elkerülésére[cite: 422, 425]. **-> MVP KÉSZ** | Tervezve |
| **8.** | **Raktárközi Átmozgatások I.** | [cite_start]Az átmozgatás (Transfers) logikájának első fele [cite: 727][cite_start]: Forrás raktárból történő indítás és az "Úton" (Pending) státusz kezelése[cite: 733]. | Tervezve |
| **9.** | **Raktárközi Átmozgatások II.** | [cite_start]Az átmozgatás logikájának második fele: Célraktárban történő jóváhagyás (Approve) és a készletek véglegesítése (Completed)[cite: 735, 736]. | Tervezve |
| **10.** | **Vezérlőpult (Dashboard) & Riasztások** | [cite_start]Kezdőképernyő metrikáinak (Összkészlet értéke, folyamatban lévő tranzakciók) és az alacsony készletszintű riasztások (Low Stock Alerts) fejlesztése[cite: 298, 299, 300]. | Tervezve |
| **11.** | **UI/UX és Interaktivitás** | [cite_start]A felhasználói élmény finomhangolása: AJAX keresők, Alpine.js dinamikus űrlapok (pl. több tétel hozzáadása), "Toast" értesítések beépítése[cite: 316, 331, 526, 858, 863]. | Tervezve |
| **12.** | **Tesztelés & Hibajavítás** | [cite_start]Funkcionális tesztek futtatása (Készlet-matematika, Jogosultságok)[cite: 973, 975]. [cite_start]Kód optimalizálása (N+1 query problémák javítása Eager Loadinggal)[cite: 369]. | Tervezve |
| **13.** | **Élesítés (Deployment) & Dokumentáció** | [cite_start]A rendszer élesítése az Ubuntu 22.04 LTS VPS szerveren (Nginx, MariaDB, PHP-FPM)[cite: 588, 595, 607, 608]. Szakdolgozati dokumentáció és használati útmutatók véglegesítése. | Tervezve |

---

## Fő technológiák és Eszközök
* [cite_start]**Backend:** PHP 8.2, Laravel 10.x [cite: 473, 664]
* [cite_start]**Adatbázis:** MariaDB 10.x [cite: 665]
* [cite_start]**Frontend:** Blade, Tailwind CSS, Alpine.js [cite: 50, 668]
* [cite_start]**Környezet / Élesítés:** Docker (Sail), Ubuntu VPS, Nginx [cite: 544, 599, 605, 606]
