# Entorno local: nginx + php-fpm 8.4 + MySQL 8.0 con Homebrew

Sustituye a **MAMP PRO**, que dejó de arrancar al actualizarlo el 17/09/2026. MAMP servía el
proyecto con **Apache**; producción usa **nginx**, así que el cambio además acerca el local a lo
que hay en el servidor.

## Qué hay y por qué esas versiones

| | Local | Producción |
|---|---|---|
| Web | nginx (Homebrew) | nginx 1.24 |
| PHP | `php@8.4` (8.4.25) + php-fpm por socket | 8.4.24, php-fpm por socket |
| MySQL | `mysql@8.0` (**8.0.46**) | **8.0.46** — la misma |
| Certificados | mkcert (CA local de confianza) | Let's Encrypt |

⚠️ **`mysql@8.0` está marcada obsoleta en Homebrew** (MySQL 8.0 ya no tiene soporte) y se
desactivará el **30/04/2027**. Se eligió igualmente porque es **exactamente** la versión de
producción. Cuando producción pase a 8.4, aquí se pasa a `mysql@8.4`.

**Mismos dominios y mismo puerto que con MAMP**, así que no se tocó ningún `.env` ni la config de
Vite: `https://{newapi,newoweb,panel,util,pax}.openperu.test:8890` (y `:8888` redirige a HTTPS).

## Instalar desde cero

```bash
brew install nginx php@8.4 mysql@8.0 mkcert nss imagemagick pkg-config
```

**imagick** no viene con `php@8.4` y el proyecto lo exige (girar escaneos, convertir a webp):

```bash
mkdir -p /opt/homebrew/lib/php/pecl/20240924
PKG_CONFIG_PATH=/opt/homebrew/opt/imagemagick/lib/pkgconfig /opt/homebrew/opt/php@8.4/bin/pecl install imagick
```

⚠️ Sin el `mkdir`, `pecl` falla con *failed to mkdir …/pecl/20240924*: el enlace de la carpeta de
extensiones de Homebrew apunta a un directorio que no existe.

Los límites de PHP van en `/opt/homebrew/etc/php/8.4/conf.d/99-newoweb.ini`, **copiados de
producción**: `memory_limit 512M`, `upload_max_filesize`/`post_max_size 64M`,
`max_execution_time 90`, `date.timezone America/Lima`. Con los de MAMP (`post_max_size 8M`) una
subida de más de 8 MB fallaba en local y funcionaba en producción.

php-fpm escucha en `/opt/homebrew/var/run/php84-fpm.sock` (en `php-fpm.d/www.conf`) y corre como
tu usuario: el puerto 9000 por defecto choca con otras herramientas.

Después, el vhost de nginx, los certificados y los servicios, con un solo script idempotente:

```bash
tools/entorno-local/instalar.sh
```

Y **dos pasos que piden tu contraseña**, una sola vez por máquina:

```bash
mkcert -install
```

```bash
echo "127.0.0.1 newapi.openperu.test newoweb.openperu.test panel.openperu.test pax.openperu.test util.openperu.test" | sudo tee -a /etc/hosts
```

## La base de datos

```bash
brew services start mysql@8.0
```

Se crea la base y el usuario **con las credenciales de `DATABASE_URL` de `.env.local`**, y se
importa un volcado. `.env.local` apunta al socket de Homebrew: `unix_socket=/tmp/mysql.sock`.

⚠️ **El volcado lleva `--hex-blob`, y no es opcional**: los UUID van en `binary(16)` y sin eso se
corrompen al pasar por texto.

### Cómo se sacó la base de MAMP cuando MAMP ya no arrancaba

El `mysqld` de MAMP seguía funcionando aunque la app no. Se arrancó a mano sobre su carpeta de
datos, con un socket propio:

```bash
/Applications/MAMP/Library/bin/mysql80/bin/mysqld --no-defaults \
  --datadir="/Library/Application Support/appsolute/MAMP PRO/db/mysql80" \
  --socket=/tmp/mamp80/mysql.sock --port=3399 --bind-address=127.0.0.1 --mysqlx=OFF
```

⚠️ **La ruta del socket no puede pasar de 103 caracteres**: con una más larga `mysqld` aborta al
arrancar, y lo dice sólo en su log de errores.

Resultado del 17/09/2026: **245 de 245 tablas**, 475 MB de datos reales —la carpeta ocupaba 2,7 GB
por logs y espacio reservado—, 36 MB comprimido. Copia en `~/Backups/openperu_oweb-mamp-20260917.sql.gz`.

## Comprobar que funciona

```bash
brew services list
```

```bash
php bin/console doctrine:schema:validate
```

`schema:validate` en verde contra la base importada es la prueba de que la importación está
completa: compara el mapeo con las tablas que hay de verdad.

⚠️ **PHPStan necesita `-d memory_limit=-1`**: con el límite por defecto sus procesos paralelos se
quedan sin memoria y lo reporta como «4 errors» que no son del código. El alias `php` de
`~/.zshrc` ya lo lleva.

## Dónde tocar para cambiar X

| Necesidad | Archivo |
|---|---|
| Añadir un dominio local | `nginx-newoweb.conf` (`server_name` ×2), el `mkcert` de `instalar.sh`, y `/etc/hosts` |
| Cambiar una regla de nginx | `nginx-newoweb.conf` y luego `instalar.sh` — **nunca** la copia instalada en `/opt/homebrew/etc/nginx/servers/` |
| Límites de PHP | `/opt/homebrew/etc/php/8.4/conf.d/99-newoweb.ini` (fuera del repo) |
| Logs de nginx | `/opt/homebrew/var/log/nginx/newoweb_error.log` |
| Reiniciar todo | `brew services restart php@8.4 nginx mysql@8.0` |
