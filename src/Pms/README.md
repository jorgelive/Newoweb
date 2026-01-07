# 🧠 PMS – Sincronización Beds24  
## Documentación de Dominio y Arquitectura

---

## 1. Propósito de este documento

Este documento describe **cómo y por qué** funciona la sincronización entre el PMS y Beds24.

No es un manual de usuario ni un tutorial técnico.
Es una **documentación de dominio**, pensada para:

- Entender el **modelo mental**
- Justificar decisiones de diseño
- Facilitar mantenimiento y evolución
- Evitar regresiones y “arreglos rápidos” en el futuro

Si algo aquí parece “estricto” o “limitante”, probablemente es **intencional**.

---

## 2. Principio rector del diseño

> **Beds24 no trabaja con reservas, trabaja con habitaciones ocupadas.**

Por eso, el PMS **no sincroniza reservas** como entidades únicas, sino
**eventos de calendario por unidad**, enlazados mediante links explícitos.

Esto permite:

- Sincronización correcta multi-room
- Propagación controlada
- Evitar duplicados
- Evitar loops
- Trazabilidad completa

---

## 3. Entidades clave

### 3.1 PmsEventoCalendario

Representa la ocupación real de **una unidad** en un rango de fechas.

Características:

- Siempre existe una unidad (`PmsUnidad`)
- Puede o no estar asociada a una reserva
- Tiene fechas, estado, pax y montos propios
- Es la **unidad mínima de sincronización**

> En Beds24, cada habitación es una fila distinta.  
> Este modelo replica exactamente esa realidad.

---

### 3.2 PmsEventoBeds24Link

Representa la relación **1 evento ↔ 1 booking Beds24**.

No es un dato accesorio:  
es el **contrato explícito** entre el PMS y Beds24.

Un evento puede tener:
- 1 link (caso simple)
- N links (multi-room)

---

## 4. Tipos de Links

### 4.1 Link Root (principal)

Un link es **root** cuando:

- `originLink === null`

Significado:

- Representa la fuente real del booking
- Es el único que define identidad comercial

Un link root **sí puede**:

- Enviar datos personales
- Enviar precios
- Enviar comisión
- Enviar `masterId`
- Representar una reserva real

---

### 4.2 Link Mirror (espejo)

Un link es **mirror** cuando:

- `originLink !== null`

Regla de dominio:

> **Un mirror no es una reserva, es solo un bloqueo técnico.**

Un mirror:

- ❌ NO representa un huésped
- ❌ NO representa dinero
- ❌ NO representa un canal
- ✅ Representa ocupación de inventario

---

## 5. Reglas estrictas de los Mirrors

Los mirrors **siempre** cumplen estas reglas:

- ❌ No envían `price`
- ❌ No envían `commission`
- ❌ No envían `masterId`
- ❌ No propagan datos personales
- ✅ Prefijan el nombre con `(M)`
- ✅ Se actualizan solo desde su root

Esto evita:

- Duplicados en Beds24
- Confusión en auditoría
- Corrupción de datos personales
- Errores en multi-room

---

## 6. SyncContext – Control del flujo global

`SyncContext` define **desde dónde se originan los cambios**.

No es un flag técnico, es una **regla de negocio transversal**.

### 6.1 SOURCE_UI

Cambios realizados por humanos desde el PMS.

Características:

- Se permite encolar
- Se permite propagar
- Se permite crear links
- Es el modo por defecto

---

### 6.2 SOURCE_PULL_BEDS24

Cambios que vienen desde Beds24 (webhooks / pull).

Objetivo:

> Evitar loops de sincronización.

Reglas:

- ❌ No se propagan cambios
- ❌ No se encolan updates generales
- ✅ Solo se permite crear links espejo
- ❌ No se modifican otros links

---

### 6.3 SOURCE_PUSH_BEDS24

Ejecución del worker de colas.

Objetivo:

> Evitar efectos colaterales durante el push.

Reglas:

- ❌ Listener completamente bloqueado
- ❌ No se crean nuevas colas
- ❌ No se reacciona a flush internos

Esto garantiza que el push sea **determinista y limpio**.

---

## 7. Listener de sincronización

El listener **NO encola todo**.

Aplica reglas explícitas.

### 7.1 Cambios que NO se encolan

- Cambios en contexto `PULL_BEDS24`
- Cambios en contexto `PUSH_BEDS24`
- Cambios solo en links mirror
- Cambios que no alteran el hash del payload

---

### 7.2 Cambios que SÍ se encolan

- Cambios hechos desde UI
- Cambios en links root
- Cambios relevantes para Beds24
- Cambios que alteran el payloadHash

---

## 8. Reservas directas (caso especial)

Una reserva es **directa** cuando:


reserva->getChannel()->isDirecto() === true

### 8.1 Datos personales que SÍ se propagan

En reservas directas, los siguientes campos:

- nombre
- apellido
- email
- teléfono
- teléfono2
- notas
- comentarios del huésped

Se consideran:

- Parte del payload
- Parte del payloadHash
- Motivo válido para reactivar colas

Esto permite **editar datos del huésped** y que se reflejen en Beds24.

---

### 8.2 Reservas no directas

En reservas de OTAs externas:

- Los datos personales **sí participan del hash**
- Pero **no siempre generan payload**
- Esto evita ruido innecesario hacia otros canales

---

## 9. Dedupe y payloadHash

El sistema usa un **payloadHash** para deduplicar colas.

### 9.1 Qué es el payloadHash

- Es una huella del payload relevante
- Representa una **intención de sincronización**
- No es un histórico
- No es versionado

---

### 9.2 Efectos colaterales (importantes)

- Si cambia un campo incluido → la cola se reactiva
- Si no cambia → no se encola nada
- Si la cola estaba en SUCCESS → se reutiliza

Esto es **intencional**.

---

## 10. Por qué no se crean colas nuevas siempre

Diseño consciente:

- Una cola = una intención
- No un evento histórico

Ventajas:

- Menos ruido
- Más claridad
- Auditoría más limpia

Si se requiere histórico completo:
- Se debe usar logging
- O snapshots
- No multiplicar colas

---

## 11. Payload Builder

`Beds24PushPayloadBuilder` es **determinista**.

Características:

- No toma decisiones de negocio
- No consulta estado externo
- Solo traduce entidades → payload Beds24

---

### 11.1 normalizeString()

Esta función:

- Evita enviar strings vacíos
- Normaliza entradas antes del payload
- No es lógica de dominio

Puede eliminarse o refactorizarse a Value Objects en el futuro.

---

## 12. Estado Beds24

El estado enviado a Beds24:

- **Nunca se hardcodea**
- Siempre se obtiene desde:
  - `PmsEventoEstado.codigoBeds24`

Esto permite:

- Mapear estados sin tocar código
- Cambiar reglas desde datos
- Mantener coherencia

---

## 13. Cancelaciones

Las cancelaciones:

- Cancelan colas POST pendientes
- Evitan crear bookings inválidos
- Usan siempre estado `cancelled` de Beds24

---

## 14. Resumen conceptual

- El **evento** es la verdad
- El **link** es el contrato
- El **mirror** es inventario
- El **contexto** gobierna el flujo
- El **hash** gobierna la intención

Si algo parece “rígido”, es porque protege el modelo.

---

## 15. Nota final

Este diseño:

- No necesita hacks
- No necesita flags temporales
- No necesita excepciones ocultas

Funciona porque **modela la realidad**, no la API.

Si algo falla, casi siempre:
- Es un bug
- O una regla mal implementada
- No un problema del modelo

---