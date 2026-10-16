/**
 * El adelanto para reservar, invocable desde PHP: la puerta de `montoDeAdelanto()`.
 *
 * Lo pide el servidor al emitir el enlace de pago del adelanto de una cotización
 * (`CotizacionEnlaceService`). Es la MISMA función que usan el editor de util y el bloque
 * «Cómo reservar» de pax: si PHP calculara su propio 50 %, el enlace podría pedir un céntimo
 * distinto del que el cliente tiene delante — y el que no cuadra es siempre el que se paga.
 *
 * Mismo molde que `itinerario.cli.ts`: un archivo por operación, siempre en lote, contrato
 * versionado que revienta si no casa.
 *
 *   echo '{"contrato":"adelanto@1","entradas":[{"total":139.99,"porcentaje":50}]}' \
 *     | node --experimental-strip-types dominio/cotizacion/adelanto.cli.ts
 */
import { montoDeAdelanto, saldoTrasAdelanto } from './adelanto.ts';

export const CONTRATO = 'adelanto@1';

interface Peticion {
  contrato?: string;
  entradas?: { total?: number; porcentaje?: number }[];
}

/** La parte pura, exportada para el test. Devuelve el error como valor; `main()` decide la salida. */
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

  const malas = peticion.entradas.filter((e) => typeof e?.total !== 'number' || typeof e?.porcentaje !== 'number');
  if (malas.length > 0) {
    // Un `undefined` aquí daría un adelanto de 0 con toda tranquilidad: mejor reventar.
    return { ok: false, error: 'Cada entrada necesita «total» y «porcentaje» numéricos.' };
  }

  return {
    ok: true,
    cuerpo: {
      contrato: CONTRATO,
      salidas: peticion.entradas.map((e) => ({
        adelanto: montoDeAdelanto(e.total as number, e.porcentaje as number),
        saldo: saldoTrasAdelanto(e.total as number, e.porcentaje as number),
      })),
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
if (process.argv[1]?.endsWith('adelanto.cli.ts')) {
  await main();
}
