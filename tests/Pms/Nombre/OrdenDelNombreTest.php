<?php

declare(strict_types=1);

namespace App\Tests\Pms\Nombre;

use App\Pms\Nombre\OrdenDelNombre;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Las tres decisiones puras de la revisión del orden del nombre.
 *
 * El caso que la motiva es real: la reserva `88233049` de Booking llegó con
 * `firstName: "RODRIGUEZ BARRERA"` y `lastName: "ALISSON ANGELICA"`, y la bienvenida saludaba
 * por el apellido.
 *
 * Unitario puro: ni contenedor, ni base, ni modelo.
 */
final class OrdenDelNombreTest extends TestCase
{
    #[Test]
    public function se_revisa_un_par_completo(): void
    {
        self::assertTrue(OrdenDelNombre::mereceRevision('RODRIGUEZ BARRERA', 'ALISSON ANGELICA'));
    }

    #[Test]
    public function no_se_gasta_una_llamada_en_lo_que_no_se_puede_juzgar(): void
    {
        self::assertFalse(OrdenDelNombre::mereceRevision('César', null), 'sin apellido no hay orden');
        self::assertFalse(OrdenDelNombre::mereceRevision('César', 'H'), 'una letra no se juzga');
        self::assertFalse(OrdenDelNombre::mereceRevision('', 'Quispe'), 'sin nombre');
        self::assertFalse(OrdenDelNombre::mereceRevision('Pendiente Sync', '(Grupo)'), 'relleno del pull');
        self::assertFalse(OrdenDelNombre::mereceRevision('12', '34'), 'sin letras');
    }

    #[Test]
    public function nuestro_propio_intercambio_no_vuelve_a_encolarse(): void
    {
        // 🔁 EL CORTA-BUCLES. Si esto deja de dar true, cada corrección se re-encola sola.
        self::assertTrue(OrdenDelNombre::esNuestroIntercambio(
            'RODRIGUEZ BARRERA', 'ALISSON ANGELICA',
            'ALISSON ANGELICA', 'RODRIGUEZ BARRERA'
        ));
    }

    #[Test]
    public function una_correccion_de_verdad_si_se_revisa(): void
    {
        // Un operador arreglando una tilde también cambia el nombre, y ése hay que mirarlo.
        self::assertFalse(OrdenDelNombre::esNuestroIntercambio(
            'Jose', 'Quispe',
            'José', 'Quispe'
        ));
    }

    #[Test]
    public function se_aplica_solo_con_invertido_y_confianza_alta(): void
    {
        $par = OrdenDelNombre::resultado(
            invertido: true, confianza: 'alta',
            nombreJuzgado: 'RODRIGUEZ BARRERA', apellidoJuzgado: 'ALISSON ANGELICA',
            nombreActual: 'RODRIGUEZ BARRERA', apellidoActual: 'ALISSON ANGELICA',
        );

        self::assertSame(['ALISSON ANGELICA', 'RODRIGUEZ BARRERA'], $par);
    }

    #[Test]
    public function ante_la_duda_no_se_toca(): void
    {
        foreach (['media', 'baja', ''] as $confianza) {
            self::assertNull(OrdenDelNombre::resultado(
                invertido: true, confianza: $confianza,
                nombreJuzgado: 'A B', apellidoJuzgado: 'C D',
                nombreActual: 'A B', apellidoActual: 'C D',
            ), "confianza «$confianza» no debería aplicar");
        }

        self::assertNull(OrdenDelNombre::resultado(
            invertido: false, confianza: 'alta',
            nombreJuzgado: 'A B', apellidoJuzgado: 'C D',
            nombreActual: 'A B', apellidoActual: 'C D',
        ));
    }

    #[Test]
    public function un_veredicto_sobre_un_dato_que_ya_cambio_se_descarta(): void
    {
        // Entre la pregunta y la respuesta entró otro pull, o un operador. Aplicarlo sería
        // cruzar dos cadenas que el modelo no llegó a ver.
        self::assertNull(OrdenDelNombre::resultado(
            invertido: true, confianza: 'alta',
            nombreJuzgado: 'RODRIGUEZ BARRERA', apellidoJuzgado: 'ALISSON ANGELICA',
            nombreActual: 'Alisson Angelica', apellidoActual: 'Rodriguez Barrera',
        ));
    }

    /**
     * @param array<string, string|bool> $extra
     * @return array{invertido: bool, confianza: string, motivo: string, nombreCapitalizado: string, apellidoCapitalizado: string}
     */
    private function veredicto(string $nombreCap, string $apellidoCap, array $extra = []): array
    {
        return array_merge([
            'invertido' => true,
            'confianza' => 'alta',
            'motivo' => '',
            'nombreCapitalizado' => $nombreCap,
            'apellidoCapitalizado' => $apellidoCap,
        ], $extra);
    }

    /**
     * 🔥 **El caso que estuvo roto.** Reserva real del 12/09/2026: llegó con
     * `campo_nombre: uylenbroeck`, `campo_apellido: robin`. El modelo acertó de pleno —invertido,
     * confianza alta, «Robin» / «Uylenbroeck»— y en el calendario se leía **«robin uylenbroeck»**.
     *
     * El código cruzaba las propuestas de caja junto con el orden, dando por hecho que venían
     * etiquetadas por el campo del que salieron. El modelo las devuelve **ya en su rol corregido**,
     * así que el cruce las desemparejaba: se intentaba escribir «Uylenbroeck» sobre «robin», el
     * guardián de las mismas letras lo rechazaba —bien— y quedaban los dos originales en
     * minúscula. El orden sí se aplicaba y la caja no, sin un solo error en ningún log.
     */
    #[Test]
    public function al_cruzar_el_orden_la_caja_llega_a_su_campo(): void
    {
        self::assertSame(
            ['Robin', 'Uylenbroeck'],
            OrdenDelNombre::comoQuedaria($this->veredicto('Robin', 'Uylenbroeck'), 'uylenbroeck', 'robin'),
        );
    }

    /**
     * 🔑 Y con la etiqueta al revés, lo mismo: quién es quién lo dicen **las letras**, no el nombre
     * del campo en un JSON. Es lo que hace que esto no dependa de cómo interprete el esquema el
     * modelo de hoy — ni el de dentro de seis meses.
     */
    #[Test]
    public function da_igual_como_venga_etiquetada_la_propuesta(): void
    {
        self::assertSame(
            ['Robin', 'Uylenbroeck'],
            OrdenDelNombre::comoQuedaria($this->veredicto('Uylenbroeck', 'Robin'), 'uylenbroeck', 'robin'),
        );
    }

    /** Sin cruce, la caja también llega: es el 90 % de los casos. */
    #[Test]
    public function sin_cruzar_tambien_se_arregla_la_caja(): void
    {
        self::assertSame(
            ['José Antonio', 'Álvarez'],
            OrdenDelNombre::comoQuedaria(
                $this->veredicto('José Antonio', 'Álvarez', ['invertido' => false]),
                'JOSE ANTONIO',
                'ALVAREZ',
            ),
        );
    }

    /**
     * ⚠️ Y el guardián sigue en pie: `B0UZA` lleva un CERO donde va una O. La propuesta «Bouza»
     * cambia una letra, no la caja, así que se rechaza — y ahora que se prueban las dos propuestas
     * hay que comprobar que la otra tampoco cuela por detrás.
     */
    #[Test]
    public function una_propuesta_que_cambia_letras_se_rechaza_venga_de_donde_venga(): void
    {
        self::assertSame(
            ['B0UZA', 'Marta'],
            OrdenDelNombre::comoQuedaria(
                $this->veredicto('Bouza', 'Marta', ['invertido' => false]),
                'B0UZA',
                'MARTA',
            ),
        );
    }
}
