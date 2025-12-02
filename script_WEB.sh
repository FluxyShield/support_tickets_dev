#!/bin/bash

# ==============================================================================
# script_WEB.sh
# Script d'installation et de configuration du Serveur WEB (Nginx)
# OS Cible : Debian 12
# ==============================================================================

# Couleurs pour les logs
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() { echo -e "${GREEN}[INFO] $1${NC}"; }
log_error() { echo -e "${RED}[ERROR] $1${NC}"; }
log_warn() { echo -e "${YELLOW}[WARN] $1${NC}"; }

# Vérification des droits root
if [ "$EUID" -ne 0 ]; then 
  log_error "Ce script doit être exécuté en tant que root (sudo)."
  exit 1
fi

# Variables de configuration
REPO_URL="https://github.com/FluxyShield/support_tickets_dev.git"
DB_HOST="192.168.11.41"
DB_USER="support_user"
DB_PASS="H7x!9qL2$#mPzW8@vR4k"
APP_DIR="/var/www/html/support_tickets"

# Dossiers SSL
DB_SSL_DIR="/etc/ssl/db-client" # Certificats client pour la BDD
WEB_SSL_DIR="/etc/ssl/web-server" # Certificats HTTPS pour Nginx

# Domaines
DOMAIN_USER="dev.ticket.descamps-bois.fr"
DOMAIN_ADMIN="dev.admin.descamps-bois.fr"

# 1. Mise à jour du système
log_info "Mise à jour du système..."
apt-get update && apt-get upgrade -y

# 2. Installation des paquets nécessaires (Nginx + PHP-FPM)
log_info "Installation de Nginx, PHP-FPM et extensions..."
# Debian 12 utilise PHP 8.2 par défaut
# Note: mysql-client est remplacé par mariadb-client sur Debian 12
apt-get install -y nginx php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-zip git unzip curl mariadb-client openssl

# Vérification de l'installation
if [ $? -ne 0 ]; then
    log_error "Erreur lors de l'installation des paquets."
    exit 1
fi

# 3. Vérification des certificats SSL BDD (Transférés par script_BDD.sh)
log_info "Vérification des certificats SSL client BDD..."

if [ ! -d "$DB_SSL_DIR" ]; then
    mkdir -p $DB_SSL_DIR
fi

# Boucle d'attente si les certificats BDD ne sont pas encore là
while [ ! -f "$DB_SSL_DIR/ca-cert.pem" ] || [ ! -f "$DB_SSL_DIR/client-cert.pem" ] || [ ! -f "$DB_SSL_DIR/client-key.pem" ]; do
    log_warn "Certificats BDD manquants dans $DB_SSL_DIR."
    echo "Veuillez exécuter script_BDD.sh sur le serveur de base de données ($DB_HOST)."
    echo "Si le transfert automatique a échoué, copiez manuellement les fichiers :"
    echo "  - ca-cert.pem"
    echo "  - client-cert.pem"
    echo "  - client-key.pem"
    echo "Vers : $DB_SSL_DIR"
    echo ""
    read -p "Appuyez sur ENTRÉE une fois que les fichiers sont présents..."
done

log_info "✅ Certificats BDD trouvés !"

# Permissions strictes (lecture pour www-data)
chown -R www-data:www-data $DB_SSL_DIR
chmod 600 $DB_SSL_DIR/*.pem
chmod 700 $DB_SSL_DIR

# 4. Génération des certificats SSL pour le WEB (HTTPS)
log_info "Génération des certificats SSL pour le serveur WEB (HTTPS)..."
mkdir -p $WEB_SSL_DIR

# Configuration OpenSSL pour SAN (Subject Alternative Name)
cat > openssl-san.cnf <<EOF
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
C = FR
ST = Nord
L = Lille
O = Descamps
CN = *.descamps-bois.fr

[v3_req]
keyUsage = keyEncipherment, dataEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
DNS.1 = $DOMAIN_USER
DNS.2 = $DOMAIN_ADMIN
DNS.3 = localhost
EOF

# Génération
openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
  -keyout $WEB_SSL_DIR/web-key.pem \
  -out $WEB_SSL_DIR/web-cert.pem \
  -config openssl-san.cnf

rm openssl-san.cnf

# Permissions
chmod 600 $WEB_SSL_DIR/web-key.pem
chmod 644 $WEB_SSL_DIR/web-cert.pem

# 5. Déploiement de l'application
log_info "Déploiement de l'application..."
if [ -d "$APP_DIR" ]; then
    log_info "Le dossier existe déjà. Mise à jour..."
    cd $APP_DIR
    git pull
else
    log_info "Clonage du dépôt..."
    mkdir -p $(dirname $APP_DIR)
    git clone $REPO_URL $APP_DIR
fi

# Installation des dépendances Composer
if [ -f "$APP_DIR/composer.json" ]; then
    log_info "Installation des dépendances Composer..."
    if ! command -v composer &> /dev/null; then
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    fi
    cd $APP_DIR
    composer install --no-dev --optimize-autoloader
fi

# 6. Configuration de l'environnement (.env)
log_info "Configuration du fichier .env..."
cat <<EOF > $APP_DIR/.env
DB_HOST=$DB_HOST
DB_USER=$DB_USER
DB_PASS=$DB_PASS
DB_NAME=support_tickets

# Configuration SSL BDD
DB_SSL_KEY=$DB_SSL_DIR/client-key.pem
DB_SSL_CERT=$DB_SSL_DIR/client-cert.pem
DB_SSL_CA=$DB_SSL_DIR/ca-cert.pem

# Clé de chiffrement
ENCRYPTION_KEY=$(openssl rand -hex 32)

# Email
MAIL_HOST=smtp.example.com
MAIL_USERNAME=user@example.com
MAIL_PASSWORD=secret
MAIL_FROM_EMAIL=noreply@example.com
APP_NAME="Support Descamps"
APP_URL_BASE="https://$DOMAIN_USER"
EOF

# 7. Permissions Nginx
log_info "Configuration des permissions..."
chown -R www-data:www-data $APP_DIR
chmod -R 755 $APP_DIR
chmod -R 770 $APP_DIR/uploads 2>/dev/null || true

# 8. Configuration Nginx
log_info "Configuration Nginx..."

# Détection de la version PHP pour le socket FPM
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"

log_info "Version PHP détectée : $PHP_VERSION (Socket: $PHP_FPM_SOCK)"

# Configuration Nginx
cat <<EOF > /etc/nginx/sites-available/support_tickets
# Redirection HTTP -> HTTPS (Global)
server {
    listen 80;
    server_name $DOMAIN_USER $DOMAIN_ADMIN;
    return 301 https://\$host\$request_uri;
}

# Serveur USER (ticket.descamps-bois.fr)
server {
    listen 443 ssl;
    server_name $DOMAIN_USER;
    root $APP_DIR;
    index index.php;

    ssl_certificate $WEB_SSL_DIR/web-cert.pem;
    ssl_certificate_key $WEB_SSL_DIR/web-key.pem;

    # Logs
    access_log /var/log/nginx/user_access.log;
    error_log /var/log/nginx/user_error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }

    location ~ /\.ht {
        deny all;
    }
}

# Serveur ADMIN (admin.descamps-bois.fr)
server {
    listen 443 ssl;
    server_name $DOMAIN_ADMIN;
    root $APP_DIR;
    
    # IMPORTANT : Force admin.php comme index pour ce domaine
    index admin.php;

    ssl_certificate $WEB_SSL_DIR/web-cert.pem;
    ssl_certificate_key $WEB_SSL_DIR/web-key.pem;

    # Logs
    access_log /var/log/nginx/admin_access.log;
    error_log /var/log/nginx/admin_error.log;

    location / {
        try_files \$uri \$uri/ /admin.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

# Activation du site
ln -sf /etc/nginx/sites-available/support_tickets /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Test de configuration et redémarrage
nginx -t
if [ $? -eq 0 ]; then
    systemctl restart nginx
    systemctl restart php${PHP_VERSION}-fpm
    log_info "Installation WEB (Nginx) terminée !"
else
    log_error "Erreur dans la configuration Nginx."
    exit 1
fi

echo "-----------------------------------------------------"
echo "Application USER  : https://$DOMAIN_USER"
echo "Application ADMIN : https://$DOMAIN_ADMIN"
echo "-----------------------------------------------------"
echo "NOTE : Pensez à configurer votre DNS ou fichier hosts :"
echo "$(hostname -I | awk '{print $1}') $DOMAIN_USER"
echo "$(hostname -I | awk '{print $1}') $DOMAIN_ADMIN"
echo "-----------------------------------------------------"
