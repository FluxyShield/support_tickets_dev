#!/bin/bash

# ==============================================================================
# script_BDD.sh
# Script d'installation et de configuration du Serveur de Base de Données
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
DB_NAME="support_tickets"
DB_USER="support_user"
DB_PASS="H7x!9qL2$#mPzW8@vR4k"
WEB_SERVER_IP="192.168.11.40"
MY_IP="192.168.11.41"

# Dossiers SSL
SSL_DIR="/etc/mysql/ssl"
WEB_DEST_DIR="/etc/ssl/db-client" # Destination sur le serveur WEB

# 1. Mise à jour du système
log_info "Mise à jour du système..."
apt-get update && apt-get upgrade -y

# 2. Installation de MariaDB et outils
log_info "Installation de MariaDB Server et OpenSSL..."
apt-get install -y mariadb-server ufw openssl sshpass

# 3. Génération des certificats SSL (Méthode Robuste)
log_info "Génération des certificats SSL..."
mkdir -p $SSL_DIR
cd $SSL_DIR

# Nettoyage préalable
rm -f *.pem *.srl *.cnf

# Création d'un fichier de config OpenSSL temporaire pour s'assurer des extensions
cat > openssl.cnf <<EOF
[req]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[req_distinguished_name]
C = FR
ST = Nord
L = Lille
O = Descamps
CN = DescampsDB

[v3_req]
keyUsage = keyEncipherment, dataEncipherment
extendedKeyUsage = serverAuth
subjectAltName = @alt_names

[alt_names]
IP.1 = $MY_IP
DNS.1 = localhost
EOF

# CA Certificate
log_info "Génération de la CA..."
openssl genrsa 4096 > ca-key.pem
openssl req -new -x509 -nodes -days 3650 -key ca-key.pem -out ca-cert.pem -subj "/C=FR/ST=Nord/L=Lille/O=Descamps/CN=DescampsCA"

# Server Certificate
log_info "Génération du certificat Serveur..."
openssl genrsa 4096 > server-key.pem
openssl req -new -key server-key.pem -out server-req.pem -config openssl.cnf
openssl x509 -req -in server-req.pem -days 3650 -CA ca-cert.pem -CAkey ca-key.pem -CAcreateserial -out server-cert.pem -extensions v3_req -extfile openssl.cnf

# Client Certificate
log_info "Génération du certificat Client..."
openssl genrsa 4096 > client-key.pem
openssl req -new -key client-key.pem -out client-req.pem -subj "/C=FR/ST=Nord/L=Lille/O=Descamps/CN=DescampsWebClient"
openssl x509 -req -in client-req.pem -days 3650 -CA ca-cert.pem -CAkey ca-key.pem -CAcreateserial -out client-cert.pem

# Permissions strictes pour MariaDB
chown -R mysql:mysql $SSL_DIR
chmod 600 $SSL_DIR/*.pem
chmod 700 $SSL_DIR

# 4. Configuration de MariaDB
log_info "Configuration de MariaDB..."
CONFIG_FILE="/etc/mysql/mariadb.conf.d/50-server.cnf"

# Backup
cp $CONFIG_FILE "${CONFIG_FILE}.bak"

# Configuration bind-address et SSL
cat > $CONFIG_FILE <<EOF
[mysqld]
user = mysql
pid-file = /run/mysqld/mysqld.pid
socket = /run/mysqld/mysqld.sock
basedir = /usr
datadir = /var/lib/mysql
tmpdir = /tmp
lc-messages-dir = /usr/share/mysql
bind-address = 0.0.0.0
query_cache_size = 16M
log_error = /var/log/mysql/error.log

# Configuration SSL
ssl-ca=$SSL_DIR/ca-cert.pem
ssl-cert=$SSL_DIR/server-cert.pem
ssl-key=$SSL_DIR/server-key.pem
require_secure_transport=ON
EOF

systemctl restart mariadb

# 5. Création de la Base de Données et de l'Utilisateur
log_info "Création de la base de données et de l'utilisateur..."

mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME;
-- Création de l'utilisateur spécifique à l'IP du serveur Web
CREATE USER IF NOT EXISTS '$DB_USER'@'$WEB_SERVER_IP' IDENTIFIED BY '$DB_PASS';
-- On force l'utilisation de SSL
ALTER USER '$DB_USER'@'$WEB_SERVER_IP' REQUIRE SSL;
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'$WEB_SERVER_IP';
FLUSH PRIVILEGES;
EOF

# 6. Configuration du Firewall (UFW)
log_info "Configuration du Firewall..."
ufw allow from $WEB_SERVER_IP to any port 3306 proto tcp
ufw --force enable

# 7. Transfert automatique des certificats (Inspiré de votre script)
log_info "Tentative de transfert automatique des certificats vers le serveur WEB ($WEB_SERVER_IP)..."
log_warn "Assurez-vous que l'échange de clés SSH est configuré ou préparez-vous à entrer le mot de passe root du serveur WEB."

# Création du dossier distant
ssh -o StrictHostKeyChecking=no root@$WEB_SERVER_IP "mkdir -p $WEB_DEST_DIR"

# Transfert
scp -o StrictHostKeyChecking=no \
    $SSL_DIR/ca-cert.pem \
    $SSL_DIR/client-cert.pem \
    $SSL_DIR/client-key.pem \
    root@$WEB_SERVER_IP:$WEB_DEST_DIR/

if [ $? -eq 0 ]; then
    log_info "✅ Transfert réussi !"
else
    log_error "❌ Échec du transfert automatique."
    echo "Vous devez copier manuellement ces fichiers vers $WEB_SERVER_IP:$WEB_DEST_DIR/ :"
    echo "  - $SSL_DIR/ca-cert.pem"
    echo "  - $SSL_DIR/client-cert.pem"
    echo "  - $SSL_DIR/client-key.pem"
fi

log_info "Installation BDD terminée !"
