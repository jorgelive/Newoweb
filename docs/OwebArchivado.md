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
4. 🔥 Lo que dependía de Oweb desde FUERA del repositorio
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

⚠️ **`router.request_context.host: oweb.openperu.pe` se QUEDA.** Viene de Sonata, pero decide el
dominio de los enlaces generados desde consola (correos, comandos) para las rutas sin `host`.
Cambiarlo alteraría enlaces que hoy funcionan, y `oweb.openperu.pe` sigue sirviendo la app.

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

## 4. 🔥 Lo que dependía de Oweb desde FUERA del repositorio

Medido en el `access.log` de producción (04–17/09/2026): Oweb seguía recibiendo **~160 peticiones al
día**, y casi todas eran **feeds iCal**:

| Feed | Quién lo leía | Feeds |
|---|---|---|
| `/app/reservaunitnexo/{28,29}/ical` | **Vrbo / HomeAway**, ~83/día | 2 |
| `/app/reservaunit/{1..4}/ical` | **Booking.com**, ~50/día | 4 |
| `/app/reservareserva/ical` | un calendario de **Outlook / Exchange** | 1 |
| `/app/cotizacioncotservicio/ical` | el mismo Outlook | 1 |

Al archivar, **esas URLs dan 404**. Lo que había que saber antes:

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
| Un feed iCal para un canal | escribirlo sobre `PmsEventoCalendario` en `src/Pms/` (ver sección 4) |
