<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\ProviderInterface;
use App\Message\ApiPlatform\State\PlantillasEnCirculacionProvider;
use App\Message\Entity\MessageTemplate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Archivada = sin ningún canal encendido: ni en el selector del chat ni en el catálogo del agente.
 *
 * El 17/09/2026 el selector ofrecía `welcome_booking` —la vieja, con las cuentas tecleadas— al lado
 * de su sustituta, y `recordatorio_llegada` con los tres canales ya apagados.
 */
final class PlantillaEnCirculacionTest extends TestCase
{
    #[Test]
    public function con_algun_canal_encendido_esta_en_circulacion(): void
    {
        self::assertTrue($this->plantilla(beds24: true)->estaEnCirculacion());
        self::assertTrue($this->plantilla(meta: true)->estaEnCirculacion());
        self::assertTrue($this->plantilla(correo: true)->estaEnCirculacion());
    }

    #[Test]
    public function sin_ningun_canal_esta_archivada(): void
    {
        self::assertFalse($this->plantilla()->estaEnCirculacion());
    }

    #[Test]
    public function el_agente_no_ve_una_archivada_aunque_tenga_su_uso_escrito(): void
    {
        $archivada = $this->plantilla()->setAgenteUso('Guía de llegada.');

        self::assertFalse($archivada->disponibleParaAgente());
        self::assertTrue($this->plantilla(beds24: true)->setAgenteUso('Guía de llegada.')->disponibleParaAgente());
    }

    #[Test]
    public function el_selector_del_chat_solo_recibe_las_que_circulan(): void
    {
        $viva = $this->plantilla(beds24: true);
        $archivada = $this->plantilla();

        $decorado = $this->createStub(ProviderInterface::class);
        $decorado->method('provide')->willReturn([$viva, $archivada]);

        self::assertSame([$viva], (new PlantillasEnCirculacionProvider($decorado))->provide(new GetCollection()));
    }

    private function plantilla(bool $beds24 = false, bool $meta = false, bool $correo = false): MessageTemplate
    {
        return (new MessageTemplate())
            ->setBeds24Tmpl(['is_active' => $beds24, 'body' => []])
            ->setWhatsappMetaTmpl(['is_active' => $meta, 'body' => []])
            ->setEmailTmpl(['is_active' => $correo, 'body' => []]);
    }
}
