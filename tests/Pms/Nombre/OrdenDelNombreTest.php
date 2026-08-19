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
}
