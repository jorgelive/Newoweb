# Plan — una sola fuente de verdad para la lógica de negocio

Cómo se llega a que cada regla de negocio esté escrita **una vez** y la haga cumplir **una sola
autoridad**, siendo consumible desde el navegador y desde el servidor.

**Alcance:** plan de ejecución con fases, criterios de «hecho» y lo que se deja fuera a propósito.
La arquitectura y su porqué están en `docs/NodeEnElStack.md`; aquí está el orden y el trabajo.

**Estado:** reescrito el 02/09/2026 tras una revisión externa que encontró tres errores de hecho en
la versión anterior (ver §9). Fase 0 en curso.

---

## Índice

1. [El objetivo, en una frase](#1-el-objetivo-en-una-frase)
2. [Las tres piezas que lo componen](#2-las-tres-piezas-que-lo-componen)
3. [El criterio de secuencia](#3-el-criterio-de-secuencia)
4. [El marcador de espejos](#4-el-marcador-de-espejos)
5. [Las fases](#5-las-fases)
6. [Trabajo suelto que no depende de nadie](#6-trabajo-suelto-que-no-depende-de-nadie)
7. [Los módulos transversales: cómo llegan](#7-los-módulos-transversales-cómo-llegan)
8. [Qué NO se construye, y por qué no obliga a rehacer](#8-qué-no-se-construye-y-por-qué-no-obliga-a-rehacer)
9. [Errores de la versión anterior](#9-errores-de-la-versión-anterior)
10. [Cómo saber que el plan va bien](#10-cómo-saber-que-el-plan-va-bien)

---

## 1. El objetivo, en una frase

**Una implementación de cada regla, y una autoridad que la hace cumplir al escribir.**

No es «compartir código». Compartir el archivo resuelve la mitad; la otra mitad es que **nadie
pueda escribir saltándose la regla**. Hoy falla justo ahí, y se puede comprobar en dos líneas:

```php
#[Groups(['cotizacion:read', 'cotizacion:write', …])]
private string $totalVenta = '0.00';
```

`totalVenta` y `totalCosto` están en el grupo de **escritura** y **ningún código PHP los calcula**:
llegan en el payload del navegador y se persisten tal cual. Un `curl` —o el agente— puede escribir
una cotización con los totales que quiera. Eso es una implementación y **dos autoridades**.

## 2. Las tres piezas que lo componen

```
   1. UNA IMPLEMENTACIÓN          dominio/*.ts — funciones puras del estado
            │
            ├──► el navegador la importa      → orientativo, 0 ms, offline
            │
            └──► PHP la invoca por Node       → vinculante, al escribir
                          │
   2. UNA AUTORIDAD       └──► PHP compara y RECHAZA lo que no cuadra
            │
   3. CERO ESPEJOS        └──► y lo que no encaje, atado con test de conformidad
```

**La división que mantiene vivo el módulo:** PHP reúne los hechos → el módulo decide los números →
PHP persiste o rechaza.

⚠️ **El módulo no consulta la base, nunca.** «¿Existe esta tarifa?», «¿está activo el proveedor?»,
«¿cuál es el tipo de cambio?» son consultas del servidor. Si el módulo empieza a quererlas deja de
ser puro, deja de correr en el navegador, y vuelves a tener un solo runtime — justo lo que se
quería evitar.

⚠️ **PHP no hace la aritmética.** «PHP recalcula al guardar» significa que **le pregunta al
módulo** y hace cumplir la respuesta. No hay ninguna réplica en PHP de ninguna regla.

## 3. El criterio de secuencia

**Las costuras ahora; las implementaciones cuando hagan falta.** Una costura —dónde vive el
código, qué forma cruza, quién valida, qué pasa al fallar— obliga a tocar todos sus lados si
cambia. Una implementación detrás de una costura buena se sustituye sin que nadie se entere.

Y una regla de secuencia que sale de la propia meta: **la autoridad no puede ir primero.** No se
puede hacer cumplir con un módulo que el servidor todavía no sabe llamar. Por eso el orden es
*mover → poder llamar → probar la tubería sin riesgo → mover el dinero → hacer cumplir*.

## 4. El marcador de espejos

La medida de si esto va bien no es cuántas fases hay hechas: es **cuántas reglas están escritas
dos veces**. Hoy son tres.

| Regla | Dónde está hoy | Muere en |
|---|---|---|
| Composición del itinerario (`posicionDeServicio`) | módulo + `cotizacionEditorStore.ts` | Fase 3 |
| La misma, en PHP (`posicionDelServicio`) | `src/Operacion/Entity/OperacionServicio.php` | Fase 6 |
| Cálculo financiero y validación | sólo el navegador; el servidor **no puede aplicarla** | Fases 5–6 |

⚠️ El tercero no es «un espejo» sino algo peor: una regla que **existe en un solo sitio y el
servidor no alcanza**, así que la guarda sólo se cumple si quien escribe es el editor.

## 5. Las fases

### Fase 0 — cerrar lo abierto

Higiene. Sin esto lo demás se construye sobre trabajo a medias.

| | Acción | Hecho cuando |
|---|---|---|
| 0.1 | Ver la **vista previa de impresión** real: días que no se parten, modo catálogo, móvil | Un día de siete paradas cabe entero o empieza en hoja nueva |
| 0.2 | Commitear el fixture real `5SRAJV` y su test | `git status` limpio |
| 0.3 | Decidir `.claude/launch.json` | No aparece sin querer |

### Fase 1 — el candado

Va antes que todo lo demás porque a partir de la fase 2 se mueve código, y mover código sin red es
lo que `NodeEnElStack.md` §5 prohíbe.

| | Acción | Hecho cuando |
|---|---|---|
| 1.1 | ~~`npm test` deja de depender de la memoria~~ **HECHO 02/09/2026**: `.claude/hooks/tests-guard.sh` | ✅ Probado en verde, en rojo, y que los territorios no se confunden |

La asimetría que cerró: existía un hook `Stop` que bloqueaba el turno si se tocaba `src/` sin
documentar, y **nada** que impidiera cerrar con los tests en rojo — la documentación estaba mejor
protegida que el código.

⚠️ Falta `util`: hoy no tiene tests, así que su territorio no está vigilado. Es una línea en el
hook el día que los tenga (fase 2).

### Fase 2 — el módulo, limpio y movible

Precondición de que `util` pueda importarlo. Hoy no puede: el módulo lleva presentación de `pax`
dentro y declara su entrada como la serialización pública entera.

| | Acción | Hecho cuando |
|---|---|---|
| 2.1 | Sacar la presentación: `componerItinerario()` puro + `decorarParaGuia()` en `pax` | `mostrarAccionInclusiones` y `mostrarTituloServicio` ya no están en el módulo |
| 2.2 | Contrato **estrecho y genérico** sobre los tres nodos (los 12 campos) | `pax` y `util` lo llaman con sus tipos y los recuperan en la salida |
| 2.3 | El `as` de los fixtures desaparece | El test no castea nada |

⚠️ **2.1 antes que nada.** El módulo produce hoy dos flags del panel del huésped. Si `util` lo
importa antes de sacarlos, lo llamará con un `Set` vacío y dos flags muertos, y el paso siguiente
es un `modo: 'editor' | 'guia'`: **el primer `if` por consumidor dentro del único módulo
compartido**.

⚠️ **No se fusionan los tipos de `pax` y `util`.** Medido: `pax` es subconjunto estricto de `util`
en las tres entidades, así que fusionarlos *compilaría*. Y aun así no se hace: los 18 campos de
diferencia del componente —`prestadorMaestroId`, `compradorNombreSnapshot`, `snapshotItems`— son
los que la API decide no mandarle al cliente. El compilador de `pax` es la única comprobación
automática de esa frontera. Es privacidad, no estilo.

### Fase 3 — la casa

| | Acción | Hecho cuando |
|---|---|---|
| 3.1 | ~~Decidir workspace~~ **DECIDIDO 02/09/2026: sin workspace.** `dominio/package.json` propio y un tercer `npm ci`, metido en el guion de despliegue | El despliegue es **un comando**, no una lista de pasos |
| 3.2 | `dominio/` en la raíz; alias en los dos `tsconfig` y los dos `vite.config` | `typecheck` y `build` limpios en las dos apps |
| 3.3 | `erasableSyntaxOnly: true` en `dominio/tsconfig.json` + regla ESLint de extensión explícita | Un `enum` en `dominio/` **falla al escribirlo** |
| 3.4 | Comprobar `server.fs.allow` de Vite en dev | El dev server sirve el módulo sin 403 |
| 3.5 | Superficie pública: un `index.ts` por módulo; ESLint prohíbe imports profundos | Reorganizar dentro de `dominio/` no rompe consumidores |
| 3.6 | `util` importa el módulo y **se borra `posicionDeServicio()` del store** | **Marcador de espejos: 3 → 2** |

**Por qué sin workspace.** El motivo por el que hacía falta decidirlo: `dominio/` sin
`package.json` no puede resolver una dependencia. Un `import { z } from 'zod'` resuelve subiendo
desde `dominio/`, y **no mira `pax/node_modules`** — falla en el spawn *y* en el build del
navegador. Con su propio `package.json` deja de ser un problema, y de paso queda **mejor aislado
que con workspaces**: Vite y Node resuelven ambos desde `dominio/node_modules`, así que las dos
mitades usan exactamente la misma versión sin depender del hoisting.

⚠️ **El riesgo no es el coste de compilar: es que el paso se olvide.** Un `npm ci` más tarda unos
segundos y no molesta a nadie. Lo que sí muerde es un despliegue que sea una **lista de pasos** —ya
pasó con las migraciones sin ejecutar, con el `api.d.ts` de `pax` ocho días congelado y con
`pax:textos:itinerario`, que es un paso nuevo que nadie tiene en los dedos—.

**La salvaguarda que hace segura esta decisión:** el despliegue del front tiene que ser **un solo
comando** que instale y compile los tres, no tres comandos que alguien recuerda. Sitio natural: el
guion que ya existe (`verify:deploy`) o el hook `post-merge`. Sin eso, la tercera compilación es la
próxima migración sin ejecutar.

⚠️ **3.3 no es ceremonia.** Comprobado en el servidor: `enum Modo { … }` da
`ERR_UNSUPPORTED_TYPESCRIPT_SYNTAX` en Node 22.22, mientras Vite lo compila tan feliz. Sin este
candado, una regla puede compilar en el navegador y **morir en producción**.

### Fase 4 — la puerta a PHP

| | Acción | Hecho cuando |
|---|---|---|
| 4.1 | `App\Dominio\EjecutorDeDominio`: **una** clase que invoca, mide y traduce fallos | Ningún controlador ni servicio llama a `Process` por su cuenta |
| 4.2 | Una operación = una clase PHP + **un punto de entrada `.cli.ts`** que ella nombra | Añadir una operación no toca ningún registro, ni en PHP ni en TS |
| 4.3 | Contrato de entrada **siempre en lote**: `{version, entradas[]}` → `{version, salidas[]}` | Un consumidor que pida N no obliga a N arranques |
| 4.4 | `CONTRATO_VERSION` **por operación**; desajuste = fallo ruidoso | Un campo renombrado revienta, no devuelve `undefined` |
| 4.5 | Canal de log propio: operación, duración, versión | Una llamada lenta o rota se ve sin reproducirla |
| 4.6 | **Piloto de sólo lectura**: el PDF del itinerario en servidor | Un itinerario de 16 días sale con 16 días |

**Política de fallo, decidida de una vez:**

| Consumidor | Si Node no responde |
|---|---|
| PDF / correo | **503 y ningún documento.** Un PDF con la mitad del viaje es peor que no mandarlo |
| Escritura con dinero (fase 6) | **No se guarda.** Nunca un total a medias |
| Editor | No aplica: el navegador importa el módulo, no lo pide por red |

⚠️ **El piloto es de sólo lectura a propósito.** Prueba la tubería entera —invocación, contrato,
validación, fallo, rastro— sin que un error cueste dinero. Y arranca con su regresión conocida:
el servicio equivalente escrito en PHP daba 11 días donde hay 16.

⚠️ **4.3 es lo más barato de decidir ahora y lo más caro de retrofitar.** Cambiar la forma de la
entrada después toca todas las operaciones y todos los llamadores.

### Fase 5 — mover el dinero

Sólo cuando 1–4 estén hechas y el piloto lleve semanas funcionando.

| | Acción | Hecho cuando |
|---|---|---|
| 5.1 | Fixtures de `resumenFinanciero` con cotizaciones reales **de las difíciles**: rangos, opcionales, grupales, dos monedas | Congelada la salida de hoy |
| 5.2 | Extraer el cálculo a `dominio/`, exigiendo salida **idéntica al céntimo** | Los fixtures pasan sin tocar un valor |
| 5.3 | `construirInclusiones`, `advertencias` y `publicable`, igual | El servidor puede responder «esto no se puede enviar» |

⚠️ **5.1 no es el primer paso de la fase: es la fase.** Son ~650 líneas que nunca se revisaron con
calculadora en mano.

⚠️ **5.3 es lo que de verdad desbloquea al agente.** Para armar una cotización no necesita sobre
todo los totales: necesita saber **si es válida**. Esa guarda vive hoy en el navegador, así que
sólo se cumple si quien escribe es el editor.

### Fase 6 — la autoridad

El objetivo. Todo lo anterior existe para poder hacer esto.

| | Acción | Hecho cuando |
|---|---|---|
| 6.1 | Al escribir una cotización, PHP pide el cálculo al módulo y **rechaza la discrepancia** | Un `curl` con totales inventados devuelve 422 |
| 6.2 | `totalVenta`/`totalCosto` **salen de `cotizacion:write`** | El cliente ya no los manda: los deriva el servidor |
| 6.3 | Log de discrepancias cliente↔servidor | Un editor que calcula mal se detecta solo |
| 6.4 | Desnormalizar `ordenItinerario` calculándolo en la escritura | **Marcador de espejos: 2 → 1**, y luego 0 con 5.3 |
| 6.5 | El agente arma cotizaciones por el mismo camino y la misma guarda | No hay ruta de escritura sin validar |

⚠️ **6.3 es un regalo que conviene no dejar pasar.** Si el servidor calcula y compara, la
diferencia con lo que mandó el cliente es **detectable**. Hoy no existe ninguna forma de saber si
el editor está calculando mal.

⚠️ **6.4 explica por qué el tercer espejo puede morir sin test de conformidad.** La regla está
duplicada en PHP porque `OperacionServicio` la evalúa por fila y ahí no se lanza Node N veces. Su
comentario dice que se dejó calculada y no en columna para que «el cuadro y la cotización no se
contradigan al reordenar» — que es un argumento **contra el estado de hoy**: es el mismo problema
de `totalVenta`. Con 6.1 puesto, toda escritura pasa por el módulo y la columna no puede quedarse
vieja. **La autoridad en la escritura es lo que hace segura la desnormalización.**

## 6. Trabajo suelto que no depende de nadie

Se puede hacer en cualquier momento; cuanto antes, menos duele.

| | Acción | Por qué ya |
|---|---|---|
| S1 | **Concurrencia optimista al guardar**: rechazar un guardado cuya base está caducada | Arregla un bug VIVO —dos operadores se pisan en silencio— y es el cimiento de cualquier fusión futura. Una columna y una comprobación |
| S2 | **Un solo `api.d.ts`** generado, en `dominio/` | Hoy hay dos copias byte a byte de 42.658 líneas. La de `pax` ya estuvo 8 días describiendo una API que no existía |
| S3 | Sacar el publicador de Mercure de `src/Message/` a un servicio transversal, con **tema = IRI del nodo que cambió** | Vale igual para SSE que para WS. Retrofitar la granularidad obliga a tocar a todos los suscriptores |
| S4 | Token de Mercure **acotado por tema** para `pax` | Hoy `subscribe: ['*']`: cualquier autenticado escucha todo. Tolerable con sólo personal interno; fuga el día que `pax` necesite tiempo real |
| S5 | La lógica de `EventSource` en **un** módulo cliente, no repartida por stores | Cambiar SSE por WS pasa a ser un archivo |

## 7. Los módulos transversales: cómo llegan

`src/Agent/`, `src/Exchange/` y `src/Message/` van a necesitar estos cálculos. La pregunta no es
**si** pueden, sino **por dónde**.

### El error que hay que evitar

Inyectar `EjecutorDeDominio` en el núcleo del Agente y llamarlo con el nombre de la operación. Eso
mete conocimiento de un dominio dentro de un servicio transversal: el núcleo pasaría a saber que
existe «componer itinerario», y el cálculo siguiente pediría un `if`.

### La capa

```
   TRANSVERSAL   Agent · Exchange · Message      consumen CONTRATOS
        ▼
   DOMINIO PHP   Agent/Skill/Cotizacion/… · src/Cotizacion/…   aquí SÍ va el ejecutor
        ▼
   INFRA         App\Dominio\EjecutorDeDominio
        ▼
   DOMINIO TS    dominio/
```

**`EjecutorDeDominio` es al cálculo lo que `EntityManager` es a la persistencia.** Infraestructura
que inyectan las clases de dominio, nunca el núcleo. No es una analogía inventada: **7 de 7** skills
de dominio de `Cotizacion` y `Travel` inyectan `EntityManagerInterface`, y `SkillRegistry` no
inyecta ninguno — su docblock dice «es dominio puro: no sabe de proveedores».

| Módulo | Camino |
|---|---|
| **Agent** | Una skill en `Agent/Skill/<Dominio>/`, autolocalizada. La skill inyecta el ejecutor |
| **Exchange** | Un handler **implementado en el dominio** (`src/Cotizacion/Service/Exchange/`), no el motor |
| **Message** | El redactor pide al dominio el dato ya compuesto |

### Cuando lo transversal recorre VARIOS dominios

Un contrato que cada dominio implementa y el núcleo consume sin entender — como
`FinOrigenCobroRegistry` o `EnumeradorDeFrentes`. Nunca dándole el ejecutor al motor.

### La prueba de que la capa sigue en pie

Un `grep` de la clase **no basta**: no caza un `use App\Cotizacion\…` en el núcleo de Exchange, que
es un `if` por dominio con otro nombre. Hace falta una **regla de dependencias entre namespaces**
(deptrac, o una regla PHPStan propia — ya hay nivel 7 y baseline auditada):

```
App\Agent\*    (salvo Agent\Skill\<Dominio>)
App\Exchange\Service\*
App\Message\*  (salvo sus carpetas de dominio)
   ──► NO importan ──► App\Cotizacion|Pms|Travel|Finanzas
```

No está medido si hoy saldría en rojo. La primera corrida también es información.

### ⚠️ Exchange es bucles

50 ms por invocación es irrelevante para un PDF y **no lo es dentro de un runner que procesa N
elementos**. Por eso 4.3 fija el lote desde el principio: se invoca una vez con N entradas, nunca
un `spawn` por elemento.

## 8. Qué NO se construye, y por qué no obliga a rehacer

| Diferido | La costura que lo hace aditivo |
|---|---|
| **Servicio HTTP (Hono)** | El transporte vive detrás de `EjecutorDeDominio` (4.1). Cambiarlo es una clase |
| **Demonio, reintentos, caché** | Misma costura. Y hoy no hacen falta: 50 ms medidos |
| **WebSockets, CRDT, registro de operaciones** | Maquinaria: envejece rápido y el ecosistema la mejora más rápido que tú. Lo que **no** envejece —identidad estable por nodo, marca de tiempo por nodo— **ya lo tienes** (`IdTrait`, `TimestampTrait`), y es lo que ninguna librería futura te va a regalar |
| **Presencia, cursores** | Igual. Y no necesitan reposición de huecos, así que serán baratos cuando toque |
| **Más operaciones de dominio** | 4.2 las autolocaliza: una clase nueva se enchufa sola |
| **Node escribiendo en base de datos** | **No se difiere: se prohíbe.** Es lo que hace barato el segundo lenguaje |

## 9. Errores de la versión anterior

Se dejan escritos porque el motivo de cada uno sigue siendo útil.

| Decía | Por qué era falso |
|---|---|
| «El contador de espejos arranca en uno» | Son **tres**: falta `posicionDelServicio()` en `src/Operacion/Entity/OperacionServicio.php`, con su propio comentario diciendo «los tres cambian juntos» |
| «Node 25 ejecuta TypeScript directamente» | Producción es **22.22**. Ejecuta TS, pero **sólo sintaxis borrable**: un `enum` muere con `ERR_UNSUPPORTED_TYPESCRIPT_SYNTAX` — y Vite lo compila sin quejarse |
| «Carpeta plana, y migrar a workspaces después no toca ni un import» | Cierto para los imports, **falso para la instalación y el despliegue**. Y con una dependencia dentro, `dominio/` no tiene con qué resolverla |
| «Sobre dinero el Agente falla cerrado» | Era una petición al modelo, no código: `SkillInterface` dice literalmente «hoy ese paso no lo fuerza el servidor, sólo la descripción que lee el modelo» |

## 10. Cómo saber que el plan va bien

Cinco señales, y ninguna es «está terminado»:

1. **El marcador de espejos baja y nunca sube.** 3 → 2 (fase 3) → 1 (6.4) → 0 (5.3 + 6.1).
2. **Añadir una operación no toca ningún registro**, ni en PHP ni en TS. Si hace falta editar un
   `match`, un array o un YAML, la costura 4.2 está mal.
3. **Meter un `import` de la app en `dominio/` falla el lint.** Si sólo lo dice un comentario, la
   costura está mal.
4. **Un desajuste de contrato revienta nombrando el campo.** Si devuelve `undefined` y sigue, mal.
5. **No hay ninguna ruta de escritura que se salte la validación.** Es la meta; hasta que se
   cumpla, la fuente es única en el código pero no en los hechos.

Si en algún momento la respuesta a «¿dónde está escrita esta regla?» es «en dos sitios, y hay un
comentario que lo avisa», el plan se desvió — da igual cuántas fases estén hechas.
