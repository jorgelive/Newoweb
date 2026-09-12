<?php

declare(strict_types=1);

namespace App\Tests\Pms\Enum;

use App\Pms\Entity\PmsUnidadMedia;
use App\Pms\Enum\PmsGuiaVisibilidad;
use App\Pms\Enum\PmsUnidadMediaTipo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los tipos de medio de una casita: qué son, quién los ve y cómo llegan al desplegable.
 */
final class PmsUnidadMediaTipoTest extends TestCase
{
    #[Test]
    public function las_opciones_del_panel_son_casos_y_no_cadenas(): void
    {
        // 🔥 Esto tumbó la edición de medios en producción el 12/09/2026 con un 500 seco:
        // «Object of class PmsUnidadMediaTipo could not be converted to string».
        //
        // La columna está mapeada con `enumType`, así que `PmsUnidadMedia::getTipo()` devuelve el
        // OBJETO. Si las opciones son cadenas, el formulario intenta convertirlo a texto para
        // casarlo con una de ellas y revienta. Y revienta al ABRIR la pantalla, no al guardar:
        // no hay forma de llegar al formulario para deshacerlo desde el panel.
        //
        // PHPStan no lo ve —`ChoiceField::setChoices()` acepta un array suelto— y ningún test
        // tocaba el formulario, así que el primero en enterarse fue quien lo usaba.
        foreach (PmsUnidadMediaTipo::opciones() as $etiqueta => $opcion) {
            self::assertInstanceOf(PmsUnidadMediaTipo::class, $opcion, sprintf(
                'La opción «%s» tiene que ser el caso del enum, no su valor: es lo que guarda la '
                . 'entidad y con lo que el formulario compara.',
                $etiqueta
            ));
        }
    }

    #[Test]
    public function el_desplegable_ofrece_todos_los_tipos(): void
    {
        // Si un tipo nuevo no sale en el desplegable, existe en el código y no se puede crear.
        self::assertCount(count(PmsUnidadMediaTipo::cases()), PmsUnidadMediaTipo::opciones());
    }

    #[Test]
    public function el_tipo_por_defecto_de_la_entidad_es_un_caso_valido(): void
    {
        // La propiedad nace inicializada. Si alguien renombra un caso y no el valor por defecto,
        // Doctrine falla al persistir la primera fila, lejos de aquí.
        self::assertContains(
            (new PmsUnidadMedia())->getTipo(),
            PmsUnidadMediaTipo::cases()
        );
    }

    #[Test]
    public function solo_la_portada_es_publica(): void
    {
        // El croquis y la foto de la puerta ubican a quien ya tiene su localizador; el vídeo del
        // ingreso enseña el recorrido hasta dentro y espera a la ventana. La portada es el
        // escaparate: sale sin reserva de por medio. Ver `docs/PmsGuiaHuesped.md` §3.c.
        self::assertSame(PmsGuiaVisibilidad::Publico, PmsUnidadMediaTipo::PORTADA->visibilidad());
        self::assertSame(PmsGuiaVisibilidad::Cliente, PmsUnidadMediaTipo::CROQUIS->visibilidad());
        self::assertSame(PmsGuiaVisibilidad::Cliente, PmsUnidadMediaTipo::FOTO_PUERTA->visibilidad());
        self::assertSame(PmsGuiaVisibilidad::SoloVentana, PmsUnidadMediaTipo::VIDEO_INGRESO->visibilidad());
    }

    #[Test]
    public function el_unico_tipo_que_no_es_archivo_es_el_video(): void
    {
        // `esArchivo()` es lo que decide si el contenido viene de `imageName` o de `url`. Un tipo
        // nuevo que se olvide aquí se leería por la columna equivocada y saldría vacío.
        foreach (PmsUnidadMediaTipo::cases() as $tipo) {
            self::assertSame(
                $tipo !== PmsUnidadMediaTipo::VIDEO_INGRESO,
                $tipo->esArchivo(),
                sprintf('El tipo «%s» no declara bien dónde vive su contenido.', $tipo->value)
            );
        }
    }
}
