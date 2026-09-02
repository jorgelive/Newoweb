/**
 * El itinerario, invocable desde fuera del navegador. Es la puerta por la que PHP entra.
 *
 * ── Un archivo de entrada por operación, y ningún despachador ────────────────
 * ⚠️ Lo natural sería un solo `ejecutar.ts <operacion>` con un `switch` nombre → función. Eso es
 * un `match` por dominio disfrazado, en el otro lenguaje: cada operación nueva tocaría un archivo
 * compartido, que es justo lo que este proyecto evita con `#[AutowireIterator]` en PHP. En su
 * lugar hay **un `.cli.ts` por operación**, y la clase PHP nombra el suyo. Nadie mantiene una lista.
 *
 * ── Siempre en LOTE, aunque sea de uno ──────────────────────────────────────
 * ⚠️ Entra `{contrato, entradas: []}` y sale `{contrato, salidas: []}` incluso para una sola
 * cotización. Arrancar `node` cuesta ~50 ms: irrelevante para un PDF, y **N × 50 ms dentro de un
 * runner de Exchange que procesa N elementos**. Cambiar la forma después obligaría a tocar todas
 * las operaciones y todos los llamadores; ponerla ahora cuesta cero.
 *
 * ── El contrato se versiona, y el desajuste REVIENTA ────────────────────────
 * ⚠️ Sin versión, un campo renombrado en PHP llega como `undefined` y el resultado sale mal **sin
 * un solo error** — la familia de fallo que persigue este proyecto. La versión es **por
 * operación**, no global: con diez operaciones, una global se subiría cada semana y dejaría de
 * significar nada.
 *
 * ── Cómo se prueba a mano ───────────────────────────────────────────────────
 *   echo '{"contrato":"itinerario@1","entradas":[{"cotservicios":[]}]}' \
 *     | node --experimental-strip-types dominio/cotizacion/itinerario.cli.ts
 */
import { componerItinerario, type ServicioMinimo } from './itinerarioVista.ts';

export const CONTRATO = 'itinerario@1';

interface Peticion {
  contrato?: string;
  entradas?: { cotservicios?: ServicioMinimo[] | null }[];
}

/**
 * La parte pura: se exporta para poder probarla sin tocar stdin.
 *
 * ⚠️ Devuelve el error como VALOR, no lo lanza. Quien decide el código de salida es `main()`, y
 * así el mismo camino sirve para el test y para el proceso.
 */
export function responder(peticion: Peticion): { ok: true; cuerpo: unknown } | { ok: false; error: string } {
  if (peticion?.contrato !== CONTRATO) {
    return {
      ok: false,
      error: `Contrato incompatible: se esperaba «${CONTRATO}» y llegó «${peticion?.contrato ?? '(ninguno)'}». `
        + 'Quien invoca y este módulo no están de acuerdo en la forma del dato.',
    };
  }

  if (!Array.isArray(peticion.entradas)) {
    return { ok: false, error: 'Falta «entradas», que tiene que ser una lista aunque lleve un solo elemento.' };
  }

  return {
    ok: true,
    cuerpo: {
      contrato: CONTRATO,
      salidas: peticion.entradas.map((cot) => componerItinerario(cot)),
    },
  };
}

async function leerEntrada(): Promise<string> {
  const trozos: Buffer[] = [];
  for await (const t of process.stdin) trozos.push(t as Buffer);
  return Buffer.concat(trozos).toString('utf8');
}

async function main(): Promise<void> {
  let peticion: Peticion;

  try {
    peticion = JSON.parse(await leerEntrada());
  } catch (e) {
    process.stderr.write(`Entrada ilegible: ${e instanceof Error ? e.message : String(e)}\n`);
    process.exitCode = 1;
    return;
  }

  const r = responder(peticion);

  if (!r.ok) {
    // ⚠️ El motivo va por stderr y el código de salida a 1: **nunca un resultado a medias por
    // stdout**. Quien llama distingue «falló» de «salió vacío» sin tener que adivinar.
    process.stderr.write(r.error + '\n');
    process.exitCode = 1;
    return;
  }

  process.stdout.write(JSON.stringify(r.cuerpo));
}

// Sólo cuando se ejecuta como proceso; importarlo desde un test no lee stdin ni cierra nada.
if (process.argv[1]?.endsWith('itinerario.cli.ts')) {
  await main();
}
