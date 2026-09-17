<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Plantilla;

use App\Message\Entity\MessageRule;
use App\Message\Entity\MessageTemplate;
use App\Message\Service\Plantilla\ArchivadorDePlantillas;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Archivar es apagar todos los canales; devolver, encender sólo los que tienen texto.
 *
 * Lo usan el botón del panel y `msg:plantilla:archivar`, y lo que de verdad comparten es la guarda:
 * una plantilla que usa una regla activa no se archiva.
 */
final class ArchivadorDePlantillasTest extends TestCase
{
    #[Test]
    public function archivar_apaga_todos_los_canales(): void
    {
        $plantilla = $this->plantilla(beds24: true, meta: true, correo: true);

        $this->archivador()->archivar($plantilla);

        self::assertFalse($plantilla->estaEnCirculacion());
        self::assertFalse($plantilla->isBeds24Active());
        self::assertFalse($plantilla->isWhatsappMetaActive());
        self::assertFalse($plantilla->isEmailActive());
    }

    #[Test]
    public function archivar_no_toca_los_cuerpos(): void
    {
        // Archivar no es borrar: el texto tiene que seguir ahí para poder volver.
        $plantilla = $this->plantilla(beds24: true);

        $this->archivador()->archivar($plantilla);

        self::assertSame([['language' => 'es', 'content' => 'Hola']], $plantilla->getBeds24Tmpl()['body'] ?? null);
    }

    #[Test]
    public function no_se_archiva_si_una_regla_activa_la_usa(): void
    {
        $plantilla = $this->plantilla(beds24: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Guia de llegada/');

        $this->archivador(['Guia de llegada'])->archivar($plantilla);
    }

    #[Test]
    public function devolver_enciende_solo_los_canales_con_texto(): void
    {
        $plantilla = $this->plantilla(beds24: true, meta: false, correo: false);
        $this->archivador()->archivar($plantilla);

        $encendidos = $this->archivador()->devolverACirculacion($plantilla);

        self::assertSame(['Beds24'], $encendidos);
        self::assertTrue($plantilla->isBeds24Active());
        self::assertFalse($plantilla->isEmailActive());
    }

    #[Test]
    public function el_cuerpo_de_dentro_de_la_ventana_enciende_whatsapp(): void
    {
        // «WA dentro» no tiene interruptor propio: lo gobierna el de Meta. Una plantilla escrita
        // sólo para dentro de la ventana —`pago_texto`— tiene que volver encendida.
        $plantilla = (new MessageTemplate())
            ->setWhatsappMetaTmpl(['is_active' => false, 'body' => []])
            ->setWhatsappLinkTmpl(['body' => [['language' => 'es', 'content' => 'Hola']]]);

        self::assertSame(['WhatsApp'], $this->archivador()->devolverACirculacion($plantilla));
        self::assertTrue($plantilla->isWhatsappMetaActive());
    }

    #[Test]
    public function sin_texto_en_ningun_canal_no_enciende_nada(): void
    {
        self::assertSame([], $this->archivador()->devolverACirculacion($this->plantilla()));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @param list<string> $reglasActivas */
    private function archivador(array $reglasActivas = []): ArchivadorDePlantillas
    {
        $reglas = array_map(static fn (string $nombre): MessageRule => (new MessageRule())->setName($nombre), $reglasActivas);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($reglas);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new ArchivadorDePlantillas($em);
    }

    private function plantilla(bool $beds24 = false, bool $meta = false, bool $correo = false): MessageTemplate
    {
        $cuerpo = [['language' => 'es', 'content' => 'Hola']];

        return (new MessageTemplate())
            ->setBeds24Tmpl(['is_active' => $beds24, 'body' => $beds24 ? $cuerpo : []])
            ->setWhatsappMetaTmpl(['is_active' => $meta, 'body' => $meta ? $cuerpo : []])
            ->setEmailTmpl(['is_active' => $correo, 'body' => $correo ? $cuerpo : []]);
    }
}
