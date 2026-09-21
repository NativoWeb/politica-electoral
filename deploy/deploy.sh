#!/bin/bash
# ==============================================================
# DEPLOY SCRIPT — Sube y configura la app Laravel
# Ejecutar DESPUES de setup-server.sh
# ==============================================================
set -e

APP_DIR="/var/www/electoral"

echo "========================================="
echo "  DEPLOY — Inteligencia Electoral"
echo "========================================="

cd $APP_DIR

# --- Composer ---
echo "[1/6] Instalando dependencias PHP..."
composer install --no-dev --optimize-autoloader --no-interaction

# --- NPM + Build ---
echo "[2/6] Instalando dependencias JS y compilando..."
npm ci
npm run build

# --- Environment ---
echo "[3/6] Configurando .env..."
if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate
fi

# Update .env for production
sed -i 's|APP_ENV=.*|APP_ENV=production|' .env
sed -i 's|APP_DEBUG=.*|APP_DEBUG=false|' .env
sed -i 's|APP_URL=.*|APP_URL=http://144.202.42.252|' .env
sed -i 's|DB_CONNECTION=.*|DB_CONNECTION=pgsql|' .env
sed -i 's|DB_HOST=.*|DB_HOST=127.0.0.1|' .env
sed -i 's|DB_PORT=.*|DB_PORT=5432|' .env
sed -i 's|DB_DATABASE=.*|DB_DATABASE=politica_electoral|' .env
sed -i 's|DB_USERNAME=.*|DB_USERNAME=electoral|' .env
sed -i 's|DB_PASSWORD=.*|DB_PASSWORD=Elec2024!Sntdr|' .env
sed -i 's|REDIS_HOST=.*|REDIS_HOST=127.0.0.1|' .env
sed -i 's|REDIS_PORT=.*|REDIS_PORT=6379|' .env
sed -i 's|CACHE_STORE=.*|CACHE_STORE=redis|' .env
sed -i 's|SESSION_DRIVER=.*|SESSION_DRIVER=redis|' .env

# --- Migrations ---
echo "[4/6] Ejecutando migraciones..."
php artisan migrate --force

# --- Optimize ---
echo "[5/6] Optimizando para producción..."
php artisan optimize
php artisan view:cache
php artisan event:cache

# --- Permissions ---
echo "[6/6] Ajustando permisos..."
chown -R www-data:www-data $APP_DIR
chmod -R 775 storage bootstrap/cache

echo ""
echo "========================================="
echo "  DEPLOY COMPLETADO"
echo "========================================="
echo ""
echo "  App: http://144.202.42.252"
echo ""
echo "  Para dominio + SSL:"
echo "  1. Apunta tu dominio a 144.202.42.252"
echo "  2. Edita /etc/nginx/sites-available/electoral"
echo "     cambia server_name _ por tu dominio"
echo "  3. certbot --nginx -d tudominio.com"
echo "========================================="
