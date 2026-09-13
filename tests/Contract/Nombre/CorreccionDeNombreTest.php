<?php

declare(strict_types=1);

namespace App\Tests\Contract\Nombre;

use App\Contract\Nombre\CorreccionDeNombre;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué se propaga y qué no. Es lo único que decide este DTO, y decide por todas las copias a la vez:
 * un fallo aquí las desincroniza todas o las hace trabajar de balde.
 */
final class CorreccionDeNombreTest extends TestCase
{
    private function correccion(string $nA, string $aA, string $nD, string $aD): CorreccionDeNombre
    {
        return new CorreccionDeNombre('pms_reserva', 'id-1', $nA, $aA, $nD, $aD);
    }

    /** El caso real: el canal manda el par cruzado y en minúsculas, el corrector lo endereza. */
    #[Test]
    public function un_nombre_enderezado_se_propaga(): void
    {
        $c = $this->correccion('uylenbroeck', 'robin', 'Robin', 'Uylenbroeck');

        self::assertTrue($c->valeLaPena());
        self::assertSame('uylenbroeck robin', $c->completoAntes());
        self::assertSame('Robin Uylenbroeck', $c->completoAhora());
    }

    /** Sin cambio no hay nada que avisar: propagar sería despertar a todas las copias de balde. */
    #[Test]
    public function un_par_que_no_cambio_no_se_propaga(): void
    {
        self::assertFalse($this->correccion('Robin', 'Uylenbroeck', 'Robin', 'Uylenbroeck')->valeLaPena());
    }

    /**
     * ⚠️ Un cambio que deja el nombre VACÍO no se propaga: borraría copias buenas a cambio de
     * nada. Pasa de verdad — un pull a medias puede dejar la reserva sin nombre un instante.
     */
    #[Test]
    public function vaciar_el_nombre_no_borra_las_copias(): void
    {
        self::assertFalse($this->correccion('Robin', 'Uylenbroeck', '', '')->valeLaPena());
        self::assertFalse($this->correccion('Robin', 'Uylenbroeck', '   ', ' ')->valeLaPena());
    }

    /** Sólo cambia la caja, y sí importa: es la mitad de los casos que este mecanismo arregla. */
    #[Test]
    public function un_cambio_de_solo_caja_tambien_se_propaga(): void
    {
        self::assertTrue($this->correccion('robin', 'uylenbroeck', 'Robin', 'Uylenbroeck')->valeLaPena());
    }

    /** Con la mitad del par vacía sigue habiendo nombre, así que sigue habiendo qué propagar. */
    #[Test]
    public function con_solo_nombre_de_pila_sigue_valiendo(): void
    {
        $c = $this->correccion('katherine', '', 'Katherine', '');

        self::assertTrue($c->valeLaPena());
        self::assertSame('Katherine', $c->completoAhora());
    }
}
