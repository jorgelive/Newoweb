<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsUnidad;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaContexto;
use App\Pms\Guia\PmsGuiaInterpolador;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Busca marcadores que el editor escribió y que NO resuelve nadie.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * `PmsGuiaInterpolador` deja intacta la clave que no conoce, y es lo correcto: es una errata del
 * editor y «tiene que verse en la revisión». El problema es que **nadie revisa**, y el front dejó
 * de interpolar datos, así que debajo no hay red: lo que no se resuelve se le enseña crudo al
 * huésped.
 *
 * 🔥 Pasó el 11/09/2026. Una migración renombró `{{ door_code }}` → `{{ numero_llave }}` con buen
 * motivo, pero la clave que el contexto carga se llama `numero`. Durante un día el ítem que explica
 * cómo sacar la llave decía **«Toma la Llave {{ numero_llave }} del interior»**, en los siete
 * idiomas, el día de la llegada. Ni PHPStan ni los tests podían verlo: el nombre de la clave es
 * contenido, no código.
 *
 * ── Cómo lo mira ────────────────────────────────────────────────────────────
 * **No lleva su propia lista de claves válidas, a propósito.** Una segunda lista es un segundo
 * sitio que se queda atrás — que es exactamente el fallo que este comando busca. Interpola de
 * verdad, con el contexto real de cada casita, y denuncia lo que sobrevive.
 *
 * Se interpola con `PmsGuiaAcceso::paraEvento()` sobre la ventana ABIERTA: un dato tapado no es un
 * fallo, pero sí lo es que el marcador siga ahí cuando ya debería haberse resuelto.
 *
 * Los bloques `{{ tipo: valor }}` que el front pinta —`img`, `video`, `map`, `widget` y sus
 * variantes bloqueadas— no se cuentan: ésos llegan así a propósito.
 *
 * ⚠️ **No arregla nada**: dice qué ítem y qué clave, y devuelve código ≠ 0 para que un cron lo
 * note. Si la clave está mal escrita se corrige el texto; si falta el dato, se carga.
 *
 * Uso: `php bin/console app:pms:verificar-marcadores-guia`
 */
#[AsCommand(
    name: 'app:pms:verificar-marcadores-guia',
    description: 'Busca marcadores de la guía que no resuelve nadie y acabarían crudos ante el huésped.'
)]
final class PmsVerificarMarcadoresGuiaCommand extends Command
{
    /** Bloques que el front pinta: llegan con `:` y es su forma final, no un resto. */
    private const BLOQUES_DEL_FRONT = [
        'img', 'video', 'map', 'widget', 'imgbloqueado', 'videobloqueado',
    ];

    /**
     * Las dos claves sin `:` que el interpolador NO resuelve y aun así son correctas.
     *
     * `RichContentEngine.ts` las normaliza a `{{ widget: X }}` y las pinta con datos que son de
     * otra skill, así que el servidor no tiene por qué conocerlas. Sin esta excepción, el
     * verificador denunciaría los dos ítems que más se usan.
     */
    private const NORMALIZA_EL_FRONT = ['wifi_data', 'medios_pago'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsGuiaInterpolador $interpolador,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<PmsUnidad> $unidades */
        $unidades = $this->em->getRepository(PmsUnidad::class)->findBy(['activo' => true], ['nombre' => 'ASC']);

        $filas = [];

        foreach ($unidades as $unidad) {
            $guia = $unidad->getGuia();

            if ($guia === null || !$guia->isActivo()) {
                continue;
            }

            // Sin evento no hay estancia, así que `construir()` no carga ninguna clave sensible y
            // TODAS saldrían denunciadas. El acceso más permisivo sobre un contexto completo es lo
            // que enseña el estado final: lo que siga sin resolver ahí, no lo resuelve nadie.
            $contexto = PmsGuiaContexto::construir($unidad, null);

            // Los `…Api()` son los SIN filtrar por visibilidad: aquí no se está sirviendo nada, se
            // está revisando. Un marcador roto dentro de un ítem con candado sigue roto.
            foreach ($guia->getSeccionesApi() as $seccion) {
                foreach ($seccion->getItemsApi() as $item) {
                    foreach ($this->sobrantes($item->getDescripcion(), $contexto) as $clave => $idiomas) {
                        $filas[] = [
                            $unidad->getNombre() ?? '—',
                            $item->getNombreInterno() ?? '—',
                            $clave,
                            implode(' ', $idiomas),
                        ];
                    }
                }
            }
        }

        if ($filas === []) {
            $io->success('Ningún marcador se queda sin resolver.');

            return Command::SUCCESS;
        }

        $io->table(['Casita', 'Ítem', 'Marcador huérfano', 'Idiomas'], $filas);
        $io->error(sprintf(
            '%d marcador(es) llegarían crudos al huésped. O la clave está mal escrita, o el dato '
            . 'que debería rellenarla está vacío.',
            count($filas)
        ));

        return Command::FAILURE;
    }

    /**
     * @param list<array{language?: string, content?: string|null}>|null $contenido
     *
     * @return array<string, list<string>> Marcador → idiomas en los que sobrevive.
     */
    private function sobrantes(?array $contenido, PmsGuiaContexto $contexto): array
    {
        if (empty($contenido)) {
            return [];
        }

        $sobrantes = [];

        foreach ($this->interpolador->interpolar($contenido, $contexto, PmsGuiaAcceso::publico()) as $entrada) {
            $idioma = $entrada['language'];

            preg_match_all('/\{\{\s*([a-z0-9_]+)\s*(:)?[^}]*\}\}/i', (string) $entrada['content'], $hallazgos, PREG_SET_ORDER);

            foreach ($hallazgos as $hallazgo) {
                $clave = strtolower($hallazgo[1]);

                // Con `:` es un bloque del front; sin `:` es una clave que nadie resolvió —salvo
                // las dos que el front normaliza por su cuenta.
                if (($hallazgo[2] ?? '') === ':') {
                    if (in_array($clave, self::BLOQUES_DEL_FRONT, true)) {
                        continue;
                    }
                } elseif (in_array($clave, self::NORMALIZA_EL_FRONT, true)) {
                    continue;
                }

                $sobrantes[$hallazgo[1]][$idioma] = $idioma;
            }
        }

        return array_map(static fn (array $i): array => array_values($i), $sobrantes);
    }
}
