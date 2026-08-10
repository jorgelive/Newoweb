<?php

declare(strict_types=1);

namespace App\Finanzas\Enum;

/**
 * Clase de instrumento por el que le pedimos el dinero al cliente.
 *
 * ### Por qué no es {@see \App\Pms\Enum\PmsMedioPago}, aunque el vocabulario se parezca
 *
 * Son dos preguntas distintas sobre el mismo dinero:
 *
 * - `PmsMedioPago` responde **«¿por dónde entró este pago?»**. Es la etiqueta de un cobro que
 *   ya ocurrió, y por eso tiene `comisionPorcentaje()` y `seCobraEnMano()`.
 * - Éste responde **«¿por dónde puede pagarnos?»**. Es un anuncio, no un registro: sólo existe
 *   si hay una cuenta o un número detrás que darle al cliente.
 *
 * La prueba de que no son el mismo enum: la tarjeta de crédito es un `PmsMedioPago` de pleno
 * derecho y aquí **no está**, porque no se anuncia con un número —se anuncia con un enlace de
 * pasarela que emite {@see \App\Agent\Skill\Pms\GenerarEnlacePrepagoSkill}—. Y al revés, Yape y
 * Plin viven fusionados en `PLIN_YAPE` para contabilizar, pero al cliente se le nombran por
 * separado porque son dos apps.
 *
 * Además vive en `Finanzas` y no en `Pms` a propósito: el catálogo lo comparten el PMS y las
 * cotizaciones, y `src/Finanzas/` no importa de `App\Pms` en ningún sitio. Ver
 * docs/FinanzasEnlacesPago.md.
 */
enum FinMedioCobroTipo: string
{
    case YAPE                   = 'yape';
    case PLIN                   = 'plin';
    case TRANSFERENCIA_BANCARIA = 'transferencia_bancaria';
    case EFECTIVO               = 'efectivo';
    case WESTERN_UNION          = 'western_union';
    case PAYPAL                 = 'paypal';

    public function label(): string
    {
        return match ($this) {
            self::YAPE                   => 'Yape',
            self::PLIN                   => 'Plin',
            self::TRANSFERENCIA_BANCARIA => 'Transferencia bancaria',
            self::EFECTIVO               => 'Efectivo',
            self::WESTERN_UNION          => 'Western Union',
            self::PAYPAL                 => 'PayPal',
        };
    }

    /**
     * ¿Hace falta un número (de cuenta o de teléfono) para poder ofrecerlo?
     *
     * El efectivo no lo lleva: se paga en mano y no hay destino que teclear. Todo lo demás sí,
     * y sin él el medio no se ofrece — un titular y un banco sin número son media ficha, que es
     * justo la materia prima con la que el agente se inventó el resto en la reserva V4JE5Q.
     */
    public function exigeNumero(): bool
    {
        return $this !== self::EFECTIVO;
    }

    /** Icono FontAwesome para el panel. */
    public function icono(): string
    {
        return match ($this) {
            self::YAPE, self::PLIN       => 'fa-mobile-screen-button',
            self::TRANSFERENCIA_BANCARIA => 'fa-building-columns',
            self::EFECTIVO               => 'fa-money-bill-wave',
            self::WESTERN_UNION          => 'fa-money-bill-transfer',
            self::PAYPAL                 => 'fa-brands fa-paypal',
        };
    }

    /**
     * Para los `choices` de EasyAdmin.
     *
     * ⚠️ Devuelve **el caso del enum, no su `->value`**. La entidad guarda objetos
     * (`enumType` en el mapping), así que un array de strings hace que `ChoiceType` intente
     * convertir el valor actual a texto para casarlo con las opciones y reviente con
     * «Object of class … could not be converted to string» **al abrir el formulario de
     * edición** — el listado y el alta funcionan igual, que es lo que despista.
     *
     * Mismo criterio que {@see \App\Pms\Enum\PmsPoliticaPrepago::opciones()}.
     *
     * @return array<string, self>
     */
    public static function opciones(): array
    {
        $opciones = [];

        foreach (self::cases() as $caso) {
            $opciones[$caso->label()] = $caso;
        }

        return $opciones;
    }
}
