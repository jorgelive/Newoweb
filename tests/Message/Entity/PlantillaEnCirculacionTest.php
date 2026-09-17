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

    #[Test]
    public function sin_cuerpo_de_meta_no_hay_nada_que_subir(): void
    {
        // `solicitar_numero_whatsapp` es sólo para el chat de la OTA y ofrecía «Push a Meta»: el
        // bloque de Meta nunca está vacío, porque lleva sus interruptores dentro.
        $soloOta = (new MessageTemplate())
            ->setBeds24Tmpl(['is_active' => true, 'body' => [['language' => 'es', 'content' => 'Hola']]])
            ->setWhatsappMetaTmpl(['is_active' => false, 'is_official_meta' => false, 'body' => []]);

        self::assertFalse($soloOta->puedeSubirseAMeta());
    }

    #[Test]
    public function sin_nombre_en_meta_tampoco(): void
    {
        // El push lo exige para armar el payload: sin él sólo puede acabar en error.
        $sinNombre = (new MessageTemplate())
            ->setWhatsappMetaTmpl(['body' => [['language' => 'es', 'content' => 'Hola']], 'meta_template_name' => '  ']);

        self::assertFalse($sinNombre->puedeSubirseAMeta());
    }

    #[Test]
    public function con_cuerpo_y_nombre_si_se_puede_subir(): void
    {
        $lista = (new MessageTemplate())
            ->setWhatsappMetaTmpl(['body' => [['language' => 'es', 'content' => 'Hola']], 'meta_template_name' => 'guia_llegada_v1']);

        self::assertTrue($lista->puedeSubirseAMeta());
    }

    private function plantilla(bool $beds24 = false, bool $meta = false, bool $correo = false): MessageTemplate
    {
        return (new MessageTemplate())
            ->setBeds24Tmpl(['is_active' => $beds24, 'body' => []])
            ->setWhatsappMetaTmpl(['is_active' => $meta, 'body' => []])
            ->setEmailTmpl(['is_active' => $correo, 'body' => []]);
    }
}
