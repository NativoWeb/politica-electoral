#!/bin/bash
# ==============================================================
# SETUP SCRIPT — Inteligencia Electoral Santander
# Server: Ubuntu 24.04 LTS on Vultr (Miami)
# Stack: Nginx + PHP 8.3 + PostgreSQL 16/PostGIS + Redis + Node.js
# ==============================================================
set -e

echo "========================================="
echo "  INSTALACION DEL SERVIDOR"
echo "  Inteligencia Electoral Santander"
echo "========================================="

# --- 1. Update system ---
echo "[1/8] Actualizando sistema..."
apt update && apt upgrade -y
apt install -y curl wget git unzip software-properties-common ufw certbot python3-certbot-nginx

# --- 2. PHP 8.3 ---
echo "[2/8] Instalando PHP 8.3..."
add-apt-repository -y ppa:ondrej/php
apt update
apt install -y php8.3-fpm php8.3-cli php8.3-common php8.3-pgsql php8.3-mbstring \
    php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath \
    php8.3-readline php8.3-redis

# --- 3. Composer ---
echo "[3/8] Instalando Composer..."
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# --- 4. PostgreSQL 16 + PostGIS ---
echo "[4/8] Instalando PostgreSQL 16 + PostGIS..."
apt install -y postgresql-16 postgresql-16-postgis-3

sudo -u postgres psql -c "CREATE USER electoral WITH PASSWORD 'Elec2024!Sntdr';"
sudo -u postgres psql -c "CREATE DATABASE politica_electoral OWNER electoral;"
sudo -u postgres psql -d politica_electoral -c "CREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";"
sudo -u postgres psql -d politica_electoral -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
sudo -u postgres psql -d politica_electoral -c "CREATE EXTENSION IF NOT EXISTS postgis;"
sudo -u postgres psql -d politica_electoral -c "CREATE EXTENSION IF NOT EXISTS unaccent;"
sudo -u postgres psql -d politica_electoral -c "GRANT ALL PRIVILEGES ON DATABASE politica_electoral TO electoral;"
sudo -u postgres psql -d politica_electoral -c "GRANT ALL ON SCHEMA public TO electoral;"
sudo -u postgres psql -d politica_electoral -c "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO electoral;"

echo "[+] PostgreSQL configurado: DB=politica_electoral, USER=electoral"

# --- 5. Redis ---
echo "[5/8] Instalando Redis..."
apt install -y redis-server
systemctl enable redis-server
systemctl start redis-server

# --- 6. Node.js 22 ---
echo "[6/8] Instalando Node.js 22..."
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs

# --- 7. Nginx ---
echo "[7/8] Configurando Nginx..."
apt install -y nginx

cat > /etc/nginx/sites-available/electoral <<'NGINX'
server {
    listen 80;
    server_name _;
    root /var/www/electoral/public;
    index index.php;

    client_max_body_size 50M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location /build/ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
    }
}
NGINX

ln -sf /etc/nginx/sites-available/electoral /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# --- 8. Firewall ---
echo "[8/8] Configurando firewall..."
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

# --- Create app directory ---
mkdir -p /var/www/electoral
chown -R www-data:www-data /var/www/electoral

echo ""
echo "========================================="
echo "  INSTALACION COMPLETADA"
echo "========================================="
echo ""
echo "  PostgreSQL: localhost:5432"
echo "  DB: politica_electoral"
echo "  User: electoral"
echo "  Pass: Elec2024!Sntdr"
echo ""
echo "  Redis: localhost:6379"
echo "  Nginx: configurado en puerto 80"
echo ""
echo "  Siguiente paso: ejecutar deploy.sh"
echo "========================================="
