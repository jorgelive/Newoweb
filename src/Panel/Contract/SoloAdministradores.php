<?php

declare(strict_types=1);

namespace App\Panel\Contract;

/**
 * Un CRUD del panel que sólo abre `ROLE_ADMIN`: credenciales, colas, auditorías, usuarios.
 *
 * El menú ya escondía esas secciones a quien no es admin, pero el menú sólo esconde: cada CRUD
 * pedía el rol de su módulo, así que se abrían por URL. Susan veía las claves de Meta y Beds24, y
 * «Correo saliente» no pedía nada — Milka, de limpieza, podía leer las credenciales SMTP (revisión
 * del 03/10/2026). Lo hace cumplir `SoloAdministradoresListener` en cada acción.
 *
 * Una sección nueva de esas se cierra implementando esto, sin tocar sus permisos.
 */
interface SoloAdministradores
{
}
