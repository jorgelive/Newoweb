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

---

## Dónde tocar para cambiar X

| Necesidad | Archivo | Nota |
|---|---|---|
| Añadir un proceso Node | este doc §7 | Comprobar los criterios antes de escribir código |
| Cambiar el cálculo financiero | el módulo puro TS | Un solo archivo; los fixtures de §5 tienen que seguir pasando |
| Entender la forma acordada (agente, validación, frontera) | §8 | Decidido el 23/08/2026, sin empezar |
| Entender por qué el sidecar no sabe de negocio | §3 y §4 | |
| Despliegue de PWAs y Node en producción | memoria `newoweb-deploy-procedure` | Node vive en nvm, no está en el PATH de ssh |
