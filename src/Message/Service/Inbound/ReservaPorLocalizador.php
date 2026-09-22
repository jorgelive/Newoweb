<?php

declare(strict_types=1);

namespace App\Message\Service\Inbound;

use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * De qué reserva es este WhatsApp, cuando el número no nos dice nada.
 *
 * ── Por qué hace falta a partir del 28/09/2026 ──────────────────────────────
 * Booking deja de transmitir el teléfono del huésped por los canales de conectividad. Hasta hoy,
 * un número desconocido se casaba con su reserva **por el propio número**
 * (`findVivasByTelefono`); desde esa fecha, para Booking, no habrá número que casar. El huésped
 * escribiría y caería en un hilo «manual», sin reserva: sin guía, sin cuenta, sin códigos, y con
 * el agente contestando que esta conversación no está asociada a ninguna reserva.
 *
 * La salida es invertir el camino: se le manda por el chat de Booking un enlace de WhatsApp con
 * el mensaje ya escrito, y **ese mensaje trae su localizador**. El primer mensaje deja de ser
 * sólo un saludo y pasa a ser la credencial.
 *
 * ── Por qué el localizador y no un token nuevo ──────────────────────────────
 * Porque ya ES la llave de su guía: `pax_book_guide_url` es `<host>/<localizador>` y con él se
 * ven la dirección, las normas y el estado de cuenta. Inventar un segundo secreto para lo mismo
 * añadiría una tabla, una caducidad y un sitio más donde equivocarse, sin subir un milímetro el
 * listón: quien tiene el localizador ya tiene la guía.
 *
 * ⚠️ Lo que este servicio NO abre: los códigos de la caja y el WiFi siguen detrás de
 * `PmsGuiaAcceso` —ventana de 30 h y pago confiable—, así que casar un hilo no entrega nunca
 * una credencial de entrada. Es la diferencia entre saber de quién es la conversación y poder
 * entrar en la casa.
 *
 * ── Las tres validaciones ───────────────────────────────────────────────────
 * 1. **Formato**: sólo se miran palabras de 6 a 12 caracteres alfanuméricos. Sin esto, cualquier
 *    «hola» largo entraría a consultar la base.
 * 2. **Existe y está viva**: el estado tiene que ocupar la unidad. Una cancelada no reabre nada.
 * 3. **En su tiempo**: desde 60 días antes de la llegada hasta 15 después de la salida. Un
 *    localizador de hace dos años no sirve para colarse en un hilo, y el margen de después deja
 *    sitio a las facturas y a las quejas, que llegan tarde.
 */
final readonly class ReservaPorLocalizador
{
    /** Lo que puede ser un localizador y no una palabra cualquiera. */
    private const string PATRON = '/\b[A-Z0-9]{6,12}\b/i';

    /** Cuántas palabras candidatas se miran como mucho. Un mensaje no trae veinte códigos. */
    private const int MAX_CANDIDATOS = 5;

    private const int DIAS_ANTES = 60;
    private const int DIAS_DESPUES = 15;

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /**
     * La reserva que menciona este texto, o `null`.
     *
     * Devuelve `null` con la misma cara en los tres casos —no hay candidato, no existe, no está
     * en su tiempo— a propósito: quien llama sólo tiene que decidir si sigue al camino de
     * siempre, y distinguirlos aquí no cambiaría nada. Lo que sí se distingue es el LOG, que es
     * donde se mira cuando alguien dice «le escribí y no me reconoció».
     */
    public function enElTexto(string $texto): ?PmsReserva
    {
        if (trim($texto) === '') {
            return null;
        }

        if (preg_match_all(self::PATRON, $texto, $coincidencias) === 0) {
            return null;
        }

        $candidatos = array_slice(array_unique($coincidencias[0]), 0, self::MAX_CANDIDATOS);
        $repositorio = $this->em->getRepository(PmsReserva::class);

        foreach ($candidatos as $candidato) {
            $reserva = $repositorio->findOneBy(['localizador' => mb_strtoupper($candidato)]);

            if (!$reserva instanceof PmsReserva) {
                continue;
            }

            if (!$this->estaEnSuTiempo($reserva)) {
                $this->logger->info('WhatsApp: localizador reconocido pero fuera de fecha; no se vincula.', [
                    'localizador' => $candidato,
                    'llegada' => $reserva->getFechaLlegada()?->format('Y-m-d'),
                    'salida' => $reserva->getFechaSalida()?->format('Y-m-d'),
                ]);

                continue;
            }

            if (!$this->estaViva($reserva)) {
                $this->logger->info('WhatsApp: localizador de una reserva sin estancia viva; no se vincula.', [
                    'localizador' => $candidato,
                ]);

                continue;
            }

            $this->logger->info('WhatsApp: número nuevo reconocido por su localizador.', [
                'localizador' => $candidato,
                'reserva' => (string) $reserva->getId(),
            ]);

            return $reserva;
        }

        return null;
    }

    /** Ni un localizador viejo ni uno de dentro de un año. */
    private function estaEnSuTiempo(PmsReserva $reserva): bool
    {
        $llegada = $reserva->getFechaLlegada();
        $salida = $reserva->getFechaSalida() ?? $llegada;

        if ($llegada === null || $salida === null) {
            return false;
        }

        $hoy = new \DateTimeImmutable('today');

        return $hoy >= \DateTimeImmutable::createFromInterface($llegada)->modify('-' . self::DIAS_ANTES . ' days')
            && $hoy <= \DateTimeImmutable::createFromInterface($salida)->modify('+' . self::DIAS_DESPUES . ' days');
    }

    /**
     * Que le quede al menos una estancia que ocupe la unidad.
     *
     * Se mira el MISMO conjunto de estados que decide si una estancia ocupa una casita
     * ({@see PmsEventoEstado::OCUPAN_UNIDAD}), en vez de una lista propia: el día que se añada
     * un estado nuevo, esto lo hereda.
     */
    private function estaViva(PmsReserva $reserva): bool
    {
        foreach ($reserva->getEventosCalendario() as $evento) {
            if (in_array($evento->getEstado()?->getId(), PmsEventoEstado::OCUPAN_UNIDAD, true)) {
                return true;
            }
        }

        return false;
    }
}
