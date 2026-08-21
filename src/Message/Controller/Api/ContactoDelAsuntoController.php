<?php

declare(strict_types=1);

namespace App\Message\Controller\Api;

use App\Message\Service\Conversacion\ContactoDelAsunto;
use App\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A qué teléfono y correo se le escribe a un asunto, y de dónde sale cada uno.
 *
 * `GET /platform/message/contacto?contextType=cotizacion_file&contextId=…`
 *
 * ```json
 * {"telefono":"51967007752","telefonoOrigen":"identidad",
 *  "correo":null,"correoOrigen":null,
 *  "conversacionId":"019d…"}
 * ```
 *
 * ── Por qué un endpoint y no un campo del recurso ───────────────────────────
 * Porque el dato **no es del asunto**. Ponerlo como propiedad de `CotizacionFile` o de
 * `Organizacion` sugeriría que se guarda ahí y que editarlo ahí sirve de algo, que es justo el
 * malentendido del que venimos: el teléfono del expediente es la SEMILLA con la que se creó la
 * identidad, y a partir de ese momento el dato bueno vive en la persona.
 *
 * Además vale para los tres dominios sin que ninguno lo declare, que es lo que evita tener tres
 * campos calculados diciendo lo mismo con tres nombres.
 *
 * ⚠️ `origen` es lo que decide qué pinta el panel: `identidad` se enseña como dato firme,
 * `semilla` con el aviso de «sin verificar», y `null` es que no hay ninguno.
 */
#[AsController]
final class ContactoDelAsuntoController extends AbstractController
{
    public function __construct(private readonly ContactoDelAsunto $contacto)
    {
    }

    #[Route('/platform/message/contacto', name: 'message_contacto_asunto', methods: ['GET'])]
    #[IsGranted(Roles::MENSAJES_SHOW, message: 'Acceso denegado a los datos de contacto.')]
    public function __invoke(Request $request): JsonResponse
    {
        $tipo = trim((string) $request->query->get('contextType', ''));
        $id = trim((string) $request->query->get('contextId', ''));

        if ($tipo === '' || $id === '') {
            return $this->json(['error' => 'Falta el asunto: `contextType` y `contextId`.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->contacto->para($tipo, $id));
    }
}
