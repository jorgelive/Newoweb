<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Fuente única de verdad para los Roles del Sistema.
 */
final class Roles
{
    // --- SISTEMA ---
    public const SUPER_ADMIN    = 'ROLE_SUPER_ADMIN';
    public const ADMIN          = 'ROLE_ADMIN';

    // --- OPERACIONES ---
    public const OPERACIONES_SHOW   = 'ROLE_OPERACIONES_SHOW';
    public const OPERACIONES_WRITE  = 'ROLE_OPERACIONES_WRITE';
    public const OPERACIONES_DELETE = 'ROLE_OPERACIONES_DELETE';

    // --- RESERVAS ---
    public const RESERVAS_SHOW      = 'ROLE_RESERVAS_SHOW';
    public const RESERVAS_WRITE     = 'ROLE_RESERVAS_WRITE';
    public const RESERVAS_DELETE    = 'ROLE_RESERVAS_DELETE';

    // --- MENSAJERÍA ---
    public const MENSAJES_SHOW      = 'ROLE_MENSAJES_SHOW';
    public const MENSAJES_WRITE     = 'ROLE_MENSAJES_WRITE';
    public const MENSAJES_DELETE    = 'ROLE_MENSAJES_DELETE';

    // --- MAESTROS ---
    public const MAESTROS_SHOW      = 'ROLE_MAESTROS_SHOW';
    public const MAESTROS_WRITE     = 'ROLE_MAESTROS_WRITE';
    public const MAESTROS_DELETE    = 'ROLE_MAESTROS_DELETE';

    // --- CAMPO ---
    public const LIMPIEZA           = 'ROLE_LIMPIEZA';
    public const MANTENIMIENTO      = 'ROLE_MANTENIMIENTO';
    public const CONDUCTOR          = 'ROLE_CONDUCTOR';
    public const TRASLADISTA        = 'ROLE_TRASLADISTA';
    public const GUIA               = 'ROLE_GUIA';

    /**
     * Puede RECIBIR dinero del huésped: es quien se puede poner en
     * `PmsPagoFinanciero::$cobrador`.
     *
     * Es una RESPONSABILIDAD, no un puesto ni un nivel de acceso, y por eso tiene rol propio
     * en vez de deducirse de otra cosa:
     *
     * - **No es el puesto.** Puede haber personal de limpieza que no maneje caja.
     * - **No es `enabled`.** Ese campo dice si la persona entra al sistema, que es
     *   independiente: la limpiadora que cobra el efectivo en la casita no necesita login, y
     *   habilitarla sólo para poder nombrarla en un desplegable le daría acceso al panel.
     * - **No cuelga de ROLE_ADMIN.** Ser administrador no implica manejar el efectivo de la
     *   empresa; que lo heredara metería a cualquier admin en la lista de cobradores.
     *
     * ⚠️ Quien lo consume filtra por la columna `user.roles` LITERAL
     * (`UserRepository::findByRole()`), no por la jerarquía de `security.yaml`: un rol que
     * sólo se tenga por herencia NO aparecerá en la lista de cobradores.
     */
    public const COBRADOR           = 'ROLE_COBRADOR';


    /**
     * Recibe los avisos cuando el agente promete que responderá una persona.
     *
     * Es una **guardia**, no un permiso: dice a quién se le escribe al móvil cuando un huésped
     * se queda esperando, no qué puede hacer en el panel. Por eso no se deriva de
     * `ROLE_MENSAJES_*` —los tiene mucha gente que no está de turno— ni de `enabled`.
     *
     * Sin este rol no llega nada: el aviso se manda a los usuarios que lo tengan **y** tengan
     * móvil registrado ({@see \App\Entity\User::$telefono}). Que la lista esté vacía es un
     * fallo de configuración y la skill lo dice en su respuesta en vez de callárselo.
     */
    public const CUSTOMER_SUPPORT   = 'ROLE_CUSTOMER_SUPPORT';

    /**
     * Rol SINTÉTICO del huésped. Ningún `User` lo tiene ni debe tenerlo: se lo asigna
     * `AgentActor::huesped()` a quien escribe por el chat sin ser del equipo.
     *
     * Existe para que el huésped sea un actor más del agente —con sus propias herramientas,
     * acotadas a SU reserva— en vez de un caso especial sin permisos. No se ofrece en
     * `getChoices()` a propósito: no es asignable desde el panel.
     */
    public const HUESPED            = 'ROLE_HUESPED';

    /**
     * Rol SINTÉTICO de quien pregunta sin ser todavía nadie: un número desconocido que escribe
     * para preguntar precios. Ningún `User` lo tiene, igual que {@see self::HUESPED}.
     *
     * Lo que lo separa del huésped **no es tener menos permisos, es no tener CONTEXTO**. Las
     * skills del huésped están acotadas a SU reserva y casi todas fallan sin ella («Esta
     * conversación no está asociada a ninguna reserva»); un prospecto no tiene ninguna que
     * acotar, y ese vacío es justo lo que le cierra `consultar_cuenta` o `consultar_mi_reserva`
     * sin necesidad de una lista negra que alguien tenga que mantener.
     *
     * A cambio se le abre lo que un desconocido SÍ puede saber: qué hay libre, a qué precio, a
     * qué cambio, y la parte de la guía marcada como pública. Nada de eso menciona a nadie.
     *
     * Tampoco se ofrece en `getChoices()`: no es asignable desde el panel.
     */
    public const PROSPECTO          = 'ROLE_PROSPECTO';

    /**
     * Devuelve los roles filtrados por grupo funcional.
     */
    public static function getChoices(?string $group = null): array
    {
        // Definición de subgrupos para organización interna
        $sistema = [
            '👑 Super Admin'           => self::SUPER_ADMIN,
            '🔧 Admin Sistema'         => self::ADMIN,
        ];

        $oficina = [
            // Operaciones
            '📋 Operaciones: Ver'       => self::OPERACIONES_SHOW,
            '📋 Operaciones: Gestionar' => self::OPERACIONES_WRITE,
            '📋 Operaciones: Borrar'    => self::OPERACIONES_DELETE,
            // Reservas
            '📅 Reservas: Ver'          => self::RESERVAS_SHOW,
            '📅 Reservas: Gestionar'    => self::RESERVAS_WRITE,
            '📅 Reservas: Borrar'       => self::RESERVAS_DELETE,
            // Mensajería
            '💬 Mensajería: Ver'        => self::MENSAJES_SHOW,
            '💬 Mensajería: Escribir'   => self::MENSAJES_WRITE,
            '💬 Mensajería: Borrar'     => self::MENSAJES_DELETE,
            // Maestros
            '🛠️ Maestros: Ver'          => self::MAESTROS_SHOW,
            '🛠️ Maestros: Gestionar'    => self::MAESTROS_WRITE,
            '🛠️ Maestros: Borrar'       => self::MAESTROS_DELETE,
        ];

        $campo = [
            '🧹 Personal Limpieza'      => self::LIMPIEZA,
            '🛠️ Personal Mantenimiento' => self::MANTENIMIENTO,
            '🚗 Conductor / Chófer'     => self::CONDUCTOR,
            '🤝 Trasladista / Host'     => self::TRASLADISTA,
            '🚩 Guía Turístico'        => self::GUIA,
            // Se marca aparte del puesto: se le da a quien maneja caja, limpie o no.
            '💵 Puede cobrar al huésped' => self::COBRADOR,
            // Guardia de atención: recibe en su móvil los avisos del asistente.
            '🆘 Atención al cliente (recibe avisos)' => self::CUSTOMER_SUPPORT,
        ];

        $group = $group ? strtoupper($group) : null;

        return match ($group) {
            'SISTEMA' => $sistema,
            'OFICINA' => $oficina,
            'CAMPO'   => $campo,
            default   => array_merge($sistema, $oficina, $campo),
        };
    }
}