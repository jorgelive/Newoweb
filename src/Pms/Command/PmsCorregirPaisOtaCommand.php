<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Entity\Maestro\MaestroPais;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsReserva;
use App\Service\Phone\PhoneSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Corrige el país de las reservas de OTA donde el canal mandó el IDIOMA en vez del país.
 *
 * ### El fallo
 *
 * El `country2` de Airbnb no es el país cuando colisiona con un código de idioma: con la app en
 * español llega `ES`, que es `es` en mayúsculas y **también un país válido**, así que
 * `find(MaestroPais::class, 'ES')` casa y no falla absolutamente nada. Auditado el 19/08/2026
 * sobre 1385 payloads: de 18 reservas de Airbnb marcadas `ES` con teléfono, **16 tenían móvil
 * peruano (+51), una colombiana y otra mexicana; ninguna española**. Booking.com no lo tiene:
 * su `country2` cuadra con el prefijo siempre. Ver `docs/PmsBeds24ReservasSync.md` §3.3.
 *
 * `BookingPullPersister::resolvePais()` ya lo resuelve por teléfono para lo que entra desde
 * entonces. Esto es para lo que quedó guardado antes.
 *
 * ### Por qué importa
 *
 * `PmsProcedenciaHuesped::pagaDesdePeru()` decide qué medios de cobro se le ofrecen a alguien.
 * Con `pais = 'ES'` responde «paga desde fuera», y a un peruano con móvil +51 se le ofrece
 * tarjeta con recargo y Western Union en vez de Yape, Plin o transferencia.
 *
 * ### A quién toca, y por qué a ésos y no a más
 *
 * Sólo a las reservas que llevan **la firma exacta del fallo**: `pais === strtoupper(idioma)`.
 * Es lo que produce la colisión y nada más — un `US`/`en` no encaja («EN» no es un país, así que
 * ahí Airbnb manda el país bueno), y un `PE`/`es` tampoco. Así no se toca ningún país que se
 * haya puesto bien, ni a mano ni desde el canal.
 *
 * La evidencia es el **prefijo internacional del teléfono**, vía
 * {@see PhoneSanitizer::paisDelNumero()}, que calla cuando el número no lo trae. Si calla, la
 * reserva se deja como está: no se cambia un país por una suposición.
 *
 * ⚠️ **Se salta el candado a propósito.** `datosLocked` protege lo que escribió un operador
 * frente al pull; aquí no se corrige a un operador, se corrige una deducción NUESTRA que era
 * mala. Aun así, si alguien puso `ES` a mano en un huésped español con móvil peruano, esto se lo
 * cambiará: es el precio de arreglar los 19 restantes, y el criterio —de dónde paga— sigue
 * siendo el bueno para lo que se usa el campo.
 *
 * ### Por qué comando y no migración
 *
 * El país pasa por listeners al guardarse. Da la casualidad de que un cambio de SÓLO país no
 * dispara push —`pais` está en `IGNORED_FIELDS_ON_LOCKED_OTA` de
 * `Beds24BookingsPushQueueListener`—, pero eso es una garantía de hoy y no algo en lo que apoyar
 * un `UPDATE` masivo. Por el ORM se comporta como cualquier otro guardado.
 *
 * Es idempotente: en la segunda pasada el país ya no coincide con el idioma y no entra nadie.
 */
#[AsCommand(
    name: 'app:pms:corregir-pais-ota',
    description: 'Corrige el país de las reservas donde la OTA mandó el idioma (Airbnb), usando el prefijo del teléfono.'
)]
final class PmsCorregirPaisOtaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhoneSanitizer $telefonos,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría.');
        $this->addOption(
            'canal',
            null,
            InputOption::VALUE_REQUIRED,
            'Canal a revisar. Sólo está confirmado en Airbnb; se puede apuntar a otro si aparece el mismo patrón.',
            PmsChannel::CODIGO_AIRBNB
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');
        $canal = (string) $input->getOption('canal');

        /** @var list<PmsReserva> $reservas */
        $reservas = $this->em->getRepository(PmsReserva::class)
            ->createQueryBuilder('r')
            ->andWhere('r.channel = :canal')
            ->setParameter('canal', $canal)
            ->getQuery()
            ->getResult();

        if ($reservas === []) {
            $io->warning(sprintf('No hay reservas del canal «%s».', $canal));

            return Command::SUCCESS;
        }

        $filas = [];
        $sinEvidencia = 0;
        $corregidas = 0;

        foreach ($reservas as $reserva) {
            if (!$this->tieneLaFirmaDelFallo($reserva)) {
                continue;
            }

            $deducido = $this->paisSegunElTelefono($reserva);

            if ($deducido === null) {
                $sinEvidencia++;
                continue;
            }

            $actual = (string) $reserva->getPais()?->getId();

            if ($deducido === $actual) {
                continue;
            }

            $pais = $this->em->find(MaestroPais::class, $deducido);

            if ($pais === null) {
                $io->warning(sprintf(
                    'La reserva %s apunta a «%s» por el teléfono, pero ese país no está en el maestro.',
                    $reserva->getLocalizador(),
                    $deducido
                ));
                continue;
            }

            $filas[] = [
                (string) $reserva->getLocalizador(),
                trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()),
                (string) $reserva->getIdioma()?->getId(),
                $actual . ' → ' . $deducido,
            ];

            if (!$seco) {
                $reserva->setPais($pais);
            }

            $corregidas++;
        }

        if ($filas !== []) {
            $io->table(['Localizador', 'Huésped', 'Idioma', 'País'], $filas);
        }

        if (!$seco && $corregidas > 0) {
            $this->em->flush();
        }

        if ($sinEvidencia > 0) {
            $io->note(sprintf(
                '%d reserva(s) tienen la firma del fallo pero ningún teléfono con prefijo internacional: '
                . 'se dejan como están, porque cambiarlas sería suponer.',
                $sinEvidencia
            ));
        }

        $io->success(sprintf('%d reserva(s) %s.', $corregidas, $seco ? 'se corregirían' : 'corregidas'));

        return Command::SUCCESS;
    }

    /**
     * ¿El país de esta reserva es en realidad su idioma?
     *
     * Es la firma exacta de la colisión —`es`→`ES`, `fr`→`FR`, `pt`→`PT`— y el único filtro que
     * hace falta: cualquier otra combinación es un país que el canal mandó bien o que puso una
     * persona, y no se toca.
     */
    private function tieneLaFirmaDelFallo(PmsReserva $reserva): bool
    {
        $pais = $reserva->getPais()?->getId();
        $idioma = $reserva->getIdioma()?->getId();

        if ($pais === null || $idioma === null) {
            return false;
        }

        return strtoupper($pais) === strtoupper($idioma);
    }

    /** El país que dice el prefijo de alguno de los dos números, o `null` si ninguno lo trae. */
    private function paisSegunElTelefono(PmsReserva $reserva): ?string
    {
        return $this->telefonos->paisDelNumero((string) $reserva->getTelefono())
            ?? $this->telefonos->paisDelNumero((string) $reserva->getTelefono2());
    }
}
