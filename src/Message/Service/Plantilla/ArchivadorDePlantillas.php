<?php

declare(strict_types=1);

namespace App\Message\Service\Plantilla;

use App\Message\Entity\MessageRule;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Archivar y devolver a circulación una plantilla: el interruptor de sus canales.
 *
 * ── Qué es archivar ─────────────────────────────────────────────────────────
 * Apagar todos sus canales, y nada más ({@see MessageTemplate::estaEnCirculacion()}). No hay
 * columna «archivada»: una plantilla sin canal no puede enviarse, y una bandera aparte podría
 * decir «activa» con todo apagado. Archivada, deja de ofrecerse en el selector del chat y en el
 * catálogo del agente; **sigue en el panel, editable, y con su texto intacto**.
 *
 * **No se borra nunca**: los mensajes ya enviados la referencian —`welcome_booking` tiene 46—, y
 * borrarla dejaría el historial contando una versión incompleta.
 *
 * ── Por qué un servicio ─────────────────────────────────────────────────────
 * Lo piden dos sitios —el botón del panel y `msg:plantilla:archivar`— y lo que comparten no es el
 * `is_active` sino la GUARDA: una plantilla que usa una regla activa no se archiva, porque esa
 * regla seguiría programando mensajes que no pueden salir por ningún canal. Escrita dos veces,
 * el día que alguien afloje una de las dos copias el fallo es mudo.
 */
final readonly class ArchivadorDePlantillas
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Apaga los canales. Idempotente.
     *
     * @throws RuntimeException si una regla ACTIVA la usa; el mensaje nombra las reglas.
     */
    public function archivar(MessageTemplate $plantilla): void
    {
        $reglas = $this->reglasActivasQueLaUsan($plantilla);

        if ($reglas !== []) {
            throw new RuntimeException(sprintf(
                'La usan reglas activas (%s): archivarla las dejaría programando mensajes sin por '
                . 'dónde salir. Repúntalas o apágalas primero.',
                implode(', ', $reglas)
            ));
        }

        $plantilla
            ->setBeds24Tmpl(['is_active' => false] + ($plantilla->getBeds24Tmpl() ?? []))
            ->setWhatsappMetaTmpl(['is_active' => false] + ($plantilla->getWhatsappMetaTmpl() ?? []))
            ->setEmailTmpl(['is_active' => false] + ($plantilla->getEmailTmpl() ?? []));
    }

    /**
     * Enciende los canales que **tienen texto escrito**, y sólo ésos.
     *
     * Encenderlos todos ofrecería la plantilla por un canal vacío: el envío saldría en blanco o
     * fallaría al no encontrar cuerpo. Lo que se redactó es la mejor prueba de por dónde se
     * pensó enviar.
     *
     * ⚠️ «WA dentro» no se enciende aparte: es el mismo canal de WhatsApp con otro cuerpo —uno
     * para dentro de la ventana de 24 h y otro para fuera— y lo gobierna el interruptor de Meta.
     *
     * @return list<string> Los canales que quedaron encendidos, para poder decirlo.
     */
    public function devolverACirculacion(MessageTemplate $plantilla): array
    {
        $encendidos = [];

        if ($this->tieneCuerpo($plantilla->getBeds24Tmpl())) {
            $plantilla->setBeds24Tmpl(['is_active' => true] + ($plantilla->getBeds24Tmpl() ?? []));
            $encendidos[] = 'Beds24';
        }

        if ($this->tieneCuerpo($plantilla->getWhatsappMetaTmpl()) || $this->tieneCuerpo($plantilla->getWhatsappLinkTmpl())) {
            $plantilla->setWhatsappMetaTmpl(['is_active' => true] + ($plantilla->getWhatsappMetaTmpl() ?? []));
            $encendidos[] = 'WhatsApp';
        }

        if ($this->tieneCuerpo($plantilla->getEmailTmpl())) {
            $plantilla->setEmailTmpl(['is_active' => true] + ($plantilla->getEmailTmpl() ?? []));
            $encendidos[] = 'Correo';
        }

        return $encendidos;
    }

    /**
     * Las reglas ACTIVAS que la usan. Vacío si ninguna.
     *
     * @return list<string> Sus nombres.
     */
    public function reglasActivasQueLaUsan(MessageTemplate $plantilla): array
    {
        $nombres = [];

        /** @var list<MessageRule> $reglas */
        $reglas = $this->em->getRepository(MessageRule::class)->findBy(['template' => $plantilla, 'isActive' => true]);

        foreach ($reglas as $regla) {
            $nombres[] = (string) $regla->getName();
        }

        return $nombres;
    }

    /** @param array<string, mixed>|null $canal */
    private function tieneCuerpo(?array $canal): bool
    {
        $body = $canal['body'] ?? null;

        return is_array($body) && $body !== [];
    }
}
