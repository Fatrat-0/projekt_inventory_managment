#!/bin/bash
set -e

echo "Raktárkezelő Rendszer - Élesítési folyamat (Deployment) indítása..."

# 1. Karbantartási mód bekapcsolása (hogy a felhasználók ne végezzenek tranzakciót frissítés közben)
php artisan down || true

# 2. Legfrissebb forráskód letöltése a főágról
echo "Forráskód frissítése a Git tárolóból..."
git pull origin main

# 3. PHP függőségek telepítése (fejlesztői csomagok nélkül, optimalizált autoloaderrel)
echo "Composer csomagok telepítése produkciós módban..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# 4. Frontend (Tailwind CSS & Alpine.js) statikus fájlok lefordítása
echo "Frontend erőforrások (Vite) fordítása..."
npm ci
npm run build

# 5. Adatbázis migrációk biztonságos futtatása
echo "Adatbázis séma frissítése..."
php artisan migrate --force

# 6. Konfigurációk, útvonalak és nézetek gyorsítótárazása (teljesítményoptimalizálás)
echo "Alkalmazás gyorsítótárak (Cache) felépítése..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Karbantartási mód feloldása
php artisan up

echo "Az élesítés sikeresen befejeződött!"