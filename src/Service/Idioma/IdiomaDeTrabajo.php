<?php

declare(strict_types=1);

namespace App\Service\Idioma;

use App\Entity\Maestro\MaestroIdioma;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * El idioma en que se le escribe a alguien: el suyo si es de los que trabajamos (prioridad > 0 en
 * `maestro_idioma`), y si no, inglés (19/10/2026).
 *
 * ### Por qué
 *
 * Una huésped de Israel con la reserva en hebreo escribía en inglés. La conversación guardaba el
 * idioma de la reserva, y el agente tiene la orden de contestar «SIEMPRE en el idioma de la
 * conversación»: le contestaba en hebreo, un idioma que no trabajamos y que nadie del equipo puede
 * revisar. Las plantillas ya caían a inglés ({@see MaestroIdioma::idiomaDePlantilla()}); el texto
 * libre y el agente, no.
 *
 * ⚠️ **Revierte una decisión anterior**: `BookingPullPersister::resolveIdioma()` guardaba a propósito
 * los idiomas de prioridad 0 para que el texto libre se tradujera al idioma del huésped. Desde hoy
 * ese texto sale en inglés, como las plantillas.
 *
 * Se aplica en las fuentes automáticas (lectura de Beds24, detección por mensaje) y, como red, en
 * `IdiomaDeTrabajoListener` al guardar cualquier conversación o reserva. El idioma de cada MENSAJE
 * no pasa por aquí: dice en qué está escrito, y con eso se traduce al español para el panel.
 */
final readonly class IdiomaDeTrabajo
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function para(MaestroIdioma $idioma): MaestroIdioma
    {
        if ($idioma->esDeTrabajo()) {
            return $idioma;
        }

        return $this->em->find(MaestroIdioma::class, MaestroIdioma::ID_INGLES)
            ?? throw new RuntimeException(sprintf('No existe el idioma «%s» en el maestro.', MaestroIdioma::ID_INGLES));
    }
}
