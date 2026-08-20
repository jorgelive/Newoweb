/**
 * El motivo que devolvió la API, o uno por defecto.
 *
 * API Platform pone el mensaje de un `DomainException` en `hydra:description` —y en `detail`
 * cuando la respuesta va en JSON a secas—. Taparlo con un texto genérico cuesta caro: el
 * servidor sabe QUÉ falló («los servicios son de expedientes distintos», «ya está en la orden
 * OS-014») y un «no se pudo» obliga a adivinarlo.
 *
 * Vivía dentro de `organizacionStore`, donde no lo encontraba nadie más.
 */
export const mensajeDeErrorApi = (e: unknown, porDefecto: string): string => {
    const r = (e as { response?: { data?: Record<string, unknown> } })?.response?.data;

    return String(r?.['hydra:description'] ?? r?.detail ?? porDefecto);
};
