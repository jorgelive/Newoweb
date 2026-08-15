<?php

declare(strict_types=1);

namespace App\Pms\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Maestro\MaestroIdioma;
use App\Pms\ApiPlatform\Dto\PmsReservaCrearInput;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsEventoEstadoPago;
use App\Pms\Entity\PmsReserva;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Processor de POST /pms/pms_reservas — "Reserva completa" desde el calendario SPA.
 *
 * A diferencia del bloqueo manual (PmsEventoCalendarioProcessor, que solo crea el
 * PmsEventoCalendario), aquí no existe todavía una PmsReserva padre: se crean ambas
 * entidades (titular + estancia inicial) en un único flush atómico.
 *
 * Reglas de negocio replicadas de PmsReservaCrudController::createEntity() (EasyAdmin):
 * establecimiento único del sistema, idioma por defecto si no se especifica. El canal
 * se fuerza SIEMPRE a "directo": este endpoint no puede crear reservas de canales OTA.
 */
/** @implements ProcessorInterface<PmsReservaCrearInput, PmsReserva> */
final class PmsReservaCrearProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PmsEventoCalendarioFactory $eventoFactory,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PmsReserva
    {
        if (!$data instanceof PmsReservaCrearInput) {
            throw new LogicException('PmsReservaCrearProcessor solo procesa instancias de PmsReservaCrearInput.');
        }

        if (!$data->pmsUnidad) {
            throw new UnprocessableEntityHttpException('La unidad es obligatoria.');
        }

        $inicio = $this->parseFecha($data->inicio, 'inicio');
        $fin = $this->parseFecha($data->fin, 'fin');

        if ($fin <= $inicio) {
            throw new UnprocessableEntityHttpException('La fecha de salida debe ser posterior a la fecha de llegada.');
        }

        $canalDirecto = $this->entityManager->getReference(PmsChannel::class, PmsChannel::CODIGO_DIRECTO);
        $establecimiento = $this->entityManager->getRepository(PmsEstablecimiento::class)->findOneBy([]);
        $idioma = $data->idioma ?? $this->entityManager->getReference(MaestroIdioma::class, MaestroIdioma::DEFAULT_IDIOMA);

        $reserva = new PmsReserva();
        $reserva->setEstablecimiento($establecimiento);
        $reserva->setChannel($canalDirecto);
        $reserva->setIdioma($idioma);
        $reserva->setPais($data->pais);
        $reserva->setNombreCliente($data->nombreCliente);
        $reserva->setApellidoCliente($data->apellidoCliente);
        $reserva->setTelefono($data->telefono);
        $reserva->setTelefono2($data->telefono2);
        $reserva->setTelefono2EsPrincipal($data->telefono2EsPrincipal);
        $reserva->setEmailCliente($data->emailCliente);
        $reserva->setNota($data->nota);

        $evento = new PmsEventoCalendario();
        $evento->setPmsUnidad($data->pmsUnidad);
        $evento->setInicio($inicio);
        $evento->setFin($fin);
        $evento->setCantidadAdultos($data->cantidadAdultos);
        $evento->setCantidadNinos($data->cantidadNinos);
        $evento->setDescripcion($data->descripcion);
        $evento->setMonto($data->monto);
        $evento->setComision($data->comision);
        $evento->setChannel($canalDirecto);
        $evento->setEstado($this->entityManager->getReference(PmsEventoEstado::class, PmsEventoEstado::CODIGO_PENDIENTE));
        $evento->setEstadoPago($this->entityManager->getReference(PmsEventoEstadoPago::class, PmsEventoEstadoPago::ID_SIN_PAGO));

        $reserva->addEventosCalendario($evento);
        $this->eventoFactory->hydrateLinksForUi($evento);

        $this->entityManager->persist($reserva);
        $this->entityManager->flush();

        return $reserva;
    }

    private function parseFecha(?string $value, string $campo): DateTimeImmutable
    {
        if (!$value) {
            throw new UnprocessableEntityHttpException(sprintf('La fecha de %s es obligatoria.', $campo));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            throw new UnprocessableEntityHttpException(sprintf('La fecha de %s no es válida.', $campo));
        }
    }
}
