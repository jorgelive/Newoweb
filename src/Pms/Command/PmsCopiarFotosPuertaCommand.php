<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsUnidad;
use App\Pms\Entity\PmsUnidadMedia;
use App\Pms\Enum\PmsUnidadMediaTipo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Sube a `PmsUnidadMedia` las fotos de puerta que ya estaban en la galería de su ítem de guía.
 *
 * Paso 5 del plan de `docs/PmsGuiaHuesped.md` §3.c, la mitad que sí se puede automatizar: los
 * croquis hay que rehacerlos a mano —uno por casita, con sólo su número—, pero **las fotos de las
 * puertas ya existen y son buenas**.
 *
 * ── Cómo distingue la foto del croquis, sin una lista escrita a mano ────────
 * El croquis actual es **el mismo archivo subido siete veces**: mismos 73.686 bytes y el mismo
 * hash en las siete casitas. Eso lo delata sin ambigüedad, y además es la prueba de por qué este
 * plan existe — cambiarlo hoy son siete subidas.
 *
 * Así que la regla es: **un CONTENIDO que aparece en más de una casita es el croquis; lo demás son
 * fotos de esa puerta**. El hash no se escribe en el código, se calcula al vuelo: el día que el
 * croquis cambie, el comando sigue acertando.
 *
 * ⚠️ **Por contenido y no por nombre**: cada subida a la galería genera un nombre nuevo, así que el
 * mismo dibujo está guardado con siete nombres distintos. Comparar nombres no excluía nada — lo
 * sacó el `--dry-run`, que dio las siete casitas como ambiguas.
 *
 * ⚠️ **Donde hay más de una foto, NO elige.** La Casita 4 tiene dos —la puerta en primer plano y
 * el pasaje con la flecha señalándola— y las dos son razonables. Elegir por ella sería decidir con
 * menos información que la persona que las hizo: se informa y se salta.
 *
 * ⚠️ **Copia el archivo, no lo mueve.** La galería se queda intacta hasta que el ítem pase a
 * escribir `{{ foto_puerta }}`; borrar antes dejaría el ítem sin imagen si algo sale mal. Cuando
 * el ítem la referencie, la copia de la galería se quita (§3.c: un dato en dos sitios es un dato
 * que un día se cambia en uno solo).
 *
 * Idempotente: una casita que ya tiene su `FOTO_PUERTA` se salta.
 *
 * Uso:
 *   php bin/console app:pms:copiar-fotos-puerta --dry-run
 *   php bin/console app:pms:copiar-fotos-puerta
 */
#[AsCommand(
    name: 'app:pms:copiar-fotos-puerta',
    description: 'Copia a los medios de cada casita la foto de su puerta que ya está en la galería.'
)]
final class PmsCopiarFotosPuertaCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire(param: 'app.public_dir')] private readonly string $publicDir,
        #[Autowire(param: 'pms.path.galeria_images')] private readonly string $rutaGaleria,
        #[Autowire(param: 'pms.path.unidad_images')] private readonly string $rutaUnidad,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dice qué copiaría, sin copiar.')
            ->addOption(
                'elegir',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Desempata una casita con varias fotos: --elegir="Casita 4=archivo.webp". Repetible.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seco = (bool) $input->getOption('dry-run');

        // El desempate lo da una persona, casita por casita. No hay criterio automático que sirva:
        // en la Casita 4 las dos fotos eran de su puerta y la vieja lo era por la fecha de la obra,
        // no por nada que esté en el archivo.
        $elegidas = [];

        foreach ((array) $input->getOption('elegir') as $par) {
            [$casita, $archivo] = array_pad(explode('=', (string) $par, 2), 2, '');

            if (trim($casita) === '' || trim($archivo) === '') {
                $io->error(sprintf('No entiendo «%s». Se escribe --elegir="Casita 4=archivo.webp".', (string) $par));

                return Command::FAILURE;
            }

            $elegidas[trim($casita)] = trim($archivo);
        }

        $candidatas = $this->candidatas();

        if ($candidatas === []) {
            $io->success('No hay ninguna foto de puerta en galerías de ítems «Puerta del Departamento».');

            return Command::SUCCESS;
        }

        // ⚠️ Se agrupa por CONTENIDO, no por nombre de archivo. Cada subida a la galería genera un
        // nombre nuevo (`TOKEN_fecha.webp`), así que el croquis —el mismo dibujo subido siete
        // veces— tiene siete nombres distintos y un solo hash. Agrupar por nombre no excluía nada:
        // lo cazó el `--dry-run`, que sacó las siete casitas como «elige una a mano».
        $vecesPorContenido = [];
        $hashPorArchivo = [];

        foreach ($candidatas as $fila) {
            $ruta = $this->publicDir . $this->rutaGaleria . '/' . $fila['archivo'];
            $hash = is_file($ruta) ? (md5_file($ruta) ?: '') : '';

            $hashPorArchivo[$fila['archivo']] = $hash;

            if ($hash !== '') {
                $vecesPorContenido[$hash] = ($vecesPorContenido[$hash] ?? 0) + 1;
            }
        }

        /** @var array<string, list<string>> $fotosPorUnidad */
        $fotosPorUnidad = [];

        foreach ($candidatas as $fila) {
            $hash = $hashPorArchivo[$fila['archivo']] ?? '';

            if ($hash !== '' && ($vecesPorContenido[$hash] ?? 0) > 1) {
                continue;
            }

            $fotosPorUnidad[$fila['unidadId']][] = $fila['archivo'];
        }

        $filas = [];
        $copiadas = 0;

        foreach ($fotosPorUnidad as $unidadId => $archivos) {
            /** @var PmsUnidad|null $unidad */
            $unidad = $this->em->getRepository(PmsUnidad::class)->find($unidadId);

            if ($unidad === null) {
                continue;
            }

            $nombre = (string) $unidad->getNombre();

            if ($unidad->medio(PmsUnidadMediaTipo::FOTO_PUERTA) !== null) {
                $filas[] = [$nombre, '—', 'ya tenía su foto: se salta'];
                continue;
            }

            if (count($archivos) > 1) {
                $elegida = $elegidas[$nombre] ?? null;

                if ($elegida === null) {
                    $filas[] = [$nombre, implode(' · ', $archivos), 'TIENE ' . count($archivos) . ': elige una con --elegir'];
                    continue;
                }

                if (!in_array($elegida, $archivos, true)) {
                    $filas[] = [$nombre, $elegida, 'la elegida NO es una de sus fotos'];
                    continue;
                }

                $archivos = [$elegida];
            }

            $archivo = $archivos[0];
            $origen = $this->publicDir . $this->rutaGaleria . '/' . $archivo;
            $destino = $this->publicDir . $this->rutaUnidad . '/' . $archivo;

            if (!is_file($origen)) {
                $filas[] = [$nombre, $archivo, 'el archivo NO está en disco'];
                continue;
            }

            if ($seco) {
                $filas[] = [$nombre, $archivo, 'se copiaría'];
                continue;
            }

            if (!is_file($destino) && !@copy($origen, $destino)) {
                $filas[] = [$nombre, $archivo, 'NO se pudo copiar el archivo'];
                continue;
            }

            $medio = new PmsUnidadMedia();
            $medio->setUnidad($unidad);
            $medio->setTipo(PmsUnidadMediaTipo::FOTO_PUERTA);
            $medio->setImageName($archivo);
            $medio->setImageUpdatedAt(new \DateTimeImmutable());

            $this->em->persist($medio);
            $copiadas++;
            $filas[] = [$nombre, $archivo, 'copiada'];
        }

        if (!$seco && $copiadas > 0) {
            $this->em->flush();
        }

        $io->table(['Casita', 'Archivo', 'Resultado'], $filas);

        if ($seco) {
            $io->note('Modo seco: no se ha copiado nada.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d fotos de puerta copiadas a los medios de su casita.', $copiadas));

        return Command::SUCCESS;
    }

    /**
     * Las imágenes de los ítems «Puerta del Departamento», con la casita a la que pertenece cada
     * una. En SQL porque el camino ítem → sección → guía → unidad son cuatro saltos para leer dos
     * columnas.
     *
     * @return list<array{unidadId: string, archivo: string}>
     */
    private function candidatas(): array
    {
        // ⚠️ `BIN_TO_UUID` y no `HEX`: los UUID se guardan en `binary(16)` y `find()` espera la
        // forma con guiones. Con el hex pelado no da error — devuelve `null`, que se lee como
        // «esa unidad no existe» (`CLAUDE.md`).
        $sql = "SELECT BIN_TO_UUID(u.id) AS unidadId, g.image_name AS archivo
                  FROM pms_guia_item i
                  JOIN pms_guia_item_galeria g ON g.item_id = i.id
                  JOIN pms_guia_seccion_has_item shi ON shi.item_id = i.id
                  JOIN pms_guia_has_seccion ghs ON ghs.seccion_id = shi.seccion_id
                  JOIN pms_guia gu ON gu.id = ghs.guia_id
                  JOIN pms_unidad u ON u.id = gu.unidad_id
                 WHERE JSON_UNQUOTE(JSON_EXTRACT(i.titulo, '$[0].content')) LIKE '%uerta del Departamento%'
                   AND COALESCE(g.image_name, '') <> ''
                 ORDER BY u.numero, g.orden";

        /** @var list<array{unidadId: string, archivo: string}> $filas */
        $filas = $this->em->getConnection()->fetchAllAssociative($sql);

        return $filas;
    }
}
