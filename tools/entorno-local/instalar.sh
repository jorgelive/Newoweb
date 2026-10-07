#!/usr/bin/env bash
# Instala o reinstala el entorno local de Newoweb con Homebrew: nginx + php-fpm 8.4 + MySQL 8.4.
# Idempotente: se puede correr las veces que haga falta. Ver tools/entorno-local/README.md.
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/../.." && pwd)"
CERTS="$HOME/.config/newoweb/certs"
FPM="/opt/homebrew/var/run/php84-fpm.sock"
DESTINO="/opt/homebrew/etc/nginx/servers/newoweb.conf"

# Un host por app. Los dos fronts públicos: front.openperu.test (tours, FRONT_HOST) y
# centrocuscointi.test (alojamiento, FRONT_ALOJAMIENTO_HOST). Ver docs/WebPublica.md §9.
# ⚠️ Cambiar esta lista exige también /etc/hosts (README) y el server_name de nginx-newoweb.conf.
DOMINIOS="newapi.openperu.test panel.openperu.test pax.openperu.test util.openperu.test front.openperu.test centrocuscointi.test"

# Se regenera si falta algún dominio, no sólo si no hay certificado: antes bastaba con que el
# archivo existiera, y un dominio nuevo se quedaba sin HTTPS sin que nada lo dijera.
falta_alguno() {
  [ -f "$CERTS/openperu.test.crt" ] || return 0
  local san; san="$(openssl x509 -in "$CERTS/openperu.test.crt" -noout -ext subjectAltName 2>/dev/null)"
  for d in $DOMINIOS; do grep -q "DNS:$d\b" <<<"$san" || return 0; done
  return 1
}
if falta_alguno; then
  mkdir -p "$CERTS"
  # shellcheck disable=SC2086
  mkcert -cert-file "$CERTS/openperu.test.crt" -key-file "$CERTS/openperu.test.key" $DOMINIOS localhost 127.0.0.1
fi

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
echo "✅ nginx sirve https://{newapi,panel,util,pax,front}.openperu.test:8890 y https://centrocuscointi.test:8890"
