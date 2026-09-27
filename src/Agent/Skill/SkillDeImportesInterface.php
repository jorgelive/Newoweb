<?php

declare(strict_types=1);

namespace App\Agent\Skill;

/**
 * Una herramienta cuyo único objeto es el DINERO: cargos, saldos, cómo pagar, cambio de moneda.
 *
 * No añade métodos: es una marca. {@see SkillRegistry::paraActor()} no ofrece estas herramientas
 * a quien tiene la restricción de canal que oculta importes
 * ({@see \App\Agent\Access\RestriccionCanal::ocultaImportes()}): en una consulta de OTA el
 * precio lo pone la plataforma, y una herramienta que no está en la lista no hay prompt que la
 * invoque.
 *
 * Las que mezclan dinero con otra cosa —disponibilidad, los datos de la reserva— NO la llevan:
 * ésas se ofrecen igual y quitan los importes en la respuesta.
 */
interface SkillDeImportesInterface
{
}
