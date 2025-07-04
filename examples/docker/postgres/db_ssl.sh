#!/bin/bash

# Create the HBA configuration file
mkdir /etc/postgresql
export PG_HBA_FILE="/etc/postgresql/pg_hba.conf"
export PG_HBA_CONTENT="# Allow SSL connections from any IP address
      hostssl   all             all             0.0.0.0/0               md5
      hostssl   all             all             ::/0                    md5

      # Allow local connections
      local     all             all                                     trust"

echo "$PG_HBA_CONTENT" > $PG_HBA_FILE
################################################################################

# Create the SSL certificates files from .env file located in docker folder
build_file() {
  echo "${1}" | base64 -d | gunzip > "${2}"
  chmod 400 "${2}"
}

certdir=${CERT_DIR:-"/etc/ssl"}
mkdir -p "${certdir}"/certs "${certdir}"/private
pdir="${certdir}/private"
cdir="${certdir}/certs"

if [[ -n "${PGSSLROOTCERT}" ]] && [[ "${PGSSLROOTCERT}" != "None" ]]; then
  echo "Writing postgres ssl ca cert"
  build_file "${PGSSLROOTCERT}" "${cdir}/root.crt"
fi
if [[ -n "${PGSSLKEY}" ]] && [[ "${PGSSLKEY}" != "None" ]]; then
  echo "Writing postgres ssl client key"
  build_file "${PGSSLKEY}" "${pdir}/server.key"
fi
if [[ -n "${PGSSLCERT}" ]] && [[ "${PGSSLCERT}" != "None" ]]; then
  echo "Writing postgres ssl client cert"
  build_file "${PGSSLCERT}" "${cdir}/server.crt"
fi
################################################################################

# Update the SSL configuration over the database container
# Database credentials
USER=${POSTGRES_USER}
PASSWORD=${POSTGRES_PASSWORD}
DB_NAME=${POSTGRES_DB}

# SQL commands
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET ssl_cert_file TO '/etc/ssl/certs/server.crt';"
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET listen_addresses TO '*';"
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET ssl_key_file TO '/etc/ssl/private/server.key';"
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET ssl_ca_file TO '/etc/ssl/certs/root.crt';"
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET ssl TO 'ON';"
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET hba_file TO '/etc/postgresql/pg_hba.conf';"
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET ssl_crl_file TO '';"
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET ssl_ciphers TO 'HIGH:MEDIUM:+3DES:!aNULL';"

# Uncomment the following lines to enable logging for connections and hostnames
#PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET log_connections TO 'on';"
#PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET log_hostname TO 'on';"

# Set ownership and permissions for the SSL certificates and config files
chown postgres:postgres /etc/ssl/private/server.key
chmod 600 /etc/ssl/private/server.key
chown postgres:postgres /etc/ssl/certs/server.crt
chmod 600 /etc/ssl/certs/server.crt
chown postgres:postgres /etc/ssl/certs/root.crt
chmod 600 /etc/ssl/certs/root.crt
chown -R postgres:postgres /etc/postgresql
chown -R postgres:postgres /var/lib/postgresql

# Update config file and reload the PostgreSQL service
su - postgres -c config_file=/etc/postgresql/postgresql.conf
su - postgres -c "pg_ctl reload -D /var/lib/postgresql/data"
################################################################################

