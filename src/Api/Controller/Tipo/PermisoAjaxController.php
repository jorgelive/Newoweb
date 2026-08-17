<?php

declare(strict_types=1);

namespace App\Api\Controller\Tipo;

use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Qué puede hacer el usuario que está mirando la pantalla.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * Hasta ahora el SPA no sabía nada de roles: el backend rechazaba lo que no tocaba y el
 * usuario se enteraba **al pulsar**, con un error. Para un botón de alta eso es peor que
 * inútil — parece que la aplicación está rota, no que no tienes permiso.
 *
 * Con esto el botón se puede pintar deshabilitado y explicando por qué, que es el
 * comportamiento que corresponde a **todas** las altas, no sólo a la de proveedores.
 *
 * ── Esto NO es el candado ───────────────────────────────────────────────────
 * ⚠️ Es sólo para pintar. Quien decide de verdad sigue siendo el `#[IsGranted]` de cada
 * endpoint: cualquiera puede mentirle a su propio navegador. Si algún día una comprobación
 * vive **sólo** aquí, deja de ser una comprobación.
 *
 * ── Se resuelve con la jerarquía aplicada ───────────────────────────────────
 * Va por `isGranted()` y no leyendo `user.roles`, que es la columna literal. Jorge tiene
 * `ROLE_MAESTROS_DELETE` y **no** tiene escrito `ROLE_MAESTROS_WRITE`; lo hereda por
 * `role_hierarchy`. Mirar la columna diría que no puede crear proveedores, y sí puede.
 *
 * (El caso contrario también existe y es deliberado: `findByRole()` sí mira la columna
 * literal, porque `ROLE_COBRADOR` es una marca de reparto de trabajo, no un permiso.
 * Ver el docblock de `Roles::COBRADOR`.)
 */
#[Route('/tipo/user/enum/permisos', name: 'tipo_user_enum_permisos')]
class PermisoAjaxController extends AbstractController
{
    /**
     * Los roles CRUD, que son los que gobiernan lo que se puede pintar. Los operativos
     * (`LIMPIEZA`, `CONDUCTOR`…) no entran: no abren pantallas, marcan reparto de trabajo.
     */
    private const ROLES_UI = [
        Roles::MAESTROS_SHOW,
        Roles::MAESTROS_WRITE,
        Roles::MAESTROS_DELETE,
        Roles::RESERVAS_SHOW,
        Roles::RESERVAS_WRITE,
        Roles::RESERVAS_DELETE,
        Roles::OPERACIONES_SHOW,
        Roles::OPERACIONES_WRITE,
        Roles::OPERACIONES_DELETE,
        Roles::MENSAJES_SHOW,
        Roles::MENSAJES_WRITE,
        Roles::MENSAJES_DELETE,
    ];

    #[Route('', name: '', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(): JsonResponse
    {
        $data = [];

        foreach (self::ROLES_UI as $rol) {
            $data[$rol] = $this->isGranted($rol);
        }

        return new JsonResponse($data);
    }
}
