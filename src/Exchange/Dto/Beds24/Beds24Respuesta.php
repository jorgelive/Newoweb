<?php

declare(strict_types=1);

namespace App\Exchange\Dto\Beds24;

use App\Dto\Lee;

/**
 * Un objeto de respuesta de la API v2 de Beds24: el SOBRE de una lectura (`GET bookings`,
 * `GET messages`, `GET invoices`) o cada PIEZA de la lista con que contesta una escritura (`POST
 * bookings`, `POST inventory/rooms/calendar`, `POST bookings/messages`).
 *
 * ── Por qué un solo DTO para las dos cosas ──────────────────────────────────
 * Porque Beds24 usa el MISMO vocabulario en los dos niveles —`success`, `message`, `errors`,
 * `new`, `modified`, `data`— y los siete lectores que había (tres estrategias de lectura, tres de
 * escritura y el cliente) leían esas claves cada uno a su manera. Dos DTO con los mismos campos
 * serían dos sitios donde equivocarse.
 *
 * ── Qué se lee aquí y cómo ──────────────────────────────────────────────────
 * Exactamente lo que leían las expresiones crudas a las que sustituye, y ni un campo más:
 *
 * | Campo | Clave | Lo leía |
 * |---|---|---|
 * | `exito` | `success` | las estrategias de escritura (`?? false`) y `BookingsPushHandler` (`?? true`) |
 * | `declaraFallo` | `success === false` estricto | los sobres de lectura y el error global de tarifas |
 * | `mensaje` / `primerError` | `message` / `errors[0].message` | todas, para el motivo del fallo |
 * | `idNuevo`, `idNuevoEnLista`, `id`, `bookId` | `new.id`, `new[0].id`, `id`, `bookId` | push de reservas y envío de mensajes |
 * | `modificadoOCrudo` | `modified` | el `extraData` de tarifas |
 * | `datos` / `filas` | `data` | los sobres de lectura |
 * | `siguientePagina` | `pages.nextPageExists` + `pages.nextPageLink` | la paginación de `Beds24ExchangeClient` |
 *
 * ⚠️ **Los ids NO se pasan a texto: se dejan `int|string`.** Acaban en `execution_result` tal cual
 * (`remote_id`, `remote_beds24_id`), y convertirlos cambiaría `93628254` por `"93628254"` en un
 * JSON que ya tiene 50 000 filas escritas del otro modo. Quien los usa como texto los convierte
 * él, como antes.
 *
 * ⚠️ **`exito` es `Lee::booleano()`, no `(bool)`.** Beds24 manda un booleano JSON, y con uno las dos
 * lecturas coinciden —comprobado contra las respuestas guardadas, ver
 * `tools/pruebas/probar-dto-canales.php`—. Sólo discrepan con el texto `"false"`, que `(bool)` leía
 * como éxito. La decisión de qué hacer si falta (`?? false` o `?? true`) se queda en cada consumidor
 * porque no es la misma en todos.
 *
 * Ver `docs/PmsBeds24ReservasSync.md` §8.2 y `docs/TiposDeFrontera.md`.
 */
final readonly class Beds24Respuesta
{
    /**
     * @param array<mixed>|null        $datos            `data`, si es un array; tal cual, con sus claves.
     * @param array<array-key, mixed>  $filas            Lo que el pull de reservas trata como la lista de reservas.
     * @param array<array-key, mixed>  $modificadoOCrudo `modified` como lo dejaba `(array)`, o el objeto entero.
     * @param array<mixed>             $crudo            El objeto tal cual, para la auditoría que lo guarda.
     */
    public function __construct(
        public ?bool $exito,
        public bool $declaraFallo,
        public ?string $mensaje,
        public ?string $primerError,
        public int|string|null $idNuevo,
        public int|string|null $idNuevoEnLista,
        public int|string|null $id,
        public int|string|null $bookId,
        public ?array $datos,
        public array $filas,
        public array $modificadoOCrudo,
        public ?string $siguientePagina,
        public array $crudo,
    ) {}

    /** @param array<mixed> $respuesta Un objeto de Beds24 ya decodificado. */
    public static function fromArray(array $respuesta): self
    {
        $datos = is_array($respuesta['data'] ?? null) ? $respuesta['data'] : null;

        return new self(
            exito: Lee::booleano($respuesta['success'] ?? null),
            declaraFallo: ($respuesta['success'] ?? null) === false,
            mensaje: Lee::texto($respuesta['message'] ?? null),
            primerError: Lee::texto(Lee::en($respuesta, 'errors', 0, 'message')),
            idNuevo: self::id(Lee::en($respuesta, 'new', 'id')),
            idNuevoEnLista: self::id(Lee::en($respuesta, 'new', 0, 'id')),
            id: self::id($respuesta['id'] ?? null),
            bookId: self::id($respuesta['bookId'] ?? null),
            datos: $datos,
            filas: self::filas($respuesta, $datos),
            modificadoOCrudo: self::modificadoOCrudo($respuesta),
            siguientePagina: self::siguientePagina($respuesta),
            crudo: $respuesta,
        );
    }

    /**
     * Una pieza de una lista de respuestas, sea lo que sea.
     *
     * Las estrategias de escritura recorren la lista que devuelve Beds24 y cada elemento llega como
     * `mixed`. Lo que no es un objeto no trae nada que leer: es una pieza vacía —sin `success`, así
     * que fallida para quien pone `?? false`—, que es como acababa antes con los `?? false` sobre un
     * escalar.
     */
    public static function dePieza(mixed $pieza): self
    {
        return self::fromArray(is_array($pieza) ? $pieza : []);
    }

    /**
     * El `bookingId` de un objeto de la lista `data` (un mensaje, una línea de factura), como clave
     * para repartirlo entre las reservas pedidas.
     *
     * ⚠️ **Texto, siempre.** PHP convierte a int las claves numéricas de un array, y
     * `getTargetBookId()` devuelve string: sin el paso a texto la búsqueda no encuentra nada y TODAS
     * las reservas parecen vacías. El número pasa a texto como hacía el `(string)` de antes; lo que no
     * es número ni texto no casa con ninguna reserva (`''`).
     *
     * Es estático y no un campo porque se lee sobre cada elemento de `data`, no sobre el sobre, y
     * esos elementos los lee después su propio DTO (`Beds24MessageDto`, `Beds24InvoiceItemDto`).
     *
     * @param array<mixed> $objeto
     */
    public static function bookingIdDe(array $objeto): string
    {
        $bookingId = self::id($objeto['bookingId'] ?? null);

        return $bookingId === null ? '' : (string) $bookingId;
    }

    /**
     * Un id tal cual: número o texto. Lo demás —un objeto, un booleano— no es un id.
     */
    private static function id(mixed $valor): int|string|null
    {
        return is_int($valor) || is_string($valor) ? $valor : null;
    }

    /**
     * La lista de reservas del pull: dentro de `data` si lo hay; el objeto entero envuelto si es
     * UNA reserva suelta (trae `id` y no es una lista); y si no, la respuesta tal cual, que en la
     * v2 es la lista en la raíz.
     *
     * ⚠️ Se conserva el último caso aunque sea raro —un objeto sin `data` ni `id` acaba recorrido
     * clave a clave—: el handler cuenta como fallida cada fila que no es un objeto, y ese recuento
     * es el que avisa de que la respuesta vino con otra forma. Filtrar aquí lo haría mudo.
     *
     * @param array<mixed>      $respuesta
     * @param array<mixed>|null $datos
     * @return array<array-key, mixed>
     */
    private static function filas(array $respuesta, ?array $datos): array
    {
        if ($datos !== null) {
            return $datos;
        }

        if (isset($respuesta['id']) && !isset($respuesta[0])) {
            return [$respuesta];
        }

        return $respuesta;
    }

    /**
     * `(array) ($pieza['modified'] ?? $pieza)`, que es lo que guardaba la estrategia de tarifas como
     * `extraData`: el objeto `modified` si viene, un escalar envuelto en lista como hace el cast, o
     * la pieza entera si no hay `modified`.
     *
     * @param array<mixed> $respuesta
     * @return array<array-key, mixed>
     */
    private static function modificadoOCrudo(array $respuesta): array
    {
        $modificado = $respuesta['modified'] ?? null;

        if ($modificado === null) {
            return $respuesta;
        }

        return is_array($modificado) ? $modificado : [$modificado];
    }

    /**
     * El enlace a la página siguiente, sólo si Beds24 dice que existe (`nextPageExists === true`) y
     * lo da. El enlace ya trae los parámetros de la consulta original.
     *
     * @param array<mixed> $respuesta
     */
    private static function siguientePagina(array $respuesta): ?string
    {
        if (Lee::en($respuesta, 'pages', 'nextPageExists') !== true) {
            return null;
        }

        $enlace = Lee::texto(Lee::en($respuesta, 'pages', 'nextPageLink'));

        // `empty()` y no `=== ''`: es la condición que había, y descarta también el «0».
        return empty($enlace) ? null : $enlace;
    }
}
