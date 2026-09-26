<?php

declare(strict_types=1);

namespace App\Tests\Calidad;

use PHPUnit\Framework\TestCase;

/**
 * El baseline de PHPStan es deuda medida, no una lista de perdonados.
 *
 * ── Por qué existe este test ────────────────────────────────────────────────
 * El baseline nació de golpe con `--generate-baseline` y volvió a crecer cada vez que se subió
 * de nivel. Eso es lo correcto —congelar lo viejo para que lo nuevo entre limpio— pero tiene un
 * efecto que no se ve: **se traga lo que hubiera en ese momento**, sin que nadie mire qué era.
 *
 * Y así se coló un `cast.string` sobre un enum en `PmsAuditarReservaCommand`: no era una
 * anotación pendiente, era un **fatal en runtime**. El comando reventaba con cualquier reserva
 * que tuviera un cobro, y PHPStan lo sabía desde el primer día.
 *
 * ── Qué separa a estas familias del resto ───────────────────────────────────
 * De los 357 errores del baseline, el 86 % es tipado pendiente (`missingType.*`) o código
 * defensivo de más (`alwaysTrue`, `nullsafe.neverNull`). Nada de eso puede romperse solo.
 *
 * Las de aquí abajo sí: son las que dicen «esta llamada no va a funcionar». Congelar una es
 * congelar un fallo, así que **no pueden entrar al baseline aunque se regenere entero**. Si una
 * aparece, se arregla el código; no se apunta la deuda.
 *
 * `reportUnmatchedIgnoredErrors` no sirve para esto: avisa de entradas **rancias**, no impide
 * que entre una nueva. Por eso el candado es un test y no una opción de configuración.
 */
final class PhpstanBaselineTest extends TestCase
{
    /**
     * Nivel 1 — familias que tienen que estar a CERO, y el porqué de cada una.
     *
     * Hoy lo están. Añadir aquí es endurecer la red; quitar es abrir la puerta que dejó pasar el
     * `cast.string`.
     *
     * @var array<string, string>
     */
    private const array PROHIBIDOS = [
        // El caso real: `(string)` sobre un enum o un array → `Error` en cuanto se ejecuta.
        'cast.string' => 'un cast que PHP rechaza en runtime',
        // Instanciar, extender o tipar contra algo que no está.
        'class.notFound' => 'una clase que no existe',
        // Leer una propiedad que no existe.
        'property.notFound' => 'un acceso a una propiedad inexistente',
        'staticMethod.notFound' => 'una llamada estática a un método inexistente',
    ];

    /**
     * Nivel 2 — familias con entradas heredadas: no se pueden poner a cero hoy, pero **no crecen**.
     *
     * Las diez son del mismo patrón: la interfaz declarada es más estrecha que el objeto que
     * llega de verdad (`ObjectManager` cuando es un `EntityManagerInterface`, `SerializerInterface`
     * cuando es un `DenormalizerInterface`). Funcionan en ejecución; lo que está mal es la firma.
     * Arreglarlas es cambiar type hints en Exchange, Message y Pms — barato, pero no ahora.
     *
     * El número es el censo del día que se escribió esto. Si baja, se baja aquí y queda cerrado
     * un poco más; si sube, es que entró una nueva y ésa sí hay que mirarla.
     *
     * @var array<string, int>
     */
    private const array TOPE = [
        // 16/08/2026: 9 → 8 al tipar `AutoTranslationService`, donde el parámetro pedía un
        // `ObjectManager` genérico y el cuerpo llamaba a `getUnitOfWork()`, que sólo tiene el
        // del ORM. Los eventos de Doctrine ya entregaban el bueno; mentía la firma.
        // 26/09/2026: a CERO las dos, en la subida al nivel 10. Queda el tope para que no vuelvan.
        'method.notFound' => 0,
        'argument.type' => 0,
    ];

    /**
     * Lo ÚNICO que puede haber en el baseline desde la auditoría del 26/09/2026: guardas que
     * PHPStan da por redundantes y que protegen una frontera que no modela —una columna JSON, la
     * hidratación de Doctrine, lo que llama un framework, un candado leído en SQL crudo—. Ninguna
     * de estas familias dice «esto está mal»; dicen «esto sobra». Cualquier otra familia en el
     * baseline es un error congelado, y no se congela: se arregla.
     *
     * @var list<string>
     */
    private const array FAMILIAS_DE_FRONTERA = [
        'arrayValues.list',
        'booleanAnd.rightAlwaysTrue',
        'function.alreadyNarrowedType',
        'instanceof.alwaysTrue',
        'isset.offset',
        'notIdentical.alwaysFalse',
        'nullCoalesce.offset',
        'property.onlyWritten',
    ];

    /**
     * Ocurrencias el 26/09/2026, tras auditarlas una por una (de 107 a 51). Sólo puede bajar: si
     * baja, se baja aquí.
     */
    private const int TOPE_TOTAL = 51;

    public function testElBaselineSoloGuardaFronteras(): void
    {
        $censo = $this->censoDelBaseline();
        $ajenas = array_diff(array_keys($censo), self::FAMILIAS_DE_FRONTERA);

        self::assertSame([], array_values($ajenas), sprintf(
            "El baseline tiene familias que no son guardas de frontera: %s.\n\n"
            . "Eso es un aviso congelado en código propio. Se arregla; no se apunta.",
            implode(', ', $ajenas),
        ));

        self::assertLessThanOrEqual(self::TOPE_TOTAL, array_sum($censo), sprintf(
            "El baseline pasó de %d a %d ocurrencias. No se añaden entradas: nunca.",
            self::TOPE_TOTAL,
            array_sum($censo),
        ));
    }

    public function testElBaselineNoCongelaFallosDeEjecucion(): void
    {
        $censo = $this->censoDelBaseline();

        $presentes = array_intersect(array_keys(self::PROHIBIDOS), array_keys($censo));

        self::assertSame([], array_values($presentes), sprintf(
            "El baseline de PHPStan congela errores que fallan en ejecución:\n\n%s\n\n"
            . "Estos no se apuntan como deuda: se arreglan en el código y se borra su entrada del\n"
            . "baseline. Si aparecieron al regenerarlo, regenerar NO era la respuesta.",
            implode("\n", array_map(
                static fn (string $id): string => sprintf('  · %s — %s', $id, self::PROHIBIDOS[$id]),
                $presentes,
            )),
        ));
    }

    public function testLasFirmasRotasHeredadasNoAumentan(): void
    {
        $censo = $this->censoDelBaseline();

        foreach (self::TOPE as $identificador => $tope) {
            $actual = $censo[$identificador] ?? 0;

            self::assertLessThanOrEqual($tope, $actual, sprintf(
                "`%s` pasó de %d a %d entradas en el baseline.\n\n"
                . "Es la familia que dice «esta llamada no existe en el tipo declarado». Una nueva\n"
                . "no se congela: o se estrecha el type hint al que de verdad se usa, o la llamada\n"
                . "está mal. Corre `vendor/bin/phpstan analyse` sin el baseline para verla.",
                $identificador,
                $tope,
                $actual,
            ));
        }
    }

    /**
     * Cuántos errores tiene cada identificador en el baseline.
     *
     * Se suma `count:`, no las entradas: una sola entrada puede cubrir varias líneas del mismo
     * fichero, y lo que interesa es cuántos fallos hay congelados.
     *
     * @return array<string, int>
     */
    private function censoDelBaseline(): array
    {
        $ruta = \dirname(__DIR__, 2) . '/phpstan-baseline.neon';

        self::assertFileExists($ruta, 'El baseline debería existir; si se retiró, borra este test.');

        // Se lee la línea `identifier:` entera para no confundir `method.notFound` con
        // `staticMethod.notFound`, que comparten sufijo.
        preg_match_all(
            '/^\s*identifier:\s*(\S+)\s*\n\s*count:\s*(\d+)\s*$/m',
            (string) file_get_contents($ruta),
            $coincidencias,
            PREG_SET_ORDER,
        );

        self::assertNotEmpty($coincidencias, 'No se pudo leer el baseline: ¿cambió el formato de PHPStan?');

        $censo = [];

        foreach ($coincidencias as [, $identificador, $cuenta]) {
            $censo[$identificador] = ($censo[$identificador] ?? 0) + (int) $cuenta;
        }

        return $censo;
    }
}
