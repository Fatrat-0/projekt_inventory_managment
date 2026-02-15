# Projekt Ütemterv: Inventory Management Application
**Időtartam:** 13 hét
**Cél:** A teljes raktárkezelő rendszer (MVP + haladó funkciók) fejlesztése és tesztelése.

## Mérföldkövek és Időbeosztás

| Hét | Fejlesztési Fázis | Fő Feladatok és Funkciók | Státusz |
| :--- | :--- | :--- | :--- |
| **1.** | **Környezet & Adatbázis alapok** | Fejlesztői környezet (Docker/Laravel Sail) felállítása. Adatbázis sémák (Migrations) megtervezése a specifikáció alapján (Users, Products, Warehouses, Stocks stb.). | Tervezve |
| **2.** | **Modellek & Kapcsolatok** | Eloquent modellek létrehozása. Adatbázis kapcsolatok (1:N, N:M) beállítása. Tesztadatok (Seeders/Factories) generálása. | Tervezve |
| **3.** | **Hitelesítés & Jogosultságok** | Laravel Breeze telepítése a beléptetéshez. Szerepkör alapú jogosultságkezelés (RBAC) implementálása (Admin, Manager, Warehouseman) Middleware-ekkel. | Tervezve |
| **4.** | **Törzsadatok I. (CRUD)** | Kategóriák és Termékek (Products) kezelésének fejlesztése. Alapvető Blade nézetek és Tailwind CSS formázások elkészítése. | Tervezve |
| **5.** | **Törzsadatok II. (CRUD)** | Raktárak (Warehouses) és Partnerek (Suppliers, Customers) kezelésének fejlesztése. Űrlapok validációja (FormRequests). | Tervezve |
| **6.** | **Készletmozgások (Tranzakciók)** | A legfontosabb üzleti logika: Bevételezés, Kiadás, Selejtezés funkciók. Készletnapló (Inventory_Movements) rögzítésének lefejlesztése. | Tervezve |
| **7.** | **Adatintegritás & MVP Zárás** | Adatbázis tranzakciók (DB::transaction) és zárolások (Pessimistic Locking) implementálása a "Race Conditions" elkerülésére. **-> MVP KÉSZ** | Tervezve |
| **8.** | **Raktárközi Átmozgatások I.** | Az átmozgatás (Transfers) logikájának első fele: Forrás raktárból történő indítás és az "Úton" (Pending) státusz kezelése. | Tervezve |
| **9.** | **Raktárközi Átmozgatások II.** | Az átmozgatás logikájának második fele: Célraktárban történő jóváhagyás (Approve) és a készletek véglegesítése (Completed). | Tervezve |
| **10.** | **Vezérlőpult (Dashboard) & Riasztások** | Kezdőképernyő metrikáinak (Összkészlet értéke, folyamatban lévő tranzakciók) és az alacsony készletszintű riasztások (Low Stock Alerts) fejlesztése. | Tervezve |
| **11.** | **UI/UX és Interaktivitás** | A felhasználói élmény finomhangolása: AJAX keresők, Alpine.js dinamikus űrlapok (pl. több tétel hozzáadása), "Toast" értesítések beépítése. | Tervezve |
| **12.** | **Tesztelés & Hibajavítás** | Funkcionális tesztek futtatása (Készlet-matematika, Jogosultságok). Kód optimalizálása (N+1 query problémák javítása Eager Loadinggal). | Tervezve |
| **13.** | **Élesítés (Deployment) & Dokumentáció** | A rendszer élesítése az Ubuntu 22.04 LTS VPS szerveren (Nginx, MariaDB, PHP-FPM). Szakdolgozati dokumentáció és használati útmutatók véglegesítése. | Tervezve |

---

## Fő technológiák és Eszközök
* **Backend:** PHP 8.2, Laravel 10.x
* **Adatbázis:** MariaDB 10.x
* **Frontend:** Blade, Tailwind CSS, Alpine.js
* **Környezet / Élesítés:** Docker (Sail), Ubuntu VPS, Nginx
