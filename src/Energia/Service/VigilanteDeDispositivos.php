<?php

declare(strict_types=1);

namespace App\Energia\Service;

use App\Energia\Entity\EnergiaDispositivo;
use App\Energia\Repository\EnergiaDispositivoRepository;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Service\WebPushNotificationService;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Throwable;

/**
 * Avisa cuando un enchufe deja de registrar consumo.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 *
 * Un aparato mudo NO se nota solo, y ése es el problema entero: la cuenta del huésped
 * simplemente deja de subir, que es exactamente lo que él esperaría ver si no estuviera
 * gastando. No hay error, no hay pantalla roja, no hay queja. El descubrimiento llega el día de
 * cobrar, cuando ya no se puede reconstruir lo que no se midió — y ahí la discusión está perdida
 * de antemano, porque cobrar un consumo que no se registró es indefendible.
 *
 * Las causas son todas mundanas: alguien desenchufó el aparato para conectar otra cosa, el wifi
 * de la casita se cayó, o se agotó la cuota de Tuya. La acción es siempre la misma —ir a mirar—,
 * así que el aviso no necesita distinguirlas.
 *
 * ── Cada N fallos, no cada fallo ────────────────────────────────────────────
 *
 * El muestreo es horario. Avisar en cada fallo convierte un enchufe roto un fin de semana en
 * sesenta notificaciones idénticas, y para la número diez ya nadie las abre — el canal queda
 * quemado para cuando de verdad importe.
 *
 * Por eso decide `EnergiaDispositivo::debeAlertar()` con un módulo: salta en el fallo N, el 2N,
 * el 3N… Sigue insistiendo, porque un aparato roto sigue estándolo, pero espaciado.
 *
 * Y el primer aviso llega al fallo N, no al primero: un muestreo perdido por un wifi con hipo se
 * recupera solo a la hora siguiente, y avisar de eso es ruido.
 */
final readonly class VigilanteDeDispositivos
{
    public function __construct(
        private EnergiaDispositivoRepository $dispositivos,
        private WebPushNotificationService $push,
        private UserRepository $usuarios,
        private RoleHierarchyInterface $jerarquia,
        private LoggerInterface $logger,
        #[Autowire('%energia.fallos_para_alerta%')] private int $fallosParaAlerta,
    ) {}

    /**
     * Anota que un aparato no dio lectura y avisa si toca.
     *
     * ⚠️ NO hace `flush()`: lo llama el cron dentro de su propio ciclo y quien manda ahí es él.
     * Hacerlo aquí obligaría a escribir en base una vez por aparato.
     *
     * @return bool si se disparó el aviso
     */
    public function anotarFallo(EnergiaDispositivo $dispositivo): bool
    {
        $fallos = $dispositivo->registrarFallo();

        $this->logger->warning(sprintf(
            '[Energia] «%s» sin lectura (%d seguidas).',
            $dispositivo->getNombre(),
            $fallos
        ));

        if (!$dispositivo->debeAlertar($this->fallosParaAlerta)) {
            return false;
        }

        $dispositivo->setUltimaAlertaEn(new DateTimeImmutable());
        $this->avisar($dispositivo, $fallos);

        return true;
    }

    /**
     * Manda el aviso al equipo de operaciones.
     *
     * Ni una excepción sale de aquí: un aviso que no se puede entregar no puede tumbar el
     * muestreo que lo pidió. Perder una notificación es molesto; perder la hora de consumo de
     * todos los aparatos porque el push falló es un agujero en la facturación.
     */
    private function avisar(EnergiaDispositivo $dispositivo, int $fallos): void
    {
        try {
            $payload = [
                'title' => '⚡ Enchufe sin registrar consumo',
                'body' => sprintf(
                    '%s lleva %d lectura(s) seguidas sin dato. Última: %s.',
                    (string) $dispositivo,
                    $fallos,
                    $dispositivo->getLecturaTomadaEn()?->format('d/m H:i') ?? 'nunca'
                ),
                'actionUrl' => '/energia/dispositivos',
            ];

            foreach ($this->destinatarios() as $usuario) {
                $this->push->sendToUser($usuario, $payload);
            }
        } catch (Throwable $e) {
            $this->logger->error(
                '[Energia] No se pudo avisar del enchufe mudo: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Quién se entera: el equipo de operaciones.
     *
     * Un enchufe caído es trabajo de campo —hay que ir a la casita y mirarlo—, no una incidencia
     * de mensajería ni de reservas. Mismo criterio de selección por rol alcanzable que
     * `NotificadorPushConversacion::destinatarios()`.
     *
     * @return list<\App\Entity\User>
     */
    private function destinatarios(): array
    {
        $elegibles = [];

        foreach ($this->usuarios->findAll() as $usuario) {
            $roles = $this->jerarquia->getReachableRoleNames($usuario->getRoles());

            if (in_array(Roles::OPERACIONES_SHOW, $roles, true)) {
                $elegibles[] = $usuario;
            }
        }

        return $elegibles;
    }

    /**
     * Barrido de seguridad: avisa de los que llevan tiempo mudos aunque nadie los muestreara.
     *
     * Cubre el caso que el contador de fallos NO ve: si el cron entero deja de correr, nadie
     * llama a `anotarFallo()` y el contador se queda congelado en cero para siempre. Aquí se mira
     * el reloj en vez del contador, que es la única señal que sobrevive a que el proceso que
     * debía vigilar sea justamente el que se murió.
     *
     * Va en una entrada de cron aparte, a propósito.
     *
     * @return list<EnergiaDispositivo>
     */
    public function mudosSinMuestrear(int $horas): array
    {
        $mudos = $this->dispositivos->mudos($horas);
        $avisados = [];

        foreach ($mudos as $dispositivo) {
            // Si el contador ya va sumando, el muestreo SÍ está corriendo y este aparato ya tiene
            // su propio aviso en camino: repetirlo aquí sería avisar dos veces de lo mismo.
            if ($dispositivo->getFallosConsecutivos() > 0) {
                continue;
            }

            $dispositivo->setUltimaAlertaEn(new DateTimeImmutable());
            $this->avisar($dispositivo, 0);
            $avisados[] = $dispositivo;
        }

        return $avisados;
    }
}
