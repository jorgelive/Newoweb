<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Documento;

use App\Agent\Vision\LectorDeImagenInterface;
use App\Cotizacion\Documento\LectorDeDocumentoIdentidad;
use PHPUnit\Framework\TestCase;

/**
 * Qué nombre se queda cuando lo impreso y la MRZ no dicen lo mismo.
 *
 * ── 🔥 El caso que lo motivó, con el dato tal como está guardado ────────────
 * Un pasaporte fotografiado con un reflejo sobre el campo NOMBRES. La MRZ salió entera y coherente;
 * lo impreso salió a medias. La regla vieja era «lo impreso manda salvo que venga VACÍO», y un
 * reflejo no deja lo impreso vacío: lo deja **a medias**.
 *
 * ```
 * guardado    : «CARLOS»
 * y el control: «el trámite trae ENRIQUE y el escaneo de su pasaporte (MRZ) no»
 * ```
 *
 * ⚠️ Ese aviso no era sólo falso: empujaba a **borrar ENRIQUE del manifiesto**, donde estaba bien.
 *
 * ⚠️ Y esto se prueba sobre `interpretar()`, que no toca la red: la lectura cruda se guarda entera
 * en `datosLeidos`, así que el crudo de abajo es literalmente lo que hay en producción.
 */
final class NombreImpresoVsMrzTest extends TestCase
{
    private function lector(): LectorDeDocumentoIdentidad
    {
        return new LectorDeDocumentoIdentidad(new class implements LectorDeImagenInterface {
            public function estaConfigurado(): bool { return false; }

            public function nombre(): string { return 'de mentira'; }

            /** @param array<string, mixed> $e @return array<string, mixed> */
            public function leer(string $b, string $m, string $i, array $e): array
            {
                throw new \LogicException('interpretar() no debe llamar al modelo');
            }
        });
    }

    /** @param array<string, mixed> $encima @return array<string, mixed> */
    private function crudo(array $encima): array
    {
        return [...[
            'sexo' => 'M', 'tipo' => 'PASAPORTE', 'numero' => '125854290',
            'nacimiento' => '1976-01-27', 'paisEmisor' => 'PER', 'vencimiento' => '2036-06-17',
            'nacionalidad' => 'PER', 'bordeSuperior' => 'arriba',
            'mrzLinea1' => '', 'mrzLinea2' => '', 'mrzLinea3' => '',
        ], ...$encima];
    }

    public function testUnReflejoSobreLoImpresoNoPuedePerderUnNombreQueLaMrzTrae(): void
    {
        $d = $this->lector()->interpretar($this->crudo([
            'nombres' => 'CARLOS', 'apellidos' => 'SAMANEZ CUBA',
            'mrzLinea1' => 'P<PERSAMANEZ<CUBA<<CARLOS<ENRIQUE<<<<<<<<<<<',
            'mrzLinea2' => '1258542904PER7601277M3606171<<<<<<<<<<<<<<<<',
        ]));

        self::assertTrue($d->verificadoPorMrz(), 'la MRZ de este pasaporte real sí cuadra');
        self::assertSame('CARLOS ENRIQUE', $d->nombres);
        self::assertSame('SAMANEZ CUBA', $d->apellidos);
    }

    /**
     * ⚠️ El otro lado de la regla: la MRZ **recorta a 39 caracteres**, así que un nombre largo sale
     * cortado ahí y entero en lo impreso. Preferir la MRZ siempre habría cambiado un fallo por otro.
     */
    public function testUnNombreLargoRecortadoEnLaMrzNoPisaLoImpreso(): void
    {
        $d = $this->lector()->interpretar($this->crudo([
            'nombres' => 'JUAN CARLOS ALBERTO', 'apellidos' => 'GUTIERREZ DE LA FUENTE',
            'mrzLinea1' => 'P<PERGUTIERREZ<DE<LA<FUENTE<<JUAN<CARLOS<ALB',
            'mrzLinea2' => '1258542904PER7601277M3606171<<<<<<<<<<<<<<<<',
        ]));

        self::assertSame('JUAN CARLOS ALBERTO', $d->nombres);
    }

    /**
     * ⚠️ Y la eñe y las tildes: la MRZ es ASCII por especificación (ICAO 9303), así que ahí lo
     * impreso es estrictamente mejor. Mismas palabras = se queda lo impreso.
     */
    public function testConLasMismasPalabrasSeQuedaLoImpresoQueEsElQueLlevaTildes(): void
    {
        $d = $this->lector()->interpretar($this->crudo([
            'nombres' => 'JOSÉ MARÍA', 'apellidos' => 'NÚÑEZ PÉREZ',
            'mrzLinea1' => 'P<PERNUNEZ<PEREZ<<JOSE<MARIA<<<<<<<<<<<<<<<<',
            'mrzLinea2' => '1258542904PER7601277M3606171<<<<<<<<<<<<<<<<',
        ]));

        self::assertSame('JOSÉ MARÍA', $d->nombres);
        self::assertSame('NÚÑEZ PÉREZ', $d->apellidos);
    }

    /**
     * ⚠️ Si las dos lecturas se CONTRADICEN no hay una «más completa»: hay un problema. Se queda lo
     * impreso —que es el comportamiento de siempre— y que lo denuncie el cotejo, en vez de cambiar
     * de nombre calladamente.
     */
    public function testDosLecturasQueSeContradicenNoCambianElNombre(): void
    {
        $d = $this->lector()->interpretar($this->crudo([
            'nombres' => 'CARLOS', 'apellidos' => 'SAMANEZ CUBA',
            'mrzLinea1' => 'P<PERSAMANEZ<CUBA<<ROBERTO<ENRIQUE<<<<<<<<<<',
            'mrzLinea2' => '1258542904PER7601277M3606171<<<<<<<<<<<<<<<<',
        ]));

        self::assertSame('CARLOS', $d->nombres);
    }
}
