# Tipos de frontera: DTO donde entra un dato de fuera

Todo dato que entra al sistema desde fuera —un webhook, la respuesta de una API, lo que escribe el
modelo en una skill, el cuerpo de una petición— llega como `array<mixed>`. Este documento dice
**dónde se convierte en tipos, con qué, y cómo se comprueba que la conversión no cambió nada.**

Nació de la subida de PHPStan al **nivel 9** (el de `mixed`), en septiembre de 2026. El nivel no
es el objetivo: lo es que cada frontera se lea **una vez**, en un sitio, y que el resto del código
trabaje con objetos que dicen lo que llevan.

## Índice

1. La regla
2. El lector: `App\Dto\Lee`
3. Cómo se cambia una frontera sin cambiar lo que se guarda
4. El mapa de fronteras y su estado
5. Lo que destapó por el camino
6. Dónde tocar para cambiar X

## 1. La regla

```
JSON de fuera ──► DTO::fromArray()  ──► el resto del código, tipado
                   └─ único sitio que toca el array crudo, con Lee::…
```

Por orden de preferencia:

| Situación | Qué se pone |
|---|---|
| El framework ya lo trae | eso: `#[MapRequestPayload]` para cuerpos HTTP, `#[Argument]`/`#[Option]` para comandos |
| Una forma externa que se lee en más de un sitio, o que tiene reglas | **un DTO** con `fromArray()` |
| El resultado de una consulta SQL cruda | un `@var list<array{…}>`: la forma la define la consulta y no se reutiliza |
| Un `getResult()` de Doctrine | `@var list<Entidad>` (regla de `CLAUDE.md`) |
| Un dato sin forma fija (una credencial en un JSON de configuración) | **último recurso**: el lector `Lee` en el sitio |

⚠️ **Un lector genérico en la lógica de negocio es la señal de que falta un DTO.** `Lee` vive
dentro de los `fromArray()`; si aparece en un servicio, algo se está leyendo dos veces.

## 2. El lector: `App\Dto\Lee`

**Lo que no es del tipo esperado es «no llegó» (`null`)**, no un `(string)` a ciegas. Un array donde
se esperaba texto era, con el cast, la palabra «Array» guardada y un warning.

| Método | Qué hace |
|---|---|
| `texto()` | el texto **tal cual**; números a texto como una interpolación |
| `textoLimpio()` | recortado, y el vacío es `null` |
| `entero()` / `decimal()` | aceptan el número en texto (`"1727312345"`, `"120.50"`) |
| `booleano()` | `true`/`"false"`/`"1"`…; ausente o basura es `null` («no se sabe» no es `false`) |
| `mapa()` / `listaDeMapas()` / `listaDeTextos()` | estructuras, descartando lo que no encaja |
| `en($x, 'a', 'b', 0)` | un campo anidado sin avisos por lo que falte |

⚠️ **`texto()` y `textoLimpio()` no son intercambiables.** Cambiar una por otra en un DTO que ya
está en producción cambia lo que se guarda. Los de Meta y pagos usan `texto()` porque sustituían
lecturas crudas y no debían cambiar ni un carácter; `Beds24BookingDto` usa la limpia porque su
problema era justo que el vacío se guardaba de dos formas.

🔥 **`booleano()` mira `null` y `''` antes de `filter_var()`.** Con `FILTER_NULL_ON_FAILURE`,
`filter_var(null, …)` devuelve `false`: un `conRecargo` ausente se leía como «sin recargo» —el
contrario del valor por defecto— en un enlace de pago. Lo cazó `DtoDePagosTest` antes de desplegar.

## 3. Cómo se cambia una frontera sin cambiar lo que se guarda

El patrón que se usó con Meta y con pagos, y que se repite en cada frontera con datos guardados:

1. **Inventario**: qué campos lee hoy el código (`grep` de las lecturas crudas).
2. **El DTO**, con esos campos y ni uno más.
3. **Antes de tocar el consumidor**, una prueba en `tools/pruebas/probar-dto-*.php` que recorre
   los datos REALES guardados —la auditoría de webhooks, las respuestas de las pasarelas— y compara
   la lectura cruda de antes con la del DTO, campo a campo. Se corre en el servidor, desde `/tmp`,
   sin tocar el árbol de producción.
4. Sólo con ✅ idénticos, se pasa el consumidor al DTO.
5. Después del despliegue, la misma prueba otra vez, y el flujo real con la transacción deshecha.

| Frontera | Prueba | Datos reales comparados |
|---|---|---|
| Webhook de Meta | `probar-dto-meta.php` | 3 250 webhooks: 456 mensajes, 2 794 estados |
| Pagos (Culqi, Izipay) | `probar-dto-pagos.php` | 18 enlaces, 15 cargos, 22 intentos auditados, 17 avisos |
| Booking de Beds24 | comparación de los dos caminos del DTO | 400 webhooks + 366 respuestas de pull |

## 4. El mapa de fronteras y su estado

| Frontera | DTO | Estado |
|---|---|---|
| Booking de Beds24 (pull y webhook) | `Beds24BookingDto::fromArray()` | ✅ un solo camino (26/09/2026) |
| Webhook de Meta | `src/Message/Dto/Meta/` | ✅ |
| Pagos: respuestas de Culqi, avisos, transacción, cuerpo del enlace | `src/Finanzas/Dto/` | ✅ |

El resto de fronteras se va añadiendo aquí según se cierra; el orden y las cifras de partida están
en el historial de la subida (1 256 avisos en 14 fronteras el 26/09/2026).

## 5. Lo que destapó por el camino

- **Beds24**: el pull construía el DTO por el serializer y el webhook por `fromArray()`. El mismo
  dato vivía de dos formas en la base (`''` y `NULL`). Ver `docs/PmsBeds24ReservasSync.md` §12.20.
- **Pagos**: `conRecargo` se leía con `(bool)`, y `(bool) "false"` es `true`.
- **Pagos**: el `?? 'Error desconocido'` de los rechazos de Meta nunca podía actuar: `json_encode()`
  falla con `false`, no con `null`.
- **El lector mismo**: el `filter_var(null)` de la sección 2, cazado antes de salir.

## 6. Dónde tocar para cambiar X

| Necesidad | Dónde |
|---|---|
| Leer un campo nuevo de una frontera | el `fromArray()` de su DTO — y su `probar-dto-*.php` |
| Cambiar cómo se interpreta un tipo suelto | `App\Dto\Lee` — y su `LeeTest` |
| Añadir una frontera | un DTO en `src/<Modulo>/Dto/`, su prueba contra datos reales, y una fila en §4 |
