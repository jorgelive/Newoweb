# Panel EasyAdmin: convenciones del dashboard

Cómo está armado el panel legacy (`src/Panel/Controller/DashboardController.php`, todos los
`*CrudController` de cada módulo) y las trampas propias de EasyAdmin que no son evidentes leyendo
un controller suelto. Alcance: menú lateral, URLs bonitas, y acciones personalizadas
(`renderXxx()` colgadas de `linkToCrudAction()`).

## 1. El menú lateral se construye una vez, para todos los dashboards

`DashboardController::configureMenuItems()` es la única fuente del árbol de navegación. Cada
`MenuItem::linkTo(XxxCrudController::class, 'Etiqueta', 'icono')` apunta siempre a la acción
`index` de ese controller — nunca hace falta (ni se debe) enlazar también a `edit`, `detail` o
una acción personalizada: el propio EasyAdmin decide qué entrada resaltar, ver §2.

## 2. Por qué una acción personalizada no resalta su entrada del menú (02/09/2026)

Los cuatro `renderMassUpload()` del proyecto (`TravelOrganizacionImagenCrudController`,
`TravelOrganizacionServicioImagenCrudController`, `TravelSegmentoImagenCrudController`,
`PmsGuiaItemGaleriaCrudController`) abrían bien — la vista se renderizaba — pero la entrada del
menú lateral (y su submenú padre) se quedaban sin marcar como activa. Nada de esto es un bug
visible: la página funciona, sólo el resaltado del menú no aparece.

### El mecanismo (`EasyCorp\Bundle\EasyAdminBundle\Menu\MenuItemMatcher`)

Este panel usa **URLs bonitas** (`Dashboard::configurePrettyUrls()`; ver
`AdminRouteGenerator::usesPrettyUrls()`), así que EasyAdmin resalta el menú comparando **rutas**,
no query strings. Cuando la URL activa no coincide exactamente con ningún link del menú, cae a un
mecanismo de repliegue: toma el `crudControllerFqcn` de `$request->attributes` (no de
`$request->query`), genera la URL de la acción `index` de ese mismo controller, y la compara contra
los links del menú — así `edit`, `detail` o una acción propia resaltan el mismo item que `index`.

**La acción personalizada sin `#[AdminRoute]` no tiene una URL bonita propia.** Se sirve por la vía
histórica de query string (`/?crudAction=renderMassUpload&crudControllerFqcn=...`). Esa URL nunca
puebla `$request->attributes[crudControllerFqcn]` — sólo lo hacen las rutas reales que EasyAdmin
registra, vía los `defaults` que les pone `AdminRouteGenerator::createRouteForAdminAttribute()`, y
que Symfony copia a los atributos de la petición **al matchear la ruta**, no por ningún listener
de EasyAdmin. Sin ese atributo, `MenuItemMatcher` no tiene de qué controller resolver el
repliegue y se rinde en silencio: ninguna entrada queda seleccionada, ningún submenú se expande.

### El arreglo: darle una ruta bonita propia a la acción

```php
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;

#[AdminRoute(path: 'mass-upload', name: 'mass_upload')]
public function renderMassUpload(EntityManagerInterface $em): Response
```

El `path` se cuelga del path del CRUD controller (`/travel-organizacion-servicio-imagen` →
`/travel-organizacion-servicio-imagen/mass-upload`), y el botón que enlaza a la acción (generado
por `Action::new(...)->linkToCrudAction('renderMassUpload')`) usa automáticamente esa URL nueva
la próxima vez que se genera — no hace falta tocar el `Action`. Con la ruta real, Symfony rellena
`crudControllerFqcn`/`crudAction` en los atributos al matchear, y el repliegue de
`MenuItemMatcher` encuentra la entrada del `index` de ese mismo controller y la marca.

**La regla:** toda acción personalizada (`linkToCrudAction()`) que se llegue a abrir desde fuera
de un flujo transitorio (un botón del listado, un enlace guardado, algo que alguien pueda cargar
directo) necesita su propio `#[AdminRoute(path: ..., name: ...)]`. Sin eso funciona — EasyAdmin no
tira ningún error, ni PHPStan lo ve, es sólo una URL fea que existe igual — pero dos cosas se
quedan rotas y mudas: el resaltado del menú (este apartado) y, si el panel llegase a
`configurePrettyUrls()` alguna vez de forma más estricta, la propia navegación.

## Archivar una plantilla desde la lista (17/09/2026)

`MessageTemplateCrudController` tiene dos acciones de fila, `archivarPlantilla` y
`devolverPlantilla`, que apagan o encienden los canales de una plantilla en un clic. Las dos cuelgan
de `ArchivadorDePlantillas`, el mismo servicio que usa `msg:plantilla:archivar`.

Tres cosas que se decidieron ahí y valen para cualquier acción parecida:

1. **`#[AdminRoute]` en los dos métodos**, por §2: sin ruta propia la acción se sirve por query
   string y el menú lateral deja de resaltar su entrada.
2. **`displayIf()` decide cuál de las dos se ve**, en vez de un botón que cambia de texto: cada una
   dice lo que va a pasar y la otra no está.
3. **La guarda no es un error**: cuando la plantilla la usa una regla activa, el flash es un
   `warning` que nombra las reglas y dice qué hacer antes. Un `danger` haría pensar en un fallo del
   panel.

Y el **por qué** vive donde se ve: `Crud::setHelp(PAGE_INDEX, …)` con un bloque plegable que explica
qué significan los canales tachados, qué hace archivar y qué no toca. La lista enseña además una
etiqueta **ARCHIVADA**: cuatro etiquetas tachadas no se leen como un estado.

### La ficha «Ver» enseña el español, no el JSON

Los cuatro canales se mostraban con `CodeEditorField` sobre el array crudo: los siete idiomas, los
`origenHash` y el `buttons_map` dentro de un bloque con barra horizontal. En el móvil, para saber
qué dice una plantilla había que arrastrar el bloque de lado buscando el español entre el alemán y
el neerlandés.

Hoy son **campos virtuales** que pintan sólo el español, compuesto por
`VistaEnEspanolDePlantilla`: cabecera, cuerpo, pie y botones —cada botón con su destino, que es lo
que más se equivoca—. El español es el original: los otros seis los escribe `AutoTranslate` a partir
de él, así que leerlo es leer la plantilla. El JSON entero sigue al editar, que es donde se toca.

Dos detalles que cuestan una tarde si se descubren en caliente:

- **Los campos virtuales necesitan su stub en la entidad** (`getVirtualTextoMeta()` y compañía,
  devolviendo `''`), por lo mismo que `virtualEstadoMeta`: `TextField` valida el valor CRUDO antes
  de pasarlo al formateador, y anclarlo al array del canal revienta.
- **`<pre>` y no `nl2br()`**: estos textos llevan listas, sangrías y emojis alineados, y un `<div>`
  normal se come los espacios.

## Dónde tocar para cambiar X

| Necesito… | Archivo | Método |
|---|---|---|
| Añadir/quitar una entrada del menú lateral | `src/Panel/Controller/DashboardController.php` | `configureMenuItems()` |
| Que una acción personalizada nueva resalte su entrada del menú | el CRUD controller de esa acción | añadir `#[AdminRoute(path: '...', name: '...')]` sobre el método `renderXxx()` |
| Ver qué rutas bonitas existen ya | — | `php bin/console debug:router \| grep panel_dashboard` |
