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
 *          "leg": { "origen": "CUZ", "destino": "LIM",
 *                   "salida": "2026-09-17 06:50", "llegada": "2026-09-17 08:35" },
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
 * | cambia el horario | actualiza el leg del vuelo; sus otros PNRs no se tocan |
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

        // ⚠️ El ensayo ESCRIBE y deshace, no se limita a mirar.
        //
        // Es el patrón de `PadronImportador`, y por el mismo motivo: un ensayo más permisivo que
        // la carga no sirve de nada. Escribiendo de verdad se comprueban el índice único de
        // `(file, numero, fecha)` y las claves foráneas del puente, que es justo donde falla lo
        // que no se ve leyendo el archivo.
        //
        // Y en HTTP no vale `em->clear()`: desengancharía el propio expediente que el
        // controlador tiene en la mano.
        $this->em->getConnection()->beginTransaction();

        try {
            $this->em->flush();

            if ($aplicar) {
                $this->em->getConnection()->commit();
            } else {
                $this->em->getConnection()->rollBack();
            }
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            $r->problema('No se pudo guardar: ' . $e->getMessage());
        }

        return $r;
    }

    /**
     * @param array<string, mixed> $reserva
     */
    private function aplicarReserva(CotizacionFile $file, array $reserva, ResultadoVuelos $r): void
    {
        // `pnr` es el nombre bueno; `localizador` se acepta porque así se llamó al principio.
        $clave = trim((string) ($reserva['pnr'] ?? $reserva['localizador'] ?? ''));

        if ($clave === '') {
            $r->problema('Una reserva sin «pnr»: se salta.');

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

        if (isset($reserva['notas']) && is_array($reserva['notas'])) {
            $notas = array_values(array_map(static fn ($n): string => (string) $n, $reserva['notas']));

            if ($notas !== $grupo->getNotas()) {
                $r->cambio(sprintf('%s · %d nota(s)', $grupo->getClave(), count($notas)));
                $grupo->setNotas($notas);
            }
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
     * Busca o crea el vuelo y le vuelca lo que el archivo trae.
     *
     * ⚠️ La fecha de identidad sale de `salida`, no de un campo aparte. El archivo trae los dos
     * —`fecha` y `salida`— y son el mismo hecho: dejar que se escriban por separado es pedir que
     * un día discrepen. Aquí manda `salida` y `fecha` se deriva.
     *
     * @param array<string, mixed> $def
     */
    private function upsertVuelo(CotizacionFile $file, array $def, ResultadoVuelos $r): ?CotizacionVuelo
    {
        if (!isset($def['numero'])) {
            $r->problema('Un vuelo sin «numero»: se salta.');

            return null;
        }

        $numero = trim((string) $def['numero']);

        // ⚠️ La palabra es **leg**, no «segmento».
        //
        // En este sistema un `TravelSegmento` es un capítulo del relato de un viaje —«Parque
        // Kennedy», «Piscina y playa»—, y un vuelo no es eso. El término del sector para un salto
        // entre dos aeropuertos es *leg*, y usarlo evita que dos cosas distintas compartan nombre
        // en el mismo modelo.
        //
        // Se acepta como objeto o con los campos sueltos, que es lo que escribiría alguien a mano.
        $leg = $def;

        if (isset($def['leg'])) {
            if (!is_array($def['leg']) || array_is_list($def['leg'])) {
                $r->problema(sprintf(
                    '%s: «leg» es un objeto, no una lista. Un vuelo es UN leg; una conexión son dos vuelos.',
                    $numero,
                ));

                return null;
            }

            $leg = $def['leg'];
        }

        if (isset($def['segmentos'])) {
            $r->problema(sprintf(
                '%s usa «segmentos», que aquí significa otra cosa. Renómbralo a «leg» y ponlo como objeto.',
                $numero,
            ));

            return null;
        }

        $salida = $this->momento($leg['salida'] ?? $def['fecha'] ?? null);

        if ($salida === null) {
            $r->problema(sprintf('%s: sin «salida» legible.', $numero));

            return null;
        }

        $fecha = $salida->setTime(0, 0);
        $llave = $this->llave($numero, $fecha);
        $vuelo = $this->indice[$llave] ?? null;
        $esNuevo = $vuelo === null;

        if ($vuelo === null) {
            $vuelo = (new CotizacionVuelo())->setFile($file)->setNumero($numero)->setSalida($salida);
            $this->em->persist($vuelo);
            $this->indice[$llave] = $vuelo;
            $r->cambio(sprintf('vuelo nuevo: %s · %s', $numero, $fecha->format('d/m')));
        }

        if (isset($def['aerolinea'])) {
            $vuelo->setAerolinea((string) $def['aerolinea']);
        }

        $antes = $this->pinta($vuelo);

        if (isset($leg['origen'])) {
            $vuelo->setOrigen(strtoupper(trim((string) $leg['origen'])));
        }

        if (isset($leg['destino'])) {
            $vuelo->setDestino(strtoupper(trim((string) $leg['destino'])));
        }

        $vuelo->setSalida($salida);

        $llegada = $this->momento($leg['llegada'] ?? null);

        if ($llegada !== null) {
            $vuelo->setLlegada($llegada);
        }

        $despues = $this->pinta($vuelo);

        if (!$esNuevo && $antes !== $despues) {
            $r->cambio(sprintf('%s · %s   %s  ⟶  %s', $numero, $fecha->format('d/m'), $antes, $despues));
        }

        if (isset($def['notas']) && is_array($def['notas'])) {
            $vuelo->setNotas(array_values(array_map(static fn ($n): string => (string) $n, $def['notas'])));
        }

        return $vuelo;
    }

    private function momento(mixed $texto): ?\DateTimeImmutable
    {
        if (!is_string($texto) || trim($texto) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($texto));
        } catch (\Exception) {
            return null;
        }
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

    private function pinta(CotizacionVuelo $v): string
    {
        if ($v->getSalida() === null) {
            return '(vacío)';
        }

        return sprintf(
            '%s %s → %s %s',
            $v->getOrigen(),
            $v->getSalida()->format('H:i'),
            $v->getDestino(),
            $v->getLlegada()?->format('H:i') ?? '?',
        );
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



}
