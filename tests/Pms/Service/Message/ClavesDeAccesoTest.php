<?php

declare(strict_types=1);

namespace App\Tests\Pms\Service\Message;

use App\Pms\Enum\PmsEstablecimientoMediaTipo;
use App\Pms\Service\Message\PmsMessageDataResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Lo que abre algo no puede viajar sólo por estar en el diccionario de las plantillas.
 */
final class ClavesDeAccesoTest extends TestCase
{
    #[Test]
    public function todo_medio_del_establecimiento_esta_declarado_como_clave_de_acceso(): void
    {
        // 🔥 EL FALLO QUE ESTE TEST IMPIDE, y ocurrió el 12/09/2026:
        //
        // `getMessageVariables()` nació para rellenar plantillas —que manda un operador— pero
        // `ConsultarMiReservaSkill` lo vuelca ENTERO al modelo, y esa skill la puede llamar el
        // huésped. Al entrar ahí los códigos de las cajas y los medios de la del dinero, un
        // huésped preguntando «¿cuál es mi reserva?» recibía los dos códigos y la foto de dónde
        // se deja el efectivo. Sin ventana y sin haber pagado.
        //
        // Un tipo nuevo en el enum vuelve a abrir el agujero si nadie lo añade a la lista. Este
        // test es lo que lo nota.
        foreach (PmsEstablecimientoMediaTipo::cases() as $tipo) {
            self::assertContains(
                $tipo->clave(),
                PmsMessageDataResolver::CLAVES_DE_ACCESO,
                sprintf(
                    'El medio «%s» viaja en las variables de plantilla pero no está en '
                    . 'CLAVES_DE_ACCESO: se le escaparía al huésped por consultar_mi_reserva.',
                    $tipo->clave()
                )
            );
        }
    }

    #[Test]
    public function los_codigos_de_las_dos_cajas_estan_declarados(): void
    {
        // No salen del enum —son columnas del establecimiento—, así que se comprueban aparte.
        self::assertContains('codigo_caja_llaves', PmsMessageDataResolver::CLAVES_DE_ACCESO);
        self::assertContains('codigo_caja_dinero', PmsMessageDataResolver::CLAVES_DE_ACCESO);
    }

    #[Test]
    public function la_lista_cubre_exactamente_lo_que_produce_el_metodo(): void
    {
        // 🔒 La comprobación de verdad: se ejecuta `mediosDelAlojamiento()` y se exige que TODAS
        // las claves que devuelve estén declaradas. Así, añadir una clave nueva a ese método sin
        // declararla falla aquí y no en producción.
        //
        // Se llama sin establecimiento: los valores salen vacíos, pero las CLAVES son las mismas.
        // Lo que se fija es el contrato de qué se publica, no los datos.
        $resolver = (new \ReflectionClass(PmsMessageDataResolver::class))->newInstanceWithoutConstructor();
        $metodo = new ReflectionMethod($resolver, 'mediosDelAlojamiento');

        /** @var array<string, string> $producidas */
        $producidas = $metodo->invoke($resolver, null);

        self::assertNotEmpty($producidas, 'El método no produjo ninguna clave: el test no probaría nada.');

        foreach (array_keys($producidas) as $clave) {
            self::assertContains(
                $clave,
                PmsMessageDataResolver::CLAVES_DE_ACCESO,
                sprintf('La clave «%s» se publica pero no está declarada como clave de acceso.', $clave)
            );
        }
    }
}
