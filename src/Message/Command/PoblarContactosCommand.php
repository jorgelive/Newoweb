<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Entity\Maestro\MaestroContacto;
use App\Service\Phone\PhoneSanitizer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Puebla `maestro_contacto` con las personas que ya están escritas en los tres módulos.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * Porque la deduplicación depende de **normalizar teléfonos**, y esa regla vive en
 * {@see PhoneSanitizer}: en SQL habría que reescribirla con `REGEXP_REPLACE` y a partir de ahí
 * serían dos criterios distintos condenados a separarse — el mismo tipo de fallo que ya costó
 * caro en este proyecto cada vez que una regla se duplicó. Además los ids son UUID v7, que
 * MySQL no sabe generar (`UUID()` es v1 y rompería el orden temporal de toda la tabla).
 *
 * ── De dónde salen, y en qué orden ──────────────────────────────────────────
 * De más rico a más pobre, para que gane el que más datos tiene:
 *
 *   1. `pms_reserva`      — nombre, apellido, dos teléfonos, email, país, idioma
 *   2. `cotizacion_file`  — nombre de grupo, teléfono, email, idioma
 *   3. `msg_conversation` — nombre de perfil de WhatsApp y número, sin más
 *
 * Quien ya exista por su teléfono no se duplica ni se pisa: **el primero que entra manda**. La
 * alternativa —ir completando huecos entre fuentes— mezcla personas distintas en cuanto dos
 * comparten número, que aquí pasa (un matrimonio, una agencia que reserva para terceros).
 *
 * ── Idempotente y sin efectos ───────────────────────────────────────────────
 * Se puede correr las veces que haga falta: sólo inserta lo que no está. **No toca ni una
 * columna de los módulos de origen**: no reescribe `pms_reserva`, no borra nada, no enlaza
 * nada. Enganchar cada reserva a su contacto es el paso siguiente y va aparte, para que este
 * pueda ejecutarse en producción sin ventana de mantenimiento y comprobarse con calma.
 *
 * Uso:
 *   php bin/console app:contactos:poblar --dry-run
 *   php bin/console app:contactos:poblar
 */
#[AsCommand(
    name: 'app:contactos:poblar',
    description: 'Crea los MaestroContacto que faltan a partir de reservas, expedientes y conversaciones.'
)]
final class PoblarContactosCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhoneSanitizer $telefonos,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $io = new SymfonyStyle($input, $output);
        $conexion = $this->em->getConnection();

        // La clave de deduplicación son los últimos 8 dígitos del número normalizado, el MISMO
        // criterio que ya usa `PmsReservaRepository` para decidir si un número entrante es de
        // una reserva: los de las OTA vienen con el código de país repetido, y comparar la
        // cadena entera daría dos contactos para la misma persona.
        $vistos = $this->clavesExistentes($conexion);
        $io->text(sprintf('Contactos ya en la tabla: %d', count($vistos)));

        $creados = 0;
        $saltados = 0;
        $filas = [];

        foreach ($this->fuentes($conexion) as $fuente => $registros) {
            $deEstaFuente = 0;

            foreach ($registros as $registro) {
                $clave = $this->clave($registro['telefono'] ?? null);

                if ($clave === null || isset($vistos[$clave])) {
                    $saltados++;
                    continue;
                }

                $vistos[$clave] = true;
                $deEstaFuente++;
                $creados++;

                if (!$dryRun) {
                    $this->em->persist($this->contactoDe($registro));
                }
            }

            $filas[] = [$fuente, count($registros), $deEstaFuente];
        }

        $io->table(['Fuente', 'Filas leídas', 'Contactos nuevos'], $filas);

        if ($dryRun) {
            $io->warning(sprintf('DRY RUN: se crearían %d contactos (%d saltados por repetidos).', $creados, $saltados));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d contactos creados, %d saltados por repetidos.', $creados, $saltados));

        return Command::SUCCESS;
    }

    /**
     * Las claves de los contactos que ya existen, para no duplicarlos al reejecutar.
     *
     * @return array<string, true>
     */
    private function clavesExistentes(Connection $conexion): array
    {
        $claves = [];

        foreach ($conexion->fetchFirstColumn('SELECT telefono FROM maestro_contacto') as $telefono) {
            $clave = $this->clave($telefono);

            if ($clave !== null) {
                $claves[$clave] = true;
            }
        }

        return $claves;
    }

    /** Los últimos 8 dígitos del número normalizado, o `null` si no hay número usable. */
    private function clave(?string $telefono): ?string
    {
        $limpio = $this->telefonos->cleanPhoneNumber(trim((string) $telefono), 'PE');

        if (strlen($limpio) < 8) {
            return null;
        }

        return substr($limpio, -8);
    }

    /**
     * Las tres fuentes, de la más rica a la más pobre.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function fuentes(Connection $conexion): array
    {
        return [
            'pms_reserva' => $conexion->fetchAllAssociative(
                "SELECT nombre_cliente AS nombre, apellido_cliente AS apellido, telefono,
                        telefono2, telefono2_es_principal, email_cliente AS email,
                        pais_id, idioma_id
                   FROM pms_reserva
                  WHERE telefono IS NOT NULL AND telefono <> ''
                  ORDER BY created_at DESC"
            ),
            'cotizacion_file' => $conexion->fetchAllAssociative(
                "SELECT nombre_grupo AS nombre, NULL AS apellido, telefono, NULL AS telefono2,
                        0 AS telefono2_es_principal, email, NULL AS pais_id, idioma_id
                   FROM cotizacion_file
                  WHERE telefono IS NOT NULL AND telefono <> ''"
            ),
            'msg_conversation' => $conexion->fetchAllAssociative(
                "SELECT guest_name AS nombre, NULL AS apellido, guest_phone AS telefono,
                        NULL AS telefono2, 0 AS telefono2_es_principal, NULL AS email,
                        NULL AS pais_id, idioma_id
                   FROM msg_conversation
                  WHERE guest_phone IS NOT NULL AND guest_phone <> ''
                  ORDER BY last_message_at DESC"
            ),
        ];
    }

    /** @param array<string, mixed> $registro */
    private function contactoDe(array $registro): MaestroContacto
    {
        $contacto = new MaestroContacto();
        $contacto->setNombre($this->recorta($registro['nombre'] ?? null, 120));
        $contacto->setApellido($this->recorta($registro['apellido'] ?? null, 120));
        // Se guarda YA normalizado: es lo que se compara con el remitente de WhatsApp, y un
        // número con espacios o con «+» no casa con nada.
        $contacto->setTelefono($this->telefonos->cleanPhoneNumber((string) $registro['telefono'], 'PE'));
        $contacto->setEmail($this->recorta($registro['email'] ?? null, 180));

        $telefono2 = trim((string) ($registro['telefono2'] ?? ''));

        if ($telefono2 !== '') {
            $contacto->setTelefono2($this->telefonos->cleanPhoneNumber($telefono2, 'PE'));
            $contacto->setTelefono2EsPrincipal((bool) ($registro['telefono2_es_principal'] ?? false));
        }

        // Las referencias van por `getReference` y no cargando la entidad: son maestros con id
        // natural y esto puede recorrer cientos de filas.
        if (!empty($registro['pais_id'])) {
            $contacto->setPais($this->em->getReference(\App\Entity\Maestro\MaestroPais::class, $registro['pais_id']));
        }

        if (!empty($registro['idioma_id'])) {
            $contacto->setIdioma($this->em->getReference(\App\Entity\Maestro\MaestroIdioma::class, $registro['idioma_id']));
        }

        return $contacto;
    }

    private function recorta(?string $valor, int $max): ?string
    {
        $valor = trim((string) $valor);

        if ($valor === '') {
            return null;
        }

        // Las columnas de origen son más anchas que las del contacto en algún caso; cortar aquí
        // evita que una sola fila larga tumbe el flush entero al final del recorrido.
        return mb_substr($valor, 0, $max);
    }
}
