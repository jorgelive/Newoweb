# Plan — procesamiento compartido entre back y front

Cómo se construye la primera pieza de lógica que ejecutan **el navegador y el servidor**, usando
el PDF del itinerario como piloto, y por qué se hace más de lo que el PDF necesita.

**Alcance:** plan de ejecución con fases, criterios de «hecho» y lo que se deja fuera a propósito.
La arquitectura y su porqué están en `docs/NodeEnElStack.md`; aquí está el orden y el trabajo.

**Estado:** redactado el 02/09/2026. Fase 0 en curso.

---

## Índice

1. [El criterio](#1-el-criterio)
2. [Por qué el PDF es el piloto correcto](#2-por-qué-el-pdf-es-el-piloto-correcto)
3. [Las seis costuras](#3-las-seis-costuras)
4. [Fase 0 — cerrar lo abierto](#fase-0--cerrar-lo-abierto)
5. [Fase 1 — la red antes que nada](#fase-1--la-red-antes-que-nada)
6. [Fase 2 — la casa](#fase-2--la-casa)
7. [Fase 3 — el contrato](#fase-3--el-contrato)
8. [Fase 4 — la frontera PHP ↔ Node](#fase-4--la-frontera-php--node)
9. [Fase 5 — el generado único](#fase-5--el-generado-único)
10. [Fase 6 — lo caro](#fase-6--lo-caro)
11. [Los módulos transversales: cómo llegan](#11-los-módulos-transversales-cómo-llegan)
12. [Qué NO se construye, y por qué no obliga a rehacer](#12-qué-no-se-construye-y-por-qué-no-obliga-a-rehacer)
13. [Cómo saber que el plan va bien](#13-cómo-saber-que-el-plan-va-bien)

---

## 1. El criterio

**Las costuras ahora; las implementaciones, cuando hagan falta.**

Una *costura* es un sitio donde dos cosas se tocan: dónde vive el código, qué forma tiene el dato
que cruza, quién valida, qué pasa cuando falla, cómo se entera alguien. Cambiar una costura obliga
a tocar a todos sus lados. Una *implementación* es lo que hay detrás de la costura, y sustituirla
no se nota fuera.

De ahí sale el reparto que evita el «esto había que rehacerlo»:

| Se decide AHORA (costura) | Se difiere (implementación) |
|---|---|
| Dónde vive el módulo compartido | Cuántos módulos hay |
| Qué forma tiene el dato que cruza y quién la valida | Si el transporte es spawn o HTTP |
| Qué pasa cuando el otro lado falla | Si hay caché, reintentos o cola |
| Cómo se entera un humano de que falló | Qué panel lo enseña |
| Que los tests sean un candado, no una costumbre | Cuántos tests hay |

⚠️ **La trampa que este plan evita** es la contraria a la que parece. No es «hacer poco y tener que
rehacer»: es hacer **mucha implementación** —un servicio HTTP, un demonio, un monorepo— antes de
que ninguna costura esté decidida. Eso da una casa grande con las puertas en el sitio equivocado, y
es lo que de verdad se rehace entero.

## 2. Por qué el PDF es el piloto correcto

El PDF ya está resuelto para el cliente: se imprime desde la propia página
(`docs/Cotizaciones.md` §6.u). Eso **se queda** — es lo que el cliente usa hoy y no depende de nada
de este plan.

Lo que vuelve como piloto es el **PDF generado en el servidor**, que tiene un uso que la hoja de
impresión no cubre: **adjuntarlo a un correo o a un mensaje**. Nadie puede pulsar «Imprimir» desde
el navegador del pasajero cuando el que manda el itinerario es el operador — o el agente.

Y es el piloto correcto por tres razones, en este orden:

1. **Ejercita las seis costuras enteras.** PHP tiene que pedirle a Node un cálculo, mandarle un
   dato, validarlo, recibir una respuesta, sobrevivir a que falle y dejar rastro. No hay atajo que
   se salte ninguna.
2. **Si se rompe, no cuesta dinero.** Un itinerario mal paginado es una molestia. La misma
   arquitectura estrenada con `resumenFinanciero` se estrena sobre márgenes.
3. **Ya sabemos exactamente cómo se rompe.** El servicio en PHP daba 11 días donde hay 16, y ese
   caso ya está congelado en un test. El piloto arranca con su propia regresión conocida.

⚠️ **Y hay una tentación que hay que nombrar para no caer en ella:** el PDF de servidor «se podría»
hacer replicando la composición en PHP, y sería más corto. Es exactamente lo que se descartó
—`docs/Cotizaciones.md` §6.u— y lo que convertiría el piloto en la cuarta copia de una regla.

## 3. Las seis costuras

Lo que este plan fija de una vez. Cada fase de abajo construye una o dos.

```
   ┌─ 1. UBICACIÓN ──────────────────────────────────────────────┐
   │  dominio/ en la raíz · alias en las dos apps · regla ESLint  │
   └──────────────────────────────────────────────────────────────┘
   ┌─ 2. CONTRATO ───────────────────────────────────────────────┐
   │  entrada estrecha por rol · el esquema ES el tipo (Zod)      │
   └──────────────────────────────────────────────────────────────┘
   ┌─ 3. INVOCACIÓN ─────────────────────────────────────────────┐
   │  UNA clase en PHP · una operación = una clase (autolocalizada)│
   └──────────────────────────────────────────────────────────────┘
   ┌─ 4. FALLO ──────────────────────────────────────────────────┐
   │  política explícita por consumidor · nunca resultado parcial │
   └──────────────────────────────────────────────────────────────┘
   ┌─ 5. RASTRO ─────────────────────────────────────────────────┐
   │  canal de log propio · duración y operación en cada llamada  │
   └──────────────────────────────────────────────────────────────┘
   ┌─ 6. CANDADO ────────────────────────────────────────────────┐
   │  los tests bloquean el despliegue, no dependen de la memoria │
   └──────────────────────────────────────────────────────────────┘
```

---

## Fase 0 — cerrar lo abierto

Higiene, no arquitectura. Sin esto el resto se construye sobre trabajo a medias.

| | Acción | Hecho cuando |
|---|---|---|
| 0.1 | Ver la **vista previa de impresión** real: días que no se parten, modo catálogo, móvil | Un día de siete paradas cabe entero o empieza en hoja nueva |
| 0.2 | Decidir `.claude/launch.json` (se versiona o se ignora) | No aparece en `git status` sin querer |
| 0.3 | Commit por rutas nombradas | `git status` limpio |
| 0.4 | Desplegar: `pull` → `composer dump-autoload` → `migrate` → build de `pax` → **`pax:textos:itinerario`** | El botón dice «Print» en una propuesta en inglés de producción |

⚠️ **0.1 es el único riesgo sin medir de lo ya escrito.** La simulación que se hizo inyectando las
reglas `@media print` no puede mostrar `break-inside: avoid`: la paginación sólo existe en el
pipeline de impresión de verdad.

⚠️ **0.4 tiene un paso nuevo que nadie tiene en los dedos.** La cadena `cot_imprimir` sólo existe
en la base local. Sin ese comando el botón sale en castellano en los siete idiomas **y no falla
nada** — la familia de fallo mudo de siempre.

## Fase 1 — la red antes que nada

Costura **6**. Va antes que todo lo demás porque a partir de la fase 2 se mueve código de sitio, y
mover código sin red es exactamente lo que §5 prohíbe.

| | Acción | Hecho cuando |
|---|---|---|
| 1.1 | `npm test` deja de depender de la memoria: hook de cierre o `verify:deploy` | Cerrar con tests en rojo es imposible sin decirlo a mano |
| 1.2 | Vitest también en `util`, con la misma config separada y su porqué | `npm test` existe en las dos apps |
| 1.3 | Fixture del **caso que hoy no cubre ningún dato real**: servicio con `orden > 0` sacado de producción, si aparece alguno | El caso sintético del test se sustituye por uno real, o se documenta que no existe ninguno |

⚠️ **Hay una asimetría que conviene cerrar:** existe un hook `Stop` que bloquea el turno si se toca
`src/` sin documentar, y **nada** que impida cerrar con los tests en rojo. La red se estrenó ayer y
hoy depende de que alguien se acuerde.

## Fase 2 — la casa

Costura **1**. Mover el módulo a donde puedan verlo los dos frentes, y poner el candado que lo
mantiene mudable.

| | Acción | Hecho cuando |
|---|---|---|
| 2.1 | Crear `dominio/` en la raíz. **Carpeta plana, no workspace** (ver `NodeEnElStack.md` §9: el build ocurre en el servidor, por app) | La carpeta existe con el módulo dentro |
| 2.2 | Alias `@dominio` en los dos `tsconfig.json` y los dos `vite.config.ts`; `include` que la alcance | `npm run typecheck` y `npm run build` limpios en las dos |
| 2.3 | ⚠️ **Comprobar `server.fs.allow` de Vite en dev** | El servidor de desarrollo sirve el módulo sin 403 |
| 2.4 | Regla ESLint en `dominio/`: prohibido importar `@/`, `vue`, `pinia` | Meter un store en el módulo **falla el lint** |
| 2.5 | `util` importa `componerItinerario` y **se borra `posicionDeServicio()` del store** | El espejo deja de existir; los snapshots no se mueven |

⚠️ **2.5 es el entregable real de esta fase**, no la carpeta. Una carpeta compartida con un
inquilino y ningún compartidor es estructura por adelantado; lo que justifica la fase es **matar un
espejo que hoy se mantiene a mano**.

⚠️ **2.4 no es ceremonia.** La condición para que el módulo siga siendo compartible está hoy escrita
en un docblock, y un docblock no falla. Es el mismo criterio del hook de `git add -A`.

## Fase 3 — el contrato

Costura **2**. La fase que decide si dentro de un año esto se rehace.

| | Acción | Hecho cuando |
|---|---|---|
| 3.1 | El módulo declara su **entrada mínima** (los 12 campos) y se hace genérico sobre los tres nodos | `pax` y `util` lo llaman con sus propios tipos y los recuperan en la salida |
| 3.2 | El esquema en **Zod**, y el tipo sale de él (`z.infer`) | El contrato es UN artefacto que valida y tipa |
| 3.3 | `CONTRATO_VERSION` exportada por el módulo; quien lo invoca desde fuera la manda y se compara | Un desajuste de forma **revienta**, no devuelve `undefined` |
| 3.4 | El `as` de los fixtures desaparece | El test no castea nada |

⚠️ **No se fusionan los tipos de `pax` y `util` en uno.** Se midió: `pax` es subconjunto estricto de
`util` en las tres entidades, así que fusionarlos *compilaría*. Y aun así no se hace, porque los 18
campos de diferencia del componente —`prestadorMaestroId`, `compradorNombreSnapshot`,
`snapshotItems`— son **exactamente los que la API decide no mandarle al cliente**. Un tipo único le
diría al compilador de `pax` que existen, y esa comprobación es la única automática que impide leer
en la pantalla del pasajero lo que no debe ver. Es una frontera de privacidad, no de estilo.

⚠️ **3.3 es barato ahora y caro después.** Sin versión de contrato, un campo renombrado en PHP llega
como `undefined` al cálculo y el resultado sale mal **sin un solo error**. Retrofitarlo obliga a
tocar todos los llamadores.

## Fase 4 — la frontera PHP ↔ Node

Costuras **3**, **4** y **5**. Aquí el piloto se hace de verdad.

| | Acción | Hecho cuando |
|---|---|---|
| 4.1 | Comprobar la versión de Node **en producción** (`--experimental-strip-types` funciona en 25) | Decidido: `.ts` directo o empaquetado con esbuild |
| 4.2 | `App\Dominio\EjecutorDeDominio`: **una** clase que invoca, mide y traduce fallos | Ningún controlador llama a `Process` por su cuenta |
| 4.3 | Una operación = una clase con su DTO de entrada y salida, autolocalizada con `#[AutowireIterator]` | Añadir la segunda operación no toca ningún registro ni ningún `match` |
| 4.4 | **Política de fallo explícita** por consumidor | Ver abajo |
| 4.5 | Canal de log propio: operación, duración, versión de contrato; los fallos a `error.log` | Una llamada lenta o rota se ve sin reproducirla |
| 4.6 | El PDF de servidor, como primer cliente: ruta pública, dompdf, la plantilla en tablas | Un itinerario de 16 días sale con 16 días |
| 4.7 | El segundo cliente es **transversal**: una skill en `Agent/Skill/Cotizacion/` (§11) | El Agente compone un itinerario **sin que su núcleo sepa que existe la operación** |

**La política de fallo, escrita de una vez:**

| Consumidor | Si Node no responde |
|---|---|
| PDF / correo | **503 y ningún documento.** Un PDF con la mitad del viaje es peor que no mandarlo |
| Cálculo con dinero (futuro) | **No se guarda.** `NodeEnElStack.md` §8: sobre dinero se falla cerrado |
| Pantalla del editor | No aplica: el navegador importa el módulo, no lo pide por red |

⚠️ **Lo que NO cambia en esta fase: `Node calcula, PHP persiste`.** El ejecutor recibe un objeto
plano y devuelve otro. Si algún día una operación quiere escribir, la respuesta es que no.

⚠️ **Y el editor sigue importando el módulo, no llamándolo.** `NodeEnElStack.md` §8 lo explica: si
`resumenFinanciero` pasa a pedirse por red deja de ser un `computed` síncrono, y hay dos sitios
donde eso no es cosmético. Mismo archivo, dos formas de consumirlo.

## Fase 5 — el generado único

Independiente de todo lo anterior: se puede hacer en cualquier momento, y cuanto antes menos duele.

| | Acción | Hecho cuando |
|---|---|---|
| 5.1 | **Un solo `api.d.ts`** en `dominio/`, generado una vez | Los dos de 42.658 líneas idénticas desaparecen |
| 5.2 | `gen:api` en un solo sitio, con el `cache:pool:clear --all` que ya hace falta | Regenerar es un comando, no dos |

⚠️ Hoy hay **dos copias byte a byte** del mismo archivo generado, cada una regenerada a mano. Ya se
pagó: la de `pax` estuvo 8 días congelada describiendo una API que ya no existía.

## Fase 6 — lo caro

Sólo cuando 1 a 4 estén hechas y el piloto lleve semanas funcionando.

| | Acción |
|---|---|
| 6.1 | Fixtures de `resumenFinanciero` con cotizaciones reales **de las difíciles**: rangos, opcionales, grupales, dos monedas |
| 6.2 | Extraer el cálculo al módulo compartido, exigiendo salida idéntica al céntimo |
| 6.3 | `construirInclusiones`, igual |
| 6.4 | PHP puede por fin aplicar `publicable` — que es el motivo de fondo de todo esto (`NodeEnElStack.md` §8) |

⚠️ **6.1 no es el primer paso de la fase: es la fase.** Son ~650 líneas que nunca se revisaron con
calculadora en mano.

---

## 11. Los módulos transversales: cómo llegan

`src/Agent/`, `src/Exchange/` y `src/Message/` van a necesitar estos cálculos. La pregunta no es
**si** pueden llegar, sino **por dónde** — y aquí es fácil romper la regla de `CLAUDE.md`
§Dominios y contratos sin darse cuenta.

### El error que hay que evitar

Lo natural es inyectar `EjecutorDeDominio` en una skill del núcleo del Agente y llamarlo con el
nombre de la operación. Eso mete **conocimiento de un dominio dentro de un servicio transversal**:
el núcleo pasaría a saber que existe «componer itinerario», y el siguiente cálculo pediría un `if`.

### La capa, y quién puede ver qué

```
   TRANSVERSAL   Agent · Exchange · Message
        │        consumen CONTRATOS, nunca operaciones
        ▼
   DOMINIO PHP   Agent/Skill/Cotizacion/… · servicios de src/Cotizacion/
        │        aquí SÍ se inyecta el ejecutor
        ▼
   INFRA         App\Dominio\EjecutorDeDominio
        │        spawn hoy · HTTP el día que se mida
        ▼
   DOMINIO TS    dominio/  ·  componerItinerario, resumenFinanciero…
```

**La regla, en una frase: `EjecutorDeDominio` es al cálculo lo que `EntityManager` es a la
persistencia.** Infraestructura que inyectan las clases *de dominio*, nunca el núcleo transversal.

Y no es una analogía inventada para la ocasión: es el patrón que ya sigue este código. Las **7 de 7**
skills de dominio de `Cotizacion` y `Travel` inyectan `EntityManagerInterface` directamente, y
`SkillRegistry` —el núcleo— no inyecta ninguno. Su propio docblock lo dice: *«es dominio puro: no
sabe de proveedores»*.

### Cómo llega cada uno

| Módulo | Camino | Qué NO hace |
|---|---|---|
| **Agent** | Una skill en `Agent/Skill/<Dominio>/`, autolocalizada con `#[AutowireIterator('app.agent_skill')]`. La skill inyecta el ejecutor | El núcleo del Agente no aprende el nombre de ninguna operación |
| **Exchange** | Llama al **servicio del dominio** (`src/Cotizacion/…`), que por dentro usa el ejecutor | No invoca operaciones por nombre desde un runner |
| **Message** | Igual: el redactor pide al dominio el dato ya compuesto | No compone nada |

⚠️ **Añadir el acceso del Agente no debe tocar ningún registro.** Una clase nueva en
`Skill/Cotizacion/` se enchufa sola. Si hace falta editar un `match`, un array o un YAML, la costura
está mal — es el criterio 1 de §13.

### Cuando lo transversal necesita recorrer VARIOS dominios

Existe el caso legítimo: un runner de Exchange que deba recalcular «lo que haya que recalcular»,
sea del dominio que sea. La respuesta **no** es dejarle el ejecutor, sino la misma que ya usa este
proyecto media docena de veces: **un contrato que cada dominio implementa y el núcleo consume sin
entender** —como `FinOrigenCobroRegistry` o `EnumeradorDeFrentes`—. El runner itera contratos; cada
dominio decide qué calcula y con qué operación.

### ⚠️ Exchange es bucles, y ahí 50 ms deja de ser gratis

El coste medido —50 ms por invocación de `node`— es irrelevante para un PDF y **no lo es dentro de
un runner que procesa N elementos**: son N arranques de proceso. Es justo el escenario que justifica
cambiar el transporte.

Dos consecuencias prácticas:

- **No se mete un `spawn` dentro de un bucle por elemento sin medir antes.** Si hace falta, se
  invoca **una vez con N entradas**.
- Ese cambio es **aditivo**: el ejecutor gana un método de lote y las operaciones no se enteran. Es
  exactamente para esto que la invocación tiene una sola puerta (costura 3).

### ⚠️ Y sobre dinero, el Agente falla CERRADO

Es el consumidor con más riesgo: arma cotizaciones **sin pasar por el editor**, que es el motivo de
fondo de todo esto (`NodeEnElStack.md` §8). Si el cálculo no responde, **no se guarda**. No se
guarda sin validar, ni se guarda con un total a medias.

### La prueba de que la capa sigue en pie

Es comprobable con una línea, y por eso conviene que alguien la corra de vez en cuando:

```bash
grep -rn "EjecutorDeDominio" src/Agent src/Exchange src/Message
```

Todo lo que salga tiene que estar dentro de una carpeta de dominio (`Skill/Cotizacion/`,
`Skill/Pms/`…). Un resultado en el núcleo de cualquiera de los tres significa que la capa se
rompió — y que el siguiente cálculo va a pedir un `if` por dominio.

## 12. Qué NO se construye, y por qué no obliga a rehacer

La lista es tan importante como el plan. Cada cosa diferida va con **la costura que la hace
aditiva**, que es lo que responde a «¿y cuando llegue algo más complejo?».

| Diferido | Por qué no obliga a rehacer |
|---|---|
| **Servicio HTTP (Hono)** | El transporte vive detrás de `EjecutorDeDominio` (4.2). Cambiar spawn por HTTP es una clase, y ningún llamador se entera |
| **Demonio, supervisión, reintentos** | Misma costura. Y hoy no hacen falta: 50 ms por invocación, medido |
| **npm workspaces / monorepo** | El alias de la fase 2 resuelve rutas, no paquetes. Migrar a workspaces después no toca ni un import |
| **Caché de resultados** | El ejecutor es el único sitio que invoca: la caché entra ahí, sin tocar operaciones |
| **Zod en todo el proyecto** | 3.2 lo pone donde cruza el dato. Extenderlo es añadir esquemas, no cambiar el patrón |
| **Más operaciones de dominio** | 4.3 las autolocaliza: una clase nueva se enchufa sola, como las skills del agente |
| **Node escribiendo en base de datos** | **Esto no se difiere: se prohíbe.** Es la frontera de §4, y es lo que hace barato el segundo lenguaje |

## 13. Cómo saber que el plan va bien

Cuatro señales concretas, y ninguna es «está terminado»:

1. **Añadir una operación de dominio no toca ningún registro.** Si hace falta editar un `match`, un
   array o un archivo de configuración, la costura 3 está mal.
2. **Meter un `import` de la app en `dominio/` falla el lint.** Si sólo lo dice un comentario, la
   costura 1 está mal.
3. **Un desajuste de contrato revienta con un mensaje que nombra el campo.** Si devuelve
   `undefined` y sigue, la costura 2 está mal.
4. **Ninguna regla de negocio está escrita dos veces.** El contador arranca en uno
   (`posicionDeServicio()`, que muere en 2.5) y no debe subir nunca.

Si en algún momento la respuesta a «¿dónde está escrita esta regla?» es «en dos sitios, y hay un
comentario que lo avisa», el plan se ha desviado — da igual cuántas fases estén hechas.
