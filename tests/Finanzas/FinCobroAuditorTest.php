<?php

declare(strict_types=1);

namespace App\Tests\Finanzas;

use App\Finanzas\Service\FinCobroAuditor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lo que se guarda de la respuesta de una pasarela, y lo que NUNCA se guarda.
 *
 * Esta traza existe para poder terminar una implementación que no se puede probar —el reto 3DS
 * sólo lo dispara el banco de una tarjeta extranjera—, así que su valor entero está en que los
 * campos salgan bien la **primera** vez: no hay un segundo intento para el mismo cliente.
 *
 * Los cuerpos de aquí abajo no son inventados: salen de cargos reales de la cuenta de
 * producción y del demo oficial de Culqi.
 */
final class FinCobroAuditorTest extends TestCase
{
    #[Test]
    public function un_cargo_autorizado_deja_su_veredicto_y_su_id(): void
    {
        $senales = FinCobroAuditor::senalesDe([
            'object' => 'charge',
            'id' => 'chr_live_makAUGGGZujka2rF',
            'outcome' => [
                'type' => 'venta_exitosa',
                'code' => 'AUT0000',
                'merchant_message' => 'La operación de venta ha sido autorizada exitosamente',
            ],
        ]);

        self::assertSame('charge', $senales['objeto']);
        self::assertSame('venta_exitosa', $senales['outcomeType']);
        self::assertSame('AUT0000', $senales['outcomeCode']);
        self::assertSame('chr_live_makAUGGGZujka2rF', $senales['cargoId']);
    }

    /**
     * El cargo real que destapó el agujero: `object: charge` **y** denegado.
     *
     * Mientras `cargoPagaElEnlace()` decidía por `object`, éste saldaba el enlace. Aquí lo que
     * importa es que el `outcome.type` quede escrito tal cual, que es lo que permite mirar la
     * tabla y ver que no se cobró.
     */
    #[Test]
    public function un_denegado_tambien_es_un_charge_y_asi_queda_registrado(): void
    {
        $senales = FinCobroAuditor::senalesDe([
            'object' => 'charge',
            'id' => 'chr_live_amECtx8jft1ti9zY',
            'outcome' => [
                'type' => 'operacion_denegada',
                'code' => 'DNGE0116',
                'decline_code' => 'authentication_required',
                'merchant_message' => 'Denegación sospecha de fraude, se solicita autenticación 3DS',
            ],
        ]);

        self::assertSame('charge', $senales['objeto']);
        self::assertSame('operacion_denegada', $senales['outcomeType']);
        self::assertSame('DNGE0116', $senales['outcomeCode']);
        self::assertStringContainsString('3DS', (string) $senales['motivo']);
    }

    /** Cuando Culqi pide el reto no manda `outcome`: manda `action_code`, y 200. */
    #[Test]
    public function la_peticion_de_reto_se_reconoce_por_action_code(): void
    {
        $senales = FinCobroAuditor::senalesDe(['object' => 'authentication', 'action_code' => 'REVIEW']);

        self::assertSame('REVIEW', $senales['actionCode']);
        self::assertNull($senales['outcomeType']);
        self::assertNull($senales['cargoId']);
    }

    /** En un error, los mismos datos viven un nivel más arriba. Se leen las dos formas. */
    #[Test]
    public function en_un_error_el_codigo_no_cuelga_de_outcome(): void
    {
        $senales = FinCobroAuditor::senalesDe([
            'object' => 'error',
            'code' => 'DNGA0330',
            'merchant_message' => 'Tarjeta no permitida',
        ]);

        self::assertSame('error', $senales['objeto']);
        self::assertSame('DNGA0330', $senales['outcomeCode']);
        self::assertSame('Tarjeta no permitida', $senales['motivo']);
    }

    /** Un cuerpo vacío no revienta ni inventa: todo nulo. */
    #[Test]
    public function un_cuerpo_vacio_no_inventa_nada(): void
    {
        self::assertSame(
            ['objeto' => null, 'actionCode' => null, 'outcomeType' => null,
             'outcomeCode' => null, 'cargoId' => null, 'motivo' => null],
            FinCobroAuditor::senalesDe([])
        );
    }

    /**
     * 🔥 Lo que no puede quedar guardado.
     *
     * `source` trae la tarjeta enmascarada, el correo del titular y la huella del dispositivo;
     * `antifraud_details`, su nombre y su teléfono. La tabla se consulta meses después para
     * entender qué contestó la pasarela, y para eso no hace falta saber quién pagó.
     */
    #[Test]
    public function la_tarjeta_y_la_persona_no_se_guardan(): void
    {
        $limpio = FinCobroAuditor::sinDatosDelTitular([
            'object' => 'charge',
            'amount' => 17568,
            'source' => ['cardNumber' => '447409******5298', 'email' => 'alguien@ejemplo.com'],
            'antifraud_details' => ['first_name' => 'Nombre', 'phone' => '999999999'],
            'client' => ['ip' => '200.215.229.218'],
            'outcome' => ['type' => 'venta_exitosa'],
        ]);

        self::assertArrayNotHasKey('source', $limpio);
        self::assertArrayNotHasKey('antifraud_details', $limpio);
        self::assertArrayNotHasKey('client', $limpio);
        self::assertSame(17568, $limpio['amount']);
        self::assertSame(['type' => 'venta_exitosa'], $limpio['outcome']);
    }
}
