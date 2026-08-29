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

⚠️ **Un nombre bidireccional lleva «(ida o vuelta)», y sólo en el nombre OPERATIVO.**

El proveedor ve el nombre del componente en grande y el del segmento debajo. Si sólo lee el de
arriba, «Transporte Cusco ↔ Ollanta» no le dice cuál de los dos extremos es el destino de hoy —
y una flecha de dos puntas **invita a suponer**, que es peor que no decir nada: se puede
presentar en el extremo equivocado. El paréntesis le obliga a bajar la vista al segmento.

No va en el `titulo`: ése es prosa de cliente, y «(ida o vuelta)» no significa nada para quien
viaja. Es la misma separación de siempre — operativo para despachar, público para vender.

⚠️ **Las divergencias de precio son el hallazgo, no el estorbo.** Salieron 7, y dos eran un error:
en el urbano de Cusco la Van del aeropuerto y la del terminal estaban **intercambiadas**. Nadie
lo vio en cinco componentes por cinco tarifas.

### Cerrar una divergencia: quién decide y con qué regla (29/08/2026)

La fusión conserva las dos tarifas cuando los importes no coinciden, y las deja marcadas con su
sentido. Cerrarlas es una decisión de negocio, no del comando.

Se cerraron **quedándose con la MÁS ALTA**, y el motivo importa más que la regla: las de bus
**son referenciales**, no precios pactados. Se usan para cotizar y el pasajero compra su pasaje
al precio del día; quedarse corto obliga a pedir la diferencia después, pasarse deja margen.

⚠️ **Con un precio PACTADO la regla sería la contraria.** «La más alta» no es una regla general
de catálogo: es la que corresponde a una tarifa de referencia.

Y una se dejó **sin resolver a propósito**: `Auto · Paracas → Ica` a 300 contra `Ica → Paracas` a
200 — el mismo tramo con 100 soles de diferencia no es una referencia, es un error, y coger el
alto sería tan arbitrario como coger el bajo. Vive en
`ResolverTarifasDivergentesCommand::PENDIENTES` con el porqué escrito al lado, que es mejor sitio
que la cabeza de alguien.

### ⚠️ Una preposición basta para que un par pase desapercibido (29/08/2026)

`Transporte Aeropuerto Lima - Hotel Lima` y `Transporte Hotel Lima - Aeropuerto **de** Lima` son
el mismo par direccional, y la detección automática **no los vio**: compara los extremos y
«Aeropuerto Lima» no es «Aeropuerto de Lima».

Por eso la fusión automática **no sustituye a mirar la lista**. Quedaron tres componentes para el
mismo traslado —los dos con los precios reales y uno tercero, creado el mismo día para meter
Minibús y Bus, con todo a cero **y quedándose con los enlaces**—. Lo consolidó
`app:travel:consolidar-transporte-aeropuerto-lima` con un cuadro explícito.

Dos cosas que quedaron escritas ahí y valen para el resto:

- **Las tarifas a 0 pueden ser deliberadas.** Minibús y Bus existen para poder cotizar un grupo
  grande antes de negociar el precio. Un 0 no siempre es un olvido, y el comando lo dice en voz
  alta al terminar para que nadie lo «arregle».
- **La noche era +20% exacto** en los cuatro vehículos con precio (70→84, 110→132, 200→240,
  280→336). No se calculó: un patrón observado no es una regla de negocio, y convertirlo en
  código lo vuelve una a espaldas de quien pone los precios.

⚠️ **Y un fallo del propio ensayo, que es el peor sitio donde tenerlo.** El `--dry-run` construía
el mapa de destinos con las entidades que crea… y en ensayo no crea ninguna, así que informaba
«sin equivalente» en las 24 tarifas. El mapeo estaba bien; el preview mentía. **Un preview que
miente es peor que no tenerlo**, porque es justo lo que se mira para decidir si aplicar. El
conjunto de nombres se llena siempre, escriba o no.

### Dos componentes por ciudad: urbano y aeropuerto (29/08/2026)

Fase 2. En Cusco había **cinco** componentes urbanos —terminal, paradero, restaurante ida,
restaurante vuelta— con las mismas cuatro tarifas repetidas y **ninguno con enlaces**. Cinco
copias del mismo precio es donde se esconden los errores.

```
             Auto  Van  Master  Sprinter  Bus
urbano        25    35    14      16       20
aeropuerto    30    40    16      20       25
recargo       +5    +5    +2      +4       +5     ← el estacionamiento de la terminal aérea
```

⚠️ **El recargo no es plano ni porcentual, así que no se puede calcular: hay que preguntarlo.**
Es un coste real —lo que cobran por entrar—, no un margen.

**Por qué DOS componentes y no uno con una tarifa de recargo.** Lo segundo es más fiel al dato
—un solo componente, «Van» y «Van · con ingreso al aeropuerto»— y se descartó a propósito:
obliga al operador a acordarse de elegir la correcta, y **equivocarse ahí lo paga la operación**.
Con dos componentes hay que equivocarse de componente, que es mucho más difícil.

**Lo que destapó:** el `Auto` urbano cobraba 30, el precio del aeropuerto, en todas partes. Y las
Van estaban **intercambiadas** entre el componente de terminal y el de aeropuerto. Ninguna de las
dos cosas se ve leyendo un componente: sólo aparecen al poner los cinco cuadros uno al lado del
otro.

### ⚠️ «Aeropuerto» no significa lo mismo en tres ciudades

La forma NO es única, y asumirla habría estropeado dos de las tres. El bloque de aeropuerto de
`CUADROS` es opcional justo por esto:

| Ciudad | Qué pasa con el aeropuerto | Resultado |
|---|---|---|
| **Cusco** | cobra recargo por entrar a la terminal aérea | **dos** componentes, urbano y aeropuerto |
| **Arequipa** | cuesta **lo mismo** que el terminal | **uno**: «Aeropuerto o Terminal ↔ Arequipa» |
| **Puno** | está en **Juliaca, a casi una hora** | el urbano va solo; Juliaca es **interurbano** y no se toca |

Puno es el caso que más fácil se estropea: por nombre parece «el aeropuerto de Puno» y meterlo en
el componente urbano habría metido un tramo de 45 km —150 a 600 soles— junto a traslados de 25.
Lo que decide no es cómo se llama, sino **dónde está**.

⚠️ **El cuadro tiene que poder re-correrse, y casi no podía.** El comando buscaba el canónico
sólo por su nombre VIEJO, así que en cuanto renombraba dejaba de encontrarse a sí mismo: un
cambio de precio o de capacidad ya no se podía aplicar volviendo a lanzarlo, que es justo para lo
que sirve tener un cuadro. Se descubrió al corregir las plazas de Arequipa. Ahora busca por los
dos nombres.

### El sentido también se duplica DENTRO de la tarifa (29/08/2026)

`Transporte Valle Sagrado ↔ Ollanta` tenía 21 tarifas y casi la mitad eran pares: «Master
**hasta** sector Aranwa» y «Master **desde** sector Aranwa», mismo precio, misma capacidad. En un
componente que ya es bidireccional esas dos palabras no distinguen nada —la dirección la pone el
segmento— y sólo obligan al operador a elegir entre dos filas idénticas.

`app:travel:fusionar-tarifas-por-sentido` fundió 12 grupos, 24 tarifas en 12. Actúa **sólo en
componentes con `↔`**: en uno direccional, «desde» y «hasta» sí podrían significar algo.

⚠️ **Y lo que NO se toca, que es la mitad de la lección.** En el mismo nombre convivían un eje
falso y uno verdadero: el sentido no distingue, pero el **sector sí** —Urubamba 55, Aranwa 60,
Pisac 120— porque es distancia real. Mirar cuál es cuál **antes** de fusionar es todo el trabajo;
el comando sólo ejecuta la decisión.

⚠️ La palabra se quita con **límites de palabra** (`\bdesde\b`), no con un `str_replace`: éste
mordería cualquier nombre que la contenga dentro de otra.

### Copias exactas: siempre queda una (29/08/2026)

`Pool By Car` tenía **siete** «Pool Bickmar» idénticas a 40 USD, ninguna en uso. Siete filas
iguales en un desplegable no son un catálogo: son ruido, y esconden si alguna de ellas era la
buena.

`app:travel:limpiar-tarifas-repetidas` quita las copias **exactas** —mismo componente, nombre,
importe y moneda—. Si difieren en algo, se quedan las dos: en cuanto hay una diferencia deja de
ser un duplicado y pasa a ser una decisión de negocio.

⚠️ **Siempre queda UNA, aunque ninguna esté en uso.** Un componente sin tarifas no se puede
cotizar: entra sin precio y no falla en ninguna parte hasta que alguien mira el total. Vaciarlo
sería cambiar «tiene siete copias» por «no se puede vender», que es peor.

Quedan otras repetidas fuera de transporte —`Alojamiento en Ollantaytambo`, `Cena en Valle
Sagrado`, `Transporte Xcaret Rountrip`—, todas ×2. El comando las coge sin `--componente`.

### Una plaza por vehículo, la misma en todo el catálogo (29/08/2026)

La misma palabra declaraba cosas distintas según dónde se mirara: `Van` entre 3 y 8 plazas, `Auto`
entre 2 y 6, y unas cuantas «sin medir» —peor que un número equivocado, porque no se puede
contrastar con nada—.

```
Auto 3 · Van 5 · Master 10 · Sprinter 15
```

152 tarifas ajustadas: **129 bajan, 15 suben, 8 estaban sin medir**.

⚠️ **Sólo entran las `privado` + por grupo.** Son las que significan «alquilo el vehículo
entero», y ahí la capacidad ES la del vehículo. En una `compartido` se vende asiento —Cruz del
Sur, Bus Bimodal, los pools— y ponerle la capacidad del vehículo limitaría el grupo por un número
que no tiene nada que ver.

⚠️ **Se separa lo que SUBE de lo que baja, y no es cosmético.** Que una capacidad baje es
inofensivo: se vende de menos. Que suba **permite meter gente donde antes no cabía**, y eso hay
que mirarlo una a una — son 15 y están listadas al aplicar.

### 🔜 PENDIENTE: `Bus` y `Minibús` son un problema de vocabulario, no de número

Quedaron fuera **a propósito**, y no por falta de datos: hay tarifas donde al **Sprinter se le
llama «minibús» de cara al cliente**, así que hoy los dos nombres no describen el mismo vehículo
en todo el catálogo. `Bus` sigue con 25, 27 y 45 declaradas.

Fijarles una capacidad ahora **congelaría la confusión en un número**, que es justo lo que costó
caro con las Van intercambiadas de Cusco. Primero se acuerda qué vehículo es cada uno; los
números vienen después.

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
| `app:travel:normalizar-transporte-urbano` | un cuadro de precios como fuente de verdad · absorber copias |
| `app:travel:resolver-tarifas-divergentes` | aplicar una decisión de negocio dejando por escrito la que no se tomó |
| `app:travel:consolidar-transporte-aeropuerto-lima` | consolidar con cuadro explícito · reapuntar componente Y tarifa en cotizaciones hechas |
| `app:travel:fusionar-tarifas-por-sentido` | distinguir un eje falso (el sentido) de uno verdadero (el sector) en el mismo nombre |
| `app:travel:limpiar-tarifas-repetidas` | borrar copias exactas sin dejar nunca un componente sin precio |
| `app:travel:normalizar-capacidades-vehiculo` | separar lo que sube de lo que baja · dejar fuera lo que aún no tiene nombre acordado |
| `app:travel:renombrar-componente` | escribir sólo el español y dejar traducir al listener |
