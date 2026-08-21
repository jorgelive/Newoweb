<?php

declare(strict_types=1);

namespace App\Tests\Pms\EventListener;

use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use App\Pms\EventListener\PmsReservaDeleteListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Por qué NO se borra una reserva, dicho antes de intentarlo.
 *
 * ── El fallo que lo motivó ──────────────────────────────────────────────────
 * El 20/08/2026 a las 23:49 un borrado murió en MySQL con una violación de clave ajena sobre
 * `pms_conversacion_enlace`. Al salir del `commit()` de Doctrine llegaba como 500 sin nada que
 * leer, así que en el panel era «no borra y no dice nada». El operador terminó desmontando la
 * reserva a mano —evento primero, conversación después— y dejó atrás una reserva vacía.
 */
final class BorradoDeReservaTest extends TestCase
{
    #[Test]
    public function una_conversacion_viva_impide_el_borrado_Y_DICE_CON_QUIEN(): void
    {
        // El nombre importa: con 333 hilos de alojamiento, «tiene una conversación» obliga a ir
        // a buscar cuál. Con el nombre delante se retira y se reintenta en un minuto.
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage(
            'No se puede borrar la reserva 6PN5SH: su conversación de chat con Susan Acuña '
            . 'todavía la tiene como asunto — retírala primero desde el chat.'
        );

        $this->correr($this->reserva('6PN5SH'), ['Susan Acuña']);
    }

    #[Test]
    public function sin_nada_que_lo_impida_no_dice_nada(): void
    {
        $this->expectNotToPerformAssertions();

        $this->correr($this->reserva('ABC123'), []);
    }

    #[Test]
    public function una_conversacion_sin_nombre_no_deja_la_frase_coja(): void
    {
        // Los hilos de walk-in pueden no tener nombre todavía. El mensaje se adapta en vez de
        // quedar como «su conversación de chat con  todavía…».
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('su conversación de chat todavía la tiene como asunto');

        $this->correr($this->reserva('XYZ999'), ['']);
    }

    #[Test]
    public function dos_hilos_sobre_la_misma_reserva_se_enumeran_los_dos(): void
    {
        // ⚠️ Pasa: dos huéspedes alojados en la misma reserva, cada uno con su hilo. MySQL
        // sólo habría contado el primero, y el operador borraría uno y volvería a chocar.
        $this->expectException(AccessDeniedHttpException::class);
        $this->expectExceptionMessage('; y ');

        $this->correr($this->reserva('DOS111'), ['Ana', 'Beto']);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function reserva(string $localizador): PmsReserva
    {
        return (new PmsReserva())->setLocalizador($localizador);
    }

    /** @param list<string> $nombresDeHilo */
    private function correr(PmsReserva $reserva, array $nombresDeHilo): void
    {
        $enlaces = array_map(
            static function (string $nombre) use ($reserva): PmsConversacionEnlace {
                $hilo = new MessageConversation('pms_reserva', (string) $reserva->getId());
                $hilo->setGuestName($nombre);

                return new PmsConversacionEnlace($hilo, $reserva);
            },
            $nombresDeHilo
        );

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($enlaces);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        new PmsReservaDeleteListener()->preRemove($reserva, new PreRemoveEventArgs($reserva, $em));
    }
}
