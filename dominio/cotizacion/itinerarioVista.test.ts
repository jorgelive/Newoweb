import { describe, it, expect } from 'vitest';
import { componerItinerario, type ServicioMinimo, type DiaVista } from './itinerarioVista';
import cot2KVBMX from './__fixtures__/2KVBMX.json';
import cotVQ2EG5 from './__fixtures__/VQ2EG5.json';
import cot5SRAJV from './__fixtures__/5SRAJV.json';

/**
 * Fija el comportamiento de la composición del itinerario **antes** de moverla a ningún sitio.
 *
 * ── Por qué existe, y por qué con datos reales ──────────────────────────────
 * `docs/NodeEnElStack.md` §5 lo exige antes de tocar lógica que se va a compartir: guardar la
 * salida de hoy y exigir que la de mañana sea idéntica. Estos son los primeros tests de
 * TypeScript del proyecto — hasta hoy había 540 en PHP y **cero** aquí, así que mover cálculo a
 * TS era moverlo del lado con red al lado sin ella.
 *
 * Los fixtures son **cotizaciones reales de producción**, podadas a los doce campos que el
 * módulo lee. Se podaron y no se inventaron porque un caso de juguete no tiene lo que rompe: la
 * estadía de tres noches dentro de un servicio con actividades, el componente promovido a
 * servicio completo, el día con siete paradas.
 *
 * ⚠️ **Los snapshots son el contrato, no un adorno.** Si un cambio los mueve, hay que mirar el
 * diff y decidir: o es una mejora que se acepta a conciencia, o es una regresión. Actualizarlos
 * a ciegas con `-u` vacía el propósito entero de este archivo.
 */

// ⚠️ **Aquí ya no hay ningún `as`, y eso es el test de verdad del contrato.** Hasta el
// 02/09/2026 el módulo declaraba su entrada como la serialización pública entera de `pax`, así
// que un fixture con los doce campos que de verdad lee **no encajaba** y había que afirmarlo.
// Que estos JSON compilen tal cual demuestra que el contrato describe lo que el módulo usa y no
// la forma de un consumidor concreto — que es lo que permite que `util` lo llame con los suyos.

/** La forma legible de un itinerario: lo que se compara en los snapshots. */
const resumir = <S extends ServicioMinimo>(dias: DiaVista<S>[]): string[] =>
    dias.flatMap((dia) => [
        `── Día ${dia.numeroDia} · ${dia.fecha}`,
        ...dia.bloques.map((b) => {
            const hora = b.horaServicioInicio ?? b.horaInicio ?? '  —  ';
            const titulos = (b.segmento as { tituloSnapshot?: { content?: string | null }[] }).tituloSnapshot;
            const titulo = titulos?.find((t) => t.content)?.content ?? '(sin título)';
            const marca = b.esEstadia
                ? (b.esRepeticion ? ` [noche ${b.noche}/${b.totalNoches}]` : ` [estadía ${b.totalNoches}n]`)
                : '';
            return `   ${String(hora).padEnd(6)} ${titulo}${marca}`;
        }),
    ]);

describe('componerItinerario · cotizaciones reales', () => {
    it('2KVBMX v2 — 16 días con estadías encadenadas', () => {
        const dias = componerItinerario(cot2KVBMX);

        // El número de días es la aserción que más grita: un viaje con hoteles pierde los días
        // intermedios en cuanto la regla de estadías se rompe. La primera versión del PDF en PHP
        // daba 11 en vez de 16, y ése es exactamente el fallo que este número vigila.
        expect(dias).toHaveLength(16);
        expect(resumir(dias)).toMatchSnapshot();
    });

    it('VQ2EG5 v1 — 7 días sin estadías', () => {
        const dias = componerItinerario(cotVQ2EG5);

        expect(dias).toHaveLength(7);
        expect(resumir(dias)).toMatchSnapshot();
    });
});

describe('las tres reglas que no se adivinan leyendo las entidades', () => {
    const dias = componerItinerario(cot2KVBMX);
    const bloques = dias.flatMap((d) => d.bloques);

    it('una estadía se repite cada día de su periodo, y sólo la primera no es repetición', () => {
        const estadias = bloques.filter((b) => b.esEstadia);
        expect(estadias.length).toBeGreaterThan(0);

        // Por cada estadía distinta: tantos bloques como noches, y exactamente una cabecera.
        const porSegmento = new Map<string, typeof estadias>();
        for (const b of estadias) {
            const lista = porSegmento.get(b.segmento.id) ?? [];
            lista.push(b);
            porSegmento.set(b.segmento.id, lista);
        }

        for (const [id, grupo] of porSegmento) {
            expect(grupo, `estadía ${id}`).toHaveLength(grupo[0].totalNoches);
            expect(grupo.filter((b) => !b.esRepeticion), `cabeceras de ${id}`).toHaveLength(1);
            // Las noches van numeradas 1..N y en orden de fecha.
            expect(grupo.map((b) => b.noche)).toEqual(grupo.map((_, i) => i + 1));
        }
    });

    it('la hora de un bloque es min(inicio) de sus componentes con hora, no la del primero', () => {
        for (const b of bloques) {
            const inicios = b.componentes
                .filter((c) => c.sinHorario !== true && !c.horaServicioCompleto && c.fechaHoraInicio)
                .map((c) => (c.fechaHoraInicio as string).substring(11, 16));

            if (inicios.length === 0) continue;

            expect(b.horaInicio, `bloque ${b.key}`).toBe([...inicios].sort()[0]);
        }
    });

    it('un componente «servicio completo» NO estira su segmento: su hora se promueve aparte', () => {
        const conPromo = bloques.filter((b) => b.horaServicioInicio !== null);
        expect(conPromo.length).toBeGreaterThan(0);

        for (const b of conPromo) {
            // La hora promovida sale de un componente marcado, esté o no en ESTE bloque.
            expect(b.horaServicioInicio).toMatch(/^\d{2}:\d{2}$/);
            // Y nunca es la de un componente promovido del propio bloque metida en `horaInicio`.
            const promovidosAqui = b.componentes.filter((c) => c.horaServicioCompleto);
            for (const c of promovidosAqui) {
                const suya = (c.fechaHoraInicio ?? '').substring(11, 16);
                if (suya && suya !== '00:00') {
                    expect(b.horaInicio, `bloque ${b.key} no debe adoptar la hora promovida`).not.toBe(suya);
                }
            }
        }

        // Se promueve UNA vez por servicio y día: el primer bloque lo lleva, los demás no.
        for (const dia of dias) {
            const porServicio = new Map<string, number>();
            for (const b of dia.bloques) {
                if (b.horaServicioInicio) {
                    porServicio.set(b.servicio.id, (porServicio.get(b.servicio.id) ?? 0) + 1);
                }
            }
            for (const [id, veces] of porServicio) {
                expect(veces, `servicio ${id} en el día ${dia.numeroDia}`).toBe(1);
            }
        }
    });
});

describe('el orden del día', () => {
    it('sin orden manual: con hora primero (cronológico), luego sin hora, estadías al final', () => {
        // ⚠️ El escalonado ordena **grupos**, no bloques sueltos, y la diferencia no es sutil: un
        // servicio se coloca por su hora más temprana y **todo lo suyo se pinta seguido**, así que
        // dentro de un grupo conviven bloques con hora y sin ella. Comprobarlo bloque a bloque
        // falla contra datos correctos — lo hizo al escribir este test.
        for (const dia of componerItinerario(cot2KVBMX)) {
            const grupos: { clave: string; conHora: boolean; esEstadia: boolean }[] = [];

            for (const b of dia.bloques) {
                const clave = b.esRepeticion ? `${b.servicio.id}::repeticion` : b.servicio.id;
                const ultimo = grupos.at(-1);

                if (ultimo?.clave === clave) {
                    ultimo.conHora ||= Boolean(b.horaInicio ?? b.horaServicioInicio);
                    ultimo.esEstadia &&= b.esEstadia;
                } else {
                    grupos.push({
                        clave,
                        conHora: Boolean(b.horaInicio ?? b.horaServicioInicio),
                        esEstadia: b.esEstadia,
                    });
                }
            }

            // Un grupo aparece UNA vez: si su clave reaparece más tarde, es que se partió.
            expect(new Set(grupos.map((g) => g.clave)).size, `día ${dia.numeroDia} · grupos contiguos`)
                .toBe(grupos.length);

            const escalon = grupos.map((g) => (g.conHora ? 0 : g.esEstadia ? 2 : 1));
            expect(escalon, `día ${dia.numeroDia}`).toEqual([...escalon].sort((a, b) => a - b));
        }
    });

    /**
     * La rama «día colocado a mano» sobre datos REALES: `5SRAJV` tiene tres servicios con
     * `orden` 10, 20 y 30 puestos por un operador —vuelo, escala en Lima, varios en el
     * aeropuerto—, que es exactamente el caso que el itinerario tiene que respetar.
     *
     * Es la rama que más cuesta si se rompe: el operador coloca el día y el huésped lo lee en
     * otro orden. Estuvo cubierta sólo por el caso mínimo de abajo hasta que apareció esta
     * cotización en la base de pruebas.
     */
    it('con orden manual, sobre datos reales: el orden del operador se respeta', () => {
        const dias = componerItinerario(cot5SRAJV);

        // Los servicios ordenados a mano conviven en un mismo día con otros sin `orden`.
        const conOrden = cot5SRAJV.cotservicios.filter((s) => (s.orden ?? 0) > 0).map((s) => s.id);
        expect(conOrden).toHaveLength(3);

        for (const dia of dias) {
            const ordenes = dia.bloques
                .filter((b) => conOrden.includes(b.servicio.id))
                .map((b) => b.servicio.orden as number);

            // Dentro de un día, los colocados a mano nunca aparecen en orden decreciente.
            expect(ordenes, `día ${dia.numeroDia}`).toEqual([...ordenes].sort((a, b) => a - b));
        }

        expect(resumir(dias)).toMatchSnapshot();
    });

    /**
     * El caso mínimo, sintético a propósito: dos servicios y nada más, para que la regla quede
     * fijada sin depender de la forma de ninguna cotización concreta. El de arriba prueba que
     * ocurre en producción; éste prueba QUÉ hace exactamente.
     */
    it('con orden manual: manda el orden de la persona, no el reloj', () => {
        const cot = {
            cotservicios: [
                {
                    id: 'tarde', orden: 1, tituloSnapshot: [],
                    cotsegmentos: [{ id: 's-tarde', dia: 1, orden: 1, fechaAbsoluta: '2030-01-01', tituloSnapshot: [] }],
                    cotcomponentes: [{ id: 'c-tarde', cotsegmento: { id: 's-tarde' }, fechaHoraInicio: '2030-01-01T18:00:00', fechaHoraFin: null, sinHorario: false, horaServicioCompleto: false, ordenNarrativo: 30, tituloSnapshot: [] }],
                },
                {
                    id: 'mañana', orden: 2, tituloSnapshot: [],
                    cotsegmentos: [{ id: 's-manana', dia: 1, orden: 1, fechaAbsoluta: '2030-01-01', tituloSnapshot: [] }],
                    cotcomponentes: [{ id: 'c-manana', cotsegmento: { id: 's-manana' }, fechaHoraInicio: '2030-01-01T08:00:00', fechaHoraFin: null, sinHorario: false, horaServicioCompleto: false, ordenNarrativo: 30, tituloSnapshot: [] }],
                },
            ],
        };

        const [dia] = componerItinerario(cot);

        // Las 18:00 van primero porque alguien lo colocó así. Si mandara la hora, saldría 08:00.
        expect(dia.bloques.map((b) => b.horaInicio)).toEqual(['18:00', '08:00']);
    });
});

describe('bordes', () => {
    it('sin cotización, sin días', () => {
        expect(componerItinerario(null)).toEqual([]);
        expect(componerItinerario(undefined)).toEqual([]);
        expect(componerItinerario({ cotservicios: [] })).toEqual([]);
    });

    /**
     * `esPrimeroDelServicioEnElDia` sustituyó a dos flags de pantalla que el módulo devolvía
     * —`mostrarTituloServicio` y `mostrarAccionInclusiones`—. De este hecho estructural cuelgan
     * los dos, y cualquier consumidor futuro colgará el suyo; el módulo ya no sabe qué se pinta.
     */
    it('exactamente un bloque por servicio y día lleva la marca de «primero»', () => {
        for (const dia of componerItinerario(cot2KVBMX)) {
            const marcados = dia.bloques.filter((b) => b.esPrimeroDelServicioEnElDia);
            const serviciosDelDia = new Set(dia.bloques.map((b) => b.servicio.id));

            // Uno por servicio presente, ni más ni menos.
            expect(marcados, `día ${dia.numeroDia}`).toHaveLength(serviciosDelDia.size);
            expect(new Set(marcados.map((b) => b.servicio.id))).toEqual(serviciosDelDia);

            // Y es el PRIMERO en el orden ya compuesto, no uno cualquiera.
            const vistos = new Set<string>();
            for (const b of dia.bloques) {
                expect(b.esPrimeroDelServicioEnElDia, `bloque ${b.key}`).toBe(!vistos.has(b.servicio.id));
                vistos.add(b.servicio.id);
            }
        }
    });

    it('la hora promovida se adjunta al primero del servicio, no a los demás', () => {
        for (const dia of componerItinerario(cot2KVBMX)) {
            for (const b of dia.bloques) {
                if (b.horaServicioInicio) {
                    expect(b.esPrimeroDelServicioEnElDia, `bloque ${b.key}`).toBe(true);
                }
            }
        }
    });
});
