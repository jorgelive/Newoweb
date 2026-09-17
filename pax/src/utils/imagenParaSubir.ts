/**
 * ⚠️⚠️ **ESPEJO de `util/src/utils/imagenParaSubir.ts`. Se tocan LOS DOS, siempre.**
 *
 * Está copiado a propósito y no compartido: `dominio/` —el único sitio que importan las dos apps—
 * está declarado **sin DOM**, porque PHP lo ejecuta por Node, y esto es `canvas`. Se decidió
 * (17/09/2026) duplicar este archivo antes que abrir una excepción a esa regla. El precio es éste:
 * si se cambia `LADO_MAXIMO`, el umbral o la calidad en uno y no en el otro, el pasajero y el
 * equipo subirán fotos distintas y nadie lo notará. Ver `docs/Cotizaciones.md`.
 *
 * 🔑 **En `pax` importa más que en `util`**: la mayoría de los escaneos los sube el pasajero desde su
 * móvil, y desde el 17/09/2026 la subida **espera a que el documento se lea** para decirle si hay
 * que repetirlo. Cada megabyte que no viaja es espera que no pasa con la pantalla girando.
 */
/**
 * Acota una foto ANTES de subirla, para que no viaje lo que el servidor va a tirar.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El servidor ya recomprime todo lo que entra (`VichWebpConversionListener` + los filtros de
 * `config/packages/liip_imagine.yaml`): un escaneo de identidad acaba en **2400 px y webp de
 * calidad 88**, unos 430 KB. Pero eso pasa **después de la subida**, así que una foto de móvil de
 * 4 MB viaja entera por la red para convertirse en 430 KB al llegar.
 *
 * Desde una oficina no se nota. Desde un móvil con datos en el aeropuerto —que es donde se suben
 * los documentos de verdad— es la parte más lenta de todo el proceso, y la única que no arregló
 * quitar la recarga del expediente.
 *
 * ── 🔑 Es una COTA, no una regla de negocio ─────────────────────────────────
 * Aquí **no se decide a qué tamaño va cada documento**: eso lo decide el servidor por
 * `tipoArchivo`, y son 1600 px o 2400 px según sea una foto cualquiera o un escaneo de identidad.
 * Duplicar esa decisión en el navegador sería la misma regla escrita dos veces.
 *
 * Lo que se hace es garantizar **«nunca más de 2400 px»**, que es el mayor de los dos destinos: lo
 * que el servidor iba a reducir de todas formas, ya reducido. Si mañana el filtro del servidor
 * baja a 2000, esto sigue siendo correcto —sólo deja de ahorrar un poco—; si subiera de 2400, hay
 * que tocar `LADO_MAXIMO` y el comentario de allí lo dice.
 *
 * ── ⚠️ EXIF: la trampa que este repositorio ya pagó una vez ─────────────────
 * Un móvil en vertical guarda los píxeles **apaisados** y añade una etiqueta EXIF «gírala 90° al
 * mostrar». Dibujar en un `canvas` borra esa etiqueta, así que dibujar sin girar antes deja la
 * imagen **tumbada para siempre** — y sin dar ningún error. Es exactamente el fallo que está
 * documentado a gritos al final de `liip_imagine.yaml`, donde costó que el pasajero viera su
 * pasaporte derecho y al operador le llegara girado.
 *
 * Por eso se decodifica con `imageOrientation: 'from-image'`, que aplica el EXIF a los píxeles: lo
 * que sale del canvas ya está derecho de verdad, y el `auto_rotate` del servidor se encuentra una
 * imagen que no hay que girar. Un navegador que no lo soporte se detecta y **no se toca la foto**.
 *
 * ── ⚠️ Ante la duda, se sube el original ────────────────────────────────────
 * Cualquier fallo —formato raro, canvas bloqueado, memoria— devuelve el fichero tal cual. Subir
 * 4 MB de más es una molestia; no subir el documento es perder el viaje de esa persona.
 */

/** El mayor de los destinos del servidor (`documento_identidad`). Ver la cabecera. */
const LADO_MAXIMO = 2400;

/**
 * Por debajo de esto no se toca: una foto ya pequeña recomprimida sólo pierde calidad.
 *
 * ⚠️ Importa más de lo que parece **porque aquí se leen MRZ**. Media hora de esta misma sesión se
 * fue en un pasaporte cuyo nombre impreso no se leía; degradar de más los escaneos pequeños sería
 * fabricar ese problema a mano.
 */
const MINIMO_QUE_COMPENSA = 900 * 1024;

/** Calidad alta a propósito: el servidor va a recomprimir encima, y dos pérdidas se suman. */
const CALIDAD = 0.92;

const puedeDecodificar = (): boolean =>
    typeof createImageBitmap === 'function' && typeof document !== 'undefined';

export const acotarSiEsFotoGrande = async (original: File): Promise<File> => {
    if (!original.type.startsWith('image/') || original.type === 'image/svg+xml') {
        return original;   // un PDF ya viene acotado por quien lo generó
    }

    if (original.size < MINIMO_QUE_COMPENSA || !puedeDecodificar()) {
        return original;
    }

    try {
        // ⚠️ `from-image` es lo que aplica el EXIF a los píxeles. Sin esto, todo lo demás de esta
        // función deja las fotos de móvil tumbadas. Ver la cabecera.
        const bitmap = await createImageBitmap(original, { imageOrientation: 'from-image' });
        const lado = Math.max(bitmap.width, bitmap.height);

        if (lado <= LADO_MAXIMO) {
            bitmap.close();

            return original;   // pesa por ser detallada, no por ser grande: se respeta
        }

        const escala = LADO_MAXIMO / lado;
        const lienzo = document.createElement('canvas');
        lienzo.width = Math.round(bitmap.width * escala);
        lienzo.height = Math.round(bitmap.height * escala);

        const pincel = lienzo.getContext('2d');

        if (!pincel) {
            bitmap.close();

            return original;
        }

        pincel.imageSmoothingQuality = 'high';
        pincel.drawImage(bitmap, 0, 0, lienzo.width, lienzo.height);
        bitmap.close();

        const blob = await new Promise<Blob | null>(r => lienzo.toBlob(r, 'image/jpeg', CALIDAD));

        // Si por lo que sea salió más pesado, no se ha ganado nada y se sube el original.
        if (!blob || blob.size >= original.size) {
            return original;
        }

        return new File([blob], original.name.replace(/\.[^.]+$/, '') + '.jpg', {
            type: 'image/jpeg',
            lastModified: original.lastModified,
        });
    } catch {
        return original;
    }
};
