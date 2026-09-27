import { components } from '@dominio/api';

/**
 * Un pedido pendiente de cotizar (`App\Cotizacion\Entity\CotizacionPedido`, ver
 * `docs/Cotizaciones.md`, «Pendientes de cotizar»).
 *
 * Sin `id` propio en el esquema —como `PmsPeticion`, su hermana en el PMS—: API Platform no lo
 * expone porque la identidad viaja en `@id` (la IRI), que es lo que se usa para el PATCH que lo
 * marca hecho a mano.
 */
export type ApiCotizacionPedido = components['schemas']['CotizacionPedido-cotizacion_pedido.read'] & {
    '@id'?: string;
};
