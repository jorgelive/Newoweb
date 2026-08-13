# Logging y rotación

**Propósito.** Cómo se escriben y rotan los logs de Newoweb, y por qué el filtro de
deprecaciones existe. **Alcance:** `config/packages/monolog.yaml`,
`src/Logging/FiltroDeprecacionesVendor.php`, y el logrotate del servidor
(`/etc/logrotate.d/openperu`, fuera del repositorio).

---

## 1. Quién rota qué

Regla única: **en producción rota logrotate, no Monolog.** En desarrollo rota Monolog,
porque en un Mac no hay logrotate.

| Entorno | Handler | Rotación |
|---|---|---|
| `dev` | `stream` → `rotating_file`, `max_files: 5` | Monolog |
| `prod` | `stream` de nombre fijo | logrotate, con `copytruncate` |

### El bug que originó esta regla (agosto 2026)

El directorio de logs de producción llegó a **862 MB en 109 ficheros**. La causa no era
el volumen: era que **los dos rotadores actuaban sobre los mismos ficheros**.

```
Monolog (rotating_file)     logrotate (glob *.log, dateext)
        │                            │
        ├─ crea cada día un          ├─ renombra a
        │  nombre base NUEVO:        │  deprecations-prod-2026-08-12.log-20260813-000001
        │  deprecations-prod-             │
        │  2026-08-12.log            └─ y trata ese nombre base como una
        │                               serie propia, con derecho a sus
        └─ su max_files nunca            propias 30 rotaciones
           reconoce los renombrados
```

Cada día nacía una serie nueva que nadie caducaba. Ninguno de los dos rotadores estaba
mal configurado por separado; sobraba uno.

**Al tocar la rotación:** si se vuelve a poner `rotating_file` en `prod`, hay que sacar
esos ficheros del glob de logrotate. No se pueden dejar los dos.

---

## 2. El filtro de deprecaciones

`FiltroDeprecacionesVendor` decora al handler real y **descarta las deprecaciones que
emite el propio código de `vendor/`**, dejando pasar las de `symfony/*`.

La distinción no es estética. De los 862 MB, **736 MB eran una sola deprecación de
api-platform repetida** (`ApiProperty::getBuiltinTypes()`), disparada por api-platform
llamándose a sí misma. No hay nada que arreglar en `src/`: se resuelve el día que su
autor la resuelva. Mientras tanto enterraba las deprecaciones propias, que sí son
accionables y son el inventario de trabajo para el salto a Symfony 7.

El filtro se ancla al prefijo **`Since <paquete>`** con que Symfony formatea todo aviso
de deprecación, no a la mera aparición del nombre del paquete — así un error nuestro que
mencione api-platform no desaparece del log. Hay test para ese caso.

Paquetes filtrados hoy: `api-platform/`, `vich/uploader-bundle`.

**Gotcha:** `handle()` devuelve `true` al descartar. En Monolog eso significa «consumido»,
que es justo lo que se quiere — si devolviera `false`, el registro se propagaría al
siguiente handler y volvería a escribirse.

---

## 3. Deprecaciones propias: estado

Tras apagar las anotaciones de Doctrine (`framework.annotations: false`) y convertir el
último comando a `#[AsCommand]`, las deprecaciones de `symfony/*` bajaron **de 104 a 0**
en un warmup limpio. Si vuelven a aparecer en el log, son trabajo real pendiente para el
upgrade a 7.4.

---

## 4. Dónde tocar para cambiar X

| Necesidad | Archivo | Símbolo / clave |
|---|---|---|
| Cambiar qué se escribe en `info.log` | `config/packages/monolog.yaml` | `when@prod.handlers.info.channels` |
| Cambiar retención de logs en producción | `/etc/logrotate.d/openperu` (servidor) | `rotate`, `size` |
| Cambiar retención en desarrollo | `config/packages/monolog.yaml` | `when@dev.handlers.main.max_files` |
| Filtrar otro paquete ruidoso | `src/Logging/FiltroDeprecacionesVendor.php` | `PAQUETES_IGNORADOS` |
| Dejar de filtrar y ver todo | `config/packages/monolog.yaml` | `deprecation_stream.id` → apuntar a `app.logging.deprecaciones_stream` |
| Revisar el handler real del filtro | `config/services/services_core.yaml` | `app.logging.deprecaciones_stream` |
