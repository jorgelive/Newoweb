<?php

declare(strict_types=1);

namespace App\Tests\Service\Idioma;

use App\Entity\Maestro\MaestroIdioma;
use App\Service\Idioma\IdiomaDeTrabajo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sólo se le escribe en los idiomas que trabajamos (prioridad > 0); en los demás, inglés. La
 * huésped con la reserva en hebreo que escribía en inglés recibía las respuestas en hebreo.
 */
final class IdiomaDeTrabajoTest extends TestCase
{
    #[Test]
    public function unIdiomaDeTrabajoSeQuedaYUnoSinPrioridadPasaAIngles(): void
    {
        $ingles = (new MaestroIdioma('en', 'Inglés'))->setPrioridad(90);
        $frances = (new MaestroIdioma('fr', 'Francés'))->setPrioridad(70);
        $hebreo = new MaestroIdioma('he', 'Hebreo');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($ingles);
        $idiomas = new IdiomaDeTrabajo($em);

        self::assertSame($frances, $idiomas->para($frances));
        self::assertSame($ingles, $idiomas->para($hebreo));
        self::assertSame('en', $hebreo->idiomaDePlantilla());
        self::assertFalse($hebreo->esDeTrabajo());
    }
}
