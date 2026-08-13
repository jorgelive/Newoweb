<?php

declare(strict_types=1);

namespace App\Tests\Message\Service;

use App\Message\Contract\Frente;
use App\Message\Contract\FrentesPorDominioInterface;
use App\Message\Contract\MomentoDeFrente;
use App\Message\Service\EnumeradorDeFrentes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué asuntos se le enseñan al triaje para que elija de cuál se habla.
 *
 * Unitario puro: los dominios son dobles en memoria, así que esto corre sin contenedor ni base
 * de datos. Lo que se protege aquí es el contrato que hace posible el caso que motivó el
 * modelo entero — **un cliente puede tener dos asuntos vivos del MISMO negocio en momentos
 * distintos**, y la puerta de venta está abierta aunque no haya comprado nada.
 */
final class EnumeradorDeFrentesTest extends TestCase
{
    /** @param list<Frente> $vivos */
    private function dominio(string $negocio, array $vivos, bool $vendible = true): FrentesPorDominioInterface
    {
        return new class ($negocio, $vivos, $vendible) implements FrentesPorDominioInterface {
            /** @param list<Frente> $vivos */
            public function __construct(
                private readonly string $negocio,
                private readonly array $vivos,
                private readonly bool $vendible,
            ) {}

            public function negocio(): string { return $this->negocio; }
            public function supports(?string $contextType): bool { return $contextType === $this->negocio . '_ctx'; }
            public function esVendible(): bool { return $this->vendible; }
            public function etiquetaDeVenta(): string { return 'Comprar ' . $this->negocio; }
            public function frentesVivos(?string $telefono): array { return $this->vivos; }
        };
    }

    private function ancla(string $negocio, MomentoDeFrente $momento, string $id): Frente
    {
        return new Frente(
            negocio: $negocio,
            momento: $momento,
            etiqueta: sprintf('Asunto %s de %s', $id, $negocio),
            entidadTipo: $negocio . '_entidad',
            entidadId: $id,
        );
    }

    /**
     * El caso que tumbó el diseño anterior: con una estancia en curso y una cotización de
     * ampliación pendiente, el vínculo comercial dice «cliente» y no distingue nada. Los dos
     * asuntos tienen que llegar al triaje para que pueda preguntar de cuál se habla.
     */
    #[Test]
    public function dos_asuntos_del_mismo_negocio_en_momentos_distintos_conviven(): void
    {
        $enumerador = new EnumeradorDeFrentes([
            $this->dominio('hotelero', [
                $this->ancla('hotelero', MomentoDeFrente::Operacion, 'estancia'),
                $this->ancla('hotelero', MomentoDeFrente::Venta, 'ampliacion'),
            ]),
        ]);

        $frentes = $enumerador->paraTelefono('51987654321');
        $conEntidad = array_values(array_filter($frentes, static fn (Frente $f) => !$f->esVentaSintetica()));

        self::assertCount(2, $conEntidad);
        self::assertSame(MomentoDeFrente::Operacion, $conEntidad[0]->momento);
        self::assertSame(MomentoDeFrente::Venta, $conEntidad[1]->momento);
    }

    /**
     * La puerta de venta de cada negocio aparece SIEMPRE. Es lo que hace que «no tengo
     * alojamiento, ¿qué ofreces?» de un cliente de tours no necesite ningún caso especial.
     */
    #[Test]
    public function la_venta_esta_disponible_aunque_no_haya_comprado_nada_de_ese_negocio(): void
    {
        $enumerador = new EnumeradorDeFrentes([
            $this->dominio('hotelero', []),
            $this->dominio('turistico', [$this->ancla('turistico', MomentoDeFrente::Operacion, 'tour')]),
        ]);

        $negociosEnVenta = array_map(
            static fn (Frente $f) => $f->negocio,
            array_filter($enumerador->paraTelefono('51987654321'), static fn (Frente $f) => $f->esVentaSintetica())
        );

        self::assertContains('hotelero', $negociosEnVenta);
        self::assertContains('turistico', $negociosEnVenta);
    }

    /** Un desconocido no tiene asuntos, pero sí las dos puertas: es un lead, no un callejón. */
    #[Test]
    public function un_desconocido_ve_solo_las_puertas_de_venta(): void
    {
        $enumerador = new EnumeradorDeFrentes([
            $this->dominio('hotelero', []),
            $this->dominio('turistico', []),
        ]);

        $frentes = $enumerador->paraTelefono(null);

        self::assertCount(2, $frentes);
        self::assertSame([true, true], array_map(static fn (Frente $f) => $f->esVentaSintetica(), $frentes));
        self::assertNull($enumerador->porDefecto($frentes), 'sin historia no hay defecto razonable');
    }

    /** Sólo el primer asunto con entidad queda marcado, y nunca una puerta de venta. */
    #[Test]
    public function el_defecto_es_el_primer_asunto_con_entidad(): void
    {
        $enumerador = new EnumeradorDeFrentes([
            $this->dominio('hotelero', [$this->ancla('hotelero', MomentoDeFrente::Operacion, 'estancia')]),
            $this->dominio('turistico', [$this->ancla('turistico', MomentoDeFrente::Operacion, 'tour')]),
        ]);

        $frentes = $enumerador->paraTelefono('51987654321');
        $defecto = $enumerador->porDefecto($frentes);

        self::assertNotNull($defecto);
        self::assertSame('hotelero', $defecto->negocio);
        self::assertSame(1, count(array_filter($frentes, static fn (Frente $f) => $f->porDefecto)));
    }

    /**
     * El tope no puede ser mudo: si se recortan asuntos y el bloque no lo dice, el modelo da la
     * lista por completa y elegirá otro frente convencido de que acierta.
     */
    #[Test]
    public function el_bloque_avisa_de_los_asuntos_que_no_caben(): void
    {
        $vivos = [];
        for ($i = 1; $i <= 7; $i++) {
            $vivos[] = $this->ancla('hotelero', MomentoDeFrente::Operacion, 'r' . $i);
        }

        $enumerador = new EnumeradorDeFrentes([$this->dominio('hotelero', $vivos)]);
        $frentes = $enumerador->paraTelefono('51987654321');

        $conEntidad = array_filter($frentes, static fn (Frente $f) => !$f->esVentaSintetica());
        self::assertCount(5, $conEntidad, 'se enseñan como mucho MAX_CON_ENTIDAD');

        self::assertStringContainsString('2 asunto(s) más', $enumerador->bloqueParaElPrompt($frentes));
    }

    /** Sin recorte no se inventa el aviso. */
    #[Test]
    public function sin_recorte_no_hay_linea_de_sobrantes(): void
    {
        $enumerador = new EnumeradorDeFrentes([
            $this->dominio('hotelero', [$this->ancla('hotelero', MomentoDeFrente::Operacion, 'r1')]),
        ]);

        self::assertStringNotContainsString(
            'más que no caben',
            $enumerador->bloqueParaElPrompt($enumerador->paraTelefono('51987654321'))
        );
    }

    /**
     * Dos implementaciones del mismo negocio pintarían dos puertas idénticas **con el mismo
     * id**, y `porId()` devolvería la primera sin que nadie lo notara.
     */
    #[Test]
    public function no_se_duplica_la_puerta_de_venta_de_un_negocio(): void
    {
        $enumerador = new EnumeradorDeFrentes([
            $this->dominio('hotelero', []),
            $this->dominio('hotelero', []),
        ]);

        $ventas = array_filter($enumerador->paraTelefono(null), static fn (Frente $f) => $f->esVentaSintetica());

        self::assertCount(1, $ventas);
    }

    /**
     * A qué negocios llega un actor: lo vendible SIEMPRE, más aquel en cuyo contexto está.
     *
     * Es lo que impide que el filtro de dominio deje sin catálogo a un prospecto —que por
     * definición no tiene nada comprado— justo cuando más interesa atenderlo.
     */
    #[Test]
    public function los_dominios_de_un_actor_incluyen_siempre_lo_vendible(): void
    {
        $enumerador = new EnumeradorDeFrentes([
            $this->dominio('hotelero', []),
            $this->dominio('turistico', [], vendible: false),
        ]);

        // Un desconocido: sólo lo que se vende a cualquiera.
        self::assertSame(['hotelero'], $enumerador->dominiosPara(null));

        // Dentro de un contexto turístico: lo vendible MÁS el negocio en el que está, aunque
        // ese negocio no se venda por el chat.
        self::assertEqualsCanonicalizing(
            ['hotelero', 'turistico'],
            $enumerador->dominiosPara('turistico_ctx')
        );
    }

    /**
     * Lista blanca estricta: el modelo puede inventarse un id con toda la seguridad del mundo,
     * y aquí es donde se descarta. Mismo blindaje que ya tiene la skill que propone el triaje.
     */
    #[Test]
    public function un_id_inventado_no_resuelve_ningun_frente(): void
    {
        $enumerador = new EnumeradorDeFrentes([$this->dominio('hotelero', [])]);
        $frentes = $enumerador->paraTelefono(null);

        self::assertNull($enumerador->porId($frentes, 'fInventado'));
        self::assertNull($enumerador->porId($frentes, ''));
        self::assertNotNull($enumerador->porId($frentes, $frentes[0]->id()));
    }

    /**
     * El id se deriva del contenido, no de la posición: si dependiera del orden, una pregunta
     * de desambiguación de ayer dejaría de ser mapeable hoy —el cliente contesta «el segundo» y
     * el segundo ya es otro—.
     */
    #[Test]
    public function el_id_es_estable_aunque_cambie_el_orden(): void
    {
        $tour = $this->ancla('turistico', MomentoDeFrente::Operacion, 'tour');
        $estancia = $this->ancla('hotelero', MomentoDeFrente::Operacion, 'estancia');

        self::assertSame($tour->id(), $this->ancla('turistico', MomentoDeFrente::Operacion, 'tour')->id());
        self::assertNotSame($tour->id(), $estancia->id());
    }

    /**
     * El bloque del prompt lleva la etiqueta legible y un id opaco — **nunca el identificador
     * de la entidad**. Es lo que permite enseñárselo al modelo sin vigilar si acaba citado en
     * una respuesta al cliente.
     *
     * (Sólo se garantiza lo que pone el núcleo: si un dominio escribe un uuid DENTRO de su
     * etiqueta, lo está publicando él. Por eso la etiqueta la redacta el dominio, que es quien
     * sabe qué es decible.)
     */
    #[Test]
    public function el_bloque_del_prompt_no_lleva_el_id_de_la_entidad(): void
    {
        $enumerador = new EnumeradorDeFrentes([
            $this->dominio('hotelero', [
                new Frente(
                    negocio: 'hotelero',
                    momento: MomentoDeFrente::Operacion,
                    etiqueta: 'Tu reserva Casita 3, 12–15 mar',
                    entidadTipo: 'pms_reserva',
                    entidadId: '019c0153-8153-7ae8-a343-3c073fddfd9d',
                ),
            ]),
        ]);

        $bloque = $enumerador->bloqueParaElPrompt($enumerador->paraTelefono('51987654321'));

        self::assertStringNotContainsString('019c0153', $bloque);
        self::assertStringContainsString('Tu reserva Casita 3', $bloque);
        self::assertStringContainsString('por defecto', $bloque);
    }
}
