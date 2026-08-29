<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Vuelos;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionVuelo;
use App\Cotizacion\Enum\GrupoTipoEnum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aplica a un expediente los vuelos que declara un archivo de localizadores.
 *
 * ## El archivo va POR PNR, porque así llega la información
 *
 * La aerolínea no escribe «el DM6771 se movió»: escribe sobre **un localizador**. Por eso el
 * archivo declara, para cada PNR, el estado completo de sus vuelos:
 *
 *     [{ "localizador": "AAAAA",
 *        "pnr_nuevo":   "YMFLHB",          // opcional: Sky emitió y cambió el código
 *        "emitido":     true,              // opcional
 *        "vuelos": [{
 *          "numero": "H2 5002", "fecha": "2026-09-17", "aerolinea": "Sky Airline",
 *          "segmentos": [{ "numero": "H2 5002", "origen": "CUZ", "destino": "LIM",
 *                          "salida": "2026-09-17 06:50", "llegada": "2026-09-17 08:35" }],
 *          "notas": ["…"]
 *        }] }]
 *
 * ⚠️ **El expediente NO va dentro del archivo**: se importa DESDE un expediente, así que lo
 * pone el contexto. La palabra «localizador» significa aquí una sola cosa —el PNR de la
 * aerolínea—, que es lo que significa en el sector.
 *
 * ## Los tres cambios que manda una aerolínea, y cómo caen
 *
 * | Lo que pasó | Qué hace esto |
 * |---|---|
 * | cambia el horario | actualiza los segmentos del vuelo; sus otros PNRs no se tocan |
 * | el PNR se reubica en otro vuelo | el vínculo pasa al otro; el vuelo sigue igual |
 * | **el vuelo se traslada de fecha** | el PNR apunta al vuelo nuevo, y el viejo queda sin nadie |
 *
 * ⚠️ El tercero es el que obliga a que **los vínculos del PNR se REEMPLACEN**, no se sumen: el
 * archivo declara dónde viaja ese localizador hoy, y lo que no aparece deja de valer. Un vuelo
 * que se queda sin ningún PNR no se borra —puede haber sido un traslado a medias— pero se avisa.
 *
 * ## Lo que nunca hace
 *
 * No crea localizadores. Un PNR que no existe en el expediente se reporta y se salta: crearlo
 * convertiría una errata en un grupo huérfano sin pasajeros, que es la familia de fallo que
 * este modelo viene a cerrar.
 */
final class VuelosImportador
{
    /**
     * Los vuelos del expediente durante UNA importación, indexados por `numero|fecha`.
     *
     * ⚠️ Sin esto, `findOneBy()` no encuentra lo que aún no se ha volcado y cada localizador
     * crea su propio vuelo: los cuatro PNRs de Copa creaban cuatro `CM264` distintos, y al
     * aplicar reventaba el índice único. El repositorio consulta la base; lo persistido en
     * esta misma pasada todavía no está allí.
     *
     * @var array<string, CotizacionVuelo>
     */
    private array $indice = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<array<string, mixed>> $reservas
     */
    public function importar(CotizacionFile $file, array $reservas, bool $aplicar): ResultadoVuelos
    {
        $r = new ResultadoVuelos();
        $this->indice = [];

        foreach ($this->em->getRepository(CotizacionVuelo::class)->findBy(['file' => $file]) as $vuelo) {
            $this->indice[$this->llave((string) $vuelo->getNumero(), $vuelo->getFecha())] = $vuelo;
        }

        foreach ($reservas as $reserva) {
            $this->aplicarReserva($file, $reserva, $r);
        }

        $this->avisarVuelosVacios($file, $r);

        if ($aplicar && $r->hayAlgoQueHacer()) {
            $this->em->flush();
        } elseif (!$aplicar) {
            $this->em->clear();
        }

        return $r;
    }

    /**
     * @param array<string, mixed> $reserva
     */
    private function aplicarReserva(CotizacionFile $file, array $reserva, ResultadoVuelos $r): void
    {
        $clave = trim((string) ($reserva['localizador'] ?? ''));

        if ($clave === '') {
            $r->problema('Una reserva sin «localizador»: se salta.');

            return;
        }

        $grupo = $this->buscarGrupo($file, $clave);

        if ($grupo === null) {
            $r->problema(sprintf('%s no existe en el expediente: no se crea.', $clave));

            return;
        }

        $this->renombrar($file, $grupo, $reserva, $r);

        if (isset($reserva['emitido']) && (bool) $reserva['emitido'] !== $grupo->isEmitido()) {
            $r->cambio(sprintf(
                '%s · emitido: %s → %s',
                $grupo->getClave(),
                $grupo->isEmitido() ? 'sí' : 'no',
                $reserva['emitido'] ? 'sí' : 'no',
            ));
            $grupo->setEmitido((bool) $reserva['emitido']);
        }

        if (!isset($reserva['vuelos']) || !is_array($reserva['vuelos'])) {
            return;
        }

        $declarados = [];

        foreach ($reserva['vuelos'] as $def) {
            if (!is_array($def)) {
                continue;
            }

            $vuelo = $this->upsertVuelo($file, $def, $r);

            if ($vuelo !== null) {
                $declarados[] = $vuelo;
            }
        }

        $this->reemplazarVinculos($file, $grupo, $declarados, $r);
    }

    /**
     * El código provisional pasa a ser el definitivo, y los pasajeros ni se enteran: cuelgan
     * del grupo, no de la clave.
     *
     * @param array<string, mixed> $reserva
     */
    private function renombrar(CotizacionFile $file, CotizacionFileGrupo $grupo, array $reserva, ResultadoVuelos $r): void
    {
        $nuevo = trim((string) ($reserva['pnr_nuevo'] ?? ''));

        if ($nuevo === '' || $nuevo === $grupo->getClave()) {
            return;
        }

        // ⚠️ Si el destino ya existe son DOS reservas distintas, con su gente cada una. Fundirlas
        // en silencio perdería a unos u otros, así que se para.
        if ($this->buscarGrupo($file, $nuevo) !== null) {
            $r->problema(sprintf('%s → %s: el código destino ya existe. No se renombra.', $grupo->getClave(), $nuevo));

            return;
        }

        $r->cambio(sprintf(
            '%s → %s   (%d pax siguen en la reserva)',
            $grupo->getClave(),
            $nuevo,
            $grupo->getMiembros()->count(),
        ));
        $grupo->setClave($nuevo);
    }

    /**
     * @param array<string, mixed> $def
     */
    private function upsertVuelo(CotizacionFile $file, array $def, ResultadoVuelos $r): ?CotizacionVuelo
    {
        if (!isset($def['numero'], $def['fecha'])) {
            $r->problema('Un vuelo sin «numero» o «fecha»: se salta.');

            return null;
        }

        $numero = trim((string) $def['numero']);

        try {
            $fecha = new \DateTimeImmutable((string) $def['fecha']);
        } catch (\Exception) {
            $r->problema(sprintf('%s: fecha ilegible «%s».', $numero, (string) $def['fecha']));

            return null;
        }

        $llave = $this->llave($numero, $fecha);
        $vuelo = $this->indice[$llave] ?? null;

        if ($vuelo === null) {
            $vuelo = (new CotizacionVuelo())->setFile($file)->setNumero($numero)->setFecha($fecha);
            $this->em->persist($vuelo);
            $this->indice[$llave] = $vuelo;
            $r->cambio(sprintf('vuelo nuevo: %s · %s', $numero, $fecha->format('d/m')));
        }

        if (isset($def['aerolinea']) && (string) $def['aerolinea'] !== (string) $vuelo->getAerolinea()) {
            $vuelo->setAerolinea((string) $def['aerolinea']);
        }

        if (isset($def['segmentos']) && is_array($def['segmentos'])) {
            $nuevos = $this->normalizarSegmentos($def['segmentos']);

            if ($this->canonico($nuevos) !== $this->canonico($vuelo->getSegmentos())) {
                foreach ($this->compararItinerarios($vuelo->getSegmentos(), $nuevos) as $linea) {
                    $r->cambio(sprintf('%s · %s   %s', $numero, $fecha->format('d/m'), $linea));
                }
                $vuelo->setSegmentos($nuevos);
            }
        }

        if (isset($def['notas']) && is_array($def['notas'])) {
            $vuelo->setNotas(array_values(array_map(static fn ($n): string => (string) $n, $def['notas'])));
        }

        return $vuelo;
    }

    /**
     * El archivo declara DÓNDE viaja este PNR hoy: lo que no aparece deja de valer.
     *
     * @param list<CotizacionVuelo> $declarados
     */
    private function reemplazarVinculos(CotizacionFile $file, CotizacionFileGrupo $grupo, array $declarados, ResultadoVuelos $r): void
    {
        $quitados = [];

        foreach ($this->vuelosDelExpediente($file) as $vuelo) {
            $estaba = $vuelo->getGrupos()->contains($grupo);
            $debe = in_array($vuelo, $declarados, true);

            if ($estaba && !$debe) {
                $vuelo->removeGrupo($grupo);
                $quitados[] = sprintf('%s·%s', $vuelo->getNumero(), $vuelo->getFecha()?->format('d/m'));
            } elseif (!$estaba && $debe) {
                $vuelo->addGrupo($grupo);
                $r->cambio(sprintf('%s ahora viaja en %s · %s', $grupo->getClave(), $vuelo->getNumero(), $vuelo->getFecha()?->format('d/m')));
            }
        }

        if ($quitados !== []) {
            $r->cambio(sprintf('%s deja de viajar en %s', $grupo->getClave(), implode(', ', $quitados)));
        }
    }

    /**
     * Un vuelo sin ningún localizador ya no lleva a nadie: casi siempre es el resto de un
     * traslado de fecha. No se borra —podría ser un cambio a medias— pero se dice.
     */
    private function avisarVuelosVacios(CotizacionFile $file, ResultadoVuelos $r): void
    {
        foreach ($this->vuelosDelExpediente($file) as $vuelo) {
            if ($vuelo->getGrupos()->isEmpty()) {
                $r->aviso(sprintf(
                    '%s · %s no tiene ningún localizador: nadie viaja en él.',
                    $vuelo->getNumero(),
                    $vuelo->getFecha()?->format('d/m/Y'),
                ));
            }
        }
    }

    /** @return list<CotizacionVuelo> */
    private function vuelosDelExpediente(CotizacionFile $file): array
    {
        return array_values($this->indice);
    }

    private function llave(string $numero, ?\DateTimeImmutable $fecha): string
    {
        return $numero . '|' . ($fecha?->format('Y-m-d') ?? '');
    }

    private function buscarGrupo(CotizacionFile $file, string $clave): ?CotizacionFileGrupo
    {
        foreach ($file->getGrupos() as $grupo) {
            if ($grupo->getTipo() === GrupoTipoEnum::RESERVA_AEREA && $grupo->getClave() === $clave) {
                return $grupo;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $crudos
     * @return list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}>
     */
    private function normalizarSegmentos(array $crudos): array
    {
        $salida = [];

        foreach ($crudos as $s) {
            if (!is_array($s)) {
                continue;
            }

            $salida[] = [
                'numero' => trim((string) ($s['numero'] ?? '')),
                'origen' => strtoupper(trim((string) ($s['origen'] ?? ''))),
                'destino' => strtoupper(trim((string) ($s['destino'] ?? ''))),
                'salida' => trim((string) ($s['salida'] ?? '')),
                'llegada' => trim((string) ($s['llegada'] ?? '')),
            ];
        }

        return $salida;
    }

    /**
     * ⚠️ **MySQL reordena las claves de un objeto JSON al guardarlo** —por longitud y luego
     * alfabéticamente— y el `!==` de PHP sobre arrays sí mira ese orden. Comparar en crudo daba
     * «cambia» en los catorce vuelos con el antes y el después idénticos en pantalla.
     *
     * @param list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}> $segmentos
     */
    private function canonico(array $segmentos): string
    {
        $ordenados = array_map(
            static function (array $s): array {
                ksort($s);

                return $s;
            },
            $segmentos,
        );

        return json_encode($ordenados, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}> $antes
     * @param list<array{numero: string, origen: string, destino: string, salida: string, llegada: string}> $ahora
     * @return list<string>
     */
    private function compararItinerarios(array $antes, array $ahora): array
    {
        $pinta = static fn (array $s): string => sprintf(
            '%s %s → %s %s',
            $s['origen'],
            substr($s['salida'], 11) ?: $s['salida'],
            $s['destino'],
            substr($s['llegada'], 11) ?: $s['llegada'],
        );

        if ($antes === []) {
            return [];
        }

        $lineas = [];

        foreach ($ahora as $i => $s) {
            $viejo = $antes[$i] ?? null;

            if ($viejo === null) {
                $lineas[] = 'segmento nuevo: ' . $pinta($s);
            } elseif ($viejo !== $s) {
                $lineas[] = sprintf('%s  ⟶  %s', $pinta($viejo), $pinta($s));
            }
        }

        return $lineas;
    }
}
