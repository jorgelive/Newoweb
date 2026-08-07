<?php

declare(strict_types=1);

namespace App\Agent\Skill;

use App\Agent\Conversation\PotenciaRequerida;

/**
 * Cómo se le presenta una skill al modelo, en términos neutrales.
 *
 * 🔥 La descripción ES prompt, no documentación: di **cuándo** usarla, no sólo qué hace, y
 * prohíbe explícitamente responder de memoria si el dato tiene que salir de aquí. Eso es lo
 * que sube la tasa de invocación.
 */
final readonly class SkillDefinition
{
    /**
     * @param list<SkillParameter> $parametros
     * @param PotenciaRequerida $siguientePaso Cuánta cabeza hace falta DESPUÉS de que el triaje
     *        haya elegido esta skill: pedir los datos que falten y redactar con lo que devuelva.
     *        No es la potencia para elegirla —eso lo decide el triaje, que ve todo el catálogo—;
     *        es la del trabajo que queda cuando la decisión difícil ya está tomada.
     *
     *        `Media` por defecto, que es exactamente lo que hace hoy el agente entero: así
     *        ninguna de las skills existentes cambia de comportamiento hasta que alguien decida
     *        bajarla a mano.
     *
     *        Regla para elegirlo: **¿queda alguna decisión de negocio por tomar?** Si sólo falta
     *        preguntar «¿de qué casita?» y formatear, es `Baja`. Si hay que encadenar consultas,
     *        interpretar lo que devolvió una herramienta o decidir si se escala, es `Media`. Y
     *        `Alta` si la respuesta compromete algo caro de deshacer.
     */
    public function __construct(
        public string $descripcion,
        public array $parametros = [],
        public PotenciaRequerida $siguientePaso = PotenciaRequerida::Media,
    ) {}

    /** @return list<SkillParameter> */
    public function requeridos(): array
    {
        return array_values(array_filter($this->parametros, static fn (SkillParameter $p) => $p->requerido));
    }
}
