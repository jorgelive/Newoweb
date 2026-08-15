<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill\Pms;

use App\Agent\Skill\Pms\DistribucionLeida;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El parseo del reparto por casitas y, sobre todo, su CONTRATO: las dos listas existen siempre.
 *
 * La versión array de este contrato se rompió justo por ahí —el texto vacío devolvía un array
 * sin claves y el llamador moría en `implode(null)`—, así que el primer test cubre el caso que
 * tumbó la skill en producción. Unitario puro, sin contenedor ni base de datos.
 */
final class DistribucionLeidaTest extends TestCase
{
    /**
     * El caso NORMAL, que fue el que falló: preguntar disponibilidad sin decir reparto. Con el
     * objeto, el test parece tautológico, y ése es el punto — las propiedades no pueden faltar.
     */
    #[Test]
    public function el_texto_vacio_da_las_dos_listas_vacias(): void
    {
        $leido = DistribucionLeida::deTexto('');

        self::assertSame([], $leido->reparto);
        self::assertSame([], $leido->ilegibles);
    }

    /** Comas y puntos y comas sueltos no son trozos que reportar: son lo mismo que nada. */
    #[Test]
    public function solo_separadores_es_lo_mismo_que_texto_vacio(): void
    {
        $leido = DistribucionLeida::deTexto(' , ;; ,  ');

        self::assertSame([], $leido->reparto);
        self::assertSame([], $leido->ilegibles);
    }

    #[Test]
    public function un_reparto_valido_se_lee_entero_y_en_orden(): void
    {
        $leido = DistribucionLeida::deTexto('2:7, Casita 5:2');

        self::assertSame([['2', 7], ['5', 2]], $leido->reparto);
        self::assertSame([], $leido->ilegibles);
    }

    /** El formato exacto es lo primero que el modelo olvida: «=», espacios y «casitas» valen. */
    #[Test]
    public function tolera_el_igual_los_espacios_y_la_palabra_casita(): void
    {
        $leido = DistribucionLeida::deTexto('Casita 2 = 7; casitas 5=2');

        self::assertSame([['2', 7], ['5', 2]], $leido->reparto);
        self::assertSame([], $leido->ilegibles);
    }

    /** Lo ilegible VUELVE, no se calla: el descarte silencioso cotizaba con medio reparto. */
    #[Test]
    public function lo_que_no_encaja_vuelve_como_ilegible(): void
    {
        $leido = DistribucionLeida::deTexto('siete personas en la dos');

        self::assertSame([], $leido->reparto);
        self::assertSame(['siete personas en la dos'], $leido->ilegibles);
    }

    /**
     * La mezcla es el caso peligroso: si lo válido tapara lo ilegible, se volvería a cotizar
     * con la mitad del reparto como si estuviera completo.
     */
    #[Test]
    public function la_mezcla_conserva_lo_valido_y_reporta_lo_ilegible(): void
    {
        $leido = DistribucionLeida::deTexto('2:7, Casita 5: dos personas');

        self::assertSame([['2', 7]], $leido->reparto);
        self::assertSame(['Casita 5: dos personas'], $leido->ilegibles);
    }

    /**
     * «Casitas 2-7: 10» es «de la 2 a la 7» para un humano; aceptando el guion se cotizaba
     * SOLO la 7 con las 10 personas y la cotización salía marcada como completa.
     */
    #[Test]
    public function un_rango_con_guion_no_se_interpreta_como_una_casita(): void
    {
        $leido = DistribucionLeida::deTexto('Casitas 2-7: 10');

        self::assertSame([], $leido->reparto);
        self::assertSame(['Casitas 2-7: 10'], $leido->ilegibles);
    }

    /** Cero personas no es un reparto: es un trozo que corregir antes de cotizar. */
    #[Test]
    public function cero_personas_es_ilegible(): void
    {
        $leido = DistribucionLeida::deTexto('2:0, 5:3');

        self::assertSame([['5', 3]], $leido->reparto);
        self::assertSame(['2:0'], $leido->ilegibles);
    }
}
