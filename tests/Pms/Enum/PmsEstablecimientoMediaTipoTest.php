<?php

declare(strict_types=1);

namespace App\Tests\Pms\Enum;

use App\Pms\Entity\PmsEstablecimientoMedia;
use App\Pms\Enum\PmsEstablecimientoMediaTipo;
use App\Pms\Enum\PmsGuiaVisibilidad;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Los medios del edificio: las dos cajas fuertes, y por qué sólo una sale en la guía.
 */
final class PmsEstablecimientoMediaTipoTest extends TestCase
{
    #[Test]
    public function lo_de_la_caja_del_dinero_no_entra_en_la_guia(): void
    {
        // 🔒 ESTA ES LA PROPIEDAD QUE HAY QUE DEFENDER, y no es una preferencia de diseño: el
        // huésped no puede pedir dónde está la caja del dinero. Se la entrega un operador con
        // `enviar_plantilla`, que exige ROLE_MENSAJES_WRITE.
        //
        // `null` significa «no está en la escalera de la guía», que no es lo mismo que «el peldaño
        // más alto»: SoloVentana acabaría enseñándola sola 30 h antes de cada llegada.
        self::assertNull(PmsEstablecimientoMediaTipo::VIDEO_CAJA_DINERO->visibilidad());
        self::assertNull(PmsEstablecimientoMediaTipo::FOTO_CAJA_DINERO->visibilidad());
    }

    #[Test]
    public function lo_de_la_caja_de_llaves_sale_con_la_ventana_abierta(): void
    {
        // Abre una puerta: mismo nivel que todo lo que abre puertas.
        self::assertSame(
            PmsGuiaVisibilidad::SoloVentana,
            PmsEstablecimientoMediaTipo::VIDEO_CAJA_LLAVES->visibilidad()
        );
        self::assertSame(
            PmsGuiaVisibilidad::SoloVentana,
            PmsEstablecimientoMediaTipo::FOTO_CAJA_LLAVES->visibilidad()
        );
    }

    #[Test]
    public function un_tipo_nuevo_tiene_que_decidir_si_sale_en_la_guia(): void
    {
        // `match` sin rama por defecto ya obliga a declararlo, pero este test dice POR QUÉ importa:
        // el que se olvide no fallará en tiempo de compilación si alguien añade un `default`.
        foreach (PmsEstablecimientoMediaTipo::cases() as $tipo) {
            $nivel = $tipo->visibilidad();

            self::assertTrue(
                $nivel === null || $nivel instanceof PmsGuiaVisibilidad,
                sprintf('El tipo «%s» no declara si sale en la guía.', $tipo->value)
            );
        }
    }

    #[Test]
    public function las_fotos_son_archivo_y_los_videos_url(): void
    {
        // `esArchivo()` decide de qué columna se lee. Un tipo que lo declare mal sale vacío.
        self::assertTrue(PmsEstablecimientoMediaTipo::FOTO_CAJA_LLAVES->esArchivo());
        self::assertTrue(PmsEstablecimientoMediaTipo::FOTO_CAJA_DINERO->esArchivo());
        self::assertFalse(PmsEstablecimientoMediaTipo::VIDEO_CAJA_LLAVES->esArchivo());
        self::assertFalse(PmsEstablecimientoMediaTipo::VIDEO_CAJA_DINERO->esArchivo());
    }

    #[Test]
    public function las_opciones_del_panel_son_casos_y_no_cadenas(): void
    {
        // La columna va con `enumType`: con cadenas, abrir la edición revienta con «could not be
        // converted to string» — al ABRIR, no al guardar. Pasó con PmsUnidadMediaTipo el
        // 12/09/2026.
        foreach (PmsEstablecimientoMediaTipo::opciones() as $etiqueta => $opcion) {
            self::assertInstanceOf(PmsEstablecimientoMediaTipo::class, $opcion, sprintf(
                'La opción «%s» tiene que ser el caso del enum, no su valor.',
                $etiqueta
            ));
        }
    }

    #[Test]
    public function el_tipo_por_defecto_de_la_entidad_es_un_caso_valido(): void
    {
        self::assertContains(
            (new PmsEstablecimientoMedia())->getTipo(),
            PmsEstablecimientoMediaTipo::cases()
        );
    }
}
