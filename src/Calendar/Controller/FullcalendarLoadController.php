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
        // FullCalendar manda start/end (ISO). Convertimos directo a DateTimeImmutable.
        $from = new DateTimeImmutable((string) $request->query->get('start'));
        $to   = new DateTimeImmutable((string) $request->query->get('end'));

        $config = $this->configResolver->getConfig($calendar);
        $provider = $this->providerRegistry->getProviderForConfig($config);

        return $this->json($provider->getEvents($from, $to, $config));
    }

    #[Route('/resource/{calendar}', name: 'app_fullcalendar_load_resource', methods: ['GET'])]
    public function resources(Request $request, string $calendar): JsonResponse
    {
        $from = new DateTimeImmutable((string) $request->query->get('start'));
        $to   = new DateTimeImmutable((string) $request->query->get('end'));

        $config = $this->configResolver->getConfig($calendar);
        $provider = $this->providerRegistry->getProviderForConfig($config);

        return $this->json($provider->getResources($from, $to, $config));
    }
}