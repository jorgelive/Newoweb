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