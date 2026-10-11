<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Finanzas\Contract\FinDeudasDeContactoInterface;
use App\Finanzas\Dto\FinDeudaDeContacto;
use App\Finanzas\Enum\FinOrigenCobro;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsReserva;
use App\Pms\Service\Finance\PmsTotalesPorMoneda;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Las reservas con saldo pendiente de quien tenga ese teléfono o ese correo (10/10/2026).
 *
 * Dos caminos, porque ninguno basta solo:
 *
 * - **Las identidades** —la misma búsqueda que «¿de quién es este teléfono?» al crear una
 *   reserva—: identidad → conversación → sus reservas. Recoge los números que la persona dio
 *   después, por el chat.
 * - **Los datos con que nació la reserva** (`telefono`, `emailCliente`): una reserva sin
 *   conversación todavía no tiene identidades.
 *
 * Sólo reservas no canceladas que no hayan salido hace más de `DIAS_TRAS_SALIDA`: más atrás, una
 * deuda vieja haría saltar el aviso en cada venta a un cliente que vuelve.
 */
final readonly class PmsDeudasDeContacto implements FinDeudasDeContactoInterface
{
    private const int DIAS_TRAS_SALIDA = 30;

    public function __construct(
        private EntityManagerInterface $em,
        private EnlacesDeConversacion $enlaces,
    ) {}

    public function deudasDe(?string $telefono, ?string $email): array
    {
        $ids = [];

        foreach ([[IdentidadTipo::TELEFONO, $telefono], [IdentidadTipo::EMAIL, $email]] as [$tipo, $valor]) {
            $normalizado = $valor !== null && trim($valor) !== '' ? $tipo->normalizar($valor) : '';
            if ($normalizado === '') {
                continue;
            }

            $conversacion = $this->em->getRepository(MessageIdentidad::class)
                ->findOneBy(['tipo' => $tipo, 'valor' => $normalizado])?->getConversacion();

            foreach ($conversacion === null ? [] : $this->enlaces->de($conversacion) as $enlace) {
                if ($enlace->getContextType() === PmsConversacionEnlace::CONTEXT_TYPE) {
                    $ids[$enlace->getContextId()] = true;
                }
            }
        }

        foreach ($this->porDatosDeAlta($telefono, $email) as $id) {
            $ids[$id] = true;
        }

        $desde = new \DateTimeImmutable(sprintf('-%d days', self::DIAS_TRAS_SALIDA));
        $deudas = [];

        foreach (array_keys($ids) as $id) {
            $reserva = Uuid::isValid((string) $id) ? $this->em->getRepository(PmsReserva::class)->find((string) $id) : null;
            $info = $reserva?->getInformacionFinanciera();
            $salida = $reserva?->getFechaSalida();

            if ($reserva === null || $info === null || $reserva->isCancelada() || ($salida !== null && $salida < $desde)) {
                continue;
            }

            foreach (PmsTotalesPorMoneda::de($info)->porMoneda as $moneda => $cifras) {
                if ((float) $cifras['saldo'] > 0.005) {
                    $deudas[] = new FinDeudaDeContacto(
                        tipoEtiqueta: FinOrigenCobro::PMS_RESERVA->etiqueta(),
                        origenTipo: FinOrigenCobro::PMS_RESERVA->value,
                        origenId: (string) $reserva->getId(),
                        referencia: $reserva->getLocalizador(),
                        descripcion: trim(sprintf('%s %s · %s', $reserva->getNombreCliente(), $reserva->getApellidoCliente(), $reserva->getUnidadesAggregate() ?? '')),
                        moneda: (string) $moneda,
                        saldo: $cifras['saldo'],
                    );
                }
            }
        }

        return $deudas;
    }

    /**
     * Las reservas cuyos datos de alta coinciden. El teléfono, por sus dígitos: se guardó tal cual
     * se tecleó («+51 984 …», «0051984…»), así que se comparan los últimos nueve.
     *
     * @return list<string>
     */
    private function porDatosDeAlta(?string $telefono, ?string $email): array
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono) ?? '';
        $correo = strtolower(trim((string) $email));

        if (strlen($digitos) < 7 && $correo === '') {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            "SELECT BIN_TO_UUID(id) FROM pms_reserva
             WHERE (:correo <> '' AND LOWER(email_cliente) = :correo)
                OR (:digitos <> '' AND RIGHT(REGEXP_REPLACE(telefono, '[^0-9]', ''), 9) = RIGHT(:digitos, 9))",
            ['correo' => $correo, 'digitos' => strlen($digitos) >= 7 ? $digitos : ''],
        );

        return $ids;
    }
}
