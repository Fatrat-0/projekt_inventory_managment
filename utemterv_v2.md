# Projekt Ütemterv: Inventory Management Application (Frissített)
**Időtartam:** 13 hét
**Fókusz:** Fejlesztés, belső tesztelés és a szakdolgozat szöveges részének előkészítése.

## Mérföldkövek és Időbeosztás

| Hét | Fejlesztési Fázis | Fő Feladatok és Funkciók |
| :--- | :--- | :--- |
| **1.** | **Környezet & Adatbázis** | Fejlesztői környezet (Docker/Sail) felállítása. Adatbázis sémák (Migrations) megtervezése. |
| **2.** | **Modellek & Kapcsolatok** | Eloquent modellek és relációk (1:N, N:M) definiálása. Tesztadatok generálása. |
| **3.** | **Auth & Jogosultságok** | Laravel Breeze (Auth) és szerepkör alapú hozzáférés (RBAC) beállítása. |
| **4.** | **Törzsadatok I. (CRUD)** | Kategóriák és Termékek kezelése (Létrehozás, Szerkesztés, Listázás). |
| **5.** | **Törzsadatok II. (CRUD)** | Raktárak és Partnerek (Beszállítók/Vevők) kezelőfelületének elkészítése. |
| **6.** | **Készletmozgások alapjai** | Bevételezés, Kiadás és Selejtezés tranzakcióinak lefejlesztése. |
| **7.** | **Adatintegritás & MVP** | DB tranzakciók és készletszámítási logika véglegesítése. **(MVP kész)** |
| **8.** | **Transfers I.** | Raktárközi átmozgatások indítása és "Úton lévő" készlet kezelése. |
| **9.** | **Transfers II.** | Átmozgatások céloldali jóváhagyása és a folyamat lezárása. |
| **10.** | **Dashboard & Alerts** | Vezérlőpult statisztikák és alacsony készletszint riasztások vizuális megjelenítése. |
| **11.** | **UI/UX finomítás** | Alpine.js dinamikus elemek, AJAX keresés és reszponzív design tökéletesítése. |
| **12.** | **Átfogó tesztelés** | Funkcionális és biztonsági tesztek (pl. negatív készlet elleni védelem ellenőrzése). |
| **13.** | **Dokumentáció & Hibajavítás** | A forráskód kommentezése, a readme.md véglegesítése és felkészülés a bemutatásra. |

---

## Technológiai Stack (Helyi futtatáshoz)
* **PHP 8.2+ & Laravel 10.x**
* **MariaDB 10.x** (Adatbázis)
* **Tailwind CSS & Blade** (Kezelőfelület)
* **Docker / Laravel Sail** (Fejlesztői környezet)
