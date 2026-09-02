<?php

declare(strict_types=1);

namespace App\Dominio;

use App\Dominio\Contrato\OperacionDominioInterface;
use App\Dominio\Excepcion\DominioNoDisponible;
use App\Service\Config\Parametro;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * La ÚNICA puerta por la que PHP invoca una regla que vive en TypeScript.
 *
 * ── Es infraestructura, no dominio ──────────────────────────────────────────
 * ⚠️ **Es al cálculo lo que el `EntityManager` es a la persistencia.** Lo inyectan las clases de
 * dominio —`src/Cotizacion/…`, una skill en `Agent/Skill/Cotizacion/`— y **nunca** el núcleo de
 * `src/Agent/`, `src/Exchange/` o `src/Message/`. Si aparece ahí, el núcleo transversal ha
 * absorbido conocimiento de un dominio y el siguiente cálculo pedirá un `if`.
 *
 * Se comprueba con una línea:
 *
 *     grep -rn "EjecutorDeDominio" src/Agent src/Exchange src/Message
 *
 * Todo lo que salga tiene que estar dentro de una carpeta de dominio.
 *
 * ── Node calcula; PHP persiste ──────────────────────────────────────────────
 * Entra un objeto plano, sale otro. El proceso no toca la base de datos, y esa frontera es la que
 * mantiene barato el segundo lenguaje: un `dominio/` roto deja de calcular, pero no corrompe nada.
 *
 * ── Por qué un proceso por llamada y no un servicio HTTP ────────────────────
 * Medido: ~50 ms por invocación (150 ms la primera). Para un PDF o un correo no lo nota nadie, y
 * se ahorra entero el coste que `docs/NodeEnElStack.md` §6 señala como el peor — **un proceso más
 * que puede caerse, y caerse callado**. El día que una medición diga que el arranque estorba, se
 * cambia el transporte AQUÍ DENTRO y ningún llamador se entera: para eso hay una sola puerta.
 *
 * ⚠️ Por eso el contrato es **siempre un lote**, aunque lleve un elemento: `N × 50 ms` dentro de
 * un bucle sí duele, y la salida es invocar una vez con N entradas, no N veces.
 *
 * ── ⚠️ `node` no está en el PATH de php-fpm ─────────────────────────────────
 * En producción Node vive en nvm, así que la ruta se configura (`DOMINIO_NODE_BINARIO`). Con el
 * valor por defecto —`node` a secas— funciona en local y **falla en el servidor**, que es
 * exactamente el tipo de fallo que este proyecto persigue: el mensaje de `Process` lo dice claro
 * y aquí se re-lanza nombrando el binario, para que nadie pierda una tarde.
 */
final readonly class EjecutorDeDominio
{
    /** Sin límite no hay fallo: hay una petición colgada. */
    private const float TIMEOUT_SEGUNDOS = 30.0;

    /**
     * Cuántas invocaciones simultáneas se permiten.
     *
     * ⚠️ **El límite de esta máquina no es el tiempo: es la memoria.** Medido en el servidor:
     * 1.835 MB totales y ~838 MB libres, con 2 CPU. Cada proceso de Node ronda los 45 MB, así que
     * una ráfaga de veinte peticiones se llevaría toda la RAM libre — y lo que se cae entonces no
     * es el PDF, es el servidor entero.
     *
     * Tres es holgado para lo que hay hoy (un PDF tarda ~120 ms) y deja el 90 % de la memoria
     * libre para lo que de verdad la necesita. Si algún día esto se queda corto, la respuesta
     * **no es subir el número**: es pasar a un servicio residente, que consume ~60 MB fijos pase
     * lo que pase. Ver `docs/NodeEnElStack.md` §10.
     */
    private const int MAX_SIMULTANEOS = 3;

    /** Cuánto se espera por una ranura antes de rendirse. Un PDF tarda ~120 ms. */
    private const int ESPERA_MAX_MS = 1500;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,

        #[Autowire(env: 'default::DOMINIO_NODE_BINARIO')]
        private mixed $nodeBinario,

        private LoggerInterface $dominioLogger,
    ) {
    }

    /**
     * Ejecuta una operación sobre un lote de entradas y devuelve un lote de salidas.
     *
     * @param list<mixed> $entradas
     *
     * @return list<mixed> Una salida por entrada, en el mismo orden.
     *
     * @throws DominioNoDisponible si el proceso falla, tarda de más, o responde algo ilegible.
     */
    public function ejecutar(OperacionDominioInterface $operacion, array $entradas): array
    {
        if ($entradas === []) {
            return [];
        }

        $binario = Parametro::texto($this->nodeBinario ?? 'node', 'DOMINIO_NODE_BINARIO');
        $guion = $this->projectDir . '/dominio/' . $operacion->puntoDeEntrada();

        if (!is_file($guion)) {
            throw new DominioNoDisponible(sprintf('No existe el punto de entrada «%s».', $guion));
        }

        $peticion = json_encode(
            ['contrato' => $operacion->contrato(), 'entradas' => $entradas],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        // `--experimental-strip-types`: Node ejecuta el `.ts` sin paso de empaquetado. Comprobado
        // en producción (22.22). Por eso `dominio/` tiene `erasableSyntaxOnly`: un `enum` compila
        // en Vite y muere aquí.
        $proceso = new Process([$binario, '--experimental-strip-types', $guion], $this->projectDir);
        $proceso->setInput($peticion);
        $proceso->setTimeout(self::TIMEOUT_SEGUNDOS);

        // ⚠️ La ranura se pide ANTES de arrancar el proceso y se suelta pase lo que pase. Sin el
        // `finally`, un fallo dejaría la ranura ocupada para siempre y el tope se iría cerrando
        // solo hasta que nadie pudiera calcular nada — un fallo que empeora con el tiempo.
        $ranura = $this->tomarRanura($operacion);
        $inicio = microtime(true);

        try {
            $proceso->run();
        } catch (ProcessTimedOutException) {
            fclose($ranura);

            throw new DominioNoDisponible(sprintf(
                'La operación «%s» pasó de %.0f s y se cortó.',
                $operacion->contrato(),
                self::TIMEOUT_SEGUNDOS,
            ));
        } finally {
            if (is_resource($ranura)) {
                fclose($ranura);
            }
        }

        $ms = (int) round((microtime(true) - $inicio) * 1000);

        if (!$proceso->isSuccessful()) {
            $motivo = trim($proceso->getErrorOutput()) ?: 'sin salida de error';

            $this->dominioLogger->error('Dominio: la operación falló', [
                'contrato' => $operacion->contrato(),
                'entradas' => count($entradas),
                'ms' => $ms,
                'salida_codigo' => $proceso->getExitCode(),
                'motivo' => $motivo,
            ]);

            // ⚠️ El binario se nombra en el mensaje a propósito: el fallo más probable en un
            // servidor es que `node` no esté en el PATH de php-fpm, y sin el nombre delante ese
            // diagnóstico cuesta una tarde.
            throw new DominioNoDisponible(sprintf(
                'La operación «%s» falló (binario «%s»): %s',
                $operacion->contrato(),
                $binario,
                $motivo,
            ));
        }

        try {
            /** @var array{contrato?: string, salidas?: list<mixed>} $respuesta */
            $respuesta = json_decode($proceso->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new DominioNoDisponible(sprintf(
                'La operación «%s» respondió algo que no es JSON: %s',
                $operacion->contrato(),
                $e->getMessage(),
            ));
        }

        // El módulo también comprueba el contrato; aquí se comprueba la vuelta. Las dos mitades,
        // porque un desajuste tiene que gritar en cualquier dirección.
        if (($respuesta['contrato'] ?? null) !== $operacion->contrato()) {
            throw new DominioNoDisponible(sprintf(
                'La operación «%s» respondió con el contrato «%s».',
                $operacion->contrato(),
                $respuesta['contrato'] ?? '(ninguno)',
            ));
        }

        $salidas = $respuesta['salidas'] ?? null;

        // ⚠️ Una salida por entrada, o no se sabe cuál es cuál. Un lote descuadrado devolvería
        // resultados asignados a la cotización equivocada, que es peor que no devolver nada.
        if (!is_array($salidas) || count($salidas) !== count($entradas)) {
            throw new DominioNoDisponible(sprintf(
                'La operación «%s» devolvió %s salidas para %d entradas.',
                $operacion->contrato(),
                is_array($salidas) ? (string) count($salidas) : '(ninguna lista de)',
                count($entradas),
            ));
        }

        $this->dominioLogger->info('Dominio: operación resuelta', [
            'contrato' => $operacion->contrato(),
            'entradas' => count($entradas),
            'ms' => $ms,
        ]);

        return $salidas;
    }

    /**
     * Toma una de las `MAX_SIMULTANEOS` ranuras, o se rinde.
     *
     * `flock` sobre N archivos y no un contador: un contador en disco o en caché se queda
     * desincronizado en cuanto un proceso muere a mitad, y entonces el tope se cierra solo sin
     * que nadie entienda por qué. Un bloqueo del sistema operativo lo suelta el kernel cuando el
     * proceso se va, pase lo que pase.
     *
     * @return resource El manejador; cerrarlo suelta la ranura.
     *
     * @throws DominioNoDisponible si no hay ninguna libre a tiempo.
     */
    private function tomarRanura(OperacionDominioInterface $operacion): mixed
    {
        $dir = $this->projectDir . '/var/dominio';

        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new DominioNoDisponible(sprintf('No se pudo crear «%s» para el tope de concurrencia.', $dir));
        }

        $limite = microtime(true) + (self::ESPERA_MAX_MS / 1000);

        do {
            for ($i = 0; $i < self::MAX_SIMULTANEOS; ++$i) {
                $fh = @fopen($dir . '/ranura-' . $i . '.lock', 'c');

                if ($fh === false) {
                    continue;
                }

                if (flock($fh, LOCK_EX | LOCK_NB)) {
                    return $fh;
                }

                fclose($fh);
            }

            usleep(25_000);
        } while (microtime(true) < $limite);

        $this->dominioLogger->warning('Dominio: sin ranura libre', [
            'contrato' => $operacion->contrato(),
            'max' => self::MAX_SIMULTANEOS,
        ]);

        // Falla CERRADO, que es la política: mejor no responder que tumbar la máquina.
        throw new DominioNoDisponible(sprintf(
            'La operación «%s» no encontró ranura libre (máx. %d simultáneas).',
            $operacion->contrato(),
            self::MAX_SIMULTANEOS,
        ));
    }

    /**
     * Azúcar para el caso de una sola entrada, que es el más común.
     *
     * ⚠️ No es un atajo que se salte el lote: por debajo manda una lista de uno. Que exista este
     * método es lo que evita que alguien invente un camino distinto para «sólo una».
     */
    public function ejecutarUna(OperacionDominioInterface $operacion, mixed $entrada): mixed
    {
        return $this->ejecutar($operacion, [$entrada])[0];
    }
}
