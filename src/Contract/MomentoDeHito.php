<?php

declare(strict_types=1);

namespace App\Contract;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * La fecha de un hito, en el único formato en que puede existir.
 *
 * ── Por qué existe este tipo ────────────────────────────────────────────────
 * Un hito nace como objeto de fecha (lo calcula `PmsReservaMessageContext` desde los tramos) y
 * vive como texto (columna JSON). Esa doble forma se coló por todo el módulo: llegó a haber
 * **cinco sitios** que aceptaban `string|DateTimeInterface|array` y ramificaban por su cuenta —
 * `setMilestones()`, `marcarCancelado()`, `addContextMilestone()`, `setContextMilestones()` y
 * `parseMilestone()`—. Cinco copias de la misma decisión es cinco sitios donde olvidarla, y se
 * olvidó dos veces en tres días: el 13/08/2026 y el 15/08/2026 (`docs/Mensajeria.md` §22.16).
 *
 * La respuesta no es que cada puerta acepte las dos formas —eso es lo que ya fallaba, sólo que
 * escrito más veces—. Es que **haya una sola puerta**: se convierte aquí, en {@see self::de()}, y
 * a partir de ahí el tipo garantiza que lo que circula es una fecha válida. Quien reciba un
 * `MomentoDeHito` no tiene nada que comprobar.
 *
 * ⚠️ Y no, el problema NO era que `getMilestones()` exista en dos clases: PHP resuelve por el
 * tipo del objeto y no los confunde nunca. Con nombres distintos habría fallado igual. El
 * problema es que `array` no dice qué lleva dentro.
 *
 * Y pasar otra cosa **se ve**. Medido sobre el fallo real del 15/08: antes no lo cazaba PHPStan
 * en NINGÚN nivel —del 2 al 9— porque el valor salía de un `array` sin tipo y eso es `mixed`
 * implícito, que sólo se revisa con `checkImplicitMixed`, que no trae ningún nivel. Ahora el
 * **nivel 3** caza la forma exacta del bug (`Cannot access offset 'cancelled_at' on MapaDeHitos`)
 * y el **5** caza todas las variantes. El proyecto está en el 2, así que hoy sigue sin verse a la
 * primera; la diferencia es que ahora existe un nivel al que subir para que se vea, y antes no.
 *
 * Ése es el motivo de que el constructor sea privado. Si se pudiera hacer `new`, la garantía
 * duraría hasta el primero que lo hiciera.
 *
 * ── Un solo formato, y es el de la T ────────────────────────────────────────
 * Antes había dos: `PmsConversacionEnlace` escribía `Y-m-d H:i:s` y `MessageConversation`
 * escribía `Y-m-d\TH:i:s`. Nadie lo notó porque `new DateTimeImmutable()` traga los dos, pero
 * significaba que comparar dos hitos como texto daba resultados falsos.
 *
 * ⚠️ **De los dos gana el de la `T`, y no es indiferente.** El mapa de la conversación sale por
 * la API (`conversation:read`) y el front lo pasa por `new Date()` — ver `formatDateTime()` en
 * `EditConversationModal.vue`—. Con `T` es ISO-8601 y lo parsea cualquier navegador; con espacio
 * es sintaxis no estándar que Safari ha devuelto como `Invalid Date`, o sea la fecha del chat en
 * blanco justo en el iPhone. Unificar hacia el espacio habría roto eso sin tocar una línea de
 * frontend.
 *
 * ── Sin zona en el texto, a propósito ───────────────────────────────────────
 * Los hitos son horas de pared de la operación (`America/Lima`), no instantes universales: el
 * check-in es a las 14:00 de Lima pase lo que pase. Por eso el texto va sin sufijo de zona y la
 * zona se fija al releer — es lo que impide que el mismo dato signifique dos instantes distintos
 * según el `php.ini` del servidor.
 */
final readonly class MomentoDeHito
{
    /** El único formato en que se persiste un hito. */
    public const string FORMATO = 'Y-m-d\TH:i:s';

    /** Zona de operación. Ver la nota de arriba sobre horas de pared. */
    public const string ZONA = 'America/Lima';

    private function __construct(
        private DateTimeImmutable $momento,
    ) {
    }

    /**
     * La única conversión del módulo. Devuelve `null` cuando no hay hito que representar.
     *
     * Devuelve `null` en vez de lanzar porque quien llama suele estar recorriendo el mapa entero
     * de un asunto: una clave ilegible tiene que perderse ella sola, no llevarse por delante al
     * asunto. Eso ya pasó —un hito malo dejaba una reserva **sin ninguno** de sus recordatorios—
     * y es la razón de que aquí se descarte en silencio en vez de reventar.
     *
     * @param DateTimeInterface|string|array{date?: string}|null $crudo
     *        La forma `array` es la de un `DateTimeImmutable` serializado, que quedó grabada en
     *        filas antiguas. Ya no queda ninguna en producción (comprobado el 15/08/2026: 0 de
     *        51 enlaces y 0 de 334 conversaciones) y ninguna puerta puede volver a escribirla,
     *        pero se acepta al leer porque un JSON viejo no avisa antes de llegar.
     */
    public static function de(DateTimeInterface|string|array|null $crudo): ?self
    {
        if (null === $crudo) {
            return null;
        }

        if ($crudo instanceof DateTimeInterface) {
            // Se normaliza pasando por el texto para que un objeto y su forma persistida den
            // exactamente el mismo momento: sin esto, un `DateTime` con microsegundos y su
            // relectura desde JSON no serían iguales.
            $crudo = $crudo->format(self::FORMATO);
        }

        if (is_array($crudo)) {
            $crudo = (string) ($crudo['date'] ?? '');
        }

        $texto = trim($crudo);

        if ('' === $texto) {
            return null;
        }

        try {
            return new self(new DateTimeImmutable($texto, new DateTimeZone(self::ZONA)));
        } catch (Throwable) {
            return null;
        }
    }

    /** Ahora mismo, para los hitos que se marcan en el momento en que ocurren. */
    public static function ahora(): self
    {
        return new self(new DateTimeImmutable('now', new DateTimeZone(self::ZONA)));
    }

    /** La forma que va a la columna JSON. */
    public function comoTexto(): string
    {
        return $this->momento->format(self::FORMATO);
    }

    /** La forma con la que se hacen cuentas (offsets de las reglas de envío). */
    public function comoFecha(): DateTimeImmutable
    {
        return $this->momento;
    }
}
