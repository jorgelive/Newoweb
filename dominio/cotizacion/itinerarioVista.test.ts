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
            const marca = b.esPeriodo
                ? (b.esRepeticion
                    ? ` [${b.unidad} ${b.indiceUnidad}/${b.totalUnidades}]`
                    : ` [periodo ${b.totalUnidades} ${b.unidad}]`)
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
        const estadias = bloques.filter((b) => b.esPeriodo);
        expect(estadias.length).toBeGreaterThan(0);

        // Por cada estadía distinta: tantos bloques como noches, y exactamente una cabecera.
        const porSegmento = new Map<string, typeof estadias>();
        for (const b of estadias) {
            const lista = porSegmento.get(b.segmento.id) ?? [];
            lista.push(b);
            porSegmento.set(b.segmento.id, lista);
        }

        for (const [id, grupo] of porSegmento) {
            expect(grupo, `estadía ${id}`).toHaveLength(grupo[0].totalUnidades);
            expect(grupo.filter((b) => !b.esRepeticion), `cabeceras de ${id}`).toHaveLength(1);
            // Las noches van numeradas 1..N y en orden de fecha.
            expect(grupo.map((b) => b.indiceUnidad)).toEqual(grupo.map((_, i) => i + 1));
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
            const grupos: { clave: string; conHora: boolean; cierraElDia: boolean }[] = [];

            for (const b of dia.bloques) {
                const clave = b.esRepeticion ? `${b.servicio.id}::repeticion` : b.servicio.id;
                const ultimo = grupos.at(-1);

                if (ultimo?.clave === clave) {
                    ultimo.conHora ||= Boolean(b.horaInicio ?? b.horaServicioInicio);
                    ultimo.cierraElDia &&= b.esPeriodo && b.unidad === 'noches';
                } else {
                    grupos.push({
                        clave,
                        conHora: Boolean(b.horaInicio ?? b.horaServicioInicio),
                        cierraElDia: b.esPeriodo && b.unidad === 'noches',
                    });
                }
            }

            // Un grupo aparece UNA vez: si su clave reaparece más tarde, es que se partió.
            expect(new Set(grupos.map((g) => g.clave)).size, `día ${dia.numeroDia} · grupos contiguos`)
                .toBe(grupos.length);

            const escalon = grupos.map((g) => (g.conHora ? 0 : g.cierraElDia ? 2 : 1));
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

    /**
     * El hotel colocado a mano: la PRIMERA noche obedece, las repeticiones cierran igual.
     *
     * Hasta el 07/09/2026 toda estadía se resolvía en el andén 2 antes de mirar el `orden`, así
     * que arrastrar un hotel lo movía en el editor —que no tiene andenes— y en la guía volvía al
     * final: el gesto se guardaba y no hacía nada. Si alguien lo coloca en mitad del día es
     * porque significa algo —el CHECK-IN— y después puede seguir habiendo actividades.
     *
     * Las noches 2 y 3 no son una parada del relato sino un «sigues aquí», y ésas sí cierran.
     */
    it('estadía colocada a mano: la primera noche obedece al operador; las repeticiones cierran', () => {
        const cot = {
            cotservicios: [
                {
                    id: 'hotel', orden: 10, tituloSnapshot: [],
                    cotsegmentos: [{ id: 's-hotel', dia: 1, orden: 1, fechaAbsoluta: '2030-01-01', tituloSnapshot: [] }],
                    cotcomponentes: [{ id: 'c-hotel', cotsegmento: { id: 's-hotel' }, fechaHoraInicio: '2030-01-01T00:00:00', fechaHoraFin: '2030-01-04T00:00:00', sinHorario: true, horaServicioCompleto: false, ordenNarrativo: 90, tituloSnapshot: [] }],
                },
                {
                    id: 'cena', orden: 20, tituloSnapshot: [],
                    cotsegmentos: [{ id: 's-cena', dia: 1, orden: 1, fechaAbsoluta: '2030-01-01', tituloSnapshot: [] }],
                    cotcomponentes: [{ id: 'c-cena', cotsegmento: { id: 's-cena' }, fechaHoraInicio: '2030-01-01T20:00:00', fechaHoraFin: null, sinHorario: false, horaServicioCompleto: false, ordenNarrativo: 50, tituloSnapshot: [] }],
                },
                {
                    id: 'tour', orden: 0, tituloSnapshot: [],
                    cotsegmentos: [{ id: 's-tour', dia: 2, orden: 1, fechaAbsoluta: '2030-01-02', tituloSnapshot: [] }],
                    cotcomponentes: [{ id: 'c-tour', cotsegmento: { id: 's-tour' }, fechaHoraInicio: '2030-01-02T09:00:00', fechaHoraFin: null, sinHorario: false, horaServicioCompleto: false, ordenNarrativo: 30, tituloSnapshot: [] }],
                },
            ],
        };

        const dias = componerItinerario(cot);

        // Día 1, curado: el hotel (orden 10) va ANTES de la cena (orden 20), que es donde el
        // operador lo puso. Antes del arreglo salía al final.
        expect(dias[0]!.bloques.map((b) => b.servicio.id)).toEqual(['hotel', 'cena']);

        // Día 2: la noche 2 del hotel es un «sigues aquí» y cierra, aunque el servicio lleve
        // `orden` 10 heredado del día 1. Y el día 2 NO cuenta como colocado a mano por esa
        // herencia: el tour conserva su sitio por reloj.
        expect(dias[1]!.bloques.map((b) => b.servicio.id)).toEqual(['tour', 'hotel']);
        expect(dias[1]!.bloques.at(-1)!.esRepeticion).toBe(true);
    });
});

describe('noches vs días', () => {
    /**
     * Las mismas dos fechas, dos cantidades. Un hotel del 18 al 22 son 4 noches —el día de salida
     * no se duerme— y un seguro del 18 al 22 son 5 días —el día de salida sigue cubierto—.
     *
     * Hasta el 07/09/2026 sólo existía la primera cuenta, así que para cobrar 5 días hubo que
     * escribir el seguro como «18 → 23»: se torció la fecha para que la resta cuadrara.
     */
    it('el mismo intervalo da 4 noches y 5 días, y el seguro llega al día de salida', () => {
        const periodo = (id: string, unidad: string, sustantivo: string) => ({
            id, orden: 0, tituloSnapshot: [],
            cotsegmentos: [{ id: `s-${id}`, dia: 1, orden: 1, fechaAbsoluta: '2030-01-18', tituloSnapshot: [] }],
            cotcomponentes: [{
                id: `c-${id}`, cotsegmento: { id: `s-${id}` },
                fechaHoraInicio: '2030-01-18T00:00:00', fechaHoraFin: '2030-01-22T00:00:00',
                sinHorario: true, horaServicioCompleto: false, ordenNarrativo: 90,
                unidadDeConteo: unidad, sustantivoUnidad: sustantivo, tituloSnapshot: [],
            }],
        });

        const dias = componerItinerario({
            cotservicios: [periodo('hotel', 'noches', ''), periodo('seguro', 'dias', 'día')],
        });

        const bloquesDe = (id: string) => dias.flatMap((d) => d.bloques).filter((b) => b.servicio.id === id);

        expect(bloquesDe('hotel')).toHaveLength(4);
        expect(bloquesDe('seguro')).toHaveLength(5);

        // El hotel acaba la víspera de la salida; el seguro cubre el día de salida.
        expect(dias.at(-1)!.fecha).toBe('2030-01-22');
        expect(dias.at(-1)!.bloques.map((b) => b.servicio.id)).toEqual(['seguro']);

        expect(bloquesDe('hotel')[0]!.totalUnidades).toBe(4);
        expect(bloquesDe('seguro')[0]!.totalUnidades).toBe(5);
    });

    /**
     * Sólo la cama cierra el día. Lo que se cuenta por días CUBRE la jornada, así que se lee al
     * empezarla: un cliente que recorre su día de arriba abajo y encuentra al pie «incluía el
     * almuerzo» se enteró tarde de algo que ya no puede usar.
     */
    it('el periodo en días no cierra el día; el de noches sí', () => {
        const dias = componerItinerario({
            cotservicios: [
                {
                    id: 'hotel', orden: 0, tituloSnapshot: [],
                    cotsegmentos: [{ id: 's-hotel', dia: 1, orden: 1, fechaAbsoluta: '2030-01-18', tituloSnapshot: [] }],
                    cotcomponentes: [{ id: 'c-hotel', cotsegmento: { id: 's-hotel' }, fechaHoraInicio: '2030-01-18T00:00:00', fechaHoraFin: '2030-01-20T00:00:00', sinHorario: true, horaServicioCompleto: false, ordenNarrativo: 90, unidadDeConteo: 'noches', tituloSnapshot: [] }],
                },
                {
                    id: 'seguro', orden: 0, tituloSnapshot: [],
                    cotsegmentos: [{ id: 's-seguro', dia: 1, orden: 1, fechaAbsoluta: '2030-01-18', tituloSnapshot: [] }],
                    cotcomponentes: [{ id: 'c-seguro', cotsegmento: { id: 's-seguro' }, fechaHoraInicio: '2030-01-18T00:00:00', fechaHoraFin: '2030-01-20T00:00:00', sinHorario: true, horaServicioCompleto: false, ordenNarrativo: 5, unidadDeConteo: 'dias', tituloSnapshot: [] }],
                },
                {
                    id: 'tour', orden: 0, tituloSnapshot: [],
                    cotsegmentos: [{ id: 's-tour', dia: 1, orden: 1, fechaAbsoluta: '2030-01-19', tituloSnapshot: [] }],
                    cotcomponentes: [{ id: 'c-tour', cotsegmento: { id: 's-tour' }, fechaHoraInicio: '2030-01-19T09:00:00', fechaHoraFin: null, sinHorario: false, horaServicioCompleto: false, ordenNarrativo: 30, tituloSnapshot: [] }],
                },
            ],
        });

        // Día 2: el seguro ABRE —cubre la jornada entera, así que se anuncia antes de nada—, el
        // tour va por su hora y la cama cierra. Antes del 07/09/2026 el seguro salía el ÚLTIMO:
        // lo sin-hora se amontonaba detrás de todo lo que tenía reloj.
        expect(dias[1]!.bloques.map((b) => b.servicio.id)).toEqual(['seguro', 'tour', 'hotel']);
        expect(dias[1]!.bloques.at(-1)!.unidad).toBe('noches');
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
