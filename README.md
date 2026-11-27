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


## 🌐 Élesítés Produkciós Szerverkörnyezetben (Deployment)

1. Másold át a `.env.production.example` fájlt `.env` néven az éles szerverre, és töltsd ki a produkciós adatbázis-adatokat, majd generálj alkalmazáskulcsot (`php artisan key:generate`).
2. Állítsd a webszerver (Nginx / Apache) `DocumentRoot` könyvtárát a projekt `/public` mappájára.
3. Adj írási jogosultságot a `storage/` és a `bootstrap/cache/` könyvtárakra a webszerver felhasználójának (`www-data`).
4. Futtasd az automatizált telepítő szkriptet:
   ```bash
   chmod +x deploy.sh
   ./deploy.sh
   ```
5. Új adatbázis esetén hozd létre az első adminisztrátort a szerveren. A parancs a jelszót interaktívan, rejtett mezőben kéri.
   ```bash
   php artisan inventory:create-admin "Rendszergazda" admin@ceg.example
   ```

A Felhasználók oldalon az adminisztrátor adhat ki, módosíthat és inaktiválhat fiókokat. Raktáranként több helyi szerepkör is kiosztható. Meglévő adatbázison a frissítést mentés után, karbantartási ablakban, adatmegőrző migrációval végezd. A fiókkiadás módja, az átállási lépések és a jogosultsági mátrix a [felhasználói hozzáférés dokumentációjában](docs/felhasznaloi-hozzaferes.md) található.

---

## 🧪 Automatizált Tesztek Futtatása

A rendszer üzleti folyamatainak, jogosultságkezelésének, tranzakciós adatintegritásának és PDF-generálásának ellenőrzése:

```bash
php artisan test
```

---

## 🔑 Alapértelmezett Tesztfiókok (Seeder futtatása után)

A bemutatóadatokat csak elkülönített fejlesztői vagy tesztadatbázisba töltsd be. A `DatabaseSeeder` kizárólag `local` és `testing` környezetben fut, az alábbi jelszavak a demonstrációhoz készültek. A Playwright-futtató saját eldobható adatbázist és külön tesztfiókokat készít.

| Szerepkör | E-mail cím | Jelszó | Jogosultsági kör |
| :--- | :--- | :--- | :--- |
| **Adminisztrátor (`admin`)** | `admin@raktar.hu` | `admin123` | Teljes hozzáférés (felhasználók, raktárak, kategóriák, készletek) |
| **Raktárvezető (`manager`)** | `manager@raktar.hu` | `manager123` | Raktárak, kategóriák és készletmozgások kezelése. Az átmozgatási műveletek raktári tagságtól függnek. |
| **Raktáros (`worker`)** | `worker@raktar.hu` | `worker123` | Termékek és készletmozgások kezelése. Az átmozgatási műveletek és bizonylatok raktári tagságtól függnek. |

### Átmozgatási igények bemutatója

A tesztfiókok létrehozása és a migrációk futtatása után a bemutatóhoz külön hozzáadható két raktári tagság.

```bash
php artisan db:seed --class=TransferIntentMembershipDemoSeeder
```

A `worker@raktar.hu` a pécsi telephely igénylője. A `manager@raktar.hu` a budapesti központ felügyelője. A meglévő tagságokat a seeder megtartja.

A bal oldali **Átmozgatások** menüben a raktáros hozzon létre egy Budapest → Pécs igényt. A budapesti felügyelő vagy az adminisztrátor elbírálhatja azt az adatlapon.
