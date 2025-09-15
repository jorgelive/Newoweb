<?php
namespace App\EventListener;

use App\Entity\ReservaChannel;
use App\Entity\ReservaReserva;
use App\Service\MainVariableproceso;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Symfony\Component\HttpFoundation\RequestStack;

class ReservaReservaDoctrineEventListener
{

    private const BOOKING_HOTEL_ACCOUNT_ID = 16435683;
    private const CODIGOBOOKING_SAPHY = 11658819;
    private const CODIGOBOOKING_INTI  = 9610078;
    private $mainVariableproceso;
    private $requestStack;

    public function __construct(MainVariableproceso $mainVariableproceso, RequestStack $requestStack)
    {
        $this->mainVariableproceso = $mainVariableproceso;
        $this->requestStack = $requestStack;
    }

    public function prePersist(PrePersistEventArgs $args)
    {
        $entity = $args->getObject();
        if (!$entity instanceof ReservaReserva) {
            return;
        }

        // Token aleatorio
        $entity->setToken(bin2hex(random_bytes(8)));

        // UID si no existe
        if (empty($entity->getUid())) {
            $entity->setUid(sprintf('%06d', $entity->getUnit()->getId()) . '-' . sprintf('%06d', $entity->getChannel()->getId()) . '-' . sprintf('%012d', mt_rand()) . '@openperu.pe');
        }

        if (!empty($entity->getNombre())) {
            $entity->setNombre($this->normalizeNombre($entity->getNombre()));
        }

        // Normalizar teléfono
        if (!empty($entity->getTelefono())) {
            $entity->setTelefono(str_replace(["\xC2\xA0", "\xE2\x80\x91"], [' ', '-'], $entity->getTelefono()));
        }

        // Marcar manual si canal directo
        if ($entity->getChannel()->getId() == ReservaChannel::DB_VALOR_DIRECTO) {
            $entity->setManual(true);
        }

        // Procesar enlace
        if (!empty($entity->getEnlace())) {
            $entity->setEnlace($this->processEnlace($entity->getEnlace(), $entity));
        }
    }

    public function preUpdate(PreUpdateEventArgs $args)
    {
        $entity = $args->getObject();
        if (!$entity instanceof ReservaReserva) {
            return;
        }

        if (!empty($entity->getNombre())) {
            $entity->setNombre($this->normalizeNombre($entity->getNombre()));
        }

        // Normalizar teléfono
        if (!empty($entity->getTelefono())) {
            $entity->setTelefono(str_replace(["\xC2\xA0", "\xE2\x80\x91"], [' ', '-'], $entity->getTelefono()));
        }

        // Marcar manual si canal directo
        if ($entity->getChannel()->getId() == ReservaChannel::DB_VALOR_DIRECTO) {
            $entity->setManual(true);
        }

        // Procesar enlace
        if (!empty($entity->getEnlace())) {
            $entity->setEnlace($this->processEnlace($entity->getEnlace(), $entity));
        }
    }

    private function addFlash(string $type, string $message)
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $request->getSession()->getFlashBag()->add($type, $message);
        }
    }

    private function processEnlace(string $enlace, ReservaReserva $reserva): string
    {
        $channelId = $reserva->getChannel()->getId();

        // Booking.com
        if ($channelId === ReservaChannel::DB_VALOR_BOOKING) {
            $unitnexo = $reserva->getUnitnexo();

            if (is_numeric($enlace)) {
                $hotelId = self::CODIGOBOOKING_INTI;
                if ($unitnexo && $unitnexo->getDistintivo() === 'S') {
                    $hotelId = self::CODIGOBOOKING_SAPHY;
                }

                return "https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/booking.html"
                    . "?hotel_id={$hotelId}"
                    . "&res_id={$enlace}"
                    . "&hotel_account_id=" . self::BOOKING_HOTEL_ACCOUNT_ID;
            }

            if (filter_var($enlace, FILTER_VALIDATE_URL)) {
                $parsedUrl = parse_url($enlace);
                $queryArray = [];
                if (isset($parsedUrl['query'])) {
                    parse_str($parsedUrl['query'], $queryArray);
                }
                $queryArray['hotel_account_id'] = self::BOOKING_HOTEL_ACCOUNT_ID;
                $parsedUrl['query'] = http_build_query($queryArray);

                return $this->mainVariableproceso->buildUrl($parsedUrl);
            }

            // Warning
            $this->addFlash('warning', 'El campo enlace de Booking.com no es numérico ni URL válida. Se dejará en blanco.');
            return '';
        }

        // Otros canales
        $parsedUrl = parse_url($enlace);
        if (!isset($parsedUrl['query'])) {
            if (!is_numeric($enlace) && !filter_var($enlace, FILTER_VALIDATE_URL)) {
                $this->addFlash('warning', 'El campo enlace no es numérico ni URL válida. Se dejará en blanco.');
                return '';
            }
            return $enlace;
        }

        parse_str($parsedUrl['query'], $queryArray);
        unset($queryArray['ses'], $queryArray['lang']);
        $parsedUrl['query'] = http_build_query($queryArray);

        return $this->mainVariableproceso->buildUrl($parsedUrl);
    }

    private function normalizeNombre(string $nombre): string
    {
        // Convertir a minúsculas y quitar espacios extras
        $nombre = strtolower(trim($nombre));
        $nombre = preg_replace('/\s+/', ' ', $nombre);

        // Convertir a CamelCase (Primera letra de cada palabra en mayúscula)
        $nombre = ucwords($nombre);

        return $nombre;
    }

}