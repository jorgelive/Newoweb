<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\ResolutorDeHilo;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Registrar un identificador en un hilo, sin robárselo a otro.
 *
 * `(tipo, valor)` es ÚNICO. `vincular()` insertaba a ciegas, y el caso llegaba solo al crear una
 * reserva: si el teléfono tecleado ya es de una persona y el correo es de otra,
 * `porIdentificadores()` no elige ninguno de los dos hilos —hace bien: unir historiales es
 * decisión de persona—, nace un tercero, y ahí se intentaban fichar los dos ajenos.
 *
 * El resultado no era un hilo mal formado: era **la reserva sin poder guardarse**, con un error
 * de clave duplicada que no dice nada de lo que pasó.
 */
final class ResolutorDeHiloVincularTest extends TestCase
{
    #[Test]
    public function un_identificador_libre_se_registra(): void
    {
        $hilo = $this->hilo();

        $this->resolutor()->vincular($hilo, IdentidadTipo::TELEFONO, '+51 984 123 456', 'contexto');

        self::assertCount(1, $hilo->getIdentidades());
        self::assertSame('51984123456', $hilo->getIdentidades()->first()->getValor());
    }

    #[Test]
    public function un_identificador_de_OTRO_hilo_no_se_mueve(): void
    {
        $ajeno = $this->hilo();
        $ocupado = new MessageIdentidad(IdentidadTipo::TELEFONO, '51984123456');
        $ajeno->addIdentidad($ocupado);

        $nuevo = $this->hilo();
        $this->resolutor($ocupado)->vincular($nuevo, IdentidadTipo::TELEFONO, '51984123456');

        self::assertCount(0, $nuevo->getIdentidades(), 'El hilo nuevo se queda sin él…');
        self::assertCount(1, $ajeno->getIdentidades(), '…y el dueño lo conserva.');
    }

    #[Test]
    public function el_identificador_propio_no_se_duplica(): void
    {
        $hilo = $this->hilo();
        $suyo = new MessageIdentidad(IdentidadTipo::EMAIL, 'nune@ejemplo.com');
        $hilo->addIdentidad($suyo);

        $this->resolutor($suyo)->vincular($hilo, IdentidadTipo::EMAIL, 'NUNE@Ejemplo.com');

        self::assertCount(1, $hilo->getIdentidades());
    }

    #[Test]
    public function un_identificador_retirado_del_propio_hilo_NO_revive(): void
    {
        // ⚠️ Es lo que hace posible retirar un número: el dominio re-registra los
        // identificadores en CADA recálculo, así que sin la lápida el siguiente pull de Beds24
        // resucitaría el que acaba de retirarse.
        $hilo = $this->hilo();
        $retirada = new MessageIdentidad(IdentidadTipo::TELEFONO, '51984123456');
        $hilo->addIdentidad($retirada);
        $retirada->retirar(new DateTimeImmutable('2026-08-20 12:00:00'));

        $this->resolutor($retirada)->vincular($hilo, IdentidadTipo::TELEFONO, '51984123456');

        self::assertCount(1, $hilo->getIdentidades());
        self::assertFalse($hilo->getIdentidades()->first()->estaViva());
    }

    #[Test]
    public function un_valor_irreconocible_no_se_registra(): void
    {
        $hilo = $this->hilo();

        $this->resolutor()->vincular($hilo, IdentidadTipo::TELEFONO, '   ');

        self::assertCount(0, $hilo->getIdentidades());
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function hilo(): MessageConversation
    {
        return new MessageConversation('pms_reserva', 'r-' . uniqid());
    }

    private function resolutor(?MessageIdentidad $yaExistente = null): ResolutorDeHilo
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($yaExistente);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new ResolutorDeHilo($em, new NullLogger());
    }
}
