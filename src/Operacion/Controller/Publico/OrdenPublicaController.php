<?php

declare(strict_types=1);

namespace App\Operacion\Controller\Publico;

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Enum\EstadoOrdenServicioEnum;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Operacion\Service\OperacionOrdenDocumento;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La orden que ve el PROVEEDOR, sin sesión.
 *
 * ```
 * GET /orden/{token}       → la página
 * GET /orden/{token}.pdf   → el mismo documento, descargable
 * ```
 *
 * ── ⚠️ Esto es PÚBLICO. Lo que sale aquí lo puede leer cualquiera con el enlace ──
 * Por eso el documento **no lleva importes**, igual que el mensaje: ni lo negociado ni el total.
 * La llave son 32 caracteres de azar —ni el número de OS ni el UUID sirven, que son
 * adivinables— pero un enlace se reenvía, y hay que asumir que acaba fuera.
 *
 * ⚠️ **Sí sale QUIÉN viaja, desde el 07/09/2026, y antes no.** La regla escrita aquí era «al
 * proveedor se le dice qué operar, no para quién», y en la práctica dejaba al conductor con horas
 * y pax pero sin el nombre del grupo ni un teléfono al que llamar. Lo pidió el operador con el
 * caso delante: en una orden de dos expedientes no hay forma de saber qué línea es de quién.
 *
 * Lo que sigue sin salir es **el dinero**: ni importes, ni lo que se vendió, ni nada de la
 * cotización. Ésa es la parte de la regla que no se toca.
 *
 * ── Se genera al vuelo ──────────────────────────────────────────────────────
 * No hay fichero guardado: el PDF se compone en el momento a partir de los ítems congelados. Sin
 * copias que limpiar ni que se queden viejas.
 *
 * ⚠️ La contrapartida, y hay que saberla: si después se aplican «cambios menores», el proveedor
 * que reabra el enlace verá la versión NUEVA, no la que recibió. Es lo aceptado a cambio de no
 * mantener un almacén de documentos.
 */
#[AsController]
final class OrdenPublicaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OperacionOrdenDocumento $documento,
        #[Autowire(param: 'operaciones_telefono_emergencia')]
        private readonly string $telefonoEmergencia = '',
    ) {
    }

    #[Route('/orden/{token}', name: 'operacion_orden_publica', methods: ['GET'], requirements: ['token' => '[0-9a-f]{32}'])]
    public function pagina(string $token): Response
    {
        $orden = $this->orden($token);

        return $this->render('operacion/orden_publica.html.twig', [
            'orden' => $orden,
            // El teléfono, resuelto ahora: el snapshot lo congeló y ya no es el bueno.
            'grupos' => $this->documento->gruposVivos($orden),
            // Mismo criterio que el mensaje al proveedor: el recojo, una vez al día.
            'rutas' => $orden->getRutasVisibles(),
            'telefonoEmergencia' => trim($this->telefonoEmergencia),
            'paraPdf' => false,
        ]);
    }

    #[Route('/orden/{token}.pdf', name: 'operacion_orden_publica_pdf', methods: ['GET'], requirements: ['token' => '[0-9a-f]{32}'])]
    public function pdf(string $token): Response
    {
        $orden = $this->orden($token);

        $opciones = new Options();
        // Sin acceso remoto: el HTML lo componemos nosotros y no necesita traerse nada de fuera.
        // Dejarlo abierto convertiría una plantilla en un cliente HTTP.
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('defaultFont', 'DejaVu Sans');   // tildes y ñ sin cajas

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($this->renderView('operacion/orden_publica.html.twig', [
            'rutas' => $orden->getRutasVisibles(),
            'orden' => $orden,
            'grupos' => $this->documento->gruposVivos($orden),
            'telefonoEmergencia' => trim($this->telefonoEmergencia),
            'paraPdf' => true,
        ]));
        $dompdf->setPaper('a4');
        $dompdf->render();

        return new Response(
            (string) $dompdf->output(),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf('attachment; filename="%s.pdf"', $orden->getNumeroOs()),
            ]
        );
    }

    /**
     * La orden de esa llave, o 404.
     *
     * ⚠️ Una orden **anulada** también responde 404: el proveedor no debe quedarse con la
     * versión que se retiró abierta en una pestaña. Si hubo sucesora, se le manda su enlace.
     */
    private function orden(string $token): OperacionOrdenServicio
    {
        $orden = $this->em->getRepository(OperacionOrdenServicio::class)
            ->findOneBy(['tokenPublico' => $token]);

        if ($orden === null || $orden->getEstadoOs() === EstadoOrdenServicioEnum::CANCELADA) {
            throw $this->createNotFoundException('Esa orden de servicio ya no está disponible.');
        }

        return $orden;
    }
}
