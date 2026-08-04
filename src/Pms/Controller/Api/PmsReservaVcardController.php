<?php

declare(strict_types=1);

namespace App\Pms\Controller\Api;

use App\Pms\Entity\PmsReserva;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use JeroenDesloovere\VCard\VCard;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Genera el archivo .vcf de contacto de una reserva (para la agenda del celular).
 * Réplica de PmsReservaCrudController::generarVcard() (EasyAdmin), como descarga
 * directa consumible desde el calendario SPA — un <a href> normal basta, no hace
 * falta AJAX/blob: el navegador maneja el Content-Disposition: attachment.
 */
#[Route('/pms/reservas')]
final class PmsReservaVcardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%pax_book_guide_url%')]
        private readonly string $paxBookGuideUrl,
    ) {}

    #[Route('/{id}/vcard', name: 'app_pms_reserva_vcard', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $this->denyAccessUnlessGranted(Roles::RESERVAS_SHOW);

        $reserva = $this->entityManager->getRepository(PmsReserva::class)->find($id);
        if (!$reserva instanceof PmsReserva) {
            throw new NotFoundHttpException('Reserva no encontrada.');
        }

        $vcard = new VCard();

        $localizador = $reserva->getLocalizador() ?? 'Sin-Loc';
        $nombre = $reserva->getNombreApellido() ?? 'Huésped Desconocido';
        $cantidad = $reserva->getPaxTotal();

        $inicio = $reserva->getFechaLlegada();
        $fin = $reserva->getFechaSalida();

        $inicioText = $inicio ? $inicio->format('Y/m/d') : '-';
        $finText = $fin ? $fin->format('Y/m/d') : '-';
        $diaFin = $fin ? $fin->format('d') : '-';

        $fechaReservaText = $reserva->getCreatedAt() ? $reserva->getCreatedAt()->format('Y/m/d') : '-';

        $unidad = $reserva->getNombreHabitacion();
        $canalNombre = $reserva->getChannel() ? (string) $reserva->getChannel()->getId() : 'Directo';
        $inicialCanal = substr(strtoupper($canalNombre), 0, 1);

        // LÓGICA DE FORMATEO (Número sanitizado en BD sin '+')
        // El número de contacto elegido por el operador, no siempre el primero.
        $telefonoRaw = trim((string) $reserva->getTelefonoContacto());
        $telefonoVcard = '';

        if ($telefonoRaw !== '') {
            $telefonoConPlus = str_starts_with($telefonoRaw, '+') ? $telefonoRaw : '+' . $telefonoRaw;

            try {
                $phoneUtil = PhoneNumberUtil::getInstance();
                $numberProto = $phoneUtil->parse($telefonoConPlus, null);

                $telefonoVcard = $phoneUtil->isValidNumber($numberProto)
                    ? $phoneUtil->format($numberProto, PhoneNumberFormat::E164)
                    : $telefonoConPlus;
            } catch (NumberParseException) {
                $telefonoVcard = $telefonoConPlus;
            }
        }

        // Formato visual para la agenda: 2024/05/10/12 B x2 (101) Juan Perez
        $campoNombre = sprintf('%s/%s %s x%s (%s) %s', $inicioText, $diaFin, $inicialCanal, $cantidad, $unidad, $nombre);

        $nota = <<<TXT
Localizador: $localizador
Nombre: $nombre
Alojamiento: $unidad
Ingreso: $inicioText
Salida: $finText
Canal: $canalNombre
Fecha de reserva: $fechaReservaText
TXT;

        $vcard->addName('', $campoNombre);

        if ($telefonoVcard !== '') {
            $vcard->addPhoneNumber($telefonoVcard, 'CELL');
        }

        $vcard->addNote($nota);

        if ($reserva->getLocalizador()) {
            $vcard->addURL(rtrim($this->paxBookGuideUrl, '/') . '/' . $reserva->getLocalizador());
        }

        return new Response(
            $vcard->getOutput(),
            200,
            [
                'Content-Type' => 'text/vcard',
                'Content-Disposition' => 'attachment; filename="' . $localizador . '_contacto.vcf"',
            ]
        );
    }
}
