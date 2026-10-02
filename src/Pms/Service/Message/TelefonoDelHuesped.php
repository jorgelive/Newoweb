<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Service\Phone\PhoneSanitizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El WhatsApp que deja el propio huésped en su página, cuando su reserva llegó sin él.
 *
 * Booking dejó de pasar el teléfono (octubre de 2026): sin número sólo se le puede escribir por la
 * mensajería de Beds24, sin botones y con ~6 minutos de vuelta. El huésped sí entra a su página
 * —por ahí paga, ahí está su guía—, y lo que escribe ahí va a SU reserva sin adivinar: entra por
 * su localizador. Ver `docs/Mensajeria.md`, «Pedirle el teléfono al huésped».
 *
 * Guardar es poner la semilla de la reserva (`PmsReserva::$telefono`) y nada más: el recálculo de
 * la reserva lo vincula a su conversación, rellena `guestPhone` y da cola de WhatsApp a sus avisos
 * pendientes. Y si el número ya era de otra conversación, salta la fusión sugerida con su aviso al
 * equipo. Es el mismo camino que cuando el canal trae el número.
 */
final readonly class TelefonoDelHuesped
{
    public const string GUARDADO = 'guardado';
    public const string INVALIDO = 'invalido';
    public const string YA_TENEMOS = 'ya_tenemos';
    public const string SIN_ESTANCIA = 'sin_estancia';

    public function __construct(
        private TelefonoDeContacto $contacto,
        private PhoneSanitizer $telefonos,
        private EntityManagerInterface $em,
        private EnlacesDeConversacion $enlaces,
    ) {}

    /**
     * Se pide sólo con una estancia por delante (o en curso) y sin teléfono VERIFICADO en su
     * conversación —la semilla sola no cuenta: es justo el caso en que no le llega nada—.
     *
     * ⚠️ **Y no si ya lo dejó y está pendiente de unir.** Si el número que dejó ya era de otra
     * conversación, no pasa a la suya —eso lo decide el equipo con la fusión sugerida—, así que su
     * hilo sigue sin teléfono verificado. Sin esta condición la tarjeta le volvía a salir cada vez
     * que entraba, pidiéndole algo que ya había dado. Visto el 02/10/2026 antes de probarlo.
     */
    public function hayQuePedirlo(PmsReserva $reserva): bool
    {
        return $this->tieneEstanciaPorDelante($reserva)
            && !$this->contacto->vieneDeIdentidad($reserva)
            && !$this->telefonoPendienteDeUnir($reserva);
    }

    /**
     * ⚠️ **Sólo si no hay ya uno verificado.** El localizador es la única credencial de la página:
     * si sirviera para CAMBIAR el número, quien lo conociera podría desviarse los mensajes del
     * huésped, guía de llegada incluida. Corregir un número que ya existe lo hace el equipo.
     */
    public function guardar(PmsReserva $reserva, string $tecleado): string
    {
        if (!$this->tieneEstanciaPorDelante($reserva)) {
            return self::SIN_ESTANCIA;
        }

        if ($this->contacto->vieneDeIdentidad($reserva)) {
            return self::YA_TENEMOS;
        }

        $numero = $this->telefonos->validoONulo($tecleado, $reserva->getPais()?->getId());

        if ($numero === null) {
            return self::INVALIDO;
        }

        $reserva->setTelefono($numero);
        $this->em->flush();

        return self::GUARDADO;
    }

    private function telefonoPendienteDeUnir(PmsReserva $reserva): bool
    {
        $sugerida = $this->enlaces->hiloTitularDe(PmsConversacionEnlace::CONTEXT_TYPE, (string) $reserva->getId())
            ?->fusionSugerida();

        return $sugerida !== null && $sugerida->tipo === IdentidadTipo::TELEFONO->value;
    }

    private function tieneEstanciaPorDelante(PmsReserva $reserva): bool
    {
        $hoy = new DateTimeImmutable('today');

        foreach ($reserva->getEventosCalendario() as $evento) {
            if (in_array($evento->getEstado()?->getId(), PmsEventoEstado::IDENTIFICAN_HUESPED, true) && $evento->getFin() >= $hoy) {
                return true;
            }
        }

        return false;
    }
}
