<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Entity\Maestro\MaestroMoneda;
use App\Entity\Maestro\MaestroTipocambio;
use App\Service\TipocambioManager;
use App\Service\WebPushNotificationService;
use App\Security\Roles;
use App\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Throwable;

/**
 * Trae la cotización del día y **avisa si el maestro se quedó atrás**.
 *
 * ── Por qué existe, con fecha ───────────────────────────────────────────────
 * Hasta el 10/09/2026 nadie sincronizaba esto. El maestro se llenaba **de rebote**: cuando nacía
 * un cargo, `PmsTipoCambioSnapshotListener` pedía la tasa del día, `TipocambioManager` no la
 * encontraba en base, la traía de la API y de paso persistía el mes. Funcionó durante meses —una
 * fila por día, escrita a las 00:23, a las 04:13, a la hora que entrara una reserva—.
 *
 * Y falló de la peor manera posible. El proveedor (apis.net.pe) se mudó a decolecta.com el
 * 26/08/2026 y su ruta empezó a devolver 404. Como `findLastAvailableInDb()` sirve la última
 * cotización que haya, **nada se rompió**: simplemente cada cargo, cada cobro y cada ficha
 * pasaron a sellarse con la tasa del 26/08. Quince días. 49 cargos, 20 pagos y 18 fichas. Lo
 * único que quedó fue un WARNING de «consulta vacía» repetido 74 veces en `info.log`.
 *
 * ── Lo que este comando arregla, que no es traer el dato ────────────────────
 * Traer el dato ya lo hacía el rebote. Lo que faltaba es **notar la ausencia**, y por eso el
 * comando termina en `FAILURE` y con un aviso push cuando el maestro pasa del umbral. Un cron que
 * falla en silencio reproduce el mismo problema con más pasos.
 *
 * ⚠️ **El respaldo es lo que hizo el fallo invisible, y el respaldo se queda.** No se toca
 * `findLastAvailableInDb()`: que un cobro no se pueda anotar porque SUNAT no responde sería peor
 * que sellarlo con la tasa de ayer. Lo que se añade es alguien que mire.
 *
 * ── El umbral, y por qué no es 1 ────────────────────────────────────────────
 * SUNAT publica todos los días —fines de semana incluidos, repitiendo el último día hábil— así
 * que en régimen normal el retraso es 0. Se avisa a partir de **{@see self::DIAS_TOLERADOS}**
 * porque el dato del día en curso puede tardar unas horas en publicarse y un cron madrugador no
 * debe sonar por eso. Con dos días de retraso ya no es la hora: es que algo se rompió.
 *
 * Uso: `php bin/console app:pms:tipo-cambio:sincronizar`
 */
#[AsCommand(
    name: 'app:pms:tipo-cambio:sincronizar',
    description: 'Trae la cotización del día y avisa si el maestro de tipo de cambio se quedó atrás.'
)]
final class TipoCambioSincronizarCommand extends Command
{
    /**
     * Días de retraso a partir de los cuales se considera roto.
     *
     * Uno es normal a primera hora; dos ya no lo explica ningún horario de publicación.
     */
    private const DIAS_TOLERADOS = 2;

    public function __construct(
        private readonly TipocambioManager $tipoCambio,
        private readonly EntityManagerInterface $em,
        private readonly WebPushNotificationService $push,
        private readonly UserRepository $usuarios,
        private readonly RoleHierarchyInterface $jerarquia,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dias',
                null,
                InputOption::VALUE_REQUIRED,
                'Cuántos días hacia atrás asegurarse de tener. Sirve para tapar huecos tras una caída.',
                '1'
            )
            ->addOption('sin-aviso', null, InputOption::VALUE_NONE, 'No manda el push. Para probar a mano.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dias = max(1, (int) $input->getOption('dias'));
        $callado = (bool) $input->getOption('sin-aviso');

        // Zona de Lima y no la del servidor: el servidor va en UTC, así que a partir de las 19:00
        // de Lima «hoy» en UTC ya es mañana y se pediría una cotización que aún no existe.
        $hoy = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));

        // Se recorre de la más vieja a la más nueva para que el hueco se tape en orden. Cada
        // llamada persiste el MES entero de esa fecha, así que pedir varios días del mismo mes
        // no cuesta varias llamadas: la segunda ya la encuentra en base.
        for ($i = $dias - 1; $i >= 0; $i--) {
            $dia = $hoy->modify(sprintf('-%d days', $i));
            $encontrada = $this->tipoCambio->getTipodecambio($dia);

            $io->text(sprintf(
                '%s → %s',
                $dia->format('Y-m-d'),
                $encontrada?->getFecha()?->format('Y-m-d') ?? 'sin dato'
            ));
        }

        $ultima = $this->ultimaCotizacion();

        if ($ultima === null) {
            return $this->alarma($io, 'El maestro de tipo de cambio está VACÍO.', $callado);
        }

        $retraso = (int) $ultima->diff($hoy->setTime(0, 0))->days;

        if ($retraso >= self::DIAS_TOLERADOS) {
            return $this->alarma(
                $io,
                sprintf(
                    'El tipo de cambio lleva %d días sin actualizarse (última: %s). Se está sellando '
                    . 'todo con una cotización vieja y nadie lo nota.',
                    $retraso,
                    $ultima->format('Y-m-d')
                ),
                $callado
            );
        }

        $io->success(sprintf('Tipo de cambio al día (última cotización: %s).', $ultima->format('Y-m-d')));

        return Command::SUCCESS;
    }

    /**
     * La fecha de la cotización más reciente del dólar, o `null` si no hay ninguna.
     */
    private function ultimaCotizacion(): ?DateTimeImmutable
    {
        /** @var list<MaestroTipocambio> $filas */
        $filas = $this->em->createQueryBuilder()
            ->select('tc')
            ->from(MaestroTipocambio::class, 'tc')
            ->where('tc.moneda = :moneda')
            ->setParameter('moneda', $this->em->getReference(MaestroMoneda::class, MaestroMoneda::DB_ID_USD))
            ->orderBy('tc.fecha', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        $fecha = $filas[0] ?? null;

        return $fecha?->getFecha() === null
            ? null
            : DateTimeImmutable::createFromInterface($fecha->getFecha())->setTime(0, 0);
    }

    /**
     * Sale en FAILURE y avisa. Las dos cosas, y a propósito.
     *
     * El código de salida es lo que hace que el cron lo mande por correo al administrador del
     * sistema; el push es lo que hace que alguien de operaciones se entere el mismo día. Sin el
     * primero se pierde si nadie tiene la app; sin el segundo, se pierde en un buzón que nadie
     * lee.
     */
    private function alarma(SymfonyStyle $io, string $mensaje, bool $callado): int
    {
        $io->error($mensaje);
        $this->logger->error('[TipoCambio] ' . $mensaje);

        if ($callado) {
            $io->note('Modo sin aviso: no se notificó a nadie.');

            return Command::FAILURE;
        }

        try {
            $payload = [
                'title' => '⚠️ Tipo de cambio desactualizado',
                'body' => $mensaje,
                'actionUrl' => '/admin',
            ];

            foreach ($this->destinatarios() as $usuario) {
                $this->push->sendToUser($usuario, $payload);
            }
        } catch (Throwable $e) {
            // Que no se pueda avisar no puede tumbar el comando: el FAILURE y el log ya salieron.
            $this->logger->error('[TipoCambio] No se pudo avisar: ' . $e->getMessage(), ['exception' => $e]);
        }

        return Command::FAILURE;
    }

    /**
     * Los mismos que reciben el aviso de colas atascadas: quien puede hacer algo al respecto.
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
}
