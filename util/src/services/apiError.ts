import axios, { type AxiosError } from 'axios';

/**
 * Errores de la API en un solo sitio.
 *
 * Vivía dentro de `reservasStore` y cada store se hacía su propia versión a mano
 * (`err.response?.data?.['hydra:description'] || …`), siempre con `catch (err: any)`.
 * Aquí queda tipado una vez: `unknown` en la firma obliga a estrechar, que es
 * justo lo que el `any` se saltaba.
 */

/** Cuerpo de error de API Platform (RFC 7807 + Hydra + violaciones de validación). */
export interface ErrorApiPayload {
    'hydra:description'?: string;
    detail?: string;
    title?: string;
    violations?: Array<{ propertyPath?: string; message?: string }>;
}

/** ¿Es un error de red/HTTP de axios, y no un fallo de programación? */
export const esErrorApi = (err: unknown): err is AxiosError<ErrorApiPayload> => axios.isAxiosError(err);

/**
 * Mensaje legible de un error de API Platform.
 *
 * Orden de preferencia: violaciones de validación (lo más concreto, 422) →
 * `detail` → `hydra:description` → `title` → el fallback del llamador.
 */
export function extractApiErrorMessage(err: unknown, fallback = 'Ocurrió un error inesperado.'): string {
    const data = (err as { response?: { data?: Record<string, unknown> } })?.response?.data;
    if (!data) return fallback;

    const violations = data['violations'] as ErrorApiPayload['violations'];
    if (Array.isArray(violations) && violations.length > 0) {
        return violations.map(v => (v.propertyPath ? `${v.propertyPath}: ${v.message}` : v.message)).join(' | ');
    }

    return (data['detail'] as string) || (data['hydra:description'] as string) || (data['title'] as string) || fallback;
}

/**
 * Errores que NO merecen mensaje en pantalla: el 401 lo gestiona el interceptor
 * de `apiClient` (abre el modal de login) y la respuesta HTML es el formulario
 * de login de Symfony colándose en una petición JSON — mismo caso.
 */
export function esErrorSilencioso(err: unknown): boolean {
    if (esErrorApi(err)) {
        if (err.response?.status === 401) return true;
        if (err.message?.includes('HTML')) return true;
    }
    return false;
}
