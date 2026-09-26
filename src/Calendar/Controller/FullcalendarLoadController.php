<?php
declare(strict_types=1);

namespace App\Calendar\Controller;

use App\Calendar\Provider\ProviderRegistry;
use App\Calendar\Service\CalendarConfigResolver;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/fullcalendar/load')]
final class FullcalendarLoadController extends AbstractController
{
    public function __construct(
        private readonly CalendarConfigResolver $configResolver,
        private readonly ProviderRegistry $providerRegistry,
    ) {}

    #[Route('/event/{calendar}', name: 'app_fullcalendar_load_event', methods: ['GET'])]
    public function events(Request $request, string $calendar): JsonResponse
    {
        // Se permiten fechas vacías o inválidas? Idealmente validar, pero mantenemos tu lógica actual.
        $startStr = (string) $request->query->get('start');
        $endStr   = (string) $request->query->get('end');

        try {
            $from = new DateTimeImmutable($startStr);
            $to   = new DateTimeImmutable($endStr);
        } catch (\Exception $e) {
            // Fallback por si FullCalendar no envía fechas válidas (raro pero posible)
            $from = new DateTimeImmutable('first day of this month');
            $to   = new DateTimeImmutable('last day of this month');
        }

        $config = $this->configResolver->getConfig($calendar);

        // 🔥 CORRECCIÓN CRÍTICA: PASO DE TESTIGO (TOKEN BASE64)
        // Recibimos el 'current_page' (que viene en btoa desde JS) y NO LO TOCAMOS.
        // Lo pasamos crudo a la configuración para que el provider lo use en los links.
        // `getString()` y no `get()`: lo que llega por query es texto igual, y el `empty()` de
        // siempre sigue descartando el vacío y el '0'.
        $encodedPage = $request->query->getString('current_page');
        if (!empty($encodedPage)) {
            $config = $config->conRetorno($encodedPage);
        }

        $provider = $this->providerRegistry->getProviderForConfig($config);

        return $this->json($provider->getEvents($from, $to, $config));
    }

    #[Route('/resource/{calendar}', name: 'app_fullcalendar_load_resource', methods: ['GET'])]
    public function resources(Request $request, string $calendar): JsonResponse
    {
        $startStr = (string) $request->query->get('start');
        $endStr   = (string) $request->query->get('end');

        try {
            $from = new DateTimeImmutable($startStr);
            $to   = new DateTimeImmutable($endStr);
        } catch (\Exception $e) {
            $from = new DateTimeImmutable('first day of this month');
            $to   = new DateTimeImmutable('last day of this month');
        }

        $config = $this->configResolver->getConfig($calendar);

        // Misma lógica para recursos, por si los necesitas con links
        $encodedPage = $request->query->getString('current_page');
        if (!empty($encodedPage)) {
            $config = $config->conRetorno($encodedPage);
        }

        $provider = $this->providerRegistry->getProviderForConfig($config);

        return $this->json($provider->getResources($from, $to, $config));
    }
}