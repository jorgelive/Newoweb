<?php

declare(strict_types=1);

namespace App\Api\Controller\Tipo;

use App\Operacion\Enum\OperacionMedioPago;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Los enums de Operación, para que sus etiquetas vivan SÓLO en PHP.
 *
 * Mismo motivo que {@see PmsEnumAjaxController}: un diccionario duplicado en TypeScript se
 * desincroniza en cuanto alguien añade un caso, y lo hace en silencio — el selector se queda
 * corto y nadie echa de menos la opción que no sabía que existía.
 *
 * ⚠️ **EL PREFIJO `/tipo/user/` NO PROTEGE NADA.** El acceso se decide por HOST en
 * `security.yaml` y el de la API cae en `PUBLIC_ACCESS`, así que todo lo que cuelgue de aquí
 * queda abierto salvo que lleve su propio `#[IsGranted]` — ver el aviso completo, y el dato
 * real que costó, en `PmsEnumAjaxController`.
 *
 * Una lista de medios de pago no filtra nada de nadie, pero lleva rol igual: el coste es una
 * línea y la alternativa es que el siguiente endpoint que se cuelgue aquí herede la costumbre
 * de no ponerlo.
 */
#[Route('/tipo/user/enum/operacion', name: 'tipo_user_enum_operacion')]
#[IsGranted(Roles::OPERACIONES_SHOW)]
class OperacionEnumAjaxController extends AbstractController
{
    /** Por qué medios se le puede pagar a un proveedor. */
    #[Route('/medios-pago', name: '_medios_pago', methods: ['GET'])]
    public function getMediosPago(): JsonResponse
    {
        $data = [];

        foreach (OperacionMedioPago::cases() as $case) {
            $data[] = [
                'id' => $case->value,
                'label' => $case->label(),
                'icono' => $case->icono(),
            ];
        }

        $response = new JsonResponse($data);
        // Una hora: el diccionario cambia con un despliegue, no con el uso.
        $response->setSharedMaxAge(3600);

        return $response;
    }
}
