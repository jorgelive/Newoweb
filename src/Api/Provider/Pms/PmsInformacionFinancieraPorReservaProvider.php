<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Enum\PmsPoliticaPrepago;
use App\Pms\Finanzas\PmsPrepagoEnlaceService;
use App\Pms\Repository\PmsInformacionFinancieraRepository;
use App\Pms\Service\Finance\PmsCargosAutomaticosService;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
use Symfony\Component\Uid\Uuid;

/**
 * Provider de `GET /pms/pms_informacion_financieras/por-reserva/{reservaId}`.
 *
 * Existe porque el `SearchFilter` sobre la relación `reserva` **no funciona** con estos
 * UUID binarios: no declara el tipo Doctrine al vincular el parámetro, así que compara
 * texto contra `BINARY(16)` y devuelve una colección vacía sin dar ningún error (§12.6).
 *
 * Delega en `PmsInformacionFinancieraRepository::findOneByReservaId()`, que sí tipa el
 * parámetro. Devolver el recurso en singular es además más honesto que una colección:
 * la relación es 1:1 con la reserva.
 *
 * @implements ProviderInterface<PmsInformacionFinanciera>
 */
final class PmsInformacionFinancieraPorReservaProvider implements ProviderInterface
{
    public function __construct(
        private readonly PmsInformacionFinancieraRepository $repository,
        private readonly PmsPrepagoCalculador $prepagoCalculador,
        private readonly PmsPrepagoEnlaceService $prepagoEnlaces,
        private readonly PmsCargosAutomaticosService $cargosAutomaticos,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PmsInformacionFinanciera
    {
        $reservaId = $uriVariables['reservaId'] ?? null;

        // 🔴 NO comprobar `is_string()`: al declarar la variable con un `Link` sobre el `id` de
        // PmsReserva, API Platform la castea al tipo del identificador y aquí llega un OBJETO
        // `Symfony\Component\Uid\Uuid`, no una cadena. Con la guarda de string el provider
        // devolvía null y la respuesta era un 404 idéntico al de "no existe" — aunque la fila
        // estuviera en la tabla. Se normaliza a texto y que decida el repositorio.
        $reservaId = match (true) {
            $reservaId instanceof Uuid => (string) $reservaId,
            is_string($reservaId)      => $reservaId,
            default                    => null,
        };

        if ($reservaId === null || $reservaId === '') {
            return null;
        }

        // null → API Platform responde 404, que es lo correcto: esa reserva no tiene cabecera.
        $info = $this->repository->findOneByReservaId($reservaId);

        // Sin `$info === null ? … : …` dentro: el `?->` ya lo cubre y la rama muerta sólo
        // servía para hacer creer que aquí podía llegar un null.
        if ($info !== null) {
            $info->setPrepagoPendiente($this->prepago($info));
            $info->setCostosTeoricos($this->costosTeoricos($info));
        }

        return $info;
    }

    /**
     * Lo que costaría cada estancia DIRECTA según el tarifario, para enseñarlo junto al cargo.
     *
     * Se calcula aquí y no en la entidad porque hace falta el tarifario, que es un servicio.
     * `costoTeorico()` devuelve `null` en todo lo que no sea una venta directa calculable —una
     * estancia de Booking, un bloqueo, una sin fechas—, así que el filtro no está escrito dos
     * veces: manda el servicio y aquí sólo se descartan los nulos.
     *
     * @return array<string, array<string, mixed>> Indexados por `eventoId`.
     */
    private function costosTeoricos(PmsInformacionFinanciera $info): array
    {
        $costos = [];

        foreach ($info->getReserva()?->getEventosCalendario() ?? [] as $evento) {
            $teorico = $this->cargosAutomaticos->costoTeorico($evento);

            if ($teorico !== null) {
                $costos[(string) $evento->getId()] = $teorico;
            }
        }

        return $costos;
    }

    /**
     * El prepago tal como lo quiere el PANEL.
     *
     * La cifra sale de `PmsPrepagoCalculador::pendiente()`, la MISMA llamada que alimenta el
     * estado de cuenta del huésped: si el panel y el pax dieran importes distintos, la
     * conversación ya estaría perdida antes de empezar.
     *
     * Lo que cambia es la envoltura. La `claveI18n` del calculador se queda fuera —es una
     * clave del diccionario de `pax`, que se resuelve en el navegador del huésped— y en su
     * lugar viaja `PmsPoliticaPrepago::etiqueta()`, en español y ya legible. Así la etiqueta
     * mantiene una sola fuente (el enum) en vez de acabar copiada en un `Record` de TypeScript.
     *
     * @return array{monto: string, politica: string, politicaEtiqueta?: string, politicaCorta?: string, concepto?: string}|null
     */
    private function prepago(PmsInformacionFinanciera $info): ?array
    {
        $prepago = $this->prepagoCalculador->pendiente($info);

        if ($prepago === null) {
            return null;
        }

        $politica = PmsPoliticaPrepago::tryFrom($prepago['politica']);
        $reserva = $info->getReserva();

        return array_filter([
            'monto' => $prepago['monto'],
            'politica' => $prepago['politica'],
            'politicaEtiqueta' => $politica?->etiqueta(),
            // Corta para el botón del atajo, larga para su `title`: en un botón de tres
            // palabras el matiz «(solo alojamiento)» estorba, y en la configuración es
            // imprescindible. Las dos salen del enum — ver PmsPoliticaPrepago.
            'politicaCorta' => $politica?->etiquetaCorta(),
            // El concepto que verá el huésped si el operador usa el atajo. Lo redacta el
            // mismo servicio que usa la skill del agente, para que el cobro no se llame de
            // dos maneras distintas según quién lo emitiera.
            'concepto' => $reserva === null ? null : $this->prepagoEnlaces->concepto($reserva),
        ], static fn ($v) => $v !== null);
    }
}
