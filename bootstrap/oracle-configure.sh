#!/bin/sh
set -eu

deployment_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$deployment_root"
domain=${1:-}
if [ "${#domain}" -gt 253 ] || ! printf '%s\n' "$domain" | grep -Eq '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,63}$'; then
    echo 'Usage: sh bootstrap/oracle-configure.sh public-api-hostname' >&2
    exit 1
fi
if [ -e .env.oracle ]; then
    echo '.env.oracle already exists; keep its saved key and database passwords.' >&2
    exit 1
fi
command -v openssl >/dev/null
application_key=$(openssl rand -base64 32)
application_password=$(openssl rand -hex 32)
root_password=$(openssl rand -hex 32)
umask 077
set -C
{
    printf 'API_DOMAIN=%s\n' "$domain"
    printf 'APP_KEY=base64:%s\n' "$application_key"
    printf 'MYSQL_PASSWORD=%s\n' "$application_password"
    printf 'MYSQL_ROOT_PASSWORD=%s\n' "$root_password"
} > .env.oracle
echo 'Created private .env.oracle. Back it up securely; no secret values were printed.'
