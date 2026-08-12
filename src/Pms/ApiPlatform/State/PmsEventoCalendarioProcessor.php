<?php

declare(strict_types=1);

namespace App\Pms\ApiPlatform\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Factory\PmsEventoCalendarioFactory;
use App\Repository\UserRepository;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Processor de escritura para PmsEventoCalendario (POST/PATCH desde la API — app util/Vue).
 *
 * La integridad OTA (fechas/unidad/estado inmutables, auto-confirmación por pago,
 * recálculo de totales de la reserva) ya está blindada a nivel de Doctrine por
 * PmsEventoCalendarioSecurityListener, PmsEventoCalendarioIntegrityListener y
 * PmsReservaRecalculoListener — corre para cualquier entrypoint, no solo EasyAdmin.
 *
 * Lo único que SÍ es responsabilidad exclusiva de este processor es reconstruir
 * los links espejo de Beds24 (PmsEventoCalendarioFactory::hydrateLinksForUi), ya
 * que esa lógica no vive en ningún listener y solo la invocaban los controllers
 * de EasyAdmin.
 *
 * Y, desde el drawer de limpieza, resolver los ids de quien limpia: ver
 * aplicarLimpieza(). Va aquí porque la entidad no tiene con qué buscar usuarios.
 */
final class PmsEventoCalendarioProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PmsEventoCalendarioFactory $eventoFactory,
        private readonly UserRepository $usuarios,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PmsEventoCalendario
    {
        if (!$data instanceof PmsEventoCalendario) {
            throw new LogicException('PmsEventoCalendarioProcessor solo procesa instancias de PmsEventoCalendario.');
        }

        // Antes de tocar nada más: en el POST tiene que estar puesta ANTES del persist, porque
        // PmsLimpiezaAsignacionListener::prePersist sólo pone el defecto si la colección viene
        // vacía. Al revés, el defecto pisaría a quien eligió el operador.
        $this->aplicarLimpieza($data);

        if ($operation instanceof Post) {
            // Evento nuevo (bloqueo manual creado desde el calendario SPA):
            // este recurso solo puede crear eventos DIRECTOS. Se ignora cualquier
            // `channel` que venga en el payload y se fuerza "directo" — el frontend
            // no debe (ni puede) crear eventos OTA desde aquí bajo ninguna circunstancia.
            $data->setChannel($this->entityManager->getReference(PmsChannel::class, PmsChannel::CODIGO_DIRECTO));

            // Construye los links espejo de Beds24 para la unidad asignada.
            $this->eventoFactory->hydrateLinksForUi($data);
            $this->entityManager->persist($data);
        } else {
            $uow = $this->entityManager->getUnitOfWork();
            $meta = $this->entityManager->getClassMetadata(PmsEventoCalendario::class);

            $uow->computeChangeSet($meta, $data);
            $changes = $uow->getEntityChangeSet($data);

            // Si se movió de unidad física, reconstruye los links espejo de la nueva unidad.
            // (Un evento OTA con pmsUnidad modificado nunca llega vivo hasta aquí:
            // PmsEventoCalendarioSecurityListener lo rechaza antes del flush.)
            if (array_key_exists('pmsUnidad', $changes)) {
                $this->eventoFactory->hydrateLinksForUi($data);
            }
        }

        $this->entityManager->flush();

        return $data;
    }

    /**
     * Sincroniza quién limpia con los ids que trajo el payload (`limpiezaIds`).
     *
     * ── Ausente ≠ vacío ─────────────────────────────────────────────────────
     * `null` es «el payload no lo menciona» y no se toca nada. Sin esa distinción, cada PATCH
     * del drawer —tocar el importe, cambiar el estado— habría dejado la estancia sin asignar,
     * y el síntoma (nadie de campo la ve) no aparece hasta el día de la salida.
     * `[]` sí es una orden explícita: quitar a todo el mundo.
     *
     * ── Sólo el equipo de limpieza, y falla en voz alta ─────────────────────
     * Los candidatos salen de `ROLE_LIMPIEZA`, la MISMA fuente que llena el desplegable
     * (`PmsEnumAjaxController::getLimpiadores()`). Un id de fuera se rechaza con un 400 en vez
     * de ignorarse: asignar a quien no limpia parece que funciona —la etiqueta se pinta— pero
     * `listar_entradas_salidas` sólo filtra por asignación a quien tiene ese rol, así que esa
     * estancia no le llegaría a esa persona nunca.
     */
    private function aplicarLimpieza(PmsEventoCalendario $evento): void
    {
        $ids = $evento->limpiezaIdsPendientes();

        if (null === $ids) {
            return;
        }

        $candidatos = [];

        foreach ($this->usuarios->findByRole(Roles::LIMPIEZA) as $usuario) {
            $candidatos[(string) $usuario->getId()] = $usuario;
        }

        /** @var array<string, User> $elegidos */
        $elegidos = [];

        foreach ($ids as $id) {
            $id = (string) $id;

            if (!isset($candidatos[$id])) {
                throw new BadRequestHttpException(
                    sprintf('El usuario %s no está en el equipo de limpieza.', $id)
                );
            }

            $elegidos[$id] = $candidatos[$id];
        }

        // Se itera sobre una copia (`toArray()`): quitar elementos de la colección mientras se
        // la recorre salta posiciones y dejaría a alguien asignado sin motivo.
        foreach ($evento->getLimpiezaAsignada()->toArray() as $actual) {
            if (!isset($elegidos[(string) $actual->getId()])) {
                $evento->removeLimpiezaAsignada($actual);
            }
        }

        foreach ($elegidos as $usuario) {
            $evento->addLimpiezaAsignada($usuario);
        }
    }
}
