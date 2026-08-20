<?php

declare(strict_types=1);

namespace App\Message\Enum;

/**
 * Por dónde se reconoce a alguien.
 *
 * No es «el canal por el que escribió»: un mismo correo puede llegar por Graph o por IMAP y
 * sigue siendo la misma persona. Lo que identifica es el **valor**, no la puerta.
 */
enum IdentidadTipo: string
{
    case TELEFONO = 'telefono';
    case EMAIL = 'email';

    /**
     * El `bookId` de Beds24.
     *
     * ⚠️ **No es un dato de contacto, y por eso hacía falta.** Un hilo de reserva de OTA nace
     * SIN teléfono y SIN correo —el huésped todavía no ha escrito— y aun así es alcanzable: la
     * salida por Beds24 se dirige con `targetBookId`, no con un número
     * ({@see \App\Message\Entity\Beds24SendQueue}).
     *
     * Un identificador es «por dónde se le reconoce o se le alcanza», y esto lo es. Sin él, la
     * mitad de los hilos no tendría ninguno y habría que seguir resolviéndolos por
     * `(contextType, contextId)` — es decir, mantener dos criterios vivos.
     */
    case BEDS24 = 'beds24';

    /**
     * Deja el valor en su forma canónica, que es lo que hace fiable la búsqueda exacta.
     *
     * ⚠️ **La normalización vive aquí y sólo aquí.** Si cada sitio que guarda una identidad
     * normaliza a su manera, dos formas del mismo correo acaban en dos hilos — que es el
     * problema que esta tabla viene a cerrar.
     */
    public function normalizar(string $valor): string
    {
        $valor = trim($valor);

        return match ($this) {
            // Minúsculas: los buzones no distinguen mayúsculas en la parte local en la
            // práctica, y `Jorge@` frente a `jorge@` son la misma persona escribiendo.
            self::EMAIL => mb_strtolower($valor),
            // SÓLO DÍGITOS, y el `+` también fuera. Lo que llega trae espacios, guiones y
            // paréntesis según quién lo escriba; `PhoneSanitizer` hace el trabajo fino de país
            // y esto es el mínimo para que la comparación exacta no falle por un signo.
            //
            // ⚠️ El `+` se conservaba, y era una partición esperando: `+51984123456` y
            // `51984123456` son el mismo número y daban DOS identidades — o sea dos hilos para
            // la misma persona, que es justo lo que la tabla vino a impedir. En producción no
            // hay ni un valor con `+` (0 de 280 identidades, 0 de 298 teléfonos de reserva)
            // porque todos entraron por el mismo sitio; la puerta la abre el editor manual, en
            // el que alguien lo teclea como se dice.
            self::TELEFONO => (string) preg_replace('/\D/', '', $valor),
            // Sólo dígitos: el bookId de Beds24 es numérico y llega unas veces como int y
            // otras como string. Sin esto, `88591163` y `'88591163 '` serían dos identidades.
            self::BEDS24 => (string) preg_replace('/\D/', '', $valor),
        };
    }
}
