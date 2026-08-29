# Cargar catálogo en Travel: segmentos, componentes, servicios y plantillas

Receta para crear contenido de catálogo sin dejarlo a medias. Está escrita porque cada pieza sola
**parece funcionar**: un segmento sin componente se guarda, un componente sin tarifa se guarda, y
el fallo aparece semanas después, en la cotización, sin un solo error.

Complementa `docs/Travel.md`, que explica el porqué del modelo. Aquí está el **cómo**.

## Índice

1. [El mapa: qué cuelga de qué](#1-el-mapa-qué-cuelga-de-qué)
2. [La decisión previa: ¿qué compra el pasajero?](#2-la-decisión-previa-qué-compra-el-pasajero)
3. [Pool o plantilla](#3-pool-o-plantilla)
4. [La receta](#4-la-receta)
5. [Los valores por defecto que hay que poner](#5-los-valores-por-defecto-que-hay-que-poner)
6. [AutoTranslate: por qué esto va por comando](#6-autotranslate-por-qué-esto-va-por-comando)
7. [Trampas que ya mordieron](#7-trampas-que-ya-mordieron)
8. [Lista de comprobación](#8-lista-de-comprobación)
9. [Dónde tocar para cambiar X](#9-dónde-tocar-para-cambiar-x)

---

## 1. El mapa: qué cuelga de qué

```
TravelServicio  (ALO, VUELO, ACT_RESORT, WALK_MIR…)
   │
   ├── pool de segmentos      travel_segmento_servicio_pool      ← repertorio suelto
   ├── pool de componentes    travel_servicio_componentes_pool
   └── TravelItinerario       (opcional: el guion con día y orden)
            └── TravelItinerarioSegmentoRel   (segmento, dia, orden)

TravelSegmento          lo que el pasajero LEE
   │  slug · nombreInterno · titulo · contenido · inicioModo/Punto · finModo/Punto
   └── TravelSegmentoComponente      ← el enlace, y donde viven los defaults
            │  dia · hora · horaFin · modo · orden · horaServicioCompleto
            │  tarifaPredeterminada · itinerarioContexto
            └── TravelComponente     lo que se COMPRA
                     │  nombreInterno · titulo · tipo · duracion
                     └── TravelTarifa   precio, prestador, comprador, filtros
```

**Regla corta:** el segmento cuenta, el componente se compra, la tarifa dice cuánto y a quién.

⚠️ **Un componente sin segmento no se puede meter en un itinerario**: lo que se arrastra a un día
es el segmento. Es el fallo más común al cargar catálogo — el componente existe, tiene tarifas, y
no hay forma de usarlo.

## 2. La decisión previa: ¿qué compra el pasajero?

**No hay una forma correcta de relacionar segmento y componente: hay dos**, y la elige una
pregunta de negocio.

| Se compra… | Patrón | Ejemplo |
|---|---|---|
| cada cosa por separado | **un componente por segmento** | `ACT_RESORT` |
| el programa entero | **un componente ANCLA, el resto sólo cuenta** | `CITY_LIM`, `WALK_MIR` |

**Estadía en un resort** → cada actividad tiene prestador propio (el buffet no lo sirve quien
lleva la piscina) y cada una puede incluirse o no. Cada segmento carga su componente y su tarifa.

**Excursión** → nadie vende «el Parque Kennedy». Se contrata un programa con traslados y guía, y
el Parque Kennedy es un capítulo. **Un solo componente** lo representa, anclado en el segmento de
recojo, y los demás segmentos van **sin componente**.

⚠️ De los siete segmentos de `CITY_LIM`, **seis tienen cero componentes**. Si estás creando una
excursión y les pones componente a todos, la estás modelando mal.

Las **comidas son la excepción** en una excursión: se compran aparte y pueden ir incluidas o no,
así que llevan componente propio.

### ⚠️ Cuando el ancla se queda corta: retirarla sin perder la hora (29/08/2026)

El ancla resuelve «se compra el programa entero», pero **fija una sola forma de venderlo**.
`WALK_MIR` colgaba sus dos traslados de un componente llamado *«Escala en Lima · traslados y
caminata por Miraflores»*: el vehículo y el paseo dentro de la misma pieza. Con eso un grupo de
40 y una pareja contratan exactamente lo mismo, porque **no hay dónde elegir movilidad**: las
modalidades (Auto / Van / Minibús / Bus) son tarifas del componente de transporte, y ahí no había
componente de transporte.

La corrección es dar a **cada sentido** su componente `TRANSPORTE` con su flota, como los
traslados de Lima y Punta Cana. El ancla deja de hacer falta.

**Al retirar un ancla hay que traspasarle al sustituto sus DOS relaciones**, no una:

| Relación | `itinerarioContexto` | Hora | `horaServicioCompleto` | Para qué |
|---|---|---|---|---|
| global | `null` | — | `false` | que el componente exista en el pool y aporte su tarifa |
| plantilla | el itinerario | 08:30 | `true` | que el día entero salga encabezado a esa hora |

⚠️ **Si sólo se copia la global, no falla nada y el día pierde su hora de cabecera.** Cae al
desempate por `orden` y nadie lo nota hasta ver la guía. La promoción **exige**
`itinerarioContexto` (`validarPromocionRequierePlantilla`), así que la segunda relación no es
opcional: es la única que puede llevarla.

El ancla vieja **se queda con cero enlaces, no se borra** —regla general del proyecto—: si mañana
aparece una cotización histórica que la citaba, sigue contando lo que se vendió.

Referencia: `app:travel:crear-varios-aeropuerto-lima`, método `retirarAncla()`.

### Un servicio no es un lugar: es lo que se compra junto (29/08/2026)

Las comidas del aeropuerto de Lima vivían dentro de `WALK_MIR` porque allí nacieron, con la
caminata por Miraflores. Pero comer en el aeropuerto pasa en una escala, en una espera de
conexión o en una salida de madrugada — casi siempre **sin caminata**. Metidas en el walking
sólo se podían usar arrastrando la escala entera.

Se creó `VAR_APT_LIM` («Varios en el aeropuerto de Lima») con desayuno, almuerzo y cena. El
desayuno **se mudó** —mismo segmento, nuevo slug `DES-APT_LIM`, fuera del pool de `WALK_MIR`—
en lugar de duplicarse: dos desayunos con el mismo texto dejan al operador sin saber cuál es el
bueno, y las correcciones se aplican a uno solo.

⚠️ **El slug lleva el servicio dentro**, así que una mudanza obliga a renombrarlo:
`DES-WALK_MIR-AEROPUERTO` afirmaba una pertenencia que ya no era cierta.

### ⚠️ Un componente por RUTA, no por sentido (29/08/2026)

`Transporte Cusco - Ollanta` y `Transporte Ollanta - Cusco` eran el mismo vehículo, el mismo
proveedor y el mismo precio, duplicados. **Existían porque el nombre del componente era lo único
que le decía al proveedor a dónde ir** — `travel_componente` no tiene columnas de origen ni
destino; las tiene el segmento (`inicioPunto`/`finPunto`, y a veces como *modo*).

Desde que el nombre del segmento viaja con la orden (`docs/Operacion.md` §5, «Tres ranuras»), esa
muleta sobra: un componente por ruta, y la dirección la pone el segmento.

**Lo que la duplicación costaba, medido:** 94 componentes de transporte, **336 tarifas** para
**39 líneas de cotización** reales. Y divergía en silencio — el mismo `Auto` a 150 con capacidad
3 en un sentido y 4 en el otro.

`app:travel:fusionar-transportes-bidireccionales` fusionó 25 pares y dejó las tarifas en 269.
Sus reglas, que valen para cualquier fusión de catálogo:

| Situación | Qué hace | Por qué |
|---|---|---|
| Canónico del par | el más usado, empate → alfabético | mover pocos enlaces es menos superficie de error |
| Capacidades distintas | **la menor** | un `Auto` que dice 4 cuando caben 3 vende una plaza que no existe, y eso se descubre en el aeropuerto |
| Capacidad nula vs. número | gana el número | un nulo no es «menor», es «sin medir» |
| **Importes distintos** | **NO fusiona**: conserva las dos, cada una con su sentido en el nombre | elegir uno sería inventar dinero |
| El componente que sobra | 0 enlaces, fuera de los pools, **no se borra** | una cotización histórica que lo cite sigue contando lo que se vendió |

⚠️ **Borrar una tarifa obliga a reapuntar quien la cita.** La primera versión repuntaba
`tarifaPredeterminada` y se olvidó de `cotizacion_cottarifa.tarifaMaestraId`: dejó **13 enlaces
al vacío**. No falla nada —el snapshot conserva nombre e importe, la cotización se sirve igual—,
simplemente la línea deja de poder volver al maestro, y eso no duele hasta que alguien recalcula.
Se cazó comparando el conteo contra producción, que tenía 0. Ver `reapuntarCotizaciones()`.

⚠️ **Las divergencias de precio son el hallazgo, no el estorbo.** Salieron 7, y dos eran un error:
en el urbano de Cusco la Van del aeropuerto y la del terminal estaban **intercambiadas**. Nadie
lo vio en cinco componentes por cinco tarifas.

## 3. Pool o plantilla

| | Pool | Itinerario |
|---|---|---|
| Qué es | repertorio del que se toma lo que haga falta | guion con día y orden |
| Cuándo | el operador arma a medida | el programa tiene secuencia fija |
| Ejemplo | `ALO`, `TRF_LIM`, `VUELO`, `ACT_RESORT` | `CITY_LIM`, `WALK_MIR` |

**Siempre se llenan los pools.** El itinerario es opcional y se añade encima.

⚠️ **Una excursión necesita plantilla aunque el pool baste técnicamente.** Sus segmentos narrativos
no llevan componente, luego no llevan hora, luego en la guía se ordenan por `orden` — y ese orden
**vive en `travel_itinerario_segmento_rel`**, no en el segmento. Sin plantilla habría que recordar
a mano, en cada cotización, que el malecón va después del Parque Kennedy.

## 4. La receta

Va **siempre por comando** en `src/<Modulo>/Command/`, idempotente por clave natural y con
`--dry-run`. El porqué está en §6.

```php
// 1 · El servicio contenedor
$servicio = (new TravelServicio())
    ->setCodigo('ACT_RESORT')
    ->setNombreInterno('Actividades en resort')
    ->setTitulo([['language' => 'es', 'content' => 'Actividades en resort']]);
$this->em->persist($servicio);
$this->em->flush();                       // se necesita su id para los pools

// 2 · El segmento
$segmento = (new TravelSegmento())
    ->setSlug('ACT-RESORT-PISCINA_PLAYA') // TIPO-CONTEXTO-VARIANTE, mayúsculas
    ->setNombreInterno('Piscina y playa en resort')
    ->setTitulo([['language' => 'es', 'content' => 'Piscina y playa']])
    ->setContenido([['language' => 'es', 'content' => '…']])
    ->setInicioModo(PuntoModoEnum::SIN_DEFINIR)   // o FIJO + setInicioPunto()
    ->setFinModo(PuntoModoEnum::SIN_DEFINIR);
$this->em->persist($segmento);

// 3 · El componente
$componente = (new TravelComponente())
    ->setNombreInterno('Piscina y playa en resort')
    ->setTitulo([['language' => 'es', 'content' => 'Piscina y playa']])
    ->setTipo(ComponenteTipoEnum::EXTRAS);
$this->em->persist($componente);

// 4 · La tarifa  ⚠️ sus setters devuelven void: NO se encadena
$tarifa = new TravelTarifa();
$tarifa->setComponente($componente);
$tarifa->setNombreInterno('Occidental Caribe · Piscina y playa');
$tarifa->setTitulo([['language' => 'es', 'content' => 'Piscina y playa']]);
$tarifa->setMoneda($moneda);              // obligatorio
$tarifa->setMonto('0.00');
$tarifa->setPrestador($organizacion);     // quién lo presta
$tarifa->setPrestadorServicio($servicioDelPrestador);   // de aquí salen las FOTOS
$this->em->persist($tarifa);

// 5 · El enlace, que es donde viven los defaults
$rel = (new TravelSegmentoComponente())
    ->setSegmento($segmento)
    ->setComponente($componente)
    ->setTarifaPredeterminada($tarifa)    // ⚠️ §5
    ->setModo(ComponenteModoEnum::INCLUIDO)
    ->setDia(1)
    ->setOrden(1)
    ->setHora(new \DateTimeImmutable('10:00'));   // ⚠️ §5
$this->em->persist($rel);

// 6 · A los pools
$servicio->addSegmento($segmento);
$servicio->addComponente($componente);

$this->em->flush();
```

### Slug

`TIPO-CONTEXTO-VARIANTE`, mayúsculas, guion entre partes y guion bajo dentro de una parte.

Los prefijos más usados son `VIS` (visita), `ALO` (alojamiento), `ACT` (actividad), `ALM`/`DES`/
`CEN` (comidas), `SAL_EXC`/`RET_EXC` (salida y retorno de excursión), `TRANS`, `TREN_IDA` y
`VUELO`. **La lista no está cerrada** —hay más de treinta— así que antes de inventar uno conviene
mirar si ya existe el que buscas:

```sql
SELECT DISTINCT SUBSTRING_INDEX(slug,'-',1) FROM travel_segmento WHERE slug IS NOT NULL;
```

⚠️ **El destino NO va en el slug si el segmento es reutilizable.** `ACT-RESORT-PISCINA_PLAYA`, no
`ACT-PUJ_RESORT-PISCINA_PLAYA`: meterle el destino ata a Punta Cana una ficha que sirve para
cualquier resort. Ver `docs/Travel.md` §11.quater.

## 5. Los valores por defecto que hay que poner

### La hora: **coloca**, no describe

Los grupos de un día se ordenan así (`itinerarioVista`, ver `docs/Cotizaciones.md` §6.u):

```
tier 0   tiene alguna hora   → por su hora MÁS TEMPRANA
tier 1   ninguna hora        → por su `orden` más bajo
tier 2   es estadía          → siempre al final
```

⚠️ **Tier 1 va DESPUÉS de todo lo que tenga hora.** Dejar la hora vacía no es «sin preferencia»,
es «al final». Medido: un «Día libre en el resort» sin hora, en un día con salida nocturna a las
22:00, pintaba la discoteca primero.

**Si el segmento tiene que caer en un sitio concreto del día, dale hora.**

### `tarifaPredeterminada`: sin ella el componente entra sin precio

Es la que se copia al arrastrar el segmento a una cotización. Un enlace sin ella obliga a elegir
tarifa a mano cada vez.

### `horaServicioCompleto`: promueve la hora a toda la excursión

⚠️ **Exige `itinerarioContexto`**, y lo valida
`TravelSegmentoComponente::validarPromocionRequierePlantilla()`. Una promoción global aplicaría a
todos los tours que usen el segmento y chocaría con las horas propias de cada plantilla.

Por eso el ancla de una excursión lleva **dos relaciones** con el mismo componente:

| Relación | Contexto | Hora | Promoción | Para qué |
|---|---|---|---|---|
| global | — | **sin hora** | no | lleva la tarifa por defecto |
| de plantilla | el itinerario | 08:30 | **sí** | fija el horario del tour |

**Consecuencia de orden:** la plantilla tiene que existir **antes** que la relación promovida.

### ⚠️ Un ALOJAMIENTO no lleva hora

`esEstadia` se deduce de **no tener horas** y terminar en fecha posterior. Con hora, el alojamiento
deja de repetirse cada noche —sale sólo el primer día— y se coloca a media tarde. Lo impide
`TravelSegmentoComponente::validarAlojamientoSinHora()`.

La hora de llegada al hotel es otro campo: `OperacionServicio::$horaRecojo`, del operador.

## 6. AutoTranslate: por qué esto va por comando

Estos campos se traducen a 7 idiomas mediante un listener enganchado a `prePersist`/`preUpdate`:

| Entidad | Campos |
|---|---|
| `TravelSegmento` | `titulo`, `contenido` |
| `TravelComponente` | `titulo` |
| `TravelTarifa` | `titulo` |
| `TravelServicio` | `titulo` |
| `TravelItinerario` | `titulo` |
| `TravelOrganizacion` | `titulo`, `descripcion` |
| `TravelOrganizacionServicio` | `titulo`, `descripcion` |
| `TravelNota` | `titulo`, `contenido` |

⚠️ **Un `INSERT`/`UPDATE` en SQL se salta el listener.** Deja la ficha sólo en español o —peor—
seis traducciones vivas diciendo lo contrario que el original, **sin que nada falle**. Pasó al
renombrar «Ticket aereo»: el español habría dicho «Vuelo» y los otros seis «Ticket aereo».

**Se escribe SÓLO el español.** El listener rellena los demás:

```php
->setTitulo([['language' => 'es', 'content' => 'Piscina y playa']])
```

Escribir los siete a mano es adelantarse al listener y quedarse con la traducción de uno mismo.

### Qué SÍ puede ir por migración

Esquema, y datos de campos **sin** AutoTranslate ni listeners: `hora`, `orden`, `modo`, `clave`,
`emitido`, `detalle` de grupo. Todo lo demás, comando.

## 7. Trampas que ya mordieron

**Los setters de `TravelTarifa` devuelven `void`.** `setComponente`, `setNombreInterno`,
`setTitulo`, `setMoneda`, `setCapacidadMinima/Maxima`. Encadenarlos es un fatal en ejecución que
`php -l` no caza; PHPStan sí.

**`migrations:diff` arrastra esquema ajeno.** Mira TODO el esquema, no lo que acabas de tocar: en
una pasada real se llevó tres tablas de otro módulo sin desplegar. Para tablas nuevas, migración a
mano con sólo lo tuyo.

**`findOneBy()` no ve lo que aún no se ha volcado.** Si el bucle crea y busca en la misma pasada,
lleva un índice en memoria: cuatro reservas de Copa creaban cada una su propio vuelo y reventaba
el índice único al aplicar.

**MySQL reordena las claves de un objeto JSON** al guardarlo, y el `!==` de PHP sobre arrays sí
mira ese orden. Comparar lo guardado contra lo calculado da siempre distinto: hay que `ksort`
antes.

**El docblock va encima del bloque de atributos ENTERO.** Insertar una propiedad o un método
«justo antes» de otro le roba su docblock y sus `#[Groups]`. Pasó **cuatro veces** en una sola
sesión; lo caza PHPStan, nunca la revisión a ojo.

**Nombres internos duplicados.** Dos tarifas nombradas desde el título público quedaban
indistinguibles: el título de los dos check-in es el mismo. Nombra desde `nombreInterno`.

## 8. Lista de comprobación

Antes de dar por cerrada una carga:

```
□ php bin/console <comando> --dry-run   enseña lo esperado
□ vendor/bin/phpstan analyse src/<Modulo> --level=6
□ php bin/console lint:container
□ php bin/phpunit
□ ¿cada segmento nuevo tiene componente?      (salvo excursión: sólo el ancla)
□ ¿cada componente tiene tarifa?
□ ¿la relación tiene tarifaPredeterminada?
□ ¿tiene hora, o es a propósito que no?
□ ¿está en el pool del servicio, segmentos Y componentes?
□ ¿los títulos salieron con 7 idiomas?         SELECT JSON_LENGTH(titulo)
□ ¿el prestador está visibleParaCliente, si sus fotos deben salir?
```

Las tres últimas son las que se olvidan.

## 9. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo |
|---|---|---|
| Añadir un tipo de componente | `src/Travel/Enum/ComponenteTipoEnum.php` | los `case` |
| Cambiar cómo se ordena un día | `pax/.../PaxCotizacionGuiaView.vue` | `itinerarioVista` |
| Prohibir una combinación al guardar | la entidad | `#[Assert\Callback]` |
| Ver si un segmento saldrá con fotos | panel, ficha del segmento | `virtualCadenaFotos` |
| Reparar datos a medias | `src/Cotizacion/Service/CoherenciaCatalogoChecker.php` | `definiciones()` |
| Renombrar un componente | — | `app:travel:renombrar-componente` |
| Cambiar un ancla por componentes propios | `src/Travel/Command/CrearVariosAeropuertoLimaCommand.php` | `retirarAncla()` |
| Mover un segmento a otro servicio | idem | `comidas()` — reslug + `removeSegmento`/`addSegmento` |

### Comandos de referencia

| Comando | Qué patrón enseña |
|---|---|
| `app:travel:crear-actividades-resort` | un componente por segmento · pool sin plantilla |
| `app:travel:crear-escala-miraflores` | ancla + segmentos que sólo cuentan · con plantilla |
| `app:travel:crear-segmentos-vuelo` | segmento por ruta · puntos fijos · pool masivo |
| `app:travel:crear-varios-aeropuerto-lima` | retirar un ancla traspasando la hora promovida · mudar un segmento de servicio |
| `app:travel:fusionar-transportes-bidireccionales` | fusionar duplicados sin inventar precios · reapuntar lo que citaba lo borrado |
| `app:travel:renombrar-componente` | escribir sólo el español y dejar traducir al listener |
