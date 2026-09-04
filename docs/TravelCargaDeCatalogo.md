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
4 bis. [El contrato de escritura de cada entidad](#4-bis-el-contrato-de-escritura-de-cada-entidad)
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

⚠️ **Corregido en el detector el 29/08/2026, y no era opcional.** `extremos()` normaliza ahora
el extremo antes de comparar: pasa a minúsculas y **quita artículos y preposiciones sueltos**
(`de`, `del`, `la`, `el`, `los`, `las`) con límites de palabra. Con eso «Aeropuerto Lima» y
«Aeropuerto de Lima» son el mismo extremo. Se arregló porque **volvió a morder**: el mismo fallo
escondió el par de Punta Cana, y consolidar a mano una vez es una anécdota, dos veces es un
agujero. Dejar la lección escrita sin tocar el código habría garantizado una tercera.

Y un guarda que sale de lo mismo: un componente **sin enlaces y fuera de todos los pools** ya no
entra como candidato. Es historia —lo sustituyó otro en una consolidación previa— y volver a
fusionarlo sólo renombra cosas muertas. Bajó los pares detectados de 4 a 1, y el que quedó era el
verdadero.

Aun así la fusión automática **no sustituye a mirar la lista**. Quedaron tres componentes para el
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

### Trenes y vuelos: emparejar por PUNTOS, no por nombre (29/08/2026)

18 pares más fusionados —2 de tren, 16 de vuelo— con `app:travel:fusionar-rutas-por-puntos`.
Comando aparte del de transporte por un motivo concreto: **allí el par se detecta por el nombre y
aquí no se puede**. «Transporte Cusco - Ollanta» se parte por el « - »; «Tren San Pedro Mapi» no
se parte por nada —¿San / Pedro Mapi?— y «Vuelo Cusco Puerto Maldonado» tampoco.

El par sale de `travel_segmento.inicioPunto` / `finPunto`, que es donde el origen y el destino
viven **como dato**. Y ya que la verdad son los puntos, **el nombre nuevo también sale de ellos**:
`Tren Ollantaytambo ↔ Machu Picchu`, no `Tren Ollanta Mapi ↔ …`.

⚠️ **El argumento para fusionarlos no fue el precio: fue el mantenimiento.** «IR 360 Adulto»
estaba a 105 en un sentido y a 60 en el otro, y durante un rato eso pareció probar que subir
costaba más que bajar. No: son tarifas **referenciales**, todo subió y **sólo se actualizó un
lado**. La divergencia no demostraba que fueran productos distintos — demostraba el coste de
tenerlos por duplicado, que es justo el argumento a favor de unirlos.

⚠️ **Y el desempate es el CONTRARIO que en Paracas↔Ica.** Aquí gana el **mayor** (referencial, el
bajo es el que se quedó viejo); allí ganó el **menor**, porque 300 contra 200 en el mismo tramo
era un error, no una referencia. Un comando no puede deducir cuál de los dos casos tiene delante:
se pregunta.

⚠️ **Fusionar y mostrar son la misma decisión.** Al unir los sentidos el nombre deja de decir
hacia dónde se va hoy, y quien lo sabe es el segmento. Por eso
`ComponenteTipoEnum::mandaElSegmento()` cubre exactamente los tipos que `nombraUnaRuta()`
—transporte, tren, vuelo—. Aplicar una sin la otra deja al proveedor una flecha de dos puntas en
grande y el destino escondido debajo. Ver `docs/Operacion.md` §5.

Tres se quedan fuera y con motivo: `Tren Hidroeléctrica Mapi` no tiene vuelta, y `Vuelo` y
`Sobrevuelo` no tienen segmentos, así que no tienen puntos con los que emparejar.

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

### El traslado a Coco Bongo: la ficha es de la CIUDAD, no del destino (30/08/2026)

El servicio Coco Bongo tenía sus dos entradas (general y fiesta blanca) y ningún traslado. Al
añadirlos, la tentación era crear «Transporte Hotel Punta Cana - Coco Bongo» y su inverso: dos
componentes más.

Se hizo al revés: **un solo `Transporte urbano en Punta Cana`**, enlazado a los dos segmentos.
Tres razones, y las tres ya estaban pagadas:

1. **Un componente por ruta, no por sentido** (§ anterior). La dirección la ponen `inicioModo` y
   `finModo` del segmento —aquí `ALOJAMIENTO` en un extremo y `FIJO` en el otro—, no el nombre.
2. **En un `TRANSPORTE` manda el segmento.** `ComponenteTipoEnum::mandaElSegmento()` hace que al
   proveedor y al cliente les llegue el nombre del segmento, no el del componente. Meter «Coco
   Bongo» en el componente sería escribirlo donde nadie lo lee.
3. **El coche no cambia según la discoteca.** Es un traslado dentro de Punta Cana, igual que
   `Transporte urbano en Cusco` y `Transporte urbano en Puno`. El día que haya otro sitio nocturno
   en la ciudad, reutiliza el mismo componente sin crear nada.

⚠️ **Los nombres dicen «Punta Cana» aunque hoy sobre.** Coco Bongo tiene local en varias ciudades;
un segmento llamado sólo «Coco Bongo» es correcto hasta el día que entre el de Cancún, y entonces
ya hay cotizaciones con el nombre ambiguo congelado en su snapshot.

**La flota se clona, no se recopia.** `app:travel:crear-traslado-coco-bongo` lee las tarifas del
traslado de aeropuerto y duplica vehículo, capacidad, moneda y modalidad. Escribir «Auto 3» a mano
otra vez es exactamente como divergieron las capacidades que hubo que unificar en fase 2.

⚠️ **El importe NO se clona.** El urbano no cuesta lo mismo que el de aeropuerto —el recargo de la
terminal es un coste real— así que entra a `0.00` para negociarlo. Heredar el precio del aeropuerto
habría dado un número plausible y falso, que es el peor tipo de dato.

#### ⚠️ `setComponente()` no llena `getTarifas()`: la tarifa por defecto salió nula

La primera pasada creó los dos enlaces **sin `tarifaPredeterminada`**. El comando leía
`$componente->getTarifas()` justo después de `persist()`, y como `TravelTarifa::setComponente()`
no mantiene el lado inverso, la colección estaba vacía y `tarifaPorDefecto()` devolvía `null`.

No falló nada: ni el comando, ni el `flush()`, ni el checker de coherencia. El campo admite null,
y el síntoma —el componente entra **sin precio**— no aparece hasta que alguien mira el total. Es
literalmente la trampa que advierte §5 sobre `tarifaPredeterminada`, cometida al implementarla.

Dos reglas:

- **Justo después de `persist()`, la fuente fiable es la lista que acabas de construir**, no la
  colección de la entidad dueña. Doctrine sólo la sincroniza cuando el setter mantiene el lado
  inverso a mano.
- **Idempotente no basta si lo que quedó escrito estaba mal.** Volver a ejecutar el comando habría
  dicho «ya existe» y seguido de largo. Por eso lleva `repararTarifaPorDefecto()`: al reencontrar
  un enlace suyo, rellena lo que falte en vez de saltarlo.

### La redacción: el marcado NO es cuestión de gusto (30/08/2026)

`TravelSegmento::$contenido` está declarado `#[AutoTranslate(format: 'html')]`, y `pax` lo pinta
con `v-html` dentro de un contenedor Tailwind **`prose`**, que estiliza **por selector de
elemento**. Un texto sin `<p>` no recibe `leading-relaxed` ni margen de párrafo: sale con otro
interlineado y pegado al de al lado, **en la misma lista**.

Medido en el catálogo:

| Forma | Segmentos |
|---|---|
| `<p>` sin entradilla en negrita | **102** |
| texto pelado, sin `<p>` | 47 |
| `<p>` con `<b>` + emoji de entrada | 35 |

Dos conclusiones distintas, y conviene no mezclarlas:

- **El `<p>` es obligatorio.** No es estilo: es el contrato del campo y lo que necesita el
  renderizador. Los 47 pelados se ven mal, aunque cada uno por separado parezca correcto.
- **La entradilla «✈️ **De Lima a Punta Cana:**» es minoría (35 de 184)** y rompe la voz: dentro
  de un mismo expediente el cliente leía «Navegación hasta Isla Saona» y dos líneas después
  «Volamos desde la bruma del Pacífico». Cambia la persona, aparece un emoji y aparece negrita.

⚠️ **El grupo mayoritario tampoco es el modelo.** Los 102 son contenido antiguo con prosa de
folleto («Prepárate para desafiar las alturas»), mezcla de tú y nosotros, y restos de editor
(`data-path-to-node="1"`) pegados dentro del HTML. De ahí se conserva **sólo el `<p>`**; la voz
buena es la del contenido nuevo —impersonal, concreto, sin relleno—.

**Y la regla de reparto, que es la misma de la guía del huésped.** El texto del restaurante
temático decía «Cena a la carta en uno de los restaurantes temáticos del resort. Requieren reserva
previa y la disponibilidad se gestiona en recepción al llegar»: no decía **qué cocinas hay** —que
es todo el atractivo— y gastaba media descripción en el trámite. Lo que limita va al final.

Lo aplica `app:travel:redaccion-punta-cana`, que separa los tres trabajos: retitular, reescribir y
envolver en `<p>`.

⚠️ **El envoltorio parte por líneas en blanco, no envuelve el bloque entero.** Un texto con dos
párrafos metido en un solo `<p>` los funde, y ese cambio pasaría por corrección tipográfica.

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

### ⚠️ La dirección también viaja tras un « · », y ahí NO se puede generalizar (29/08/2026)

El traslado de Punta Cana quedó fusionado con **ocho** tarifas donde bastaban cuatro: «Auto · Del
aeropuerto de Punta Cana al alojamiento» y «Auto · Del alojamiento al aeropuerto de Punta Cana»,
mismo importe (0.00) y misma capacidad (3). Nacieron así porque el componente era direccional; al
unificarlo, esa coletilla dejó de distinguir nada —la dirección la pone el segmento— exactamente
igual que el «desde/hasta» de la sección anterior.

La tentación es evidente y **es la trampa**: cortar por el ` · ` y quedarse con lo de la
izquierda. No se puede. Ese separador tiene **dos significados** en el catálogo:

| Nombre | Qué separa el « · » | ¿Se puede cortar? |
|---|---|---|
| `Auto · Del aeropuerto de Punta Cana al alojamiento` | vehículo · **dirección** | sí, la dirección sobra |
| `Occidental Caribe - Punta Cana · Almuerzo buffet en resort` | prestador · **servicio** | **no**, se pierde qué se compra |
| `Escala en Lima · traslados y caminata por Miraflores` | lugar · **contenido** | **no** |

Una regla que cortara por el símbolo destrozaría los otros dos sin un solo error: los nombres
seguirían existiendo, sólo que diciendo menos, y no se notaría hasta leer una orden ya enviada.

Por eso el aplanado va **acotado al componente y a las dos frases concretas**, dentro de
`app:travel:completar-traslados-punta-cana`, y no como regla del símbolo. Después,
`app:travel:limpiar-tarifas-repetidas` junta las copias que quedan iguales: 8 → 4.

**La regla que queda:** cuando una fusión deja tarifas repetidas, lo que se generaliza es *que
hay que mirarlas*, no el patrón concreto del nombre. `desde/hasta` sí es un eje falso siempre;
un separador no significa nada por sí mismo.

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

### ⚠️ El tipo decide si la hora CUENTA, y una comida mal tipada hunde el servicio entero

`ComponenteTipoEnum::sinHorario()` decide si la UI pide hora. Y sin hora, el servicio cae al
escalón de «sin horario» del ordenamiento y **se va detrás de todo lo que sí la tiene** — el
servicio completo, no sólo la comida.

```
alimentacion_fijo       exige hora    el desayuno de las 07:00 antes de un vuelo
alimentacion_variable   sin hora      «almuerzo en algún momento del recorrido»
```

Las comidas del aeropuerto de Lima estaban en el segundo con hora puesta en el catálogo —07:00,
12:30, 19:00— que no contaba para nada. Corregidas con `app:travel:retipar-componente`.

⚠️ **Lo que NO se hizo**: cambiar `sinHorario()` para que `alimentacion_variable` exigiera hora.
Ese tipo existe justo para la comida que no la tiene, y tocarlo se habría llevado por delante los
almuerzos de excursión, que son la mayoría. **El tipo estaba bien definido; lo que estaba mal era
a cuál pertenecían estas tres.**

🔜 **Y quedan seis más con la misma forma**, sin tocar porque ahí la duda es real y sólo la
resuelve el operador:

```
Desayuno / Almuerzo / Cena buffet en resort    07:00 · 12:30 · 19:00
Cena en restaurante temático de resort         19:30
Almuerzo en Larcomar · Almuerzo en Urubamba    12:30
```

El buffet de un resort abre de 07:00 a 10:00 y el pasajero baja cuando quiere: esa hora es
**orientativa**. La del aeropuerto es una **cita**, porque hay un vuelo después. Misma columna,
dos significados.

### 🔜 PENDIENTE: `Bus` y `Minibús` son un problema de vocabulario, no de número

Quedaron fuera **a propósito**, y no por falta de datos: hay tarifas donde al **Sprinter se le
llama «minibús» de cara al cliente**, así que hoy los dos nombres no describen el mismo vehículo
en todo el catálogo. `Bus` sigue con 25, 27 y 45 declaradas.

Fijarles una capacidad ahora **congelaría la confusión en un número**, que es justo lo que costó
caro con las Van intercambiadas de Cusco. Primero se acuerda qué vehículo es cada uno; los
números vienen después.

## 3. Pool o plantilla

La segunda decisión, y **no son excluyentes**: toda plantilla se alimenta de un pool. La pregunta
es si además hace falta un **guion** que diga qué día va cada cosa y en qué orden.

| | Pool suelto | Pool + plantilla |
|---|---|---|
| Qué es | Un repertorio de piezas | Un guion con día y orden |
| Se arma | A medida, en cada cotización | Aplicando la plantilla y retocando |
| Entidades | los dos `*_pool` | + `TravelItinerario` + `TravelItinerarioSegmentoRel` |
| Cuándo | El contenido no tiene forma fija | El producto se vende siempre igual |

Medido el 31/08/2026 sobre los 25 servicios: **15 tienen plantilla y 10 no**, y la línea que los
separa es limpia:

```
CON plantilla   Valle Sagrado (3) · Combinada (3) · Vinicunca (3) · Machu Picchu (2)
                City Tour Lima (2) · Camino Inca Corto · Paracas y Huacachina · Walking…
                → excursiones y programas: el día y el orden SON el producto

SIN plantilla   Vuelo (32 segmentos) · Alojamiento (18) · Actividades en resort (12)
                Transporte en Cusco (8) · Coco Bongo · Varios en el aeropuerto de Lima…
                → repertorios: nadie compra «el catálogo de vuelos», compra dos vuelos
```

**La regla corta:** si al venderlo tienes que elegir *cuáles* y *en qué orden*, es un pool. Si el
orden ya está decidido y sólo cambia la fecha, es una plantilla.

⚠️ **Un servicio con varias plantillas es lo normal, no una anomalía.** Valle Sagrado tiene tres y
Machu Picchu dos: son **variantes de duración o de modalidad** del mismo producto, bebiendo de los
mismos pools. Por eso la plantilla vive en `TravelItinerario` y no en `TravelServicio` — ver
`docs/Travel.md` §1, que es la confusión más repetida del modelo.

⚠️ **Los dos pools se llenan, no sólo el de segmentos.** `TRF_LIM` tiene 2 segmentos en su pool y
**0 componentes**: se ve completo en la ficha del servicio y al cotizar no ofrece nada que cobrar.
Es el hueco más fácil de dejar, porque `addSegmento()` y `addComponente()` son dos llamadas y sólo
una salta a la vista.

## 4. La receta

Siete pasos. Los valores concretos de hora, `tarifaPredeterminada` y `horaServicioCompleto` están
en §5; aquí está **el orden y qué entidad toca cada paso**, que es lo que no se deduce mirando
EasyAdmin.

⚠️ **Un producto nuevo NO se crea a mano por el panel, y no es una preferencia de estilo.** Son
siete entidades en cuatro pantallas distintas, con un orden obligatorio y cuatro enlaces que no
dan error si faltan; una estadía de resort son ~40 formularios y basta olvidar un
`addComponente()` para que el producto se vea completo y no cobre nada. Por el panel se **retoca**
lo que ya existe —una hora, un texto, una foto—; para **crear** se escribe un comando, que además
deja el repertorio revisable en un diff y se puede volver a correr contra otra base. El panel
tampoco te salva de nada aquí: `#[Assert\Callback]` valida el formulario, pero ninguna de las
cuatro reglas de §4 bis mira si el pool quedó lleno.

```
1. TravelServicio          nombreInterno · titulo · codigo          ── flush
2. TravelSegmento          slug · nombreInterno · titulo · contenido
3. TravelComponente        nombreInterno · titulo · tipo            ── flush
4. TravelTarifa            componente · moneda · nombreInterno · titulo · monto
5. TravelSegmentoComponente   el pivote: une 2 con 3, y lleva los defaults
6. pools                   servicio->addSegmento() Y ->addComponente()
7. TravelItinerario        (sólo si §3 dijo plantilla) + una Rel por segmento
                                                                    ── flush
```

### Los pasos que se olvidan, y qué pasa

| Paso | Si falta | Cuándo te enteras |
|---|---|---|
| 4 · tarifa | El componente entra en la cotización **a cero** | Al facturar |
| 5 · pivote | El componente existe y **no hay forma de usarlo** | Al armar el día |
| 6 · pool de componentes | El segmento se arrastra **sin nada que cobrar** | Al cotizar |
| 7 · Rel | La plantilla existe y **sale vacía** | Al aplicarla |

Ninguno da error. Es la razón de ser de este documento.

### La hora vive en el PIVOTE, no en la plantilla

Es lo que más cuesta al venir de EasyAdmin, porque la pantalla del itinerario enseña días y
órdenes y parece el sitio natural de la hora. No lo es:

```
TravelItinerarioSegmentoRel     dia · orden          ← QUÉ DÍA va el segmento
TravelSegmentoComponente        dia · hora · horaFin ← A QUÉ HORA ocurre lo que se compra
```

La consecuencia práctica: **un segmento sin componente no puede tener hora**, y por eso en una
excursión —donde seis de siete segmentos no llevan componente— el orden dentro del día lo da el
`orden` de la Rel. Ver `docs/Cotizaciones.md` §6.u para el algoritmo completo.

### Cuando hay plantilla, el pivote se escribe DOS veces

No es duplicar: son dos afirmaciones distintas, y `itinerarioContexto` es lo que las separa.

| Relación | `itinerarioContexto` | `dia` | `hora` | `horaServicioCompleto` | Para qué |
|---|---|---|---|---|---|
| **global** | `null` | `null` | — | `false` | Que el componente exista en el pool y aporte su tarifa |
| **de plantilla** | el itinerario | 1 | 08:30 | `true` | Que ese día salga encabezado a esa hora |

Los cuatro cubos de la matriz están **todos en uso** hoy, así que ninguno es teórico:

```
ctx null · dia null   161   la logística global del pool          ← el caso normal (63%)
ctx  X   · dia null    37   sólo dentro de esa plantilla
ctx  X   · dia  2      40   sólo en esa plantilla y ese día
ctx null · dia  2      18   ese día, en cualquier plantilla
```

⚠️ **`horaServicioCompleto` EXIGE plantilla** (`validarPromocionRequierePlantilla`): una hora
global encabezaría el día en todos los tours a la vez. Así que la promoción sólo cabe en la
segunda fila, y si escribes sólo la global el día pierde su hora de cabecera **sin fallar**: cae
al desempate por `orden` y nadie lo nota hasta ver la guía.

### El esqueleto de los pasos 5 a 7

Tal cual en `app:travel:crear-escala-miraflores`, que es el comando de referencia con plantilla:

```php
// 5 y 6 · el pivote global, y los dos pools
$servicio->addSegmento($segmento);
$servicio->addComponente($componente);

$this->em->persist((new TravelSegmentoComponente())
    ->setSegmento($segmento)->setComponente($componente)
    ->setModo(ComponenteModoEnum::INCLUIDO)
    ->setTarifaPredeterminada($tarifa)
    ->setDia(1)->setOrden(1)
    ->setHora(new \DateTimeImmutable('08:30')));

// 7 · la plantilla, y una Rel por segmento en el orden en que se cuentan
$itinerario = (new TravelItinerario())
    ->setServicio($servicio)
    ->setNombreInterno('…')->setTitulo([['language' => 'es', 'content' => '…']])
    ->setDuracionDias(1);
$this->em->persist($itinerario);

foreach ($segmentos as $pos => $seg) {
    $this->em->persist((new TravelItinerarioSegmentoRel())
        ->setItinerario($itinerario)->setSegmento($seg)
        ->setDia(1)->setOrden($pos));
}

// y el pivote DE PLANTILLA del ancla, el único que puede promover la hora
$this->em->persist((new TravelSegmentoComponente())
    ->setSegmento($anclaSeg)->setComponente($anclaComp)
    ->setItinerarioContexto($itinerario)
    ->setTarifaPredeterminada($anclaTarifa)
    ->setDia(1)->setOrden(1)
    ->setHora(new \DateTimeImmutable('08:30'))
    ->setHoraServicioCompleto(true));
```

La colección de `TravelItinerario::$itinerarioSegmentos` viene ordenada por `['dia' => 'ASC',
'orden' => 'ASC']` desde el mapeo, así que **el orden es del ORM**: no hay que reordenar en PHP ni
en el front.

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

## 4 bis. El contrato de escritura de cada entidad

Lo que hay que saber para escribir un comando de carga **sin abrir las entidades**: qué exige
cada tabla al crear una fila, con qué se reconoce una que ya existe, y qué se dispara al guardar.

### La tabla

| Entidad | Obligatorio al crear | Clave natural (idempotencia) | ¿La protege la BD? | Se dispara al guardar |
|---|---|---|---|---|
| `TravelServicio` | `nombreInterno`, `titulo` | `codigo` | **no** | AutoTranslate → `titulo` |
| `TravelSegmento` | `nombreInterno`, `titulo`, `contenido` | `slug` | **no** | AutoTranslate → `titulo` (text) y `contenido` (**html**) |
| `TravelComponente` | `nombreInterno`, `titulo`, `tipo` | `nombreInterno` | **no** | AutoTranslate → `titulo` |
| `TravelTarifa` | `componente`, `moneda`, `nombreInterno`, `titulo`, `monto` | **ninguna** — la hereda del padre, ver abajo | **no** | AutoTranslate → `titulo` |
| `TravelOrganizacion` | `nombreComercial`, `titulo`, `descripcion` | `nombreComercial` | **no** | AutoTranslate → `titulo`, `descripcion` |
| `TravelOrganizacionServicio` | `organizacion`, `nombre`, `titulo`, `descripcion` | `(organizacion, nombre)` | **no** | AutoTranslate → `titulo`, `descripcion` |
| `TravelItinerario` | `servicio`, `nombreInterno`, `titulo`, `duracionDias` | `nombreInterno` | **no** | AutoTranslate → `titulo` |
| `TravelSegmentoComponente` | `segmento`, `componente`, `modo`, `orden` | `(segmento, componente, itinerarioContexto, dia)` | **no** | `TravelSegmentoComponentePromocionUnicaListener` (`onFlush`) |
| `TravelItinerarioSegmentoRel` | `itinerario`, `segmento`, `dia`, `orden` | `(itinerario, segmento, dia)` | **no** | — |
| `TravelLugar` | `nombre` | `nombre` | **sí**, índice único | — |
| `TravelPunto` | `nombre` | `nombre` | **sí**, índice único | — |
| pools (`*_pool`) | las dos columnas | la pareja entera | **sí**, es la PK | — |

`titulo` y `descripcion` son `json` **NOT NULL**: aceptan `[]`, no `null`. Un `setTitulo([])` pasa
la base y luego lo rechaza `#[Assert\Callback]` al validar (§ más abajo), así que si escribes por
comando —donde nadie valida— pasa entero y sale un recurso sin nombre público.

⚠️ **`codigo` y `slug` son las claves naturales del catálogo y la base los deja NULOS.** Medido:
25 de 25 servicios tienen `codigo` y 186 de 186 segmentos tienen `slug`, o sea que la convención
se cumple al 100% — pero la cumple la costumbre, no un `NOT NULL`. Un comando que cree un servicio
sin código no falla: crea uno que ninguna carga posterior podrá encontrar, y que por tanto se
duplicará la próxima vez.

⚠️ **La tarifa no tiene clave natural, y no es un olvido: es que su nombre no identifica.** Un
componente puede tener quince tarifas legítimas cuyos nombres se parecen —modalidad, capacidad,
sentido— así que buscar «la tarifa que ya existe» por texto acertaría a veces. Los comandos de
creación resuelven esto **cortando antes**: si el segmento o el componente padre ya existe hacen
`continue` y nunca llegan a la tarifa. La consecuencia hay que tenerla presente: **si tu comando
crea tarifas sobre un componente que ya existía, no hay nada que te proteja de duplicarlas**, y
por eso existe `app:travel:limpiar-tarifas-repetidas`.

⚠️ **Los setters de `TravelTarifa` NO se encadenan todos.** Es la única entidad del módulo con la
convención mezclada: `setMonto()` y `setModalidad()` devuelven `self`, pero `setComponente()`,
`setNombreInterno()`, `setTitulo()` y `setMoneda()` devuelven `void`. Encadenarlos revienta con un
fatal en tiempo de ejecución —no lo caza `php -l`—, así que en las tarifas se escribe una llamada
por línea. En el resto de entidades del módulo sí se puede encadenar.

### ⚠️ La idempotencia NO la protege nadie, y por eso hay seis comandos de limpieza

De catorce tablas, **sólo dos tienen restricción única**: `travel_lugar.nombre` y
`travel_punto.nombre`. Ni el `slug` del segmento, ni el `codigo` del servicio, ni el
`nombreInterno` del componente, ni el `nombreComercial` de la organización lo tienen.

```sql
-- la comprobación, si dudas de una tabla concreta
SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME)
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND TABLE_NAME LIKE 'travel_%'
 GROUP BY TABLE_NAME, INDEX_NAME;
```

Consecuencia, y es la regla de esta sección: **la idempotencia de una carga vive entera en el
`findOneBy()` de tu comando**. Si se te olvida, correrlo dos veces duplica el catálogo en silencio
—sin error, sin log— y el duplicado sólo se nota semanas después, cuando alguien cotiza el que no
tenía tarifas.

Que esto ya mordió está escrito en el propio repositorio: `limpiar-tarifas-repetidas`,
`fusionar-transportes-bidireccionales`, `fusionar-rutas-por-puntos`, `fusionar-tarifas-por-sentido`,
`resolver-tarifas-divergentes` y `consolidar-transporte-aeropuerto-lima` existen **sólo** para
deshacer duplicados. Limpiar sale mucho más caro que comprobar antes de insertar, porque para
entonces hay cotizaciones colgando de las dos copias.

**Los pools son la excepción cómoda**: su PK es la pareja, así que `addSegmento()` dos veces no
duplica. Es el único sitio donde puedes ser descuidado.

### El orden de creación

Las flechas son «no puede existir sin»:

```
TravelLugar ─┐                    TravelOrganizacion
TravelPunto ─┤                             │
             │                             ▼
             │                  TravelOrganizacionServicio   (organizacion NOT NULL)
             │                             │
             ▼                             │  prestador / prestadorServicio / comprador
        TravelSegmento                     │  (los tres OPCIONALES)
             │                             │
             │                             ▼
             │                       TravelTarifa ──► TravelComponente  (componente NOT NULL)
             │                                              ▲                MaestroMoneda
             │                                              │                (NOT NULL)
             └──────► TravelSegmentoComponente ─────────────┘

TravelServicio ──► TravelItinerario ──► TravelItinerarioSegmentoRel ──► TravelSegmento
        └── pools: se llenan al final, cuando las dos puntas existen
```

En un comando eso se traduce en cinco `flush()` como mucho, y sólo hacen falta donde una entidad
nueva es **obligatoria** para la siguiente:

```
1. organización            → flush   (el servicio de prestador la exige NOT NULL)
2. servicios de prestador  → flush
3. servicio + itinerario   → flush
4. segmentos y componentes → flush   (el pivote y la tarifa los exigen)
5. pivotes, tarifas, pools → flush
```

### Lo que rechaza el guardado (y lo que no)

Cuatro `#[Assert\Callback]` gobiernan este módulo. Importan por lo que **no** hacen:

| Regla | Dónde | Qué impide |
|---|---|---|
| El título público en español es obligatorio | `TravelSegmento`, `TravelServicio`, `TravelItinerario` | Un recurso sin nombre de cara al cliente |
| Punto fijo marcado pero sin elegir cuál | `TravelSegmento::validarPuntos()` | Un extremo declarado y vacío |
| Un `ALOJAMIENTO` no lleva hora | `TravelSegmentoComponente::validarAlojamientoSinHora()` | Que una estadía se parta por días en la guía |
| `horaServicioCompleto` exige plantilla | `TravelSegmentoComponente::validarPromocionRequierePlantilla()` | Una hora global chocando en todos los tours |
| El servicio elegido es del prestador **de esa tarifa** | `TravelTarifa::validarConsistenciaLogica()` | «Hotel A» con «habitación del Hotel B» |

⚠️ **Ninguna se aplica a un comando.** `#[Assert\Callback]` sólo corre cuando alguien llama al
`Validator` —el formulario de EasyAdmin, el procesador de API Platform—, y un `persist()` + `flush()`
en consola **no lo llama**. La lista de arriba no es lo que te protege: es lo que tienes que
comprobar tú antes de escribir, y por eso está aquí y no en la entidad.

Lo que sí corre siempre, porque son listeners de Doctrine y no validaciones: **AutoTranslate** y
`TravelSegmentoComponentePromocionUnicaListener`.

### El esqueleto

Lo que comparten todos los comandos de `src/Travel/Command/`:

```php
#[AsCommand(name: 'app:travel:crear-<algo>', description: '…')]
final class CrearAlgoCommand extends Command
{
    private const ORG_NOMBRE = '…';           // claves naturales como constantes:
    private const SERVICIO_CODIGO = '…';      // el comando y su verificación las comparten

    /** @var list<array{slug: string, nombre: string, titulo: string, contenido: string}> */
    private const SEGMENTOS = [ /* el repertorio, como DATO */ ];

    public function __construct(private readonly EntityManagerInterface $em) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin tocar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simula = (bool) $input->getOption('dry-run');

        foreach (self::SEGMENTOS as $def) {
            // 1. ¿ya está? — la única defensa contra duplicar, ver arriba
            if ($this->em->getRepository(TravelSegmento::class)->findOneBy(['slug' => $def['slug']])) {
                $io->text(sprintf('  ya existe · %s', $def['slug']));
                continue;
            }

            // 2. informar SIEMPRE, escribir sólo si no se simula
            $io->text(sprintf('  %s · %s', $simula ? 'crearía' : 'creado ', $def['slug']));
            if ($simula) { continue; }

            $this->em->persist(/* … */);
        }

        if (!$simula) { $this->em->flush(); }

        return Command::SUCCESS;
    }
}
```

Tres rasgos que no son estilo:

- **El repertorio es una constante, no un bucle que lo deduce.** Un catálogo escrito a mano se
  revisa leyendo el diff; uno generado hay que ejecutarlo para saber qué hace.
- **`--dry-run` imprime lo mismo que la pasada real**, con el verbo en condicional. Si sólo
  imprimiera un resumen, la simulación dejaría de servir para revisar.
- **`ya existe` se imprime, no se calla.** Es lo que convierte la segunda pasada en una
  verificación: si esperabas siete «ya existe» y salen seis, hay algo que se borró.

Y el `continue` del paso 1 es lo que hace idempotente **todo lo que cuelga** —pivote, tarifas,
pools— sin comprobarlo pieza a pieza. Es cómodo y tiene un filo: si algún día ese comando tiene
que añadir una tarifa a un componente que ya existe, el `continue` la saltará en silencio y habrá
que darle su propia comprobación.

### Apagar la traducción: cuándo sí y cuándo no

`ejecutarTraduccion` **no está mapeado y arranca en `true`**, así que cualquier `flush()` sobre una
fila con campos `#[AutoTranslate]` dispara el traductor de esa fila entera.

| Tu comando… | Traducción | Por qué |
|---|---|---|
| crea o reescribe **contenido publicado** | **encendida** | El cliente lo lee en su idioma: las siete lenguas son el trabajo |
| sólo mueve **ids o flags** (prestador, comprador, lugar) | **apagada** | Poner un id no cambia ningún texto; traducir sería gasto y ruido |
| toca sólo `nombreInterno` o `slug` | **apagada** | No llevan `#[AutoTranslate]`, pero el `flush()` arrastra los que sí |

```php
$entidad->setEjecutarTraduccion(false);   // vía AutoTranslateControlTrait
```

Ejemplos vivos: `app:travel:comprador-trenes` la apaga (69 tarifas, ni un texto tocado);
`app:travel:crear-habitaciones-occidental` la deja encendida y avisa de que tarda.

### La capa de prestador: dónde se engancha y cuántas piezas hacen falta

Es la mitad que este documento no cubría, y la que decide si el producto sale **con cara** en
`/pax`. El enganche completo son cuatro piezas y una bandera:

```
TravelOrganizacion                el hotel, el operador, la ferroviaria
   │  nombreComercial · titulo(i18n) · descripcion(i18n) · visibleParaCliente
   │
   ├── TravelOrganizacionImagen           fotos de la EMPRESA
   │
   └── TravelOrganizacionServicio         lo que se contrata de ella
          │  nombre · titulo(i18n) · descripcion(i18n)
          └── TravelOrganizacionServicioImagen    fotos de ESE servicio
                        ▲
                        │  prestador_servicio_id
                  TravelTarifa  ──prestador_id──►  TravelOrganizacion
                                └─comprador_id──►  TravelOrganizacion (a quién se le encarga)
```

**Los tres papeles cuelgan de la TARIFA, no del componente.** Un componente puede tener tarifas de
empresas distintas —«Tren Ollanta Mapi» tiene 12 de PeruRail y 4 de IncaRail—, así que un
prestador arriba sería falso sobre todas menos una. El campo estuvo en el componente seis días y
volvió; la historia completa, en `docs/Travel.md` §11.

**Cuántos servicios de prestador crear.** No uno por tarifa: uno por **galería distinta**. Las
cuatro tarifas de check-in/check-out comparten «Lobby y recepción» porque las ilustran las mismas
fotos; si mañana el check-out mereciera foto propia, se le crea su servicio y se repunta esa
tarifa. Es barato, y al revés —crear uno por tarifa— obliga a subir las mismas fotos cuatro veces.

⚠️ **Evita los cajones de sastre.** Un servicio llamado «El resort» o «La empresa» parece útil y no
lo es: como galería sólo repite lo que ya enseñan los demás, y como *servicio contratado* de una
línea no afirma nada, porque nadie contrata «el resort». Lo que se contrata en un hotel es **la
habitación**. El que hubo se reconvirtió en la estándar el 31/08/2026 en vez de borrarse, para no
dejar huérfana la tarifa que colgaba de él — ver `docs/Travel.md` §11.quater.

**La bandera es opt-in y arranca en `false`.** `visibleParaCliente` decide si a esa empresa se le
puede nombrar delante del cliente, y **de ella cuelgan también las fotos y la descripción**: el
normalizer público no inyecta ninguna de las tres si está apagada, porque una foto de la piscina
identifica al hotel igual de bien que su nombre. Si cargas un prestador cuyas fotos deben salir,
enciéndela en el comando.

⚠️ **Y la bandera del catálogo es una SEMILLA, no un veto vivo.** Quien decide en cada propuesta es
`CotizacionCotcomponente::prestadorVisible`, que se copió al asignar el prestador. Encenderla en el
catálogo **no destapa** propuestas ya armadas: hay que reasignar el prestador en esas líneas. Ver
`docs/Cotizaciones.md` §6.c.

**Orden de carga**, que es el de las dependencias: organización → `flush` → sus servicios →
`flush` → las tarifas que los apuntan. Y la validación cruzada que aquí no corre —`#[Assert]` no se
ejecuta en consola— es que el `prestadorServicio` de una tarifa **pertenezca a su `prestador`**;
compruébala con la consulta 3 de abajo.

Comando de referencia: `app:travel:crear-habitaciones-occidental` (servicios de prestador puros) y
`app:travel:crear-actividades-resort` (la cadena entera, de la organización a las tarifas).

### Verificar una carga desde SQL

Las tres consultas que contestan «¿quedó completo?», en el orden en que se descubren los huecos:

```sql
-- 1. ¿los títulos salieron traducidos? (7 = completo; 1 = se escapó del ORM)
SELECT nombre_interno, JSON_LENGTH(titulo) FROM travel_segmento WHERE slug LIKE 'ACT-RESORT-%';

-- 2. ¿algún componente sin precio? — entra en la cotización en cero y nadie lo nota
SELECT c.nombre_interno FROM travel_componente c
  LEFT JOIN travel_tarifa t ON t.componente_id = c.id
 WHERE t.id IS NULL;

-- 3. ¿alguna tarifa con servicio de otra empresa? — lo que el Validator NO comprobó
SELECT t.nombre_interno FROM travel_tarifa t
  JOIN travel_organizacion_servicio s ON s.id = t.prestador_servicio_id
 WHERE t.prestador_id IS NOT NULL AND s.organizacion_id <> t.prestador_id;
```

La tercera es la que sólo hace falta si cargaste por comando: por el panel la habría impedido
`validarConsistenciaLogica()`. Hoy devuelve cero, que es lo que debe devolver siempre.

⚠️ **La segunda no devuelve cero, y conviene saberlo antes de usarla como semáforo.** Medido el
31/08/2026: **45 componentes sin ninguna tarifa** —27 de transporte, 16 de vuelo y 2 de tren—.
Es deuda anterior, no la deja tu carga, así que la forma de usarla es **comparar el antes y el
después de tu comando**, no esperar una lista vacía. Un componente sin tarifa entra en la
cotización a cero y nadie lo nota.

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

#### ⚠️ …salvo que el tipo sea `sinHorario`: ahí la hora del catálogo NO llega (30/08/2026)

`CotizacionCotcomponente::normalizarHorarioAlCrear()` es un `#[ORM\PrePersist]` que pone inicio y
fin a `00:00:00` en todo componente cuyo tipo cumpla `ComponenteTipoEnum::sinHorario()`. Así que
en `EXTRAS`, `ALOJAMIENTO`, `TICKET_HORARIO_VAR`, `ALIMENTACION_HORARIO_VAR` y `PERSONAL_EXTRA`,
**la hora que pongas en el enlace de catálogo no sobrevive a la inserción**. Esos van a tier 1 y
se ordenan por `orden`, pase lo que pase.

Medido en producción, agrupando por tipo:

| | componentes | con hora real |
|---|---|---|
| tipos `sinHorario` (`extras`, `alojamiento`, `ticket_variable`, `alimentacion_variable`) | 79 | **0** |
| tipos con horario (`transporte`, `vuelo`, `pool`, `guiado`…) | 140 | 139 |

Cero de setenta y nueve. No es una tendencia, es el listener.

**Consecuencia práctica:** una hora en el catálogo sobre un tipo `sinHorario` es un valor muerto
que además engaña — el siguiente que lo lea creerá que significa algo y ajustará el número
equivocado. Las actividades de resort tenían 10:00, 16:00 y 21:30 escritos, y las cinco que había
en la cotización estaban a `00:00:00`.

`app:travel:quitar-hora-a-extras` las vacía, con un guarda que **comprueba el tipo antes de
escribir**: si algún día el componente se retipa a uno con horario, la hora vuelve a mandar y el
comando lo salta en vez de borrarla.

🔜 Quedan **8 enlaces** con hora muerta en el catálogo: 6 de `extras` (check-in y check-out de
mañana y tarde, día libre, espectáculo) y 2 de `ticket_variable`. Se vacían cuando se decida que
ninguno necesita colocarse por hora — porque **para colocarlos hay que usar `orden`, no la hora**.

### ⚠️ Y dale también hora de FIN, o nace con duración cero (30/08/2026)

`hora` y `horaFin` son campos distintos de `TravelSegmentoComponente`, y **poner sólo el primero
es lo que sale por defecto al cargar catálogo**. El componente entra en la cotización con
`fin = inicio`.

Eso **no rompe nada visible**, que es el problema: se ordena por su hora igual que cualquier
otro, sale en la propuesta, no da error. Sólo miente sobre cuánto dura — que es justo el dato por
el que pregunta quien lee «desayuno 07:00».

Estaba así en **las cuatro comidas de resort** y en todas las actividades de resort. En el
expediente de Punta Cana se vio porque el operador estuvo corrigiendo las mismas tres horas a
mano dos días seguidos: el catálogo no le daba el rango, así que lo escribía cada vez.

`app:travel:horario-comidas-resort` puso la franja que publica el resort:

| Comida | antes | ahora |
|---|---|---|
| Desayuno buffet | 07:00 → — | 07:00 – 10:30 |
| Almuerzo buffet | 12:30 → — | 12:30 – 15:00 |
| Cena buffet | 19:00 → — | **18:30** – 22:00 |
| Cena temática | 19:30 → — | **18:30** – 22:00 |

Dos cosas que dejó claras el caso:

- **La hora de inicio también estaba mal, y en la misma dirección.** Las tres comidas del catálogo
  iban más tarde que la realidad. Cuando un operador corrige sistemáticamente el mismo campo hacia
  el mismo lado, el defecto está en el catálogo, no en su criterio.
- **Cambiar el catálogo NO toca lo ya cotizado.** `CotizacionCotcomponente` congela la hora al
  insertar, así que un expediente abierto conserva la que tenía. Arreglar el catálogo evita el
  siguiente error, no el anterior; ése se corrige en la cotización, a mano o por comando.

**Las actividades de resort no necesitan franja, y tampoco hora.** Son `extras`, o sea
`sinHorario`, así que su horario nunca llega a la cotización (ver el aviso de §5 más arriba).
Piscina y recreativas se vaciaron; el resto sigue con la hora muerta puesta.

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

### ⚠️ «Por día» NO es un campo de la tarifa: los días van en la cantidad del COMPONENTE

Un precio diario —un seguro de viaje a 8 USD, un alquiler por jornada— parece pedir un campo
`porDia` en `TravelTarifa`, y no existe. El cálculo, en
`util/src/stores/cotizacion/cotizacionEditorStore.ts` y `docs/Cotizaciones.md` §6.g.2, es:

```
costoTotal = monto × (esGrupal ? 1 : cantidadTarifa) × cantidadComponente
```

`cantidadComponente` es un **multiplicador libre** de la línea de cotización, y ése es el sitio de
los días. La tarifa se carga con el precio de **una unidad**: 8.00 con `costoPorGrupo = false`
—o sea, por pasajero— y el operador pone 5 en la cantidad del componente para cinco días.
Cinco días con tres pasajeros son 8 × 3 × 5.

⚠️ **`costoPorGrupo = true` habría dado el mismo número en la ficha y otra factura.** En grupal el
monto **ya es el total**, así que 8 dejaría de ser el precio por persona y por día para ser el
precio del grupo entero.

⚠️ **Y nada escribe los días solo.** El multiplicador se teclea al armar la cotización: cargar la
tarifa no basta para que un producto diario cobre por días. Si el operador lo deja en 1, cobra un
día y no falla nada.

### El tipo de un producto multi-día que NO ubica al pasajero

Un bloque se lee como periodo cuando **no tiene horas** y termina en fecha posterior
(`esEstadia`, en `dominio/cotizacion/itinerarioVista.ts`). Eso deja elegir entre los tipos con
`sinHorario() = true`, y la elección no es indiferente:

| Tipo | Multi-día | `esAnclaDeUbicacion()` | Para qué |
|---|---|---|---|
| `ALOJAMIENTO` | sí | **sí** | dice dónde DUERME el pasajero cada noche |
| `EXTRAS` | sí | no | algo que acompaña al viaje sin situarlo |

⚠️ **Un seguro, una tarjeta de datos o cualquier cobertura que dure todo el viaje va en `EXTRAS`.**
Con `ALOJAMIENTO` también saldría multi-día —y por eso se cae en la trampa— pero además declararía
que el pasajero *está* ahí: de los alojamientos encadenados se deducen los puntos de recojo de
todo lo demás, así que un seguro puesto como alojamiento envenenaría el «dónde recojo» de los
traslados de esos días. Sin error, como todo lo caro de este módulo.

Lo aplica `app:travel:crear-seguro-de-viaje`.

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

**Un segmento creado por el PANEL nace sin componente, y nada lo dice.** El CRUD deja escribir el
párrafo entero —slug, título, cuerpo, traducido a siete idiomas— y ahí acaba: sin componente no
hay nada que cobrar ni que despachar. El párrafo se guarda, se puede arrastrar a un día y se ve
completo en la ficha del servicio. Pasó el 01/09/2026 con «Discoteca Privada» y «Olimpiadas y
Roca Escaladora», los dos con 0 componentes.

⚠️ **Y el comando grande NO los completa.** `crear-actividades-resort` es idempotente **por el
slug del segmento**: encuentra el párrafo, hace `continue` y nunca llega a crear lo que le falta.
No es un fallo suyo —es el atajo que hace idempotente todo lo que cuelga, ver §4 bis— pero
convierte «lo relanzo y ya» en una pasada que no hace nada y parece que sí.

**La salida es un comando aparte cuya clave de idempotencia sea el ENLACE, no el slug:**

```php
if (!$segmento->getSegmentoComponentes()->isEmpty()) { continue; }   // ya tiene algo colgando
```

Referencia: `app:travel:crear-componentes-resort-sueltos`. Y copia el `titulo` **del segmento** al
componente y a la tarifa en vez de reescribirlo: el párrafo del panel ya viene traducido a siete
idiomas, y escribirlo a mano lo haría nacer sólo en español.

Se detecta con esta consulta, gemela de la 2 de §4 bis:

```sql
SELECT s.slug FROM travel_segmento s
  LEFT JOIN travel_segmento_componente sc ON sc.segmento_id = s.id
 WHERE sc.id IS NULL;
```

⚠️ **Pero NO es un semáforo de cero, y usarla como tal hace daño.** Devuelve 36, y la inmensa
mayoría **están bien así**: en una excursión sólo el ancla lleva componente y los demás párrafos
sólo cuentan (§2). El reparto lo dice todo —`VALLE_VIP` 10, `VALLE_SAGRADO` 8, `PARACAS_ICA` 8,
`CITY_LIM` 6—: son exactamente los servicios montados con ancla.

Quien la lea como una lista de errores «arreglará» segmentos que deben estar vacíos y acabará
duplicando el componente del ancla. La forma de usarla es **mirar sólo los servicios de patrón
un-componente-por-segmento** —`ACT_RESORT`, `VUELO`, `ALO`, los de transporte— o comparar el antes
y el después de tu carga.

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
□ ¿la tarifa lleva prestador Y su servicio?    sin los dos no hay foto ni nombre en /pax
□ ¿el servicio del prestador es DE ese prestador?   (§4 bis, consulta 3)
□ ¿los servicios del prestador tienen imágenes?     sin ellas el segmento sale desnudo
□ correrlo DOS veces: la segunda debe decir «ya existe» en todo
```

Las tres últimas son las que se olvidan — y la última es la más barata: **la segunda pasada es la
prueba de idempotencia**, y como ninguna clave natural está protegida por la base (§4 bis), es la
única que hay.

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
| `app:travel:crear-habitaciones-occidental` | servicios de prestador puros · **reconvierte** uno existente en vez de borrarlo, para no dejar huérfana la tarifa que colgaba de él |
| `app:travel:separar-buffet-occidental` | partir un servicio de prestador en varios · reconvertir **y reapuntar** las tarifas que se quedan atrás, buscándolas por componente y no por nombre |
| `app:travel:crear-componentes-resort-sueltos` | completar segmentos que ya existen · idempotencia por el **enlace** y no por el slug, que es lo que el comando grande no puede hacer |
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
| `app:travel:fusionar-rutas-por-puntos` | emparejar por dato y no por prosa · construir el nombre desde la fuente de la verdad |
| `app:travel:completar-traslados-punta-cana` | poner la ciudad en los **dos** extremos del título · aplanar la dirección de sus tarifas |
| `app:travel:horario-comidas-resort` | dar franja (inicio **y fin**) a lo que el catálogo dejó con duración cero |
| `app:travel:quitar-hora-a-extras` | quitar la hora a un tipo que no la usa, **comprobando el tipo antes de escribir** |
| `app:travel:crear-traslado-coco-bongo` | clonar una flota en vez de recopiarla · reparar lo que una pasada anterior dejó a null |
| `app:travel:redaccion-punta-cana` | el marcado como contrato del campo, no como gusto · separar retitular de reescribir |
| `app:travel:renombrar-componente` | escribir sólo el español y dejar traducir al listener |
| `app:travel:crear-seguro-de-viaje` | la receta de §4 **entera** en un comando · producto diario sin campo «por día» · `EXTRAS` para lo multi-día que no ubica |
