#!/usr/bin/env bash
# Instala o reinstala el entorno local de Newoweb con Homebrew: nginx + php-fpm 8.4 + MySQL 8.0.
# Idempotente: se puede correr las veces que haga falta. Ver tools/entorno-local/README.md.
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"
CERTS="$HOME/.config/newoweb/certs"
FPM="/opt/homebrew/var/run/php84-fpm.sock"
DESTINO="/opt/homebrew/etc/nginx/servers/newoweb.conf"

[ -f "$CERTS/openperu.test.crt" ] || {
  mkdir -p "$CERTS"
  mkcert -cert-file "$CERTS/openperu.test.crt" -key-file "$CERTS/openperu.test.key" \
    newapi.openperu.test newoweb.openperu.test panel.openperu.test pax.openperu.test util.openperu.test localhost 127.0.0.1
}

# Vite lee su certificado de util/certs y pax/certs con estos nombres exactos.
for app in util pax; do
  mkdir -p "$RAIZ/$app/certs"
  cp "$CERTS/openperu.test.crt" "$RAIZ/$app/certs/$app.openperu.test.crt"
  cp "$CERTS/openperu.test.key" "$RAIZ/$app/certs/$app.openperu.test.key"
done

mkdir -p "$(dirname "$DESTINO")" /opt/homebrew/var/log/nginx /opt/homebrew/var/run
sed -e "s|{{RAIZ}}|$RAIZ|g" -e "s|{{CERTS}}|$CERTS|g" -e "s|{{FPM}}|$FPM|g" \
  "$RAIZ/tools/entorno-local/nginx-newoweb.conf" > "$DESTINO"

nginx -t
brew services restart php@8.4
brew services restart nginx
echo "✅ nginx sirve https://{newapi,panel,util,pax}.openperu.test:8890"
