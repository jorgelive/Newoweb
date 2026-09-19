<?php

declare(strict_types=1);

namespace App\Tests\Agent\Service;

use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Service\Agent\PmsEnlacesDeFicha;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El enlace interno que el agente puede seguir.
 *
 * Lo que prueba de verdad es la regla de no duplicar: una entrada de conocimiento puede decir
 * «el calefactor está en tal tema» en vez de copiar su precio, y el modelo recibe el título.
 */
final class PmsEnlacesDeFichaTest extends TestCase
{
    private function servicio(?PmsGuiaItem $destino): PmsEnlacesDeFicha
    {
        $repositorio = $this->createStub(EntityRepository::class);
        $repositorio->method('findOneBy')->willReturn($destino);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repositorio);

        return new PmsEnlacesDeFicha($em);
    }

    private function ficha(string $titulo): PmsGuiaItem
    {
        return (new PmsGuiaItem())->setTitulo([['language' => 'es', 'content' => $titulo]]);
    }

    #[Test]
    public function el_marcador_se_convierte_en_el_titulo_del_destino(): void
    {
        $texto = $this->servicio($this->ficha('Alquiler de calefacción'))
            ->resolver('Hay calefactores. {{ ficha: calefactor }} Cuéntale lo que diga.', 'es');

        self::assertStringContainsString('«Alquiler de calefacción»', $texto);
        self::assertStringNotContainsString('{{', $texto);
    }

    /**
     * ⚠️ Un código que ya no existe se BORRA, no se anuncia: remitir a un tema que no está es
     * mandar al modelo a buscar humo, y contesta peor que si no hubiera remisión.
     */
    #[Test]
    public function un_codigo_que_no_existe_desaparece_sin_dejar_rastro(): void
    {
        $texto = $this->servicio(null)->resolver('Hay calefactores. {{ ficha: borrada }} Fin.', 'es');

        self::assertStringNotContainsString('{{', $texto);
        self::assertStringNotContainsString('tema', $texto);
    }

    /** Sin marcadores no se toca nada, ni se consulta la base. */
    #[Test]
    public function un_texto_sin_marcadores_sale_igual(): void
    {
        $original = 'Tenemos disponibles frazadas adicionales.';

        self::assertSame($original, $this->servicio(null)->resolver($original, 'es'));
    }
}
