<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use App\Agent\Access\ActorInterface;
use App\Contract\VinculoComercial;
use App\Security\Roles;

/**
 * Con quién está hablando el agente, y por tanto QUIÉN ES mientras dure la conversación.
 *
 * Hasta ahora había un solo prompt, escrito para un huésped alojado: «Hablas con un huésped por
 * el chat de su reserva». Servía cuando esa era la única puerta. Hoy no lo es —por el mismo
 * WhatsApp entran un operador desde su móvil, la señora que va a limpiar y alguien que sólo
 * pregunta precios— y contestarles a los cuatro con la misma voz falla por los dos lados: al
 * operador se le trata de usted y se le esconden cifras que puede ver, y al que va a limpiar se
 * le podría soltar el saldo de un huésped por no haber dicho que no.
 *
 * ── El perfil NO es el permiso ──────────────────────────────────────────────
 * Lo que cada uno PUEDE hacer lo decide `SkillRegistry::paraActor()` con los roles, y eso no se
 * duplica aquí: si a un colaborador no le toca `consultar_cuenta`, la herramienta no aparece en
 * su lista y no hay prompt que la invoque. Esto es la otra mitad —el TONO y el CRITERIO— que
 * ningún filtro de roles puede dar: a quién se tutea, si se vende o se asiste, y qué se calla
 * aunque técnicamente se tenga a mano.
 *
 * Las dos capas se necesitan. Los permisos evitan el acceso; el perfil evita el desliz.
 *
 * ── La frontera entre personal y colaborador ────────────────────────────────
 * Es la que ya existía en `Roles::getChoices()`: **OFICINA** (quien gestiona la reserva) frente
 * a **CAMPO** (quien pisa la casita). No se inventa una división nueva ni se enumera aquí una
 * lista de roles que se desincronizaría con aquélla: se pregunta a la misma función.
 */
enum PerfilConversacion: string
{
    /** Un número desconocido preguntando precios. Todavía no es cliente de nada. */
    case Prospecto = 'prospecto';

    /**
     * Tiene un contexto a su nombre pero sin confirmar: la consulta de OTA que está decidiendo.
     *
     * No es `Prospecto` —sabemos quién es, qué unidad mira y qué fechas— ni es `Huesped`, que
     * es lo que se le decía antes y por lo que se le hablaba de «su reserva» y se le daba la
     * dirección exacta antes de que el partner lo permitiera.
     */
    case Interesado = 'interesado';

    /** Alguien con una reserva viva, escribiendo por el chat de su estancia. */
    case Huesped = 'huesped';

    /** Del equipo, grupo OFICINA: gestiona reservas, cobros y mensajes. */
    case Personal = 'personal';

    /** Del equipo, grupo CAMPO: limpieza, mantenimiento, conductor, trasladista, guía. */
    case Colaborador = 'colaborador';

    /**
     * Quién es quien escribe.
     *
     * El orden de las preguntas importa: se mira primero si es del equipo, porque alguien del
     * equipo puede además ser huésped en su propia reserva (`tambienHuesped: true` en
     * {@see \App\Agent\Access\AgentActorFactory::delEquipoPorChat()}) y ahí manda su condición
     * de compañero — es quien puede ver más y a quien menos falta le hace la cortesía.
     */
    public static function deActor(ActorInterface $actor): self
    {
        if ($actor->esDelEquipo()) {
            return $actor->tieneAlguno(self::rolesDeOficina()) ? self::Personal : self::Colaborador;
        }

        if ($actor->esProspecto()) {
            return self::Prospecto;
        }

        // Lo que decide es el VÍNCULO y no el tipo de contexto: una consulta de OTA cuelga de
        // una reserva exactamente igual que una estancia confirmada, y tratarlas igual fue el
        // fallo que costó una fuga de dirección.
        //
        // `Ninguno` se mapea explícitamente aunque el `if` de arriba suela adelantarse: quien
        // construya un actor con rol de huésped y sin vínculo —una conversación sin contexto
        // real— debe caer en `Prospecto`, no en `Interesado`. Dejarlo al `default` era tratar
        // como «tiene algo a su nombre» a quien no tiene nada.
        //
        // `Terminado` (cancelado) sí cae en `Interesado`: ya no hay estancia que consultar,
        // pero sigue siendo alguien identificado a quien se le puede volver a vender.
        return match ($actor->vinculo()) {
            VinculoComercial::Ninguno => self::Prospecto,
            VinculoComercial::Cliente => self::Huesped,
            VinculoComercial::Interesado, VinculoComercial::Terminado => self::Interesado,
        };
    }

    /**
     * Los roles que hacen a alguien «de oficina», sacados del maestro de roles y no copiados.
     *
     * Incluye SISTEMA: un admin gestiona reservas por definición, y dejarlo fuera lo habría
     * mandado al perfil de campo — que es el más restringido de los cuatro.
     *
     * @return list<string>
     */
    private static function rolesDeOficina(): array
    {
        return array_values(array_merge(
            Roles::getChoices('OFICINA'),
            Roles::getChoices('SISTEMA'),
        ));
    }

    /** Cómo se le nombra en los registros. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Prospecto => 'prospecto',
            self::Interesado => 'interesado',
            self::Huesped => 'huésped',
            self::Personal => 'personal de oficina',
            self::Colaborador => 'colaborador de campo',
        };
    }

    /**
     * Quién escribe, en una línea, para el bloque VOLÁTIL del contexto.
     *
     * Existe porque el triaje resuelve él mismo las charlas —«hola», «gracias»— y su prompt es
     * idéntico byte a byte en todas las conversaciones a propósito: es el bloque cacheado, y
     * ahí no puede entrar nada de quien escribe. Consecuencia observada en producción: a un
     * compañero del equipo que escribió «Prueba» se le contestó «¿en qué te puedo ayudar con
     * tu reserva o tu estancia?», que es la voz del huésped.
     *
     * Esta línea viaja en el contexto volátil, que va FUERA del bloque cacheado: corrige el
     * tono sin tocar el ahorro.
     *
     * Vacío para el huésped: es el caso por defecto que ya asume el prompt, y añadir una línea
     * para decir lo obvio sólo gasta contexto.
     */
    public function enUnaLinea(): string
    {
        return match ($this) {
            self::Huesped => '',
            self::Prospecto => 'OJO: quien escribe NO es huésped todavía —pregunta precios y no '
                . 'ha reservado nada—. No le hables de «tu reserva» ni de «tu estancia».',
            self::Interesado => 'OJO: quien escribe TIENE una solicitud a su nombre pero SIN '
                . 'CONFIRMAR: está decidiendo si reserva. No le hables de «tu reserva» ni de '
                . '«tu estancia» como si ya fuera suya, y no le des nada que dependa de estar '
                . 'confirmada.',
            self::Personal => 'OJO: quien escribe es un COMPAÑERO del equipo, no un huésped. '
                . 'Tutéalo, ve al grano y no le hables de «tu reserva» ni de «tu estancia».',
            self::Colaborador => 'OJO: quien escribe es del equipo de CAMPO (limpieza, '
                . 'mantenimiento, traslados), no un huésped. Tutéalo, sé muy breve y no le '
                . 'hables de «tu reserva» ni le des datos de ningún huésped.',
        };
    }

}
