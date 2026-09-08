import { ref } from 'vue';
import { apiClient } from '@/services/apiClient';
import { avisoDeTope, type ConfigDelFront } from '@dominio/finanzas';

/**
 * Los números de negocio que decide el SERVIDOR, traídos una vez.
 *
 * 🔥 Existe porque el recargo de tarjeta estaba tecleado en la app del huésped —`const
 * RECARGO_TARJETA_PCT = 5.5`, con un comentario que juraba ser «el único sitio»— y no lo era: el
 * mismo número vive en `services_finanzas.yaml` y en `PmsMedioPago::comisionPorcentaje()`. Se
 * podía subir el recargo en el servidor y la pantalla del huésped seguiría enseñando el cálculo
 * viejo, **sin un solo error**.
 *
 * ⚠️ **La REGLA no está aquí**: está en `@dominio/finanzas/topePorCargo`, que es lo que comparten
 * las dos apps. Esto sólo trae el dato. Poner la fórmula en cada app habría sido repetir el mismo
 * error el día de corregirlo.
 *
 * ⚠️ **Una sola petición por sesión**: el `ref` y la promesa viven en el módulo, no dentro de la
 * función, así que varios componentes que lo pidan a la vez no disparan varias.
 *
 * ⚠️ **Y arranca con los valores de hoy como respaldo.** Si la petición falla, la pantalla sigue
 * calculando —con el número de ayer, mejor que ninguno— en vez de enseñar un cero. Cuando llega
 * la respuesta, gana ella.
 */
const RESPALDO: ConfigDelFront = {
    recargoTarjetaPorcentaje: '5.5',
    limitePorCargo: { USD: '3000', PEN: '10000' },
};

const config = ref<ConfigDelFront>(RESPALDO);
let pedida: Promise<void> | null = null;

export function useConfigDelFront() {
    const cargar = (): Promise<void> => {
        pedida ??= apiClient.get('/config/front')
            .then(({ data }) => {
                if (data?.recargoTarjetaPorcentaje) config.value = data as ConfigDelFront;
            })
            .catch(() => { /* se queda el respaldo: ver la cabecera */ });

        return pedida;
    };

    /** Espejo de `FinEnlacePagoService::comprobarTope()`, compartido en `dominio/`. */
    const superaElTope = (neto: number, moneda: string, conRecargo = true): string | null =>
        avisoDeTope(neto, moneda, config.value, conRecargo);

    return { config, cargar, superaElTope };
}
