# Autotraducción

Cómo el contenido escrito en español llega a los otros seis idiomas, quién decide cuándo se
rehace una traducción, y las trampas que este sistema ha tenido.

**Alcance:** `src/Service/Translate/`, `src/EventListener/AutoTranslationEventListener.php`,
`src/Attribute/AutoTranslate.php`, `src/Entity/Trait/AutoTranslateControlTrait.php` y las 25
entidades que lo usan.

## Índice

1. [Las piezas](#1-las-piezas)
2. [Qué decide que algo se traduzca](#2-qué-decide-que-algo-se-traduzca)
3. [El `origenHash`](#3-el-origenhash)
4. [El flag de sobrescritura](#4-el-flag-de-sobrescritura)
5. [El veto por propiedad](#5-el-veto-por-propiedad)
6. [Planas y anidadas](#6-planas-y-anidadas)
7. [Los marcadores](#7-los-marcadores)
8. [Sellar el contenido existente](#8-sellar-el-contenido-existente)
9. [Gotchas](#9-gotchas)
10. [Dónde tocar para cambiar X](#10-dónde-tocar-para-cambiar-x)

---

## 1. Las piezas

```
#[AutoTranslate]           marca la propiedad traducible
AutoTranslateControlTrait  los dos interruptores de la entidad
        │
AutoTranslationEventListener   prePersist + preUpdate
        │
AutoTranslationService     ← toda la decisión vive aquí
        │
        ├── ProtectorDeMarcadores   enmascara {{ marcadores }}
        └── GoogleTranslateService  la llamada a Google V3
```

Los idiomas activos salen de `maestro_idioma` con `prioridad > 0`. Hoy son siete: `es` (origen),
`en`, `pt`, `fr`, `it`, `de`, `nl`.

⚠️ **`prePersist` ocurre FUERA de la transacción del flush; `preUpdate`, DENTRO.** Verificado en
Doctrine 2.20.13: `UnitOfWork::commit()` abre transacción y luego llama a `executeUpdates()`, que
es quien dispara `preUpdate`. O sea que **editar** una entidad traducible mantiene los locks
abiertos durante las llamadas HTTP a Google; **crearla**, no. La asimetría es lo que lo hace
difícil de ver: se prueba creando, y creando va bien.

## 2. Qué decide que algo se traduzca

Todo pasa por `AutoTranslationService::translateAndCloneRows()` — planas y anidadas, tres sitios
de llamada y ni uno más. Por cada idioma destino, en orden:

```
1. ¿origenHash === 'manual'?      → no se toca (salvo flag explícito)
2. ¿propiedad vetada y hay texto? → no se toca
3. ¿modo sellar?                  → estampa el hash y no llama a nadie
4. ¿hay texto y el hash cuadra?   → nada que hacer
5. resto (vacío, sin hash, hash desfasado, o flag) → se traduce
```

⚠️ **Una fila SIN hash se rehace.** No se sella dándola por buena: es una fila de la que no
sabemos si corresponde a su español, y la única forma honesta de averiguarlo es rehacerla. Quien
sí lo sabe es la persona que corre el comando de sellado (§8).

Hubo una versión con la rama contraria —sellar lo que no tiene hash— y se retiró el mismo día:
volvía **decorativo** el `--clase` del comando. Si no sellar acaba sellando igual, sólo que más
tarde y sin que nadie lo mire, entonces no hay decisión que tomar. Lo que esa rama protegía —las
plantillas de Meta— lo protege ya el veto de §5, que se evalúa antes.

## 3. El `origenHash`

Cada fila traducida lleva dentro del JSON la huella del español del que salió:

```json
{"language": "en", "content": "Shower with hot water", "origenHash": "a1b2…"}
```

Si el español cambia, la huella deja de cuadrar y esa fila se rehace **sola, al guardar**. Ése es
el caso normal, y **no depende de ningún flag**.

### Por qué en la fila y no indexado por campo

Una sola columna puede contener varias unidades traducibles: `MessageTemplate::$whatsappMetaTmpl`
tiene `body`, `header`, `footer` y un `button_text` por botón. Cualquier esquema con clave
necesitaría componer `propiedad + ruta JSON + índice de botón`, y ese índice es **posicional** —
reordenar los botones emparejaría textos que no se corresponden.

En la fila, el hash viaja pegado a su texto y el problema no existe. La alternativa que se
descartó, comparar el changeset de Doctrine en `preUpdate`, sirve para las 34 propiedades planas
y se rompe en las 8 anidadas: el changeset da la **columna entera**, no la hoja.

### Por qué no hizo falta migración

Las columnas ya son `type: 'json'`, `translateAndCloneRows()` usa `array_merge` —que conserva
claves de más a propósito— y el esquema OpenAPI las exporta como diccionario abierto, así que una
clave más encaja sin tocar nada. **Y no es una apuesta: las filas de `MessageTemplate::$body` ya
viajaban con una tercera clave, `status`, desde antes de todo esto.**

### Cómo se calcula

`hashDeOrigen()`, y las tres decisiones importan:

| | Por qué |
|---|---|
| Sobre el texto **crudo**, antes de enmascarar | Los centinelas del `ProtectorDeMarcadores` dependen del orden de aparición; atar la huella a eso la ataría a un detalle interno |
| Con los **espacios normalizados** | Los editores de texto enriquecido reescriben el HTML al guardar aunque nadie lo toque. Sin esto, cada guardado retraduciría los seis idiomas por un salto de línea |
| Con el **`mimeType` dentro** | Pasar un campo de `text` a `html` obliga a rehacer las traducciones y el texto es el mismo: sin el formato, el hash cuadraría y no se rehacía nada |

### El centinela `manual`

`origenHash: 'manual'` marca una traducción curada a mano que el camino automático no rehace
nunca, ni aunque cambie el español. El flag explícito **sí** la pisa: es una acción humana
deliberada sobre un campo concreto, y el centinela está para que nadie la retraduzca sin querer,
no para blindarla contra su dueño.

## 4. El flag de sobrescritura

`sobreescribirTraduccion` significa **«rehaz TODO ahora, cambie o no el origen»**. Es la
excepción, no el modo de trabajo.

⚠️ **`false` no es «no se toca nada».** Con el flag apagado se rehace igualmente cualquier fila
cuyo hash no cuadre. Para lo que sirve el flag es para lo que el hash **no puede** detectar:

- una traducción que quedó mal sin que el origen cambiara (se cayó Google, se perdió un marcador)
- un cambio de glosario
- rehacer una corrección manual que no convenció

Se apaga solo en cuanto se ejecuta: es un disparo único y no debe quedarse pegado.

### Historia, porque explica por qué el sistema estaba así

Hasta el 31/08/2026 **este flag era el único camino** para propagar una corrección del español.
Había que pulsarlo cada vez, nadie lo hacía, y las siete traducciones se quedaban diciendo lo
viejo para siempre — es el origen de las siete duchas que se contradecían entre superficies
(`docs/Mensajeria.md` §22).

Se estudió invertirlo (`noSobrescribir`) o encenderlo por defecto. **Ninguna de las dos
funciona:**

- `options: ['default' => false]` en la columna es **sólo DDL**: Doctrine nunca lo lee al
  construir un objeto PHP. El default que manda es el inicializador de la propiedad.
- Y aunque se ponga a `true`, el auto-apagado lo anula: la entidad nace encendida, `prePersist`
  traduce, el servicio la apaga, y **la fila se INSERTA con `false`**. Todo `UPDATE` posterior va
  en modo seguro. Sobrescribe sólo en el alta, que es donde no hay nada que sobrescribir.
- Invertir el nombre es peor: el auto-apagado pasaría a escribir `noSobrescribir = true` tras el
  primer guardado, dejando cada entidad en modo seguro para siempre y grabando lo contrario de lo
  que dejó puesto el operador. Más 25 `ALTER TABLE` y migrar contenido dentro de los JSON, porque
  la misma clave se usa como flag por-contenedor.

**El flag no era el problema.** Cargaba con un trabajo que no le tocaba.

## 5. El veto por propiedad

`#[AutoTranslate(preventOverwriteIf: 'isWhatsappMetaOfficial')]` nombra un método de la entidad
que, si devuelve `true`, prohíbe pisar traducciones existentes de esa propiedad.

⚠️ **Desde el 31/08/2026 el veto NO cuelga del flag.** Antes sólo frenaba la sobrescritura
manual; con el hash, una plantilla aprobada por Meta se habría retraducido sola en cuanto alguien
tocara el español, quedándose desincronizada del texto que Meta aprobó — y sin que nadie pulsara
nada.

Lo que el veto **no** impide es rellenar un idioma vacío: eso no pisa ninguna traducción, y es
como se traduce por primera vez una plantilla nueva.

## 6. Planas y anidadas

42 propiedades traducibles: **34 planas** (la columna JSON *es* la lista de traducciones) y
**8 anidadas** (`nestedFields`, con notación de flecha para bajar niveles).

```php
#[AutoTranslate(sourceLanguage: 'es')]                                    // plana
#[AutoTranslate(sourceLanguage: 'es', nestedFields: ['titulo'])]          // una hoja
#[AutoTranslate(nestedFields: ['body', 'buttons_map->button_text'])]      // varias hojas
```

Las dos formas convergen en `translateAndCloneRows()`, y por eso el hash y el modo sellar valen
para las dos sin código aparte.

## 7. Los marcadores

Google **traduce el interior de los `{{ marcadores }}`**: `{{ medios_pago }}` vuelve como
`{{ payment_methods }}` y el interpolador ya no lo reconoce — el huésped ve la llave en crudo.
`ProtectorDeMarcadores` los enmascara antes y los restaura después.

Si el traductor se come un centinela, **esa traducción no se guarda** y queda un `warning` en el
log. Es deliberado: un texto al que le falta el widget de medios de pago se publica igual de
callado que uno bueno, y nadie lo nota hasta que alguien pregunta cómo pagar.

## 8. Sellar el contenido existente

```bash
php bin/console app:traduccion:sellar-hash --dry-run
php bin/console app:traduccion:sellar-hash --clase=PmsGuia
```

Estampa el `origenHash` en lo que ya estaba traducido **sin traducir nada**. Sin él, el primer
guardado de cada entidad retraduciría los seis idiomas: medido el 31/08/2026, **2359 entidades**.

⚠️ **Sellar es DECLARAR QUE LO QUE HAY ES CORRECTO**, y por eso `--clase` no es una comodidad,
es el mecanismo. Una traducción que ya estuviera desfasada queda **congelada**: el sistema la dará
por buena y no la rehará nunca. Se sella módulo a módulo, sólo aquello cuyo contenido te creas; lo
que no selles se retraduce solo la primera vez que se guarde, que es justo lo que quieres para el
contenido del que dudas.

Dos cinturones impiden que traduzca: el modo `soloSellar` no llama al traductor, y además apaga
`ejecutarTraduccion` en cada entidad, así que el listener sale antes de nada en el flush.

### Qué se selló el 31/08/2026, y qué no

| | Entidades | Por qué |
|---|---|---|
| **Sellado** | `TravelTarifa` (672), `UiI18n` (213) | Títulos operativos y textos de interfaz: contenido poco sospechoso, y nadie recuerda contradicciones ahí |
| **Sin sellar** | `PmsGuiaItem` (61), `PmsGuiaSeccion` (23), `TravelSegmento` (186) | Es lo que **lee el huésped**, y es donde ya hubo traducciones que decían lo contrario que el español (las siete duchas). Se rehacen solas al editarlas |
| **Sin sellar** | las otras 20 | No sellar es la opción reversible; sellar de más congela una mentira y no tiene vuelta |

⚠️ Rehacer **pisa cualquier traducción corregida a mano** en esas clases. El día que exista una
que merezca conservarse, se marca con `origenHash: 'manual'` (§3).

Descubre las entidades **por el atributo** (metadata de Doctrine + reflexión), no por una lista:
una lista se pudre el día que alguien añada una entidad traducible, y el síntoma sería mudo — esa
entidad no se sellaría y se retraduciría entera sin que nadie lo pidiera.

## 9. Gotchas

### ⚠️ Bajar un idioma a prioridad 0 BORRABA su contenido *(arreglado el 31/08/2026)*

`translateAndCloneRows()` filtraba el mapa contra `validLanguageCodes` **antes de mirar el flag**,
así que retirar un idioma del catálogo —«ya no vendemos ahí»— borraba lo ya traducido de las 25
entidades según se iban guardando. Sin error y sin log, y con el flag de sobrescritura apagado,
que es justo el modo que prometía respetar lo existente.

Retirar un idioma significa «deja de traducir a él», no «tira lo traducido». Las filas de idiomas
desconocidos se conservan intactas y vuelven a mantenerse solas si ese idioma se reactiva.

### ⚠️ El `catch` que apagaba el sistema en silencio *(arreglado el 31/08/2026)*

Un `catch (\Throwable) { continue; }` sin log: credenciales caducadas, cuota agotada o red
cortada dejaban las siete traducciones sin hacer y la entidad se guardaba con sólo el español. El
logger estaba inyectado y se usaba diez líneas más arriba.

### ⚠️ `#[AutoTranslate]` sin el trait es un atributo inerte y mudo

`processEntity()` sale antes de nada si la entidad no tiene `getEjecutarTraduccion()`. Hoy las 25
emparejan bien, pero nada lo obliga: es la misma familia que el `#[Assert\Valid]` sin su `use`.

### ⚠️ Una migración SQL se salta el listener entero

Es la regla de CLAUDE.md: todo campo con `#[AutoTranslate]` entra **por comando (ORM)**, nunca por
migración. Un `UPDATE` directo crea fichas sólo en español, o deja siete traducciones vivas
diciendo lo contrario que el original.

### ⚠️ Los 30 comandos de carga traducen dentro de la transacción, y ninguno apaga el listener

Hacen `setTitulo([['language' => 'es', 'content' => $x]])`, o sea reemplazan el array entero: el
listener ve sólo el español, los otros seis desaparecidos, y los rehace los seis. Lleva meses
funcionando así. Si añades un comando de carga masiva, considera `setEjecutarTraduccion(false)`.

### ⚠️ `validLanguageCodes` se cachea para toda la vida del proceso

Cambiar prioridades en `maestro_idioma` no surte efecto en los workers de messenger hasta
reiniciarlos.

### Dos mecanismos de sobrescritura conviven

Además del flag de la entidad hay flags `sobreescribirTraduccion` **por contenedor dentro del
JSON** (`peekLocalOverwrite`/`resetOverwriteFlags`). Quedaron semiobsoletos con el hash y siguen
funcionando; si alguien los retira, que sea a conciencia.

## 10. Dónde tocar para cambiar X

| Necesidad | Archivo | Método / clave |
|---|---|---|
| Que un campo nuevo se traduzca | la entidad | `#[AutoTranslate]` + `use AutoTranslateControlTrait` — **los dos**, o el atributo no hace nada |
| Cambiar cuándo se rehace una traducción | `AutoTranslationService` | `translateAndCloneRows()` — el embudo por el que pasan todas las hojas |
| Cambiar cómo se calcula la huella | `AutoTranslationService` | `hashDeOrigen()` — ojo: cambiarlo invalida TODAS las huellas y retraduce todo |
| Blindar una propiedad contra retraducción | la entidad | `preventOverwriteIf: 'miMetodo'` en el atributo |
| Blindar una traducción concreta | el dato | `origenHash: 'manual'` en esa fila |
| Añadir o quitar un idioma | `maestro_idioma` | `prioridad > 0`. Bajarlo a 0 ya **no** borra nada, pero exige reiniciar los workers |
| Evitar que un comando traduzca | el comando | `setEjecutarTraduccion(false)` antes del flush |
| Sellar contenido sin traducirlo | — | `app:traduccion:sellar-hash --dry-run --clase=` |
| Que un `{{ marcador }}` sobreviva | `ProtectorDeMarcadores` | `enmascarar()` / `estaIntacto()` |
| Sacar la traducción de la transacción | `AutoTranslationEventListener` | **Sin hacer.** Ver la nota de §1: hoy `preUpdate` traduce con los locks abiertos |
