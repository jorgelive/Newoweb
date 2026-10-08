# Plan — Web pública (openperu.pe → tours; centrocuscointi.com → alojamiento)

Sustituir el PHPTravels heredado que sirve `openperu.pe` por páginas públicas servidas desde el
mismo Symfony, con los **tours en promoción** como sección principal, vídeos promocionales y,
más adelante, el alojamiento con reserva online en su propio dominio.

**Alcance:** plan por fases y decisiones. El funcionamiento de lo ya construido está en
`docs/WebPublica.md`.

## Índice

1. [Punto de partida (diagnóstico del 06/10/2026)](#1-punto-de-partida-diagnóstico-del-06102026)
2. [Decisiones](#2-decisiones)
3. [Fases](#3-fases)
4. [Preguntas abiertas](#4-preguntas-abiertas)

---

## 1. Punto de partida (diagnóstico del 06/10/2026)

- `openperu.pe` y `www.` los sirve **PHPTravels (CodeIgniter, PHP 7.4)** desde
  `/var/www/phptravels`, con base de datos propia (`phptravels_legacy`). Sólo cumplió un papel:
  enseñar condiciones para la validación de Culqi, que ya está hecha. **No hay nada funcional que
  salvar**: el libro de reclamaciones tiene 0 hojas, y los tours viejos ya dan `/notfound`.
- Casi todo su tráfico son sondas de ataque (`wp-login.php`, `alfa.php`…) contra un PHP sin
  soporte. Es además lo último que retiene PHP 7.4 en el servidor (ver la migración a 26.04).
- El Symfony **ya tenía el hueco**: `FRONT_HOST=openperu.pe` en producción y
  `config/routes.yaml` enrutando `src/Front/Controller/` a ese host (hoy `src/Front/Tours/`). La carpeta estaba vacía y
  nginx mandaba el dominio a PHPTravels.
- Los tours ya existen como **catálogos de cotizaciones** (`CotizacionCatalogo` → `Cotizacion`),
  con portada, «Desde X», orden, publicado por tour y traducción a 7 idiomas. Su vista pública
  (`pax /catalogo/:localizador`) sólo se abre con el código: no hay listado ni SEO.
- `centrocuscointi.com` **no está registrado** (whois: «No match», 06/10/2026).

## 2. Decisiones

### 2.1 Twig en el servidor, no la SPA de `pax`

Para vender por Google y para que un enlace pegado en WhatsApp salga con foto y título, la
página tiene que llegar **ya escrita**. `pax` es una SPA con `<title>Pax Experience</title>`
fijo: un buscador o el previsualizador de WhatsApp ven una página vacía. Las páginas públicas
viven en `src/Front/` y `templates/front/`, y leen los mismos datos que `pax`
(`TourTarjetaResolver`, las columnas públicas de `Cotizacion`).

`pax` sigue siendo el sitio del **itinerario día a día** y de todo lo que es de un cliente ya
captado. La ficha pública de un tour enlaza ahí.

### 2.2 Catálogo publicado en la web ≠ catálogo activo

| Flag | Qué decide |
|---|---|
| `activo` (ya existía) | El enlace privado por localizador funciona. |
| `publicadoWeb` (nuevo) | El catálogo aparece en `openperu.pe`. |

Son dos cosas distintas a propósito: un catálogo para una agencia se manda por enlace y **no**
debe salir en la web. Publicar exige además un `slug` (la URL) y que el catálogo esté activo.
Dentro de un catálogo publicado sólo salen los tours con `publicado = true`; el operador **no**
ve borradores en la web (a diferencia de `pax`, aquí no hay previsualización: es la cara
pública y la caché de un buscador no distingue quién miraba).

### 2.3 Uno o varios catálogos

La portada pinta **una sección por catálogo publicado**, en su `orden`, con su título y texto
públicos (`tituloWeb`, `descripcionWeb`, traducibles; si faltan, el `nombre`). Cada catálogo
tiene además su página (`/{slug}`, ver §2.4).

**El orden ES la prioridad** (`CotizacionCatalogo::$orden`, flechas en `CatalogoDashboard.vue`,
ya existía): el primer catálogo publicado pone la cabecera de la portada.

Un catálogo es una **propuesta genérica** (un itinerario tipo, con fechas nominales), no un viaje
real. Un catálogo nuevo se llena **copiando** una cotización ya trabajada de un expediente —el
primero: «Viajes de promoción», con la de Punta Cana de `X5ZXF4` (Promoción 2027 Colegio Santa
Rosa)—, ver Fase 2.

### 2.4 URLs

```
/                                     portada (es)
/{catalogo}                           un catálogo          ← decidido 07/10/2026 (hoy: /tours/{catalogo})
/{catalogo}/{propuesta}-{slug}        un tour
/experiencias                         galería de clientes (Fase 3)
/en/...                               lo mismo en inglés
```

**Catálogos en la raíz** (decisión de Jorge, 07/10/2026): `openperu.pe/viajes-de-promocion` es más
corta y mejor para buscadores que `/tours/viajes-de-promocion`, y «tours» no describe un viaje de
promoción. El precio: el `slug` de un catálogo compite con las rutas fijas (`legal`,
`libro-de-reclamaciones`, `experiencias`, `en`, `robots.txt`…) y con las del legacy; el
validador del catálogo tiene que rechazar las palabras reservadas, y `/tours/{slug}` responde
301 a la nueva.

El identificador del tour es el **número de propuesta**; el slug del título es cosmético. Si no
coincide (título cambiado, idioma distinto) se responde **301 a la URL canónica**, así que
retitular un tour no rompe enlaces ya compartidos.

### 2.5 Idiomas

`es` sin prefijo y `en` con `/en`. Los contenidos ya están en 7 idiomas; las **cadenas de la
interfaz** se escriben en `translations/front.{es,en}.yaml` (común) y `front_tours.{es,en}.yaml`. Abrir otro idioma es añadir su
archivo y su prefijo; no hay que tocar datos.

### 2.6 Lo que NO se replica en PHP

El itinerario del cliente se compone en `componerItinerario()` (TypeScript) y
`docs/Cotizaciones.md` §6.u prohíbe reescribirlo. La ficha pública enseña portada, resumen,
duración, precios «desde» y galería, y enlaza el día a día a `pax`. Si algún día la ficha
necesita el itinerario escrito en el servidor, va por `App\Dominio\EjecutorDeDominio`, no por
una copia en PHP.

### 2.7 Libro de Reclamaciones en Symfony

Obligatorio para vender por internet (INDECOPI). Entidad propia con correlativo anual, copia al
consumidor por correo y aviso al operador, trampa para robots y CSRF. Se responde desde el
panel. Las condiciones (términos, devoluciones, privacidad, ESNNA, cookies) se traen del
legacy tal cual, en `templates/front/tours/legal/`.

### 2.8 Un solo Symfony, dos fronts

Cada web es un host en `config/routes.yaml`: `openperu.pe` (tours, `FRONT_HOST`) y
`centrocuscointi.com` (alojamiento, futuro `FRONT_ALOJAMIENTO_HOST`) con sus propios
controladores, portada, marca y motor de reservas. Comparten la plantilla base, el Libro de
Reclamaciones —que por eso funciona sin sesión: la cookie es de `.openperu.pe`— y el mecanismo
de páginas legales, cada uno con **sus** textos. Detalle en `docs/WebPublica.md` §8.

⚠️ **La variable nueva no puede tumbar producción**: `FRONT_ALOJAMIENTO_HOST` se declara con
`%env(default:…)%` y su parámetro al lado, o se añade a `.env.local` del servidor en el mismo
despliegue (CLAUDE.md, «Una variable de entorno NUEVA…»).

## 3. Fases

### Fase 1 — Tours en `openperu.pe` y apagado de PHPTravels

Hecho el 07/10/2026 (en local; funcionamiento en `docs/WebPublica.md`):

- [x] Campos web del catálogo (`publicadoWeb`, `slug`, `tituloWeb`, `descripcionWeb`) +
      migración `Version20261007010000` + edición en `util` (`CatalogoDashboard.vue`).
- [x] Portada, página de catálogo y ficha de tour (Twig, es/en, Open Graph, `hreflang`, JSON-LD).
- [x] Consulta por WhatsApp con el tour y su enlace ya escritos.
- [x] Libro de Reclamaciones (entidad, formulario sin sesión, correlativo, correos, CRUD en el panel).
- [x] Páginas legales traídas del legacy (tal cual: falta reescribirlas para tours, §4).
- [x] `robots.txt`, `sitemap.xml`, redirecciones 301 de las URLs viejas.
- [ ] Reescribir términos y devoluciones para tours (depende de §4).
- [x] **Despliegue** (08/10/2026): migrado; nginx de `openperu.pe` → Symfony, `www` → 301 al
      dominio desnudo; «Oferta Cusco» publicado en la web. Ver `docs/WebPublica.md` §9.
- [ ] Retirar `/var/www/phptravels` y PHP 7.4 tras volcar `phptravels_legacy`.

### Fase 2 — Copiar una cotización de expediente al catálogo

✅ Hecho el 07/10/2026 (en local, sin desplegar). El detalle está en `docs/Cotizaciones.md`,
«Clonar una cotización a OTRO expediente o a un CATÁLOGO». De paso se arreglaron cuatro fallos
que ya tenía el clonado entre expedientes (copia publicada, destacados e inclusiones apuntando al
original, subgrupos de otro grupo) y la fecha de creación heredada.

Lo planteado:

El `/clonar` de `Cotizacion` (`CloneCotizacionProcessor`, `Cotizacion::duplicar()`) ya copia el
árbol entero, desplaza fechas (`fechaInicio`) y ajusta pax (`numPax`), pero sólo sabe ir de
catálogo **a** expediente (`CuerpoDeClonacion::$file`). Falta el sentido contrario:

- `CuerpoDeClonacion::$catalogo` → `setFile(null)` + `setCatalogo()`, fechas a la base nominal
  (05/01/2030, `FECHA_BASE_NOMINAL` del editor), pax como «pax base», **sin publicar**.
- El precio «desde» por persona lo pone el operador: no se deriva del total del grupo (liberados,
  clases de pasajero).
- `util`: botón «Copiar al catálogo» en `FileDetalle.vue` junto a «Clonar a expediente», y en
  `CatalogoDashboard.vue` el inverso «Crear expediente desde este tour» (el backend ya lo hace).
- Comprobar antes de publicar que los textos no nombran al cliente. La de `X5ZXF4` no lo hace
  (revisado el 07/10/2026: título, resumen y 44 segmentos).

### Fase 3 — Multimedia comercial (vídeos y galería de clientes)

**Va aparte del cotizador y de los expedientes** (Jorge, 07/10/2026): el catálogo es genérico y
el expediente es la venta; las fotos y vídeos se ponen **después** de operar un viaje, y hoy no
están en ninguna parte. Los archivos del expediente son documentos privados (DNI, billetes) y
nunca alimentan la web.

```
ExperienciaAlbum   «Promo San José La Salle · Punta Cana · sept. 2026»
  ├─ titulo / texto (i18n, #[AutoTranslate]), destino, fecha, portada, orden
  ├─ publicado            ← la web sólo enseña lo publicado
  ├─ autorizado           ← ⚠️ consentimiento para publicar (menores en viajes escolares)
  └─ medios[]  ExperienciaMedio: tipo foto|video, orden, pie (i18n)
                 foto  → subida (Vich, carpeta pública) + filtro Liip travel_cliente
                 video → enlace YouTube/Vimeo (validado; nada de MP4 propios)

CotizacionCatalogo ──(selección ordenada)──▶ ExperienciaAlbum / ExperienciaMedio sueltos
```

- **Vídeos por enlace** (decidido): YouTube o Vimeo, aunque sean ocultos. No consumen ancho de
  banda del servidor; la web los carga al pulsar (miniatura + `youtube-nocookie`).
- **`publicado` exige `autorizado`.** Son viajes escolares con menores: publicar su imagen sin
  autorización de los padres es un riesgo legal (Ley 29733). La casilla la marca quien tiene la
  autorización firmada; sin ella, el álbum no sale.
- **`util`**: sección nueva «Experiencias» (álbumes: crear, subir fotos en lote, pegar enlaces,
  ordenar, publicar). En el catálogo, una pestaña «Multimedia» que elige y ordena álbumes o
  vídeos sueltos para ese catálogo.
- **Web**: `/experiencias` (todos los álbumes publicados), franja «Experiencias reales» en cada
  sección de catálogo de la portada y en su página, y el vídeo del primer catálogo como cabecera.

### Fase 4 — Reserva y cobro online de tours

Hoy la venta cierra por WhatsApp y enlace de pago creado por el operador. Pagar solo exige el
resolver de cobro `tour_reserva` (declarado y fallando a propósito, ver
`docs/FinanzasEnlacesPago.md`) y decidir fecha y número de pasajeros en la ficha.

### Fase 5 — `centrocuscointi.com`

Registrar el dominio, DNS y certificado. Vitrina de casitas (hoy `pax /:establecimiento/:unidad`
en un iframe) y motor de reservas: `PmsDisponibilidadService` público + tarifa pública + alta de
reserva + envío a Beds24 + cobro Culqi por el resolver de `pms_reserva`, que ya existe.
Alternativa a valorar: el motor de reservas de Beds24 incrustado (más rápido, sin Culqi).

## 4. Preguntas abiertas

- **RUC y marca.** Las condiciones heredadas son de Susan Acuña Romero (RUC 10249916001,
  «Centro Cusco Inti») y dicen que las excursiones se contratan **por cuenta del huésped**.
  Vender tours como actividad principal contradice esa cláusula: hay que confirmar con qué RUC
  se venden (¿agencia inscrita en DIRCETUR?) y reescribir términos y devoluciones para tours.
  La marca visible de `openperu.pe` es un único parámetro (`front.marca`).
- **Más idiomas**: ¿pt/fr/de en la web desde el principio?
