# `tools/` — verificación contra datos reales

Lo que se comprueba **con la base delante**. No es la suite de PHPUnit —aquélla es unitaria y
sin base de datos, en `tests/`— ni la de TypeScript, que vive en `dominio/`.

Existe porque los dos fallos más caros de agosto de 2026 fueron **de datos, no de código**, y eso
no lo pesca ningún test unitario: se pesca ejecutando el flujo real contra filas reales.

## Las dos carpetas, y por qué son dos

| | Qué hay | Cómo se lee el resultado |
|---|---|---|
| `pruebas/` (33) | **Afirman**: terminan en ✅ o ❌ y devuelven código de salida | verde/rojo |
| `inspeccion/` (33) | **Vuelcan**: imprimen el estado de algo para mirarlo | se lee, no se aprueba |

Mezclarlas era la mitad del problema: con las dos juntas, «prueba» no significaba nada.

## Cómo se corren

```bash
php tools/pruebas/probar-escalera.php
```

Casi todas abren una **transacción y hacen rollback**: el borrado y la escritura son reales, la
comparación es sobre filas de verdad, y lo único que no ocurre es el `COMMIT`. Ninguna escribe
sin deshacer.

⚠️ **Dos interruptores del entorno las hacen mentir**, y las dos veces costó tiempo antes de que
lo dijeran ellas mismas:

- **`FINANZAS_ENLACES_PREPAGO=1`** — con 0, el emisor de enlaces no hace nada y las pruebas de
  prepago encuentran los enlaces que la reserva ya tenía y los dan por recién emitidos: **verde
  sin haber emitido nada**.
- **Llaves `sk_test_` / `pk_test_` de Culqi** — con las `_live_`, `CulqiClient` se niega a operar
  fuera de producción (a propósito: desde una base copiada de la real, «Devolver» reembolsaría un
  cargo de verdad), así que no se emite nada y el rojo culpa al código.

Las pruebas de prepago comprueban las dos cosas y abortan con un mensaje que lo explica.

## ⚠️ Tres que hablan hacia FUERA

No se ejecutan en una pasada general. Están en `inspeccion/` porque no afirman nada, pero lo que
importa es que salen de la máquina:

| | Qué hace | Cuidado |
|---|---|---|
| `probar-ventana-cerrada.php` | **Manda un WhatsApp de verdad** por la API de Meta, con nuestras credenciales, para ver si Direct Send sigue activo | recibe el número como argumento; el mensaje LLEGA a ese teléfono |
| `probar-tuya.php` | Pide a la API de Tuya el estado de un dispositivo | sólo lectura; las credenciales van por variables de entorno, no por argumento |
| `probar-skills-por-moneda.php` | Maqueta de lo que las skills le enseñan al modelo | no sale a la red, pero lee reservas reales de producción |

`probar-ventana-cerrada` es la única con consecuencia fuera: mide **el código que devuelve Meta**
—entrega, 131047 «more than 24 hours», u otro—, y para eso el mensaje tiene que salir.

## De dónde salen

Vivían en `var/`, que en Symfony es basura de ejecución —caché y logs— y está en `.gitignore`.
Eran **78 archivos y 8390 líneas** que un `rm -rf var/` se llevaba por delante, que no se
desplegaban, y de las que **39 estaban citadas en `docs/`** como prueba de comportamiento para
quien leyera el repo y no las tuviera.

Al revisarlas una a una (06/09/2026): **17 reventaban** al ejecutarse y **3 daban ❌**. Ninguno de
los rojos era una regresión del código — eran pruebas que el código dejó atrás, y **tres que
mentían**:

- una borraba mensajes de 366 conversaciones y regeneraba sólo 35, acusando al motor de perder lo
  que ella misma había borrado;
- otra exigía «que haya algo que corregir», o sea que se ponía roja cuando todo estaba bien;
- otra simulaba mensajes que el despachador mataba al guardar, en un entorno sin canal de salida.

12 se apartaron a `var/obsoletos/` —no se borraron: no estaban en git, y un `rm` no tiene vuelta
atrás—. El resto está aquí, corriendo.

## Al añadir una

- Que **afirme** o que se llame de inspección: media tinta es lo que hubo que desenredar.
- Transacción con rollback si escribe.
- Que **compruebe sus condiciones y aborte diciéndolo** si no se cumplen. Un rojo que culpa al
  código cuando el problema es el entorno cuesta más que no tener prueba.
- Que no clave textos de contenido editable. Una se puso roja el día que se corrigió la redacción
  de la guía; ahora compara contra el contenido real del ítem.
- Y cítala desde el doc del módulo, que es donde alguien la va a buscar.
