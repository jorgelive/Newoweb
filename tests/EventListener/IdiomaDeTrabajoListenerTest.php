<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Maestro\MaestroIdioma;
use App\EventListener\IdiomaDeTrabajoListener;
use App\Message\Entity\MessageConversation;
use App\Service\Idioma\IdiomaDeTrabajo;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La red: venga de donde venga el idioma, una conversación no se guarda en uno que no trabajamos.
 */
final class IdiomaDeTrabajoListenerTest extends TestCase
{
    private MaestroIdioma $ingles;
    private EntityManagerInterface $em;
    private IdiomaDeTrabajoListener $listener;

    protected function setUp(): void
    {
        $this->ingles = (new MaestroIdioma('en', 'Inglés'))->setPrioridad(90);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($this->ingles);
        $this->em = $em;
        $this->listener = new IdiomaDeTrabajoListener(new IdiomaDeTrabajo($em));
    }

    #[Test]
    public function alCrearseEnHebreoSeGuardaEnIngles(): void
    {
        $conversacion = (new MessageConversation('pms_reserva', 'x'))->setIdioma(new MaestroIdioma('he', 'Hebreo'));

        $this->listener->prePersist(new PrePersistEventArgs($conversacion, $this->em));

        self::assertSame($this->ingles, $conversacion->getIdioma());
    }

    #[Test]
    public function alCambiarAHebreoSeGuardaEnInglesYUnIdiomaDeTrabajoNoSeToca(): void
    {
        $frances = (new MaestroIdioma('fr', 'Francés'))->setPrioridad(70);
        $hebreo = new MaestroIdioma('he', 'Hebreo');
        $conversacion = (new MessageConversation('pms_reserva', 'x'))->setIdioma($hebreo);
        $cambios = ['idioma' => [$frances, $hebreo]];

        $this->listener->preUpdate(new PreUpdateEventArgs($conversacion, $this->em, $cambios));

        self::assertSame($this->ingles, $cambios['idioma'][1]);
        self::assertSame($this->ingles, $conversacion->getIdioma());

        $conversacion->setIdioma($frances);
        $cambios = ['idioma' => [$this->ingles, $frances]];
        $this->listener->preUpdate(new PreUpdateEventArgs($conversacion, $this->em, $cambios));

        self::assertSame($frances, $cambios['idioma'][1]);
    }
}
