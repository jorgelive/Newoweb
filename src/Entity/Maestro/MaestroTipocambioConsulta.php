<?php

declare(strict_types=1);

namespace App\Entity\Maestro;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cuántas veces se le ha preguntado hoy al proveedor del tipo de cambio. Una fila por día.
 *
 * ── Por qué existe, con fecha ───────────────────────────────────────────────
 * El 09/10/2026 se agotó la cuota de decolecta y la API pasó a contestar 403. Cualquier parte del
 * sistema que necesitara la tasa de hoy y no la encontrara en base volvía a preguntar —el sellado
 * de cargos, las skills, el push de tarifas—, sin recordar que acababa de fallar, y cada intento
 * eran dos peticiones (mes y día). **873 peticiones rechazadas en cinco días**, y decolecta las
 * cuenta: el plan de 1000 comprado el 13/10 nació agotado.
 *
 * El tope ({@see \App\Service\TipocambioManager::MAX_CONSULTAS_DIA}) vive aquí y no en caché
 * porque tiene que valer para TODOS los procesos —php-fpm, workers, cron— y sobrevivir a un
 * `cache:clear`, que es justo lo que hace cada despliegue.
 *
 * Lo escribe `TipocambioManager` en SQL directo (reserva atómica con `UPDATE … WHERE intentos <
 * tope`); la entidad está para que el mapeo conozca la tabla y `doctrine:schema:validate` no la
 * dé por sobrante.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maestro_tipocambio_consulta')]
#[ORM\UniqueConstraint(name: 'uniq_tc_consulta_dia', columns: ['dia'])]
class MaestroTipocambioConsulta
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Día en hora de Lima, que es la de SUNAT. */
    #[ORM\Column(type: 'date')]
    private ?DateTimeInterface $dia = null;

    /** Peticiones HTTP hechas ese día. Nunca pasa del tope: la reserva se hace antes de llamar. */
    #[ORM\Column(type: 'integer')]
    private int $intentos = 0;

    /** Lo último que dijo el proveedor al fallar ese día, para que el aviso diga la causa. */
    #[ORM\Column(name: 'ultimo_fallo', type: 'string', length: 255, nullable: true)]
    private ?string $ultimoFallo = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDia(): ?DateTimeInterface
    {
        return $this->dia;
    }

    public function getIntentos(): int
    {
        return $this->intentos;
    }

    public function getUltimoFallo(): ?string
    {
        return $this->ultimoFallo;
    }
}
