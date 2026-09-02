# Node en el stack — cuándo sí, cuándo no, y dónde va el límite

Cuándo un proceso de este proyecto se escribe en Node en lugar de PHP, qué puede saber y qué no,
y por qué dos servicios en el mismo lenguaje siguen reglas distintas.

**Alcance:** decisiones de arquitectura. No es un manual de Node ni de despliegue.

---

## Índice

1. [La regla](#1-la-regla)
2. [Por qué Node y no otro](#2-por-qué-node-y-no-otro)
3. [Los dos casos, y por qué dan respuestas opuestas](#3-los-dos-casos-y-por-qué-dan-respuestas-opuestas)
4. [El límite que no se cruza](#4-el-límite-que-no-se-cruza)
5. [Antes de mover lógica de dinero](#5-antes-de-mover-lógica-de-dinero)
6. [Lo que cuesta](#6-lo-que-cuesta)
7. [Cuándo proponerlo](#7-cuándo-proponerlo)
8. [La forma acordada para el cálculo financiero](#8-la-forma-acordada-para-el-cálculo-financiero--23082026)
9. [La capa compartida: lo que §8 no había medido](#9-la-capa-compartida-lo-que-8-no-había-medido--02092026)
10. [Qué framework para Node (ninguno, todavía)](#10-qué-framework-para-node-ninguno-todavía--02092026)

---

## 1. La regla

**Cada pieza de lógica vive en un solo sitio.**

No es «Node va tonto» ni «todo lo que pueda ir en PHP va en PHP». Es que ninguna regla de negocio
puede estar escrita dos veces, porque dos copias divergen — no *pueden*: **divergen**. Ya pasó con
el reparto de cortesías en cotizaciones, y con las tres superficies de la guía diciendo cosas
distintas de la misma ducha.

De esa regla salen las dos respuestas de §3, que parecen contradecirse y no lo hacen.

La analogía que la originó, y que vale la pena conservar porque es la que se recuerda: durante años
un solo vehículo —una van— servía para todo, hasta que tocaba maniobrar por el centro de Cusco
buscando dónde aparcar semejante monstruo. La van no era un error. Era la van **en esa calle**.

Y el desenlace importa tanto como el planteamiento: la solución no era vender la van. Era tener un
auto pequeño para cierto tipo de calle, y saber cuál es cuál.

⚠️ **El riesgo real no es quedarse sin Node: es empezar a usarlo para todo.** Un segundo lenguaje
sin frontera clara duplica la superficie de mantenimiento y no compra nada.

## 2. Por qué Node y no otro

Node **ya está instalado en producción**, en nvm, y ya forma parte del despliegue porque compila las
dos PWAs (ver [Newoweb deploy procedure]). No añade runtime, ni cadena de instalación, ni un lenguaje
que no se escriba a diario: TypeScript es lo que ya hay en `util/` y `pax/`.

Go sería más elegante para un proceso de conexión persistente —binario único, memoria mínima— pero
obliga a un toolchain nuevo por una ventaja que a esta escala no se nota. Si algún día un proceso
pide de verdad esa robustez, se reevalúa; hoy no la pide ninguno.

## 3. Los dos casos, y por qué dan respuestas opuestas

| | Sidecar de Tuya | Cálculo financiero |
|---|---|---|
| Qué hace | Sostiene la suscripción a la cola y empuja eventos crudos | Calcula márgenes, buckets y totales |
| ¿Sabe de negocio? | **No, nada** | **Sí, todo** |
| Por qué | El dominio ya vive en PHP: meterle conocimiento **duplicaría** | La lógica ya vive en TS: reescribirla en PHP **duplicaría** |

Es la misma regla aplicada a puntos de partida distintos. En los dos casos la pregunta es «¿dónde
está ya escrito esto?», y la respuesta manda.

**El sidecar** recibe el evento y lo deja en Redis o en el bus. No sabe qué es una reserva, no
calcula crédito, no decide si avisar a nadie. Cien y pico líneas que se leen de una sentada. Es el
caso límite de la regla de `CLAUDE.md` §Dominios y contratos —*un servicio transversal nunca lleva
dentro conocimiento de un dominio*— y por eso mismo es donde más fácil se incumple.

**El cálculo financiero** es el argumento más fuerte que ha habido para Node, más que el sidecar.
Ahí no se elige por comodidad: se elige porque la alternativa es tener las reglas del margen
escritas dos veces, una para el editor del operador y otra para el agente IA. Va como **módulo puro,
sin I/O dentro**:

```
cálculo puro (TS)
   ├── lo importa util/            → reactividad del editor intacta, cero latencia
   └── lo envuelve el servicio Node → el agente lo llama por HTTP
```

Mismo archivo, dos consumidores, ningún espejo que mantener.

## 4. El límite que no se cruza

**Node calcula. PHP persiste.**

El servicio recibe un objeto plano y devuelve el resultado. No toca la base de datos, no escribe
cotizaciones, no decide estados. En cuanto Node escriba en MySQL hay **dos autores sobre el dinero**,
y ahí se rompe todo lo demás.

PHP sigue siendo el sistema de registro. Esa frontera es la que hace que el segundo lenguaje sea
barato: mientras se respete, un proceso Node caído deja de calcular, pero no corrompe nada.

## 5. Antes de mover lógica de dinero

**Fijar el comportamiento actual con fixtures, primero.** Cotizaciones reales —de las difíciles, con
rangos y servicios opcionales—, guardar la salida de hoy, y exigir que el módulo extraído dé
exactamente lo mismo.

No es opcional: son ~527 líneas que nunca se han revisado con calculadora en mano. Si la extracción
cambia un céntimo, hay que enterarse en el diff y no en una factura.

### Ya hay con qué hacerlo (02/09/2026)

Durante meses esto fue una intención sin herramienta: **540 tests en PHP y cero en TypeScript**, sin
runner instalado en ninguna de las dos apps. Mover cálculo a TS en esas condiciones era moverlo del
lado con red al lado sin ella, y eso importa más en el cálculo financiero que en ningún otro sitio.

**Vitest**, en `pax`, con `npm test`. Se eligió porque reutiliza la config de Vite y los alias `@`
que ya existen: un test importa el módulo **exactamente como lo importa la app**, sin una segunda
configuración que se desincronice.

El procedimiento está estrenado sobre `componerItinerario()`
(`pax/src/dominio/itinerarioVista.test.ts`), a propósito con la pieza barata: si la extracción de un
itinerario sale mal se nota en una pantalla, y si sale mal en los márgenes se nota en una factura.
Al cálculo financiero se llega con el método probado, no inventándolo sobre el dinero.

⚠️ **`vitest.config.ts` NO hereda de `vite.config.ts`, y es deliberado.** Vitest carga la config con
`command: 'serve'`, y esa rama exige los certificados de `pax/certs/` y hace `process.exit(1)` si
faltan: heredarla dejaría `npm test` dependiendo de un certificado local —pasaría en la máquina de
quien lo escribió y **moriría en cualquier otra**, con un error que no habla de tests—. Mientras se
teste sólo dominio (TypeScript puro, sin Vue ni PWA), lo único que hay que replicar es el alias.

### Las tres reglas del procedimiento, ya pagadas

**1. Los fixtures son datos REALES, podados — no inventados.** Un caso de juguete no tiene lo que
rompe: la estadía de tres noches dentro de un servicio con actividades, el componente promovido a
servicio completo, el día con siete paradas. Los dos fixtures salen de cotizaciones de producción,
recortadas a los campos que el módulo lee.

⚠️ **Y la poda se verifica, no se supone.** Se comparó la salida del payload **completo** contra la
del podado: idénticas en las dos cotizaciones. Sin esa comprobación, un fixture recortado de más es
un test que pasa describiendo un mundo que no existe.

**2. Un snapshot es un contrato.** Si un cambio lo mueve, se lee el diff y se decide: mejora
aceptada a conciencia, o regresión. Actualizarlo con `-u` porque «salió en rojo» vacía el propósito
entero del archivo.

**3. Lo que no cubre el dato real se escribe sintético Y SE DICE.** Ninguna de las dos cotizaciones
tiene un servicio con `orden > 0`, así que la rama «día colocado a mano» —donde el orden manda sobre
la hora— no la cubría ningún dato de producción. Ese caso va a mano, con el aviso de que lo es;
callarlo daría una cobertura que parece de producción y no lo es.

### 🔥 El primer test escrito falló, y el error era del test

Comprobaba el escalonado del día **bloque a bloque**, y la regla ordena **grupos**: un servicio se
coloca por su hora más temprana y todo lo suyo se pinta seguido, así que dentro de un grupo conviven
bloques con hora y sin ella. La aserción fallaba **contra datos correctos**.

Vale la pena dejarlo escrito porque es el argumento de fondo: en un módulo de 209 líneas que ya
llevaba meses en producción, escribir el test destapó que quien lo escribía **no entendía la regla
al nivel correcto**. Con 650 líneas de márgenes, esa clase de malentendido no se descubre leyendo.

## 6. Lo que cuesta

- **El despliegue se acopla.** Un cambio en el cálculo necesita rebuild de la PWA *y* reinicio del
  servicio. Asumible, pero es una casilla más en la lista de despliegue.
- **Un proceso más que puede caerse**, y caerse callado. Todo proceso persistente necesita su
  supervisión y su aviso — la misma lección que `docs/Domotica.md` §10.
- **El coste no es escribirlo, es operarlo durante años.** Un despliegue más, dependencias que
  parchear, y una cosa más que un mantenedor futuro tiene que conocer. Con la frontera de §4 ese
  coste es pequeño y acotado; sin ella, se multiplica.

Esto último pesa más de lo normal si el PMS se revende: cada pieza móvil es algo que explicarle a
quien lo instale. Una que sólo traduce protocolo se explica en dos frases. Una que además decide, no.

## 7. Cuándo proponerlo

Node entra cuando se cumple **al menos una** de estas, y no por «iría más rápido»:

- **Conexión persistente**: suscripción a una cola, MQTT, WebSocket, fan-out por SSE. PHP puede,
  pero a contrapelo y con librerías de nicho.
- **La lógica ya existe en TypeScript** y llevarla a PHP sería duplicarla.
- **El cliente decente sólo existe fuera de PHP** — el caso del SDK de Pulsar de Tuya.

Node **no** entra cuando:

- El proceso tendría que escribir en la base de datos.
- La lógica ya vive en PHP y sólo se trata de reusarla desde otro sitio.
- El único motivo es rendimiento, sin duplicación ni conexión persistente de por medio.

## 8. La forma acordada para el cálculo financiero — 23/08/2026

El destino, decidido en conversación con datos delante. Todavía no se ha empezado; el paso previo
sigue siendo §5.

### El agente se queda en PHP, y no es una concesión

Es un consumidor más. `Node calcula, PHP persiste` (§4) no dice dónde vive quien pregunta.

Las skills se autolocalizan con `#[AutowireIterator]`, así que envolver la llamada HTTP al servicio
**es una clase y nada más**: ni registro que tocar, ni `match` que ampliar.

### Lo que se GANA, y no es rendimiento

Hoy la validación de si una cotización puede enviarse —`construirInclusiones()` → `advertencias`
→ `publicable`— vive **en el navegador**. PHP no la ve, así que la guarda sólo se cumple si quien
escribe es el editor: cualquier otro camino pasa por encima sin enterarse.

Al mover eso a un módulo llamable desde el servidor, **PHP puede aplicarla por primera vez**. Es
justo lo que necesita un agente que arma cotizaciones sin pasar por el editor — que es el motivo
de fondo de todo esto, no aligerar el JSON.

### Lo que se queda en PHP pase lo que pase

- **Los listeners**: `AutoTranslate`, coherencia financiera, integridad de teléfonos. Cuelgan de
  `prePersist`/`preUpdate` y ahí no llega Node.
- **El snapshot a La Biblia** y la reconciliación.
- **Las transiciones de estado** y todo lo que decide si algo se escribe.

La frontera se lee sola: *Node responde «cuánto cuesta y qué le falta»; PHP decide si eso se
escribe.*

### ⚠️ Sobre dinero se falla CERRADO

El coste de §6 —«un proceso más que puede caerse, y caerse callado»— aquí es más real que en el
sidecar. Si el agente está armando una cotización y el servicio no responde, **no se guarda**. No
se guarda sin validar.

### El módulo puro NO se saca de `util/`

> ⚠️ **Matizado el 02/09/2026 en §9.** Esta frase vale para el cálculo financiero, que vive en
> `util`. No vale como regla general: el itinerario del cliente vive en `pax`, y las dos apps no
> comparten nada. Lo que se quiere decir es *«no se saca del navegador»*, no *«se queda en esta
> app»*.


⚠️ §3 dice «mismo archivo, dos consumidores», y eso incluye seguir importándolo en el navegador.
Si el editor pasa a pedir el cálculo por red, `resumenFinanciero` deja de ser un `computed`
síncrono, y hay dos sitios donde eso no es cosmético: **el payload de guardado** lee
`totalCosto`/`totalVenta` de ahí, y **`publicable`** decide si se puede guardar. Teclear rápido
devuelve respuestas fuera de orden, y la que decide si se publica no puede ser la penúltima.
Además hay service worker: sin conexión, el editor dejaría de saber cuánto cuesta nada.

Un WebSocket abarata el viaje, pero no convierte un valor derivado síncrono en uno asíncrono — eso
hay que programarlo. Y no hace falta: un módulo TS puro se importa desde el navegador **y** desde
el servicio. Una implementación, dos sitios que la llaman.

### El reparto del store

En `docs/Pendientes.md`, con los números medidos.

## 9. La capa compartida: lo que §8 no había medido — 02/09/2026

§8 dice **«el módulo puro NO se saca de `util/`»**. Esa frase se escribió mirando sólo el cálculo
financiero, que efectivamente vive en `util`. Al ir a aplicarla al itinerario del cliente aparecen
dos hechos que la dejan corta.

### El destino no es un módulo: es una capa que hoy no existe

- **`itinerarioVista` vive en `pax`**, no en `util`.
- **`util` y `pax` no comparten nada.** Sin workspaces, sin carpeta común, sin un solo import
  cruzado. `pax` ni siquiera tenía un `src/utils/`.

Así que «extraer el módulo y envolverlo en Node» tiene un paso cero que nadie había contado:
**crear el sitio donde ese módulo pueda vivir para los dos**, lo que arrastra los dos
`vite.config.ts`, los dos `tsconfig`, los dos ESLint y el build de las dos PWAs. Ése es el coste
que hay que medir antes de mover el dinero, no el de escribir el módulo.

### Lo que se unificaría, medido

| Pieza | Dónde vive hoy | Líneas |
|---|---|---|
| `resumenFinanciero` | store del editor (`util`) | 650 |
| `construirInclusiones` | store del editor (`util`) | 304 |
| `componerItinerario` | `pax/src/dominio/itinerarioVista.ts` | 209 |

⚠️ **Y hay un espejo YA activo**, que no es deuda futura sino presente: `posicionDeServicio()`
está escrita en el store del editor **y** en el módulo del itinerario, con el aviso puesto a mano
de que hay que cambiar las dos. La capa compartida no evita un espejo hipotético: borra uno real.

Aviso al contar: en el store del editor la palabra «espejo» aparece cuatro veces y **sólo una** es
un espejo de lógica. Las otras tres hablan de una tarifa estándar que coincide con una alternativa.

### El primer inquilino ya está fuera, y por eso el resto es más barato

`componerItinerario()` (02/09/2026) es el primer módulo puro del proyecto: sin `vue`, sin store,
sin `window`. Entra la cotización, salen los días. Se comprobó ejecutándolo **en Node contra el
payload real** de dos propuestas de producción, sin navegador de por medio.

Se eligió empezar por ahí a propósito, y no por el cálculo financiero:

- Es la pieza **barata**. Si la extracción sale mal, se nota en una pantalla; si sale mal en los
  márgenes, se nota en una factura.
- Permite **estrenar el procedimiento de §5** —fijar el comportamiento con fixtures antes de
  mover nada— sobre algo que no es dinero. Al cálculo financiero se llega con el método ya
  probado, en vez de inventándolo sobre los márgenes.
- Deja **cerrada la puerta de entrada**: el módulo no importa nada de la app, que es la única
  condición para que pueda mudarse tal cual el día que exista el paquete.

⚠️ **La regla que lo mantiene mudable:** el día que alguien le meta un `import` de `@/stores` o de
`vue`, deja de poder salir — y no lo dirá ningún error.

## 10. Qué framework para Node (ninguno, todavía) — 02/09/2026

La pregunta llega sola en cuanto se planea migrar mucho cálculo: *«¿qué framework uso para
mantenerlo ordenado?»*. La respuesta corta es que **mezcla dos problemas distintos**, y sólo uno de
ellos es de framework.

| | Qué lo resuelve |
|---|---|
| **Organizar** la lógica | Fronteras de módulo, contratos estrechos y **tests** (§5). No un framework |
| **Exponer** la lógica | Ahí sí hay decisión de framework, y es la mitad pequeña |

Lo que faltaba de verdad era lo primero, y se midió: cero tests en TypeScript. Ningún framework
arregla eso.

### Exponerla: dos etapas, y la primera no lleva framework

**Hoy: `Process` + un archivo.** Medido: **50 ms** por invocación de `node` (150 ms la primera).
Para un PDF o un correo no lo nota nadie, y se ahorra entero el coste que §6 señala como el peor —un
proceso persistente que puede caerse callado y hay que supervisar—.

Node 25 **ejecuta TypeScript directamente** (`--experimental-strip-types`), comprobado en local. Si
la versión de producción acompaña, PHP lanza el `.ts` y no hay ni paso de empaquetado que mantener.
Hay que verificar la versión en el servidor: Node vive en nvm.

**Cuando duela** —muchas llamadas por petición, o PHP llamándolo en bucle— un servicio HTTP mínimo:
**Hono**. Enruta, valida y poco más, que es lo que necesita algo que sólo calcula y devuelve. Ese
día puede no llegar.

### ⚠️ NestJS se descarta, y no por pesado

Su valor está en organizar aplicaciones con I/O: inyección de dependencias, módulos, integración con
ORM. **El proceso Node de este proyecto no puede persistir** —es la frontera de §4, la que hace
barato el segundo lenguaje— así que NestJS traería toda la fontanería para cruzarla justo donde no
debe cruzarse. Un contenedor de DI alrededor de funciones puras es estructura por la estructura, y
encima abarata violar la única regla que sostiene el diseño.

### El «framework» que sí compra algo: validar la frontera

Cuando PHP mande el JSON, algo tiene que comprobar que cumple el contrato. Si no, un campo renombrado
en PHP llega como `undefined` al cálculo y **el resultado sale mal sin un solo error** — la familia
de fallo que persigue este proyecto entero.

**Zod** (o valibot) en la entrada del módulo, y la parte que lo justifica: el esquema **es** la
fuente del tipo (`z.infer`), así que el contrato deja de ser dos artefactos —un tipo que no comprueba
y una validación que no tipa— y pasa a ser uno que hace las dos cosas. Misma idea que generar
`api.d.ts` desde PHP, aplicada al borde de Node.

### El orden

El detalle por fases está en **`docs/PlanProcesamientoCompartido.md`**, reescrito el 02/09/2026:
la meta no es «compartir código» sino **una implementación y una autoridad que la hace cumplir al
escribir** — hoy `totalVenta` y `totalCosto` están en el grupo de escritura y ningún código PHP los
calcula, así que el servidor persiste lo que le mande el navegador. Resumido:

1. **Vitest + fixtures** sobre una pieza que no sea dinero. ✅ Hecho (§5).
2. **Contrato estrecho** en el módulo, para que deje de declarar la serialización entera.
3. **Zod** en la frontera, cuando exista el primer consumidor de servidor.
4. **`Process` desde PHP.**
5. **Hono** sólo cuando una medición diga que el spawn estorba.

---

## Dónde tocar para cambiar X

| Necesidad | Archivo | Nota |
|---|---|---|
| Añadir un proceso Node | este doc §7 | Comprobar los criterios antes de escribir código |
| Cambiar el cálculo financiero | el módulo puro TS | Un solo archivo; los fixtures de §5 tienen que seguir pasando |
| Entender la forma acordada (agente, validación, frontera) | §8 | Decidido el 23/08/2026, sin empezar |
| Entender por qué el sidecar no sabe de negocio | §3 y §4 | |
| Saber qué falta para el procesamiento compartido | §9 | El paso cero es la capa común entre `util` y `pax`, que no existe |
| Ver cómo es un módulo puro ya extraído | `pax/src/dominio/itinerarioVista.ts` | Sin `vue`, sin store, sin `window`. Se ejecuta en Node |
| Escribir tests de una regla de negocio en TS | `pax/src/dominio/*.test.ts` + §5 | `npm test` en `pax`. Fixtures reales podados, poda verificada |
| Elegir framework/transporte para Node | §10 | Hoy: ninguno. `Process` + 50 ms. Hono si una medición lo pide; NestJS no |
| Despliegue de PWAs y Node en producción | memoria `newoweb-deploy-procedure` | Node vive en nvm, no está en el PATH de ssh |
