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
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controlador AJAX para exponer metadatos de los Enums del PMS al frontend.
 *
 * Objetivo: que las etiquetas, colores e iconos de los enums vivan SOLO en PHP.
 * El frontend (util/) los consume desde aquí en vez de duplicar diccionarios en
 * TypeScript, que era la fuente habitual de desincronización.
 *
 * ⚠️ **EL PREFIJO `/tipo/user/` NO PROTEGE NADA.** Aquí decía que se agrupaba bajo `user`
 * «para heredar las reglas del firewall», y es falso: en `security.yaml` el acceso se decide
 * por HOST —panel, util y oweb piden `ROLE_USER`— y el host de la API cae en el
 * `PUBLIC_ACCESS` del final. Todo lo que se cuelgue de aquí queda ABIERTO A INTERNET salvo que
 * lleve su propio `#[IsGranted]`, exactamente como avisa
 * {@see \App\Finanzas\Controller\Api\FinEnlacePagoApiController}.
 *
 * Costó un dato real: `/limpiadores` salió a producción sin candado y devolvía los nombres del
 * personal de limpieza a cualquiera que pidiera la URL. Antes de añadir un endpoint aquí,
 * pregúntate qué pasa si lo abre un desconocido — y si la respuesta no es «nada», ponle rol.
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
    // Mismo candado que `/limpiadores`, y por el mismo motivo: son nombres de personas del
    // equipo. Hoy devolvía `[]` en producción sólo porque nadie tiene todavía `ROLE_COBRADOR`
    // ahí — el día que se marque a alguien, empezaba a publicarlo. Lo consume el panel
    // financiero del drawer, que ya exige este permiso para lo demás.
    #[Route('/cobradores', name: '_cobradores', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW, message: 'No tienes permiso para ver a los cobradores.')]
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
     * Quién puede figurar como LIMPIEZA de una estancia: los usuarios con `ROLE_LIMPIEZA`.
     *
     * Alimenta el selector del drawer de Reservas. La asignación no es sólo reparto de
     * trabajo: es el filtro de privacidad entre compañeras que aplica
     * {@see \App\Agent\Skill\Pms\ListarSalidasSkill}, así que quien no salga aquí tampoco
     * puede recibir una casita.
     *
     * ⚠️ **NO se filtra por `enabled`**, por el mismo motivo que en `getCobradores()` y con más
     * razón todavía: quien limpia **no tiene login** —está en `enabled = 0` por norma—, y ese
     * campo dice si se entra al panel, no si se trabaja aquí. Filtrarlo dejaba el desplegable
     * vacío justo para el equipo al que va dirigido; es el mismo error que ya se corrigió en
     * `PmsLimpiezaAsignacionListener`.
     *
     * 🪞 Misma fuente que usa `PmsEventoCalendarioProcessor::aplicarLimpieza()` para validar lo
     * que llega por la API. **Si cambia el criterio, cambian los dos**: si el processor
     * admitiera a alguien que aquí no sale, habría asignaciones que el operador no puede
     * deshacer desde el desplegable.
     */
    #[Route('/limpiadores', name: '_limpiadores', methods: ['GET'])]
    #[IsGranted(Roles::RESERVAS_SHOW, message: 'No tienes permiso para ver el equipo de limpieza.')]
    public function getLimpiadores(UserRepository $usuarios): JsonResponse
    {
        $personal = $usuarios->findByRole(Roles::LIMPIEZA);

        usort(
            $personal,
            static fn (User $a, User $b): int => strcasecmp($a->getFullname(), $b->getFullname())
        );

        $data = [];

        foreach ($personal as $usuario) {
            $data[] = [
                'id' => (string) $usuario->getId(),
                'label' => $usuario->getFullname() ?: (string) $usuario->getUserIdentifier(),
            ];
        }

        // Sin caché, igual que los cobradores: dar de alta a alguien tiene que verse en el acto.
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
