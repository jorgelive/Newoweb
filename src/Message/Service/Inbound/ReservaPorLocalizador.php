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
 * 1. **Formato**: sólo palabras con la forma EXACTA de un localizador —seis caracteres del
 *    alfabeto sin `0 1 I L O`—. Sin esto, «Buenas» o «Melanie» entraban a consultar la base.
 * 2. **Existe y está viva**: el estado tiene que ocupar la unidad. Una cancelada no reabre nada.
 * 3. **En su tiempo**: desde 60 días antes de la llegada hasta 15 después de la salida. Un
 *    localizador de hace dos años no sirve para colarse en un hilo, y el margen de después deja
 *    sitio a las facturas y a las quejas, que llegan tarde.
 */
final readonly class ReservaPorLocalizador
{
    /**
     * Exactamente la forma de un localizador nuestro, no «una palabra que podría serlo».
     *
     * 🔥 El patrón de la primera versión era `[A-Z0-9]{6,12}`, y con él «Buenas», «tardes»,
     * «quisiera», «confirmar» o «Melanie» eran candidatos. Con el tope de cinco, un mensaje
     * educado —«Buenas tardes, quisiera confirmar mi llegada. Hola, soy Melanie, reserva
     * RXY9QC»— dejaba el código fuera y el huésped caía en un hilo «manual» sin reserva.
     *
     * `initializeLocator()` genera SEIS caracteres de `23456789ABCDEFGHJKMNPQRSTUVWXYZ`, o sea
     * sin `0`, `1`, `I`, `L` ni `O` — los que se confunden al leerlos. Exigir el alfabeto
     * exacto descarta de golpe «reserva», «booking», «Buchung» y casi cualquier nombre, y de
     * paso hace innecesario el tope: ya no hay contra qué protegerse.
     *
     * ⚠️ **En MAYÚSCULAS primero, y no es lo mismo que ser indiferente a la caja.** Con `/i`,
     * «Buenas» y «tardes» encajan en el patrón —sus seis letras están en el alfabeto—, y el día
     * que un localizador sea `TARDES` un «buenas tardes» engancharía el hilo de otra persona.
     * Nuestro enlace escribe el código en mayúsculas, así que ésos se miran primero; los de caja
     * mixta quedan de reserva para quien lo reescriba a mano, y sólo se consultan si ninguno de
     * los buenos casó.
     */
    private const string PATRON = '/\b[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}\b/';

    /** El mismo, sin distinguir mayúsculas: para el que lo reescribe en minúsculas. */
    private const string PATRON_LAXO = '/\b[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}\b/i';

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

        preg_match_all(self::PATRON, $texto, $exactos);
        preg_match_all(self::PATRON_LAXO, $texto, $laxos);

        // Los de mayúsculas primero: son los que escribe nuestro enlace. Los demás detrás, y
        // sólo llegan a consultarse si ninguno de los primeros casó con una reserva viva.
        $candidatos = array_unique(array_merge($exactos[0], $laxos[0]));

        if ($candidatos === []) {
            return null;
        }
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
