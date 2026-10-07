# Web pública (`src/Front/`) — openperu.pe

Las páginas públicas que sirve el Symfony fuera de las apps: la web de **tours en promoción**
de `openperu.pe` (portada, catálogos, ficha de tour), sus condiciones legales y el **Libro de
Reclamaciones**. Sustituye al PHPTravels heredado (PHP 7.4) que servía ese dominio.

**Alcance:** `src/Front/`, `templates/front/`, `translations/front*.yaml`, `public/front/`, y
los campos web de `CotizacionCatalogo`. El plan por fases, el diagnóstico de partida y las
preguntas abiertas están en `docs/PlanWebPublica.md`.

## Índice

1. [Piezas y flujo](#1-piezas-y-flujo)
2. [Qué sale en la web](#2-qué-sale-en-la-web)
3. [Plantillas, estilos e idiomas](#3-plantillas-estilos-e-idiomas)
4. [SEO y compartir](#4-seo-y-compartir)
5. [Libro de Reclamaciones](#5-libro-de-reclamaciones)
6. [Páginas legales](#6-páginas-legales)
7. [URLs del legacy](#7-urls-del-legacy)
8. [Dos fronts: openperu.pe y centrocuscointi.com](#8-dos-fronts-openperupe-y-centrocuscointicom)
9. [Desarrollo local y despliegue](#9-desarrollo-local-y-despliegue)
10. [Gotchas](#10-gotchas)
11. [Dónde tocar para cambiar X](#11-dónde-tocar-para-cambiar-x)

---

## 1. Piezas y flujo

```
openperu.pe ──nginx──▶ public/index.php ──host = %app.host.front%──┬─▶ src/Front/Tours/Controller/
                                                                    └─▶ src/Front/Comun/Controller/
 Tours/   TourController ── CatalogoWebLector ── TourTarjetaResolver::tarjetas()  (mismas reglas que pax)
          SeoController (robots.txt, sitemap.xml) · LegadoController (301 del PHPTravels)
 Comun/   LegalController ── SitioWeb ── templates/front/{sitio}/legal/{pagina}.{idioma}.html.twig
          LibroReclamacionesController ── LibroReclamacionesService ── Mailer (cola async)
```

Estructura (07/10/2026):

```
src/Front/
├── Comun/         lo de las dos webs: Libro (Entity, Enum, Form, Service, Crud), LegalController,
│                  SitioWeb (qué web es por el dominio), TextoI18n, Twig/FrontExtension
├── Tours/         openperu.pe: Controller/, Dto/, Service/CatalogoWebLector
└── Alojamiento/   centrocuscointi.com — no existe aún (§8)
templates/front/{comun,tours}/   ·   translations/front.* (común) + front_tours.*
```

**Twig en el servidor y no la SPA de `pax`**: un buscador o el previsualizador de WhatsApp
tienen que recibir la página ya escrita (título, foto, precio). `pax` sigue siendo el sitio del
itinerario día a día: la ficha de tour enlaza a `pax /catalogo/{localizador}/p/{n}`.

El host lo fija `config/routes.yaml` (`front_controllers` → `%app.host.front%`). En producción
`FRONT_HOST=openperu.pe` ya existía antes de esta web.

## 2. Qué sale en la web

Un catálogo (`CotizacionCatalogo`) aparece cuando cumple **las tres**:

| Campo | Qué decide |
|---|---|
| `activo` | El enlace privado por localizador funciona (ya existía). |
| `publicadoWeb` | Sale en openperu.pe. **No es lo mismo que `activo`**: un catálogo para una agencia se manda por enlace y no debe listarse. |
| `slug` | Su URL: `/tours/{slug}`. Publicar sin él lo rechaza el validador (`validarPublicacionWeb()`). |

Dentro, sólo los tours con `Cotizacion::$publicado = true`. **No hay previsualización de
operador** (en `pax` sí, ver `CotizacionCatalogoPublicProvider`): las páginas se cachean 5 min
públicamente y una caché no sabe quién miraba.

Textos del catálogo: `tituloWeb` (si falta, el `nombre` interno) y `descripcionWeb` (HTML
corto). Los dos llevan `#[AutoTranslate]`, así que **entran por la API/ORM, nunca por SQL**. Se
editan en el modal del catálogo en `util` (`CatalogoDashboard.vue`, «Publicar en la web»), que
manda sólo el español y conserva las traducciones si el texto no cambió.

La portada pinta **una sección por catálogo publicado**, en su `orden`. Las reglas de la tarjeta
(portada override → derivada, precio oculto, días) **no se reescriben aquí**: vienen hechas de
`TourTarjetaResolver::tarjetas()`, el mismo método que usa `pax`. `CatalogoWebLector` sólo elige
idioma (`TextoI18n::en()`: idioma → español → el primero con texto) y da forma a los DTOs de
`src/Front/Tours/Dto/`.

Un precio «desde» que no es número no se enseña (un «Desde S/ 0» vende algo que no existe).

## 3. Plantillas, estilos e idiomas

- `templates/front/comun/base.html.twig`: el armazón común — `<head>`, metadatos sociales,
  `hreflang`, selector de idioma, pie con legales y el acceso al Libro (INDECOPI lo quiere
  visible desde la portada), WhatsApp flotante. **No se extiende directamente**: cada web tiene
  `templates/front/{sitio}/layout.html.twig`, que rellena inicio, menú, lema y mensaje de
  WhatsApp. Las páginas comunes (libro, legales) hacen `{% extends layout %}` con el que les pasa
  el controlador según `SitioWeb`, así que en cada dominio salen con la cabecera de su web.
- La identidad (marca, razón social, RUC, dirección, correo, WhatsApp) son **parámetros
  `front.*`** en `config/services/services_parameters.yaml`, expuestos a Twig como `front`.
  Cambiar de marca o de RUC es tocar ahí (y los textos legales, §6).
- **CSS sin compilar**: `public/front/web.css`, servido por nginx tal cual. `front_asset()` le
  pone `?v=<mtime>` para que un despliegue no se quede detrás de la caché del navegador. No usa
  AssetMapper porque en producción no se compila (`public/assets/` está ignorado y el
  `post-merge` no corre `asset-map:compile`).
- Fotos: las mismas de `pax`, por el filtro Liip `travel_cliente`
  (`{{ url|trim('/', 'left')|imagine_filter('travel_cliente') }}`).
- **Idiomas**: `es` sin prefijo, `en` en `/en/...` (rutas localizadas de Symfony). Las cadenas
  de interfaz están en `translations/front.{es,en}.yaml` (común: navegación genérica, pie,
  legales, libro) y `front_tours.{es,en}.yaml` (portada, catálogo, ficha); los mensajes de validación del libro,
  en `validators.{es,en}.yaml`. Los contenidos ya vienen en 7 idiomas: abrir otro es añadir su
  archivo, su prefijo en las rutas y su código en `SeoController::IDIOMAS`.

## 4. SEO y compartir

- **URL del tour**: `/tours/{catalogo}/{propuesta}-{slug}`. El número identifica; el slug sale
  del título en el idioma de la página y es cosmético. Si no coincide (título cambiado, otro
  idioma, enlace viejo) → **301 a la canónica**. Retitular un tour no rompe enlaces ya
  compartidos por WhatsApp; **cambiar el `slug` del catálogo, sí**.
- `hreflang`: la ficha calcula la URL canónica de cada idioma (el slug cambia:
  `montana-de-colores` / `rainbow-mountain`); el resto de páginas la deriva de la ruta.
- Open Graph (`og:title/description/image`) en todas; `TouristTrip` + `Offer` (JSON-LD) en la
  ficha.
- `robots.txt` y `sitemap.xml` van por **controlador**, no como archivos en `public/`: `public/`
  lo comparten todos los hosts y un `robots.txt` estático le diría lo mismo a `api` y a `util`.
- Caché HTTP pública: 5 min en portada/catálogo/tour, 1 h en legales y sitemap. **En
  desarrollo no** (`kernel.debug`), o cada cambio de plantilla tardaría cinco minutos en verse.

## 5. Libro de Reclamaciones

Obligatorio para vender por internet (D.S. 011-2011-PCM). Entidad `App\Front\Comun\Entity\LibroReclamacion`
(tabla `front_libro_reclamacion`), formulario `LibroReclamacionType`, servicio
`LibroReclamacionesService`.

```
POST /libro-de-reclamaciones
   ├─ trampa `sitioWeb` con texto → finge éxito, no guarda nada
   ├─ inválido → 422 con los errores
   └─ válido → registrar():
        GET_LOCK  → correlativo «AAAA-NNNNN» (reinicia cada año) → flush → RELEASE_LOCK
        → 2 correos a la cola async: copia al consumidor + aviso a front.correo (con el plazo)
      → 302 a /libro-de-reclamaciones/hoja/{id}?_expiration=…&_hash=…   (enlace firmado, 24 h)
```

- **Primero se guarda, después se avisa.** Un correo que falla queda en el log
  (`[libro-reclamaciones]`); la hoja no se pierde.
- **Sin sesión, en ningún momento.** El CSRF es *stateless*
  (`framework.csrf_protection.stateless_token_ids: [front_libro_reclamacion]`, valida por
  `Origin`/`Referer`) y la confirmación va por URL firmada (`UriSigner`), no por flash. Motivo:
  la cookie de sesión lleva `cookie_domain = .openperu.pe` y en `centrocuscointi.com` el
  navegador la rechazaría — el formulario fallaría siempre por CSRF.
- El candado de MySQL existe porque, sin él, dos envíos simultáneos leen el mismo último número
  y el segundo revienta contra el `UNIQUE` con el EntityManager ya cerrado: no habría reintento.
- El correo viaja serializado por Messenger: el contexto es un **array de textos**
  (`datosParaCorreo()`), no la entidad. La plantilla `front/libro/correo.html.twig` se renderiza
  en el worker.
- **Plazo legal**: 15 días hábiles. `getVenceRespuesta()` cuenta lunes a viernes y **no
  descuenta feriados**: avisa antes, nunca después.
- **Panel**: menú «Libro de Reclamaciones» (`App\Front\Comun\Crud\LibroReclamacionCrudController`,
  fuera de `Comun/Controller/` porque esa carpeta se carga como rutas de los hosts públicos). **Sin alta
  y sin borrado**: es un registro legal. Se edita sólo la respuesta y «atendida»; la fecha de
  respuesta la pone la primera respuesta y no se puede retocar. La respuesta **no se envía
  sola**: hay que mandarla por correo al consumidor (responder al aviso le escribe a él).
- El libro del legacy (`phptravels_legacy.pt_libro_reclamaciones`) tenía **0 hojas**: no hubo
  nada que migrar.

## 6. Páginas legales

`/legal/{pagina}` con `LegalController::PAGINAS`. El mecanismo es común; **los textos son de cada
web** (`SitioWeb` elige la carpeta). Los de tours se trajeron **tal cual** del
PHPTravels (`pt_cms_content`, 06/10/2026) a `templates/front/tours/legal/{pagina}.{es|en}.html.twig`,
dentro de `{% verbatim %}`. Si falta un idioma se cae al otro antes que dar 404. La de cookies se
escribió de nuevo (la vieja era la plantilla genérica de PHPTravels): **si se añade analítica o
publicidad, esa página tiene que cambiar**. Las imágenes del programa ESNNA se copiaron a
`public/front/esnna/` porque vivían en el `uploads/` del PHPTravels.

⚠️ **Los términos y la política de devoluciones son del ALOJAMIENTO** y dicen que las
excursiones se contratan por cuenta del huésped. Para vender tours hay que reescribirlos — ver
`docs/PlanWebPublica.md` §4.

## 7. URLs del legacy

`LegadoController` responde **301** a lo que Google aún pide del PHPTravels
(`/Terms-and-Conditions`, `/libro-de-reclamaciones.php`, `/tours/peru/…/…`, `/hotels/…`, `/blog/…`,
`/login`…). El legacy contestaba `/notfound` con un **200**, que a un buscador no le dice nada.
Las rutas llevan prioridad negativa para no ganarle nunca a una de verdad.

## 8. Dos fronts: openperu.pe y centrocuscointi.com

Un solo Symfony, **un host por web**, una carpeta por web en `src/Front/` y en
`templates/front/`. Hoy existe `Tours/` (`FRONT_HOST`). `Alojamiento/` (`centrocuscointi.com`,
fase 5 del plan) tendrá sus propios controladores, portada, marca y motor de reservas, y
compartirá lo de `Comun/`:

- la plantilla base y los estilos (`comun/base.html.twig`, `public/front/web.css`),
- el Libro de Reclamaciones (por eso ya funciona sin sesión, §5; la hoja guarda `sitio`),
- el mecanismo de páginas legales — con **sus** textos: los de `tours/legal/` hoy son de
  alojamiento y se copiarán a `alojamiento/legal/` cuando nazca.

Lo que no se comparte: catálogo, sitemap, `robots.txt`, marca y menú.

**El motor de reservas no va en `src/Front/`.** Disponibilidad, tarifa pública, alta de la
reserva y su envío a Beds24 son del PMS (`src/Pms/`, `PmsDisponibilidadService`…).
`Front/Alojamiento/` sólo pinta y llama, igual que `Tours/` no calcula nada del catálogo.

### Para encender el segundo front

1. `src/Front/Alojamiento/Controller/` con al menos la portada (antes no: una carpeta de rutas
   que no existe tumba el arranque, y git no guarda carpetas vacías).
2. `FRONT_ALOJAMIENTO_HOST` con `%env(default:…)%` y su parámetro al lado → `app.host.front_alojamiento`.
3. `config/routes.yaml`: `Alojamiento/Controller/` con ese host; `config/services/services_front.yaml`:
   su carpeta de controladores.
4. `SitioWeb::de()`: el host nuevo → `alojamiento`; `templates/front/alojamiento/layout.html.twig`
   y `alojamiento/legal/`; `translations/front_alojamiento.*.yaml`.
5. ⚠️ **`Comun/Controller/` en los dos hosts.** Importarla dos veces duplica los nombres de ruta
   (`front_libro`…), y con `name_prefix` las plantillas comunes generarían la URL del otro
   dominio. La salida prevista: un solo import con `host: '{dominio}'` y requisito
   `openperu\.pe|centrocuscointi\.com`, rellenando `dominio` en el `RequestContext` desde la
   petición. Probarlo antes de dar nada por hecho.
6. Identidad propia (`front.*` es hoy la de tours: marca, WhatsApp) y entrada en el sitemap
   propio.

En local ya están el certificado, nginx y `/etc/hosts` de `centrocuscointi.test` (§9).

## 9. Desarrollo local y despliegue

### Cómo llega un dominio a su web

Los mismos cuatro pasos en local y en producción; sólo cambia quién contesta en cada uno:

```
                 local                                   producción
1. nombre → IP   /etc/hosts → 127.0.0.1                  DNS (Route 53) → 34.208.16.185
2. HTTPS         certificado de mkcert                   Let's Encrypt (certbot)
3. nginx         server_name de nginx-newoweb.conf       server_name de sites-enabled/openperu
                 → root public/ (php-fpm 8.4)            → root /var/www/openperu.pe/public
4. Symfony       compara la cabecera Host con los hosts de config/routes.yaml:
                   %app.host.front%            → src/Front/Tours/ + Comun/  (tours)
                   %app.host.front_alojamiento% → (fase 5: alojamiento)
                   %app.host.pax%, util, api, panel → sus apps
```

**Todos los dominios caen en el mismo `public/index.php`**: nginx no decide qué web es, sólo
que es Symfony. Quien separa es el router por `host`, con los valores de `FRONT_HOST`,
`PAX_HOST`… Por eso una web nueva no necesita otro proyecto ni otro `root`: un host más en el
DNS, en el certificado, en el `server_name` y en `routes.yaml`.

| | Local | Producción |
|---|---|---|
| Tours | `https://front.openperu.test:8890` (`FRONT_HOST=front.openperu.test`) | `https://openperu.pe` (`FRONT_HOST=openperu.pe`, ya existe) |
| Alojamiento (fase 5) | `https://centrocuscointi.test:8890` | `https://centrocuscointi.com` (sin registrar a 06/10/2026) |

En local, el certificado y el `server_name` de los dos ya están (`tools/entorno-local/instalar.sh`,
07/10/2026); `/etc/hosts` lo añade quien instala, con `sudo` (README del entorno). Hasta esa
fecha la web de tours se probó en `newoweb.openperu.test`, el dominio del Oweb archivado, que
desapareció del entorno en el mismo cambio.

Las fotos salen de `public/carga/` (ignorado): en local sólo están las que se hayan traído de
producción.

### Producción (pendiente, pide confirmación)

- En `/etc/nginx/sites-enabled/openperu`, el bloque `openperu.pe www.openperu.pe` pasa de
  `root /var/www/phptravels` + PHP 7.4 al mismo `root /var/www/openperu.pe/public` + PHP 8.4
  que el resto, con `www` → 301 al dominio desnudo. El bloque de Symfony redeclara cabeceras
  (CSP `frame-ancestors`…): copiar las que apliquen. Después, retirar PHP 7.4 y
  `/var/www/phptravels` (dejando volcado de `phptravels_legacy`).
- La migración `Version20261007010000` va en el despliegue (columnas web del catálogo + tabla
  del libro).
- `centrocuscointi.com`: registrar, registro A al servidor, `certbot --expand` con el dominio,
  `server_name` en nginx y `FRONT_ALOJAMIENTO_HOST` (con `%env(default:…)%`, ver
  `docs/PlanWebPublica.md` §2.8).

## 10. Gotchas

- ⚠️ **`_route` llega SIN el sufijo de idioma.** En una ruta localizada el matcher devuelve
  `front_catalogo`, no `front_catalogo.es`, y `_canonical_route` no llega a la petición. La
  plantilla base deriva los alternos de `_route` + `_route_params._locale`; con
  `_canonical_route` (lo primero que se prueba) el selector de idioma sale vacío sin error.
- ⚠️ **La caché pública también la sufre quien desarrolla.** Con `max-age=300` el navegador
  servía la portada vieja y el cambio «no funcionaba». Por eso `cacheable()` no la pone con
  `kernel.debug`.
- **Los esquemas sin grupos del catálogo.** Al añadir `AutoTranslateControlTrait`, los flags
  `ejecutarTraduccion`/`sobreescribirTraduccion` aparecieron en `api.d.ts`: `Post`, `Put`,
  `Patch` y `Delete` no tenían `normalizationContext` y API Platform documentaba su salida con
  todas las propiedades. Ahora llevan `catalogo:read` (CLAUDE.md, «un Delete lleva los grupos
  de sus hermanas»).
- El `resumen` del tour y la `descripcionWeb` se pintan con `|raw`: es HTML del editor y de la
  traducción automática, escrito por el equipo. Si algún día lo escribe un tercero, hay que
  sanearlo (no hay `html-sanitizer` instalado).

## 11. Dónde tocar para cambiar X

| Necesidad | Archivo | Método / clave |
|---|---|---|
| Que un catálogo salga (o no) en la web | `util` → `CatalogoDashboard.vue`, modal | `publicadoWeb`, `slug` |
| Título o entradilla de un catálogo en la web | ídem | `tituloWeb`, `descripcionWeb` (se traducen solos) |
| Qué tours / qué datos tiene una tarjeta | `src/Cotizacion/Service/TourTarjetaResolver.php` | `tarjetas()` |
| Idioma de los contenidos y su caída | `src/Front/Comun/Service/TextoI18n.php` | `en()` |
| Portada, catálogo, ficha | `src/Front/Tours/Controller/TourController.php` + `templates/front/tours/*.html.twig` | `portada()`, `catalogo()`, `tour()` |
| Menú, lema o WhatsApp de la cabecera de tours | `templates/front/tours/layout.html.twig` | bloques `nav`, `pie_lema`, `whatsapp_flotante` |
| Lo que comparten las dos webs (head, pie, idiomas) | `templates/front/comun/base.html.twig` | — |
| Qué web es un dominio | `src/Front/Comun/Service/SitioWeb.php` | `de()` |
| Textos de la interfaz | `translations/front_tours.{es,en}.yaml` (tours) · `front.{es,en}.yaml` (común) | — |
| Marca, RUC, dirección, WhatsApp, correo | `config/services/services_parameters.yaml` | `front.*` |
| Enlace de la sección de alojamiento | ídem | `front.alojamiento_url` |
| Estilos | `public/front/web.css` | — |
| Términos, devoluciones, privacidad… | `templates/front/{sitio}/legal/{pagina}.{idioma}.html.twig` | `LegalController::PAGINAS` |
| Campos u obligatoriedad del Libro | `src/Front/Comun/Entity/LibroReclamacion.php` + `src/Front/Comun/Form/LibroReclamacionType.php` | — |
| Numeración, correos o destinatario del Libro | `src/Front/Comun/Service/LibroReclamacionesService.php` | `registrar()`, `siguiente()`, `avisar()` |
| Responder una hoja | Panel → Libro de Reclamaciones | `LibroReclamacionCrudController` |
| Redirecciones del legacy | `src/Front/Tours/Controller/LegadoController.php` | — |
| `robots.txt` / `sitemap.xml` / idiomas del sitemap | `src/Front/Tours/Controller/SeoController.php` | `IDIOMAS` |
| Enlace al itinerario día a día | `TourController::tour()` | `itinerario_url` (pax) |
