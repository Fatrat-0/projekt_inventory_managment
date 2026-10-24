# 📦 Webalapú, Többfelhasználós Készletnyilvántartó Rendszer

A rendszer több raktár készleteinek egységes kezelését, hierarchikus kategóriarendszerét, raktárközi készletátmozgatások tranzakcióvédett végrehajtását, valamint szerepköralapú jogosultságkezelést (RBAC) és paraméterezhető PDF-bizonylatolást valósít meg.

---

## 🛠️ Rendszerkövetelmények (Szerverkörnyezet)

* **PHP:** >= 8.2 (Szükséges kiterjesztések: `BCMath`, `Ctype`, `Fileinfo`, `JSON`, `Mbstring`, `OpenSSL`, `PDO_MySQL`, `Tokenizer`, `XML`, `GD` vagy `Imagick` a PDF generáláshoz)
* **Adatbázis:** MySQL 8.0+ vagy MariaDB 10.6+ (InnoDB tárolómotor a tranzakciókezeléshez)
* **Webszerver:** Apache 2.4 (`mod_rewrite` engedélyezve) vagy Nginx
* **Csomagkezelők:** Composer 2.x, Node.js (v18+) & NPM

---

## 🚀 Telepítés Fejlesztői Környezetben (Localhost / Laragon)

1. **Forráskód letöltése:**
   ```bash
   git clone https://github.com/Fatrat-0/projekt_inventory_managment.git inventory-app
   cd inventory-app
   ```

2. **Függőségek telepítése:**
   ```bash
   composer install
   npm install
   ```

3. **Környezeti változók beállítása:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   *Állítsd be az adatbázis-kapcsolatot a `.env` fájlban (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).*

4. **Adatbázis migráció és kezdőadatok (Seeder) betöltése:**
   ```bash
   php artisan migrate --seed
   ```

5. **Frontend fordítása és indítása:**
   ```bash
   npm run build
   ```

---

## 🌐 Élesítés Produkciós Szerverkörnyezetben (Deployment)

1. Másold át a `.env.production.example` fájlt `.env` néven az éles szerverre, és töltsd ki a produkciós adatbázis-adatokat, majd generálj alkalmazáskulcsot (`php artisan key:generate`).
2. Állítsd a webszerver (Nginx / Apache) `DocumentRoot` könyvtárát a projekt `/public` mappájára.
3. Adj írási jogosultságot a `storage/` és a `bootstrap/cache/` könyvtárakra a webszerver felhasználójának (`www-data`).
4. Futtasd az automatizált telepítő szkriptet:
   ```bash
   chmod +x deploy.sh
   ./deploy.sh
   ```

---

## 🧪 Automatizált Tesztek Futtatása

A rendszer üzleti folyamatainak, jogosultságkezelésének, tranzakciós adatintegritásának és PDF-generálásának ellenőrzése:

```bash
php artisan test
```

---

## 🔑 Alapértelmezett Tesztfiókok (Seeder futtatása után)

| Szerepkör | E-mail cím | Jelszó | Jogosultsági kör |
| :--- | :--- | :--- | :--- |
| **Adminisztrátor (`admin`)** | `admin@raktar.hu` | `admin123` | Teljes hozzáférés (felhasználók, raktárak, kategóriák, készletek) |
| **Raktárvezető (`manager`)** | `manager@raktar.hu` | `manager123` | Raktárak, kategóriák, felhasználók és készletmozgások kezelése |
| **Raktáros (`worker`)** | `worker@raktar.hu` | `worker123` | Termékek, készletmozgások, átmozgatások és PDF-bizonylatok kezelése |