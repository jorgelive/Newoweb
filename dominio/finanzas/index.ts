/**
 * La superficie pública del dominio de finanzas.
 *
 * ⚠️ **Las apps importan de aquí, nunca de un archivo de dentro.** Un import profundo ata al
 * consumidor a la organización interna, y entonces mover un archivo rompe a alguien. Con una sola
 * puerta, dentro se reorganiza libremente. Lo vigila una regla de ESLint en las dos apps.
 */
export {
    avisoDeTope,
    totalConRecargo,
    type ConfigDelFront,
} from './topePorCargo';
