<?php

declare(strict_types=1);

namespace App\Pms\DispatchHandler;

use App\Message\Command\MessageCrearAvisoChoqueOtaCommand;
use App\Message\Service\Aviso\AvisoAlEquipo;
use App\Message\Service\Aviso\AvisoAlEquipoService;
use App\Pms\Dispatch\RevisarChoqueDeNocheExtraDispatch;
use App\Pms\Dto\ChoqueDeNocheExtra;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Service\Reserva\ChoquesDeNocheExtra;
use App\Repository\UserRepository;
use App\Security\Roles;
use App\Service\WebPushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Avisa a quien atiende las reservas de que un canal dejó dos estancias para la misma noche, por
 * culpa de una noche extra. Fase 5 de docs/PlanHorarioExtraSinEventos.md.
 *
 * Por dónde (decisión de Jorge, 01/10/2026):
 *
 * 1. **WhatsApp a `ROLE_CUSTOMER_SUPPORT`**, por `AvisoAlEquipoService`: dentro de la ventana de
 *    24 h, el texto completo; fuera, la plantilla `aviso_choque_ota_interno`.
 * 2. **Push del panel** si el WhatsApp no llegó a nadie.
 * 3. La **marca roja del calendario** no la pone este handler: se calcula sola al pintarlo, con la
 *    misma regla (`PmsEventoCalendario::nocheExtraPisadaPor()`), y dura lo que dure el choque.
 *
 * ⚠️ **La plantilla sólo cuenta UNO de los dos casos**: «{{canal}} acaba de mover ahí otra
 * reserva». Cuando lo que movió el canal es la estancia DUEÑA de la noche extra (su víspera nueva
 * cae sobre otra), esa frase sería falsa, así que ese caso va sin plantilla: dentro de la ventana
 * sale el texto, y fuera, el push. Hace falta una segunda plantilla para él.
 *
 * Un mismo choque se avisa una vez al día: el canal puede reenviar la misma reserva varias veces
 * seguidas (webhook y pull), y el choque no cambia por eso.
 */
#[AsMessageHandler]
final readonly class RevisarChoqueDeNocheExtraDispatchHandler
{
    private const int ENFRIAMIENTO_SEGUNDOS = 86400;

    public function __construct(
        private EntityManagerInterface $em,
        private ChoquesDeNocheExtra $choques,
        private AvisoAlEquipoService $avisos,
        private WebPushNotificationService $push,
        private UserRepository $usuarios,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(RevisarChoqueDeNocheExtraDispatch $mensaje): void
    {
        foreach ($mensaje->eventoIds as $id) {
            $estancia = Uuid::isValid($id) ? $this->em->find(PmsEventoCalendario::class, Uuid::fromString($id)) : null;
            if (!$estancia instanceof PmsEventoCalendario) {
                continue;
            }

            foreach ($this->choques->de($estancia) as $choque) {
                $this->avisar($choque);
            }
        }
    }

    private function avisar(ChoqueDeNocheExtra $choque): void
    {
        $clave = 'pms.choque_noche_extra.' . sha1(implode('|', [
            (string) $choque->duenio->getId(),
            (string) $choque->otra->getId(),
            $choque->noche->desde->format('Y-m-d'),
        ]));

        $visto = $this->cache->getItem($clave);
        if ($visto->isHit()) {
            return;
        }

        $texto = $this->redactar($choque);
        $this->logger->warning('[horario extra] Un canal dejó dos estancias para la misma noche.', [
            'texto' => $texto,
            'duenio' => (string) $choque->duenio->getId(),
            'otra' => (string) $choque->otra->getId(),
        ]);

        try {
            $resultado = $this->avisos->notificar(new AvisoAlEquipo(
                rol: Roles::CUSTOMER_SUPPORT,
                texto: $texto,
                plantillaCodigo: $choque->seMovioElDuenio() ? null : MessageCrearAvisoChoqueOtaCommand::CODIGO,
                // ⚠️ UNA línea por valor: Meta no admite saltos en los parámetros.
                variables: [
                    'casita' => $this->casita($choque->duenio),
                    'fecha' => $choque->noche->desde->format('d/m/Y'),
                    'huesped' => $this->quien($choque->duenio),
                    'canal' => $this->canal($choque->movida),
                    // Va entre paréntesis en la plantilla: sin los suyos propios.
                    'otra' => $this->quien($choque->otra, entreParentesis: false),
                ],
                metadata: [
                    'aviso_choque_noche_extra' => true,
                    'duenio' => (string) $choque->duenio->getId(),
                    'otra' => (string) $choque->otra->getId(),
                ],
            ));

            if (!$resultado->alguienFueAvisado()) {
                // 🛟 Nadie con el rol y móvil, o fuera de la ventana sin plantilla que sirva.
                foreach ($this->usuarios->findByRole(Roles::CUSTOMER_SUPPORT) as $usuario) {
                    $this->push->sendToUser($usuario, [
                        'title' => '⚠️ Dos reservas para la misma noche',
                        'body' => $texto,
                        'actionUrl' => '/reservas',
                    ]);
                }
            }
        } catch (Throwable $e) {
            // El aviso no puede romper nada: el choque ya está en la base y en el calendario.
            $this->logger->error('[horario extra] No se pudo avisar del choque: ' . $e->getMessage());

            return;
        }

        $visto->set(true)->expiresAfter(self::ENFRIAMIENTO_SEGUNDOS);
        $this->cache->save($visto);
    }

    private function redactar(ChoqueDeNocheExtra $choque): string
    {
        $etiqueta = mb_strtolower($choque->noche->etiqueta());

        return $choque->seMovioElDuenio()
            ? sprintf(
                "⚠️ %s: %s acaba de cambiar la reserva de %s, y su %s cae la noche del %s, que ya es de %s.\n\nHay que reubicar a una de las dos.",
                $this->casita($choque->duenio),
                $this->canal($choque->movida),
                $this->quien($choque->duenio),
                $etiqueta,
                $choque->noche->desde->format('d/m'),
                $this->quien($choque->otra),
            )
            : sprintf(
                "⚠️ %s: la noche del %s estaba reservada para la %s de %s, pero %s acaba de mover ahí la reserva de %s.\n\nHay que reubicar a una de las dos.",
                $this->casita($choque->duenio),
                $choque->noche->desde->format('d/m'),
                $etiqueta,
                $this->quien($choque->duenio),
                $this->canal($choque->movida),
                $this->quien($choque->otra),
            );
    }

    private function casita(PmsEventoCalendario $estancia): string
    {
        return $estancia->getPmsUnidad()?->getNombre() ?? 'Una casita';
    }

    private function canal(PmsEventoCalendario $estancia): string
    {
        return $estancia->getChannel()?->getNombre() ?? 'el canal';
    }

    /** «Anna Müller (UV5XPW)»: el nombre para entenderlo, el localizador para buscarla. */
    private function quien(PmsEventoCalendario $estancia, bool $entreParentesis = true): string
    {
        $nombre = $estancia->getTituloCache() ?? 'un huésped';
        $localizador = $estancia->getReserva()?->getLocalizador();

        if ($localizador === null) {
            return $nombre;
        }

        return sprintf($entreParentesis ? '%s (%s)' : '%s · %s', $nombre, $localizador);
    }
}
