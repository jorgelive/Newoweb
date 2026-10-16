<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Finanzas\Enum\FinAudienciaCobro;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Message\TelefonoDeContacto;

/**
 * ¿Este huésped nos paga desde Perú?
 *
 * Sirve para elegir qué medios de cobro se le ofrecen ({@see \App\Finanzas\Enum\FinAudienciaCobro}),
 * y por eso no es una curiosidad estadística: acertar o no le cuesta dinero al huésped.
 *
 * ### Por qué es un servicio y no un método privado
 *
 * Lo preguntan dos sitios que no se conocen —el chat del agente
 * ({@see \App\Agent\Skill\Pms\ConsultarMediosPagoSkill}) y la guía del huésped
 * ({@see \App\Api\Provider\Pms\PmsGuiaHuespedProvider})— y **tienen que contestar lo mismo**.
 * Si la pantalla le enseña una cuenta del BCP y el asistente le dice que no tenemos cuentas
 * para él, el huésped no sabe a cuál de los dos creer.
 *
 * Vive en `Pms\Finanzas` y no en `src/Finanzas/` porque necesita saber qué es una
 * `PmsReserva`, y `src/Finanzas/` no importa de `App\Pms` en ningún archivo.
 */
final readonly class PmsProcedenciaHuesped
{
    public function __construct(private TelefonoDeContacto $telefonos)
    {
    }

    /**
     * `true` desde Perú, `false` desde fuera, `null` cuando no hay con qué saberlo.
     *
     * La regla —el país manda; sin país, el teléfono, con la evidencia asimétrica del prefijo
     * 51— vive en {@see FinAudienciaCobro::desdePeru()}, que comparte con las cotizaciones. Aquí
     * sólo se decide QUÉ país y QUÉ teléfono son los de la reserva: se mira el teléfono de
     * CONTACTO ({@see PmsReserva::getTelefonoContacto()}) y no `telefono` a secas, porque cuál de
     * los dos números es el bueno ya se decidió en un sitio y aquí sólo se consume.
     */
    public function pagaDesdePeru(PmsReserva $reserva): ?bool
    {
        return FinAudienciaCobro::desdePeru($reserva->getPais()?->getId(), $this->telefonos->para($reserva));
    }
}
