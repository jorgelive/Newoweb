<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Entity\MessageConversation;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Message\Service\Conversacion\ResolutorDeHilo;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsReserva;
use App\Service\Phone\PhoneSanitizer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El WhatsApp que deja el propio huésped en su página, cuando no tenemos uno que funcione.
 *
 * Booking dejó de pasar el teléfono (octubre de 2026): sin número sólo se le puede escribir por la
 * mensajería de Beds24, sin botones y con ~6 minutos de vuelta. Y hay números que no sirven: el
 * canal pasó uno sin WhatsApp o mal escrito, y Meta lo vetó. El huésped sí entra a su página —por
 * ahí paga, ahí está su guía—, y lo que escribe ahí va a SU reserva sin adivinar: entra por su
 * localizador. Ver `docs/Mensajeria.md`, «Pedirle el teléfono al huésped».
 *
 * Tres respuestas: lo deja (`guardar()`), prefiere no darlo (`rechazar()`, y no se le vuelve a
 * pedir), o no hace nada y se le sigue pidiendo.
 */
final readonly class TelefonoDelHuesped
{
    public const string GUARDADO = 'guardado';
    public const string INVALIDO = 'invalido';
    /** El que escribe es justo el que no tiene WhatsApp: hay que pedirle otro. */
    public const string ESE_NO_TIENE_WHATSAPP = 'ese_no_tiene_whatsapp';
    public const string YA_TENEMOS = 'ya_tenemos';
    public const string SIN_ESTANCIA = 'sin_estancia';
    public const string RECHAZADO = 'rechazado';

    /** Por qué se le pide: no hay ninguno, o el que hay no tiene WhatsApp. */
    public const string SIN_TELEFONO = 'sin_telefono';
    public const string SIN_WHATSAPP = 'sin_whatsapp';

    public function __construct(
        private TelefonoDeContacto $contacto,
        private PhoneSanitizer $telefonos,
        private EntityManagerInterface $em,
        private EnlacesDeConversacion $enlaces,
        private ResolutorDeHilo $resolutor,
    ) {}

    /**
     * Por qué hay que pedírselo, o null si no hay que pedírselo.
     *
     * Se pide con una estancia por delante (o en curso) y sin un teléfono que funcione en su
     * conversación —la semilla sola no cuenta: es justo el caso en que no le llega nada—. Un
     * número VETADO cuenta como «no funciona»: `ContactoDelAsunto` sólo da por bueno uno vivo y
     * sin vetar.
     *
     * No se pide si ya dijo que prefiere no darlo, ni si ya lo dejó y está pendiente de unir: su
     * hilo sigue sin teléfono verificado, y sin esa condición la tarjeta le volvía a salir en cada
     * visita pidiéndole lo que ya dio.
     *
     * @return array{motivo: string, terminadoEn: string|null}|null
     */
    public function porQuePedirlo(PmsReserva $reserva): ?array
    {
        if ($reserva->getTelefonoRechazadoAt() !== null
            || !$this->tieneEstanciaPorDelante($reserva)
            || $this->contacto->vieneDeIdentidad($reserva)
        ) {
            return null;
        }

        $hilo = $this->hiloDe($reserva);

        if ($hilo?->fusionSugerida()?->tipo === IdentidadTipo::TELEFONO->value) {
            return null;
        }

        // Vetado: el hilo tiene número, pero todos sus teléfonos vivos están vetados.
        $vetado = $hilo !== null && $hilo->isWhatsappDisabled() ? $hilo->getGuestPhone() : null;

        return $vetado !== null && $vetado !== ''
            ? ['motivo' => self::SIN_WHATSAPP, 'terminadoEn' => substr($vetado, -3)]
            : ['motivo' => self::SIN_TELEFONO, 'terminadoEn' => null];
    }

    public function hayQuePedirlo(PmsReserva $reserva): bool
    {
        return $this->porQuePedirlo($reserva) !== null;
    }

    /**
     * ⚠️ **Sólo si no hay ya uno que funcione.** El localizador es la única credencial de la
     * página: si sirviera para CAMBIAR un número que funciona, quien lo conociera podría desviarse
     * los mensajes del huésped, guía de llegada incluida. Corregir ese número lo hace el equipo.
     *
     * Con conversación, el número se AÑADE a ella (`ResolutorDeHilo::vincular()`, que propone la
     * unión si ya es de otra persona) y pasa a ser el de envío sólo si el actual no sirve
     * (`preferirTelefonoQueEscribe()`, el caso de Or Cohen). La ficha de la reserva no se pisa: es
     * lo que dio al reservar, y si no había nada se rellena. Sin conversación, se siembra la ficha
     * y el recálculo de la reserva hace el resto.
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

        $hilo = $this->hiloDe($reserva);

        if ($hilo !== null && $this->estaVetadoEn($hilo, $numero)) {
            return self::ESE_NO_TIENE_WHATSAPP;
        }

        if ($reserva->getTelefono() === null || $reserva->getTelefono() === '') {
            $reserva->setTelefono($numero);
        }

        if ($hilo !== null) {
            $this->resolutor->vincular($hilo, IdentidadTipo::TELEFONO, $numero, 'huesped');
            $hilo->preferirTelefonoQueEscribe($numero);
        }

        // Prefirió no darlo y luego cambió de idea: lo que vale es lo último que hizo.
        $reserva->setTelefonoRechazadoAt(null);
        $this->em->flush();

        return self::GUARDADO;
    }

    /** «Prefiero no darlo», ya confirmado en la página. No se le vuelve a pedir. */
    public function rechazar(PmsReserva $reserva): string
    {
        if (!$this->hayQuePedirlo($reserva)) {
            return self::YA_TENEMOS;
        }

        $reserva->setTelefonoRechazadoAt(new DateTimeImmutable());
        $this->em->flush();

        return self::RECHAZADO;
    }

    private function hiloDe(PmsReserva $reserva): ?MessageConversation
    {
        return $this->enlaces->hiloTitularDe(PmsConversacionEnlace::CONTEXT_TYPE, (string) $reserva->getId());
    }

    private function estaVetadoEn(MessageConversation $hilo, string $numero): bool
    {
        foreach ($hilo->getIdentidades() as $identidad) {
            if ($identidad->getTipo() === IdentidadTipo::TELEFONO && $identidad->getValor() === $numero && $identidad->isBloqueado()) {
                return true;
            }
        }

        return false;
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
