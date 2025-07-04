#!/usr/bin/env bash

# Write SSL files
build_file() {
    echo "${1}" | base64 -d | gunzip > "${2}"
    chmod 400 "${2}"
}

export CERT_DIR="/usr/share/ssl"

if [[ -n "${POSTGRES_SSL_CA_CERT}" ]] && [[ "${POSTGRES_SSL_CA_CERT}" != "None" ]]; then
    echo "Writing postgres ssl ca cert"
    build_file "${POSTGRES_SSL_CA_CERT}" "${CERT_DIR}/postgres-ca-cert.crt"
fi
if [[ -n "${POSTGRES_SSL_CLIENT_KEY}" ]] && [[ "${POSTGRES_SSL_CLIENT_KEY}" != "None" ]]; then
    echo "Writing postgres ssl client key"
    build_file "${POSTGRES_SSL_CLIENT_KEY}" "${CERT_DIR}/postgres-client.key"
fi
if [[ -n "${POSTGRES_SSL_CLIENT_CERT}" ]] && [[ "${POSTGRES_SSL_CLIENT_CERT}" != "None" ]]; then
    echo "Writing postgres ssl client cert"
    build_file "${POSTGRES_SSL_CLIENT_CERT}" "${CERT_DIR}/postgres-client.crt"
fi

