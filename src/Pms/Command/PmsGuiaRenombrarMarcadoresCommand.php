<?php

declare(strict_types=1);

namespace App\Pms\Command;

use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pone en las fichas de la guía los nombres de marcador que usan las plantillas.
 *
 * ## Por qué se unifican, y por qué se mueve ESTE lado
 *
 * El mismo dato se llamaba distinto según dónde se escribiera —`host_whatsapp` en la guía,
 * `whatsapp_numero` en las plantillas— y, peor, **`check_in` era la HORA mientras `checkin_date`
 * era la FECHA**: dos nombres casi iguales para cosas distintas, en dos sistemas que el mismo
 * editor usa el mismo día.
 *
 * Manda el vocabulario de las plantillas aunque la guía sea anterior, y no es una preferencia:
 * **el nombre de un marcador de una plantilla aprobada en Meta no se puede cambiar** —hacerlo es
 * crear otra plantilla y esperar el bloqueo de 30 días, §18 de `docs/Mensajeria.md`— y
 * `guest_name`, `checkin_date` y `checkout_date` ya viven dentro de cuerpos aprobados. El lado
 * que se mueve es el que se puede mover.
 *
 * ## Y el botón deja de llevar el teléfono dentro
 *
 * «Necesito ayuda» de la ficha de las llaves apuntaba a `https://wa.me/51961281953` escrito a
 * mano: la copia que nadie sincroniza el día que ese número cambie. Pasa a `{{ emergencia_url }}`,
 * que sale de `PmsEstablecimiento::$telefonoEmergencia` — desde que los botones interpolan
 * ({@see \App\Pms\Guia\PmsGuiaArbolFiltro}).
 *
 * ## Por qué comando y no SQL
 *
 * `titulo` y `descripcion` llevan `#[AutoTranslate]`. Un `UPDATE` se salta el listener y deja las
 * siete traducciones con el marcador viejo — que es exactamente el fallo que este comando viene a
 * cerrar. Se sustituye **en todos los idiomas**, no sólo en el español: así el texto queda bien
 * incluso si una fila está marcada como traducción curada a mano y el listener no la rehace.
 *
 * Idempotente: si no queda ningún marcador viejo, no escribe nada.
 */
#[AsCommand(
    name: 'app:pms:guia:renombrar-marcadores',
    description: 'Unifica los marcadores de la guía con los de las plantillas. Idempotente.',
)]
final class PmsGuiaRenombrarMarcadoresCommand extends Command
{
    /**
     * Viejo → nuevo. El orden importa: `check_in` es prefijo de nada, pero `start_date` y
     * `end_date` tienen que sustituirse antes de que nadie los confunda con las horas.
     *
     * @var array<string, string>
     */
    private const array MARCADORES = [
        'unit_name' => 'room_name',
        'hotel_name' => 'property_name',
        'host_whatsapp' => 'whatsapp_numero',
        'booking_ref' => 'locator',
        'check_in' => 'hora_checkin',
        'check_out' => 'hora_checkout',
        'start_date' => 'checkin_date',
        'end_date' => 'checkout_date',
    ];

    /** El botón que llevaba el número escrito dentro del enlace. */
    private const string URL_VIEJA = 'https://wa.me/51961281953';
    private const string URL_NUEVA = '{{ emergencia_url }}';

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');
        $filas = [];

        foreach ($this->em->getRepository(PmsGuiaItem::class)->findAll() as $item) {
            $cambios = [];

            foreach (['getTitulo' => 'setTitulo', 'getDescripcion' => 'setDescripcion'] as $lee => $escribe) {
                $contenido = $item->$lee();

                if (!is_array($contenido)) {
                    continue;
                }

                $nuevo = $this->renombrarEnI18n($contenido, $cambios);

                if ($nuevo !== $contenido && !$simular) {
                    $item->$escribe($nuevo);
                }
            }

            $url = $item->getUrlBotonCruda();

            if ($url !== null && str_contains($url, self::URL_VIEJA)) {
                $cambios[] = 'botón → ' . self::URL_NUEVA;

                if (!$simular) {
                    $item->setUrlBoton(str_replace(self::URL_VIEJA, self::URL_NUEVA, $url));
                }
            }

            if ($cambios !== []) {
                $filas[] = [(string) $item->getNombreInterno(), implode(', ', array_unique($cambios))];
            }
        }

        if ($filas === []) {
            $io->success('No queda ningún marcador viejo.');

            return Command::SUCCESS;
        }

        $io->table(['Ficha', 'Qué cambia'], $filas);

        if ($simular) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        // Un solo flush: `AutoTranslate` corre en `preUpdate` y rehace los idiomas cuyo
        // `origenHash` deje de casar con el español nuevo.
        $this->em->flush();
        $io->success(sprintf('%d fichas actualizadas.', count($filas)));

        return Command::SUCCESS;
    }

    /**
     * Sustituye en TODOS los idiomas de una lista i18n, tolerando los espacios de dentro de las
     * llaves (`{{clave}}` y `{{ clave }}` son el mismo marcador para el interpolador).
     *
     * @param list<array{language?: string, content?: string|null}> $contenido
     * @param list<string> $cambios Se rellena con lo que se tocó, para poder enseñarlo.
     * @return list<array{language?: string, content?: string|null}>
     */
    private function renombrarEnI18n(array $contenido, array &$cambios): array
    {
        foreach ($contenido as $i => $fila) {
            $texto = (string) ($fila['content'] ?? '');

            if ($texto === '' || !str_contains($texto, '{{')) {
                continue;
            }

            foreach (self::MARCADORES as $viejo => $nuevo) {
                $patron = '/\{\{\s*' . preg_quote($viejo, '/') . '\s*\}\}/i';

                if (preg_match($patron, $texto) === 1) {
                    $texto = (string) preg_replace($patron, '{{ ' . $nuevo . ' }}', $texto);
                    $cambios[] = $viejo . ' → ' . $nuevo;
                }
            }

            $contenido[$i]['content'] = $texto;
        }

        return $contenido;
    }
}
