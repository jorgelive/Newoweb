<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Publico;

use App\Cotizacion\Entity\Cotizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Service\ItinerarioCompuesto;
use App\Dominio\Excepcion\DominioNoDisponible;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

/**
 * El itinerario en PDF, generado en el SERVIDOR. Piloto del cálculo compartido.
 *
 * ── Por qué existe, si la guía ya se imprime desde el navegador ─────────────
 * La hoja de impresión de `/pax` cubre al cliente que quiere un papel. Esto cubre lo que aquélla
 * no puede: **adjuntarlo a un correo o a un mensaje**. Nadie pulsa «Imprimir» en el navegador del
 * pasajero cuando quien manda el itinerario es el operador — o el agente.
 *
 * ── Es el piloto, y es de SÓLO LECTURA a propósito ──────────────────────────
 * Ejercita la tubería entera —invocación, contrato versionado, política de fallo, rastro— sin que
 * un error cueste dinero. La misma arquitectura estrenada con el cálculo financiero se estrenaría
 * sobre márgenes.
 *
 * Y arranca con su regresión conocida: la versión anterior, que replicaba las reglas en PHP, daba
 * **11 días donde hay 16** — perdía las estadías. Ésta no puede: no replica nada.
 *
 * ⚠️ **503 y NINGÚN documento si el cálculo no responde.** Un PDF con la mitad del viaje es peor
 * que no mandarlo: parece completo. Es la política escrita en
 * `docs/PlanProcesamientoCompartido.md` §5, y aquí se cumple sin inventar un valor por defecto.
 */
#[AsController]
final class CotizacionItinerarioPdfController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ItinerarioCompuesto $itinerario,
    ) {
    }

    #[Route(
        '/client/cotizacion/{localizador}/{propuesta}/itinerario.pdf',
        name: 'cotizacion_itinerario_pdf',
        requirements: ['propuesta' => '\d+'],
        methods: ['GET'],
    )]
    public function __invoke(string $localizador, int $propuesta): Response
    {
        $cotizacion = $this->cotizacion($localizador, $propuesta);

        try {
            $dias = $this->itinerario->dias($cotizacion);
        } catch (DominioNoDisponible $e) {
            throw $this->createNotFoundException('El itinerario no está disponible ahora mismo.', $e);
        }

        // Mismo dompdf que la orden al proveedor: sin red y con DejaVu. Lo primero porque dejarlo
        // abierto convierte una plantilla en un cliente HTTP; lo segundo porque sin esa fuente las
        // tildes y las eñes salen como cajas, y eso no se ve hasta que alguien imprime.
        $opciones = new Options();
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($this->renderView('cotizacion/itinerario_pdf.html.twig', [
            'cotizacion' => $cotizacion,
            'dias' => $dias,
            'titulo' => $this->tituloDe($cotizacion),
            'localizador' => $localizador,
            'propuesta' => $propuesta,
        ]));
        $dompdf->setPaper('a4');
        $dompdf->render();

        return new Response((string) $dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            // `inline`: en el móvil abre el visor en vez de bajar un archivo que luego hay que
            // buscar. Quien lo quiera guardar tiene el botón del propio visor.
            'Content-Disposition' => sprintf('inline; filename="%s-p%d.pdf"', $localizador, $propuesta),
        ]);
    }

    private function cotizacion(string $localizador, int $propuesta): Cotizacion
    {
        $file = $this->em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $localizador]);

        $cotizacion = $file === null ? null : $this->em->getRepository(Cotizacion::class)
            // ⚠️ La columna sigue llamándose `version`: es el nombre heredado. El concepto es
            // PROPUESTA — no se sustituyen entre sí y pueden convivir varias aprobadas.
            ->findOneBy(['file' => $file, 'version' => $propuesta]);

        // 404 uniforme: no existe, no es pública o expiró. Distinguirlos le diría a quien prueba
        // localizadores cuáles existen.
        if ($cotizacion === null || !$cotizacion->esVisibleParaCliente()) {
            throw $this->createNotFoundException('Esa propuesta ya no está disponible.');
        }

        return $cotizacion;
    }

    private function tituloDe(Cotizacion $cotizacion): string
    {
        foreach ($cotizacion->getTitulo() as $fila) {
            $contenido = trim((string) ($fila['content'] ?? ''));

            if ($contenido !== '') {
                return $contenido;
            }
        }

        return 'Itinerario';
    }
}
