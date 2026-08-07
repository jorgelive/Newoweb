<?php

declare(strict_types=1);

namespace App\Api\Controller\Tipo;

use App\Entity\User;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Enum\PmsTipoCargo;
use App\Repository\UserRepository;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controlador AJAX para exponer metadatos de los Enums del PMS al frontend.
 * Espejo de TravelEnumAjaxController: se agrupa bajo el prefijo 'user' para
 * heredar las reglas del firewall.
 *
 * Objetivo: que las etiquetas, colores e iconos de los enums vivan SOLO en PHP.
 * El frontend (util/) los consume desde aquí en vez de duplicar diccionarios en
 * TypeScript, que era la fuente habitual de desincronización.
 */
#[Route('/tipo/user/enum/pms', name: 'tipo_user_enum_pms')]
class PmsEnumAjaxController extends AbstractController
{
    /**
     * Tipos de cargo financiero (alojamiento, limpieza, servicio, otro).
     * Consumido por el panel financiero de la reserva en la SPA.
     */
    #[Route('/tipos-cargo', name: '_tipos_cargo', methods: ['GET'])]
    public function getTiposCargo(): JsonResponse
    {
        $data = [];

        foreach (PmsTipoCargo::cases() as $case) {
            $data[] = [
                'id' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
            ];
        }

        return $this->cacheable($data);
    }

    /**
     * Medios de pago admitidos, con el % de comisión por defecto de cada uno
     * (5.5% en tarjeta de crédito, 0 en el resto).
     */
    #[Route('/medios-pago', name: '_medios_pago', methods: ['GET'])]
    public function getMediosPago(): JsonResponse
    {
        $data = [];

        foreach (PmsMedioPago::cases() as $case) {
            $data[] = [
                'id' => $case->value,
                'label' => $case->label(),
                'icono' => $case->icono(),
                'comisionPorcentaje' => $case->comisionPorcentaje(),
            ];
        }

        return $this->cacheable($data);
    }

    /**
     * Quién puede figurar como COBRADOR de un pago: los usuarios con `ROLE_COBRADOR`.
     *
     * No es un enum —son filas de `user`—, pero vive aquí porque el panel financiero lo
     * consume igual que los otros dos selectores y no merece un controlador propio.
     *
     * ⚠️ **NO se filtra por `enabled`**, y es deliberado: ese campo dice si la persona entra
     * al sistema, no si maneja caja. La limpiadora que cobra el efectivo en la casita no
     * necesita login —está en `enabled = 0`— y tiene que salir igual en el desplegable;
     * habilitarla sólo para poder nombrarla aquí le daría acceso al panel. Ver
     * {@see Roles::COBRADOR}.
     *
     * ⚠️ Tampoco se cachea como los enums: el alta de una persona tiene que verse en el acto,
     * y una hora de caché haría que el operador no encontrase a quien acaban de dar de alta.
     *
     * 🪞 Mismo criterio que `RegistrarPagoSkill::cobradoresPosibles()`, que es por donde los
     * registra el agente. **Si cambia uno, cambia el otro**: si el agente admitiera a alguien
     * que aquí no sale, registraría pagos a nombre de quien el operador no puede elegir a mano.
     */
    #[Route('/cobradores', name: '_cobradores', methods: ['GET'])]
    public function getCobradores(UserRepository $usuarios): JsonResponse
    {
        $cobradores = $usuarios->findByRole(Roles::COBRADOR);

        // El orden lo pone PHP: `findByRole()` no ordena y el desplegable se lee mejor
        // alfabético que por orden de inserción.
        usort(
            $cobradores,
            static fn (User $a, User $b): int => strcasecmp($a->getFullname(), $b->getFullname())
        );

        $data = [];

        foreach ($cobradores as $usuario) {
            $data[] = [
                'id' => (string) $usuario->getId(),
                // Sin nombre y apellido cargados, el desplegable mostraría filas vacías.
                'label' => $usuario->getFullname() ?: (string) $usuario->getUserIdentifier(),
            ];
        }

        return new JsonResponse($data);
    }

    /**
     * Cachea 1 hora en el navegador: la estructura de un Enum rara vez cambia
     * (mismo criterio que TravelEnumAjaxController).
     */
    private function cacheable(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->setSharedMaxAge(3600);

        return $response;
    }
}
