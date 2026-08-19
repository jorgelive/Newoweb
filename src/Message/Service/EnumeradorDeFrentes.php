<?php

declare(strict_types=1);

namespace App\Message\Service;

use App\Contract\Frente;
use App\Message\Contract\FrentesPorDominioInterface;
use App\Contract\MomentoDeFrente;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Los asuntos abiertos de quien escribe, de TODOS los negocios, más las puertas de venta.
 *
 * Es la pieza transversal del modelo de frentes: recorre las implementaciones registradas por
 * tag ({@see FrentesPorDominioInterface}), junta lo vivo de cada negocio y añade al final la
 * venta sintética de cada negocio vendible.
 *
 * ── Por qué la venta va SIEMPRE, para todos ─────────────────────────────────
 * Podría añadirse sólo cuando el mensaje huele a compra, y sería peor: obligaría a decidir
 * «¿esto es una compra?» ANTES de clasificar el mensaje, que es justo lo que el triaje hace
 * mejor. Poniéndola siempre, dos casos que parecían excepciones se resuelven con la regla
 * normal —un cliente de tours preguntando por casitas, y un huésped que quiere ampliar— y el
 * prospecto sin nada deja de ser un caso aparte: simplemente ve sólo las puertas.
 *
 * Cuesta una línea por negocio en el bloque volátil del prompt. Es barato y es la diferencia
 * entre un agente que sabe vender y uno que sólo atiende.
 */
final class EnumeradorDeFrentes
{
    /**
     * Tope de asuntos con entidad que se le enseñan al modelo.
     *
     * No es una optimización prematura: el bloque de frentes viaja en la parte VOLÁTIL del
     * prompt, se paga entero en cada turno y no se cachea. Un cliente veterano con quince
     * expedientes convertiría cada mensaje —incluido un «gracias»— en una factura de contexto.
     *
     * Se enseñan los primeros que devuelva cada dominio —cada uno ordena los suyos por
     * relevancia, el PMS con «en curso antes que próxima»— y el resto se resume en una línea,
     * para que el modelo SEPA que hay más en vez de creer que la lista es todo. Si de verdad
     * hablaba de uno de los que no salen, el camino largo tiene skills para buscarlo.
     */
    private const MAX_CON_ENTIDAD = 5;

    /**
     * Cuántos asuntos con entidad se quedaron fuera del tope en la última enumeración.
     *
     * Es lo único que esta clase recuerda entre `paraTelefono()` y `bloqueParaElPrompt()`, y por
     * eso no es `readonly`. La alternativa —devolver una tupla o un objeto lista— ensuciaba las
     * cinco llamadas para transportar un entero que sólo usa el bloque de texto.
     */
    private int $ocultos = 0;

    /**
     * @param iterable<FrentesPorDominioInterface> $dominios
     */
    public function __construct(
        #[AutowireIterator('app.message.frentes_dominio')]
        private readonly iterable $dominios
    ) {}

    /**
     * Todo lo elegible por este teléfono: asuntos vivos primero, puertas de venta al final.
     *
     * El `porDefecto` se marca aquí y no en cada dominio porque sólo puede haber uno global:
     * es el primer asunto con entidad que devuelva el primer dominio que tenga algo. Si no hay
     * ninguno —un desconocido—, no se marca nada y el triaje elige por el mensaje, que es lo
     * correcto: con dos puertas de venta y cero historia, no hay ningún defecto razonable.
     *
     * @return list<Frente>
     */
    public function paraTelefono(?string $telefono): array
    {
        $conEntidad = [];
        $ventas = [];

        foreach ($this->dominios as $dominio) {
            foreach ($dominio->frentesVivos($telefono) as $frente) {
                $conEntidad[] = $frente;
            }

            // Indexado por negocio: dos implementaciones del mismo —el día que el alojamiento
            // se parta en servicios— pintarían dos puertas idénticas Y CON EL MISMO ID, porque
            // el hash no las distingue. `porId()` devolvería la primera y nadie lo notaría.
            if ($dominio->esVendible()) {
                $ventas[$dominio->negocio()] = new Frente(
                    negocio: $dominio->negocio(),
                    momento: MomentoDeFrente::Venta,
                    etiqueta: $dominio->etiquetaDeVenta(),
                );
            }
        }

        $this->ocultos = max(0, count($conEntidad) - self::MAX_CON_ENTIDAD);
        $conEntidad = array_slice($conEntidad, 0, self::MAX_CON_ENTIDAD);

        if ($conEntidad !== []) {
            $conEntidad[0] = $conEntidad[0]->marcadoPorDefecto();
        }

        return array_values([...$conEntidad, ...$ventas]);
    }

    /**
     * El bloque que se le enseña al modelo. **Va en el contexto VOLÁTIL, nunca en las reglas
     * cacheadas**: depende de quién escribe, y meterlo en el prefijo con marca de caché
     * significaría una caché por cliente, que es peor que no cachear.
     *
     * @param list<Frente> $frentes
     */
    public function bloqueParaElPrompt(array $frentes): string
    {
        if ($frentes === []) {
            return '';
        }

        $bloque = "FRENTES (asuntos abiertos de quien escribe):\n"
            . implode("\n", array_map(static fn (Frente $f): string => $f->comoLinea(), $frentes));

        // Decir que hay más importa: sin esta línea, el truncado es mudo y el modelo da la
        // lista por completa. Si el cliente habla del sexto asunto, elegiría otro frente
        // convencido de que acierta.
        if ($this->ocultos > 0) {
            $bloque .= sprintf(
                "\n(y %d asunto(s) más que no caben aquí: si habla de otro, pregúntale cuál)",
                $this->ocultos
            );
        }

        return $bloque;
    }

    /**
     * Resuelve lo que eligió el modelo contra la lista real de este turno.
     *
     * Lista blanca estricta: un id que no esté aquí se descarta. Es el mismo blindaje que ya
     * se aplica a la skill que el triaje propone, y por el mismo motivo — un modelo puede
     * inventarse un identificador con toda la seguridad del mundo.
     *
     * @param list<Frente> $frentes
     */
    public function porId(array $frentes, ?string $id): ?Frente
    {
        $id = trim((string) $id);

        if ($id === '') {
            return null;
        }

        foreach ($frentes as $frente) {
            if ($frente->id() === $id) {
                return $frente;
            }
        }

        return null;
    }

    /**
     * El frente que se toma cuando no hay elección válida: el marcado por defecto, o ninguno.
     *
     * Devolver `null` es un resultado legítimo y no una degradación: sin asuntos abiertos no
     * hay nada que asumir, y el comportamiento de hoy —resolver el dominio por el
     * `context_type` de la conversación— sigue siendo el fallo seguro.
     *
     * @param list<Frente> $frentes
     */
    public function porDefecto(array $frentes): ?Frente
    {
        foreach ($frentes as $frente) {
            if ($frente->porDefecto) {
                return $frente;
            }
        }

        return null;
    }

    /**
     * A qué negocios llega quien escribe desde este contexto: lo vendible **más** el negocio
     * del contexto en el que está. Es lo que alimenta {@see \App\Agent\Access\ActorInterface::dominios()}.
     *
     * ── Por qué no se miran los asuntos vivos aquí ──────────────────────────
     * Podría calcularse consultando de verdad qué tiene abierto ese teléfono, y sería más
     * exacto y más caro: una consulta por negocio **en cada construcción de actor**, incluido
     * el turno en el que alguien escribe «gracias». No compensa, porque el resultado apenas
     * cambiaría: la venta ya está abierta para todos, así que lo único que aporta el asunto
     * vivo es el negocio en el que ya se está — y ése lo dice el `context_type` sin ir a la
     * base de datos.
     *
     * El día que un actor necesite ver el catálogo de un negocio en el que tiene algo vivo
     * pero por cuyo contexto no entró, lo dará el frente que elija el triaje, que sí enumera
     * los asuntos de verdad.
     *
     * @return list<string>
     */
    public function dominiosPara(?string $contextType): array
    {
        $dominios = $this->negociosVendibles();

        foreach ($this->dominios as $dominio) {
            if ($dominio->supports($contextType)) {
                $dominios[] = $dominio->negocio();
            }
        }

        return array_values(array_unique($dominios));
    }

    /**
     * Los negocios que se le pueden vender a cualquiera.
     *
     * Es lo que impide que el filtro de dominio deje sin skills de venta a quien todavía no ha
     * comprado nada: sin esto, un prospecto —que por definición no tiene asuntos vivos— se
     * quedaría con el catálogo vacío justo cuando más falta hace atenderlo.
     *
     * @return list<string>
     */
    public function negociosVendibles(): array
    {
        $negocios = [];

        foreach ($this->dominios as $dominio) {
            if ($dominio->esVendible()) {
                $negocios[] = $dominio->negocio();
            }
        }

        return array_values(array_unique($negocios));
    }
}
