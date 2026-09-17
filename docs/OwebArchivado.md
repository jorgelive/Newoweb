# Oweb archivado (17/09/2026)

`src/Oweb/` era el **panel de administración heredado, hecho con Sonata Admin**: 269 archivos, 119
entidades y 95 pantallas de administración, servido en `oweb.openperu.pe`. Lo sustituyeron el
panel de EasyAdmin (`src/Panel/`), el PMS (`src/Pms/`) con Beds24, y las apps `util/` y `pax/`.

Este documento existe porque **lo que se quitó ya no se ve leyendo el código**, y dos cosas que
dependían de él no estaban en el repositorio.

## Índice

1. Cómo consultar el código archivado
2. Qué se quitó
3. Qué se quedó, a propósito
4. 🔥 Lo que dependía de Oweb desde FUERA del repositorio (y el subdominio)
5. Cómo se verificó
6. Desplegarlo
7. Dónde tocar para cambiar X

## 1. Cómo consultar el código archivado

Está entero en la etiqueta **`oweb-final`**:

```bash
git show oweb-final:src/Oweb/Controller/ReservaUnitController.php
```

```bash
git checkout oweb-final -- src/Oweb
```

(lo segundo lo trae de vuelta al árbol de trabajo; no lo commitees sin querer).

## 2. Qué se quitó

| Qué | Detalle |
|---|---|
| El módulo | `src/Oweb/` entero, `templates/oweb/`, `templates/bundles/SonataAdminBundle/`, `templates/form/type/` (tipos de formulario sólo de sus pantallas) |
| Código de fuera que sólo le servía | `src/Api/Controller/Oweb/` (5 controladores), `ObtenerReservasCommand` y `EnviarResumenCommand` (sus crons estaban desactivados desde el 09/08/2026), `EntityPathInitializerDoctrineEventListener` (sólo llamaba a un método de un trait de Oweb, y corría en **cada** `postLoad` de **cada** entidad), la plantilla `emails/command_enviar_resumen.html.twig` |
| Configuración | 10 `services/services_oweb*.yaml` y sus importaciones, `sonata_*.yaml`, `stof_doctrine_extensions.yaml`, `routes/sonata_admin.yaml`, la ruta `oweb_controllers`, la regla de `security.yaml` por host, el parámetro `app.host.oweb` y su binding `$owebHost`, el mapeo `Oweb` y los cuatro `gedmo_*` de `doctrine.yaml`, el tema de formulario de Sonata en `twig.yaml`, el global `facturacion_igv_porcentaje` (nadie lo leía) |
| Paquetes de Composer | `sonata-project/*`, `knplabs/knp-menu(-bundle)`, `knplabs/knp-paginator-bundle`, `knplabs/doctrine-behaviors`, `stof/doctrine-extensions-bundle`, `gedmo/doctrine-extensions`, `twig/string-extra`, `nette/utils` — 16 en total, todos traídos por el panel. Se comprobó que ninguna plantilla viva usa los filtros de `string-extra` |
| En `User` | Cinco relaciones: dependencia, área, cuentas, movimientos de cuenta y conductor. **Nada fuera de Oweb las usaba** (buscado en `src/`, `util/` y `pax/`) |
| En la base | Sólo `user.dependencia_id` y `user.area_id`, con sus índices y claves foráneas (`Version20260917120000`) |
| PHPStan | La exclusión de `src/Oweb` y 4 entradas de la baseline de archivos borrados |

⚠️ **`router.request_context.host` pasó de `oweb.openperu.pe` a `panel.openperu.pe`.** Venía de
Sonata. En el primer despliegue se dejó creyendo que decidía los enlaces de correos y comandos, y
**era falso**: en producción todas las rutas vivas llevan su `host` fijo (panel, api, pax, util),
y ese parámetro sólo lo usa una ruta **sin** host generada **sin petición**. Las únicas sin host
son las miniaturas de Liip y el login, y no se generan desde consola ni desde workers. Comprobado
con `debug:router` y buscando `ABSOLUTE_URL`/`getBrowserPath` en `src/`.

## 3. Qué se quedó, a propósito

**Todas las tablas de Oweb, con sus datos.** No se borra: se marca. Doctrine ya no las mapea, y sin
`--complete` `schema:validate` no las toca. ⚠️ **Con `--complete` propondría `DROP TABLE` de todas**:
no lo ejecutes a ciegas. Borrarlas es una decisión aparte.

Lo único que se perdió es la dependencia y el área de 5 usuarios, que sin Oweb no significan nada.
Hay volcado completo de producción del mismo día, antes del cambio.

⚠️ **Las claves foráneas de `user` las tuvo que escribir la migración a mano.** El
`schema:update` de Doctrine sólo proponía `DROP INDEX` y `DROP COLUMN`, porque ya no conoce las
tablas de destino (`use_area`, `use_dependencia`), y MySQL no deja borrar un índice que sostiene una
clave foránea viva: ese SQL habría fallado a mitad.

## 4. 🔥 Lo que dependía de Oweb desde FUERA del repositorio (y el subdominio)

Medido en el `access.log` de producción (04–17/09/2026): Oweb seguía recibiendo **~160 peticiones al
día**, y casi todas eran **feeds iCal**:

| Feed | Quién lo leía | Feeds |
|---|---|---|
| `/app/reservaunitnexo/{28,29}/ical` | **Vrbo / HomeAway**, ~83/día | 2 |
| `/app/reservaunit/{1..4}/ical` | **Booking.com**, ~50/día | 4 |
| `/app/reservareserva/ical` | un calendario de **Outlook / Exchange** | 1 |
| `/app/cotizacioncotservicio/ical` | el mismo Outlook | 1 |

Al archivar, **esas URLs dieron 404**, y desde la sección 4.1 **410**. Lo que había que saber antes:

- **Los feeds de Booking y Vrbo ya estaban congelados.** Leían `res_reserva`, la tabla del sistema
  viejo, que no recibe escrituras desde el 08/08/2026 (el cron `app:obtener-reservas` que la
  alimentaba se desactivó el 09/08, sustituido por el motor del PMS). El PMS nuevo tenía 46 reservas
  futuras; la tabla vieja, 15. **Ninguna reserva posterior al 08/08 salía en esos calendarios.**
- **Por Vrbo no entra nada**, y pasarlo a Beds24 está pendiente (según quien opera).
- **Booking entra por Beds24**, así que su importación iCal era redundante. Hay que **quitarla en la
  extranet de Booking**, o seguirá avisando de un calendario que falla.
- **El Outlook** hay que localizarlo y quitar la suscripción.

⚠️ **El código nuevo no tiene ningún feed iCal**: el generador (`IcalGenerator`) sólo existía dentro
de Oweb. Si un canal volviera a necesitar un iCal, hay que escribirlo sobre el PMS
(`PmsEventoCalendario`), no rescatar el de Oweb, que leía la tabla congelada.

### 4.1 El subdominio `oweb.openperu.pe`

Desde el 17/09/2026 **responde 410 Gone a todo**, desde un `server` propio de nginx. Antes estaba en
el mismo `server_name` que panel, api, pax y util, así que después de archivar seguía sirviendo la
app entera, login incluido. Sólo le llegaban los iCal muertos y bots probando `/admin/login` y
`/.env`. Se eligió 410 en vez de 404 porque le dice a Booking y a Vrbo que el calendario **ya no
existe**, no que falla un rato.

⚠️ **El bloque del puerto 80 conserva `/.well-known/acme-challenge/`.** El certificado
`openperu.pe` de Let's Encrypt incluye `oweb.openperu.pe`, y la renovación (vence el 19/11/2026)
lo valida por HTTP. Si ese camino también diera 410, **fallaría la renovación del certificado de
todos los dominios**, no sólo de oweb.

**Lo que queda, en la migración a Ubuntu 26.04:** no poner oweb en nginx ni en el certificado nuevo,
y después borrar el registro DNS. Quedan URLs con ese dominio en la base, pero sólo en tablas sin
mapear (`pt_tours`, `cot_cotpolitica`: imágenes de `/carga/`) y en mensajes antiguos. Ninguna la lee
el código.

## 5. Cómo se verificó

- `cache:clear`, `lint:container`, `debug:router` (0 rutas de Oweb), PHPUnit, PHPStan nivel 7.
- `doctrine:schema:update --dump-sql` **antes** de escribir la migración, para ver exactamente qué
  cambiaba en tablas vivas (sólo las dos columnas de `user`).
- La migración ejecutada en local en los dos sentidos —subir, bajar, subir— con `schema:validate`
  en verde.
- Los 5 usuarios cargan con sus roles; el login de `util` y `panel` responde.

## 6. Desplegarlo

⚠️ **No basta con el `pull`**: el hook `post-merge` no ejecuta `composer install`, y este cambio
quita 16 paquetes. Sin él, el contenedor se compila contra `vendor/` viejo y la configuración ya no
declara los bundles que ese `vendor/` espera.

```bash
git pull --ff-only && composer install --no-dev --optimize-autoloader && php bin/console doctrine:migrations:migrate --no-interaction && php bin/console cache:clear
```

Antes: **volcado de la base** y quitar la importación iCal en la extranet de Booking (sección 4).

## 7. Dónde tocar para cambiar X

| Necesidad | Dónde |
|---|---|
| Consultar cómo hacía algo el panel viejo | `git show oweb-final:src/Oweb/...` |
| Recuperar datos de una tabla de Oweb | siguen en la base, sin mapear: SQL directo |
| Volver a poner las columnas de `user` | `Version20260917120000::down()` — recupera la estructura; los datos, del volcado |
| El subdominio `oweb.openperu.pe` (410) | nginx del servidor, `/etc/nginx/sites-enabled/openperu`: su propio `server` (sección 4.1) |
| Un feed iCal para un canal | escribirlo sobre `PmsEventoCalendario` en `src/Pms/` (ver sección 4) |
