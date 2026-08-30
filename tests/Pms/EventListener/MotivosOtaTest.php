<?php

declare(strict_types=1);

namespace App\Tests\Pms\EventListener;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\EventListener\PmsEventoCalendarioSecurityListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Censo: cada estado bloqueado tiene que tener su motivo escrito.
 *
 * ⚠️ **Este test sustituye a un `throw` de reserva que era código muerto.** El listener tenía un
 * cuarto `throw` genérico «por si en el futuro se agregan más estados» a
 * `OTA_ESTADOS_NO_SELECCIONABLES`. Era inalcanzable —los tres casos de arriba cubrían la
 * constante entera— y borrarlo sin más habría dejado el agujero que ese fallback intentaba tapar:
 * añadir un código nuevo y que la regla dejara de aplicarse en silencio.
 *
 * Un censo es mejor que un fallback por dos razones. Falla **antes**, en el build y no cuando un
 * cliente se queja. Y obliga a **escribir el motivo**, en vez de despachar el caso nuevo con una
 * frase genérica que no explica nada a quien la recibe.
 *
 * Es el mismo patrón que `PuntosDeServicioTest::todo_tipo_tiene_respuesta`.
 */
final class MotivosOtaTest extends TestCase
{
    #[Test]
    public function todo_estado_bloqueado_tiene_su_motivo(): void
    {
        foreach (PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES as $codigo) {
            self::assertArrayHasKey(
                $codigo,
                PmsEventoCalendarioSecurityListener::MOTIVO_OTA,
                sprintf(
                    'El estado «%s» está en OTA_ESTADOS_NO_SELECCIONABLES pero no tiene motivo en '
                    .'MOTIVO_OTA. Sin él, el listener revienta al bloquearlo. Escribe qué se le '
                    .'dice al operador que intenta esa transición.',
                    $codigo,
                ),
            );

            self::assertNotSame(
                '',
                trim(PmsEventoCalendarioSecurityListener::MOTIVO_OTA[$codigo]),
                sprintf('El motivo de «%s» está vacío.', $codigo),
            );
        }
    }

    /**
     * Al revés: un motivo para un estado que ya no se bloquea es letra muerta.
     */
    #[Test]
    public function no_sobra_ningun_motivo(): void
    {
        foreach (array_keys(PmsEventoCalendarioSecurityListener::MOTIVO_OTA) as $codigo) {
            self::assertContains(
                $codigo,
                PmsEventoCalendario::OTA_ESTADOS_NO_SELECCIONABLES,
                sprintf('Hay motivo para «%s», pero ese estado ya no está bloqueado.', $codigo),
            );
        }
    }
}
