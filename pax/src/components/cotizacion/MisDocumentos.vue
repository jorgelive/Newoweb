<script lang="ts">
/**
 * El CATÁLOGO de lo que se puede pedir, y el porqué de cada uno en su propia línea.
 *
 * ⚠️ **Esto ya no es «lo que se pide»: es lo que se PUEDE pedir.** Qué se exige de verdad lo dice
 * cada expediente (`CotizacionFile::$documentosPedidos`) y llega por la API. Era una lista fija
 * para todos y se rompió con el E-Ticket dominicano, que está atado a un destino: un viaje a Cusco
 * acabó pidiendo un formulario de Migración de República Dominicana.
 *
 * ⚠️ El DNI son **dos**: en un control migratorio no vale sólo el anverso. Pedirlos por separado
 * —en vez de «sube tu DNI»— es lo que hace que se note cuál falta.
 *
 * ⚠️ **Se EXPORTA, y por eso vive en un `<script>` normal y no en el `setup`.** Quien pinta esta
 * tarjeta necesita saber si queda algo pendiente para decidir dónde ponerla —arriba si le pide
 * algo, al final si ya cumplió— y la única forma de saberlo es comparar lo enviado contra esta
 * lista. Copiarla en la vista serían dos verdades sobre qué documentos se piden, y el día que se
 * añada uno se olvidaría la copia.
 */
export const CATALOGO_DOCUMENTOS = [
  { tipo: 'pasaporte', titulo: 'Pasaporte', ayuda: 'La página de la foto, entera y sin reflejos.', icono: 'camera' },
  { tipo: 'dni_anverso', titulo: 'DNI — anverso', ayuda: 'La cara con tu foto.', icono: 'camera' },
  { tipo: 'dni_reverso', titulo: 'DNI — reverso', ayuda: 'La cara de atrás.', icono: 'camera' },
  {
    // 🔥 **Faltaba aquí, y era pedible en el panel desde el primer día.** El operador marcaba
    // «Autorización notarial» en «Qué se pide», el pasajero **nunca veía la casilla**, y en el
    // manifiesto salían las 133 personas con «Falta documento» sin que ninguna pudiera resolverlo
    // desde su app. Cero errores en los dos lados: `documentosPedidos()` filtra este catálogo, así
    // que un tipo que no esté aquí no se pide — se ignora en silencio.
    //
    // ⚠️ **Esta lista es espejo de `ArchivoTipoEnum::pedibles()`.** Si allí `loSubeElPasajero()`
    // dice `true`, aquí tiene que haber una entrada, o el operador podrá pedir algo que nadie
    // puede mandar. Hay que tocar los dos.
    tipo: 'autorizacion',
    titulo: 'Autorización notarial',
    // No se dice «para menores»: el expediente ya decidió a quién se la pide, y quien esté viendo
    // esta casilla es porque se la piden a él. Lo que sí hace falta es que sepa QUÉ subir, porque
    // es el único de la lista que no está ya en su bolsillo.
    ayuda: 'El permiso notarial de salida del país, firmado. Vale la foto de todas las hojas o el PDF del notario.',
    icono: 'file-signature',
  },
  {
    tipo: 'eticket',
    // ⚠️ Se le llama **E-Ticket** porque es como lo llama Migración de República Dominicana y como
    // lo va a encontrar. Pero se dice «migratorio» al lado: sin eso, medio grupo sube su billete
    // de avión —que es lo que «e-ticket» significa para cualquiera— y el trámite se queda sin
    // hacer sin que nadie se entere hasta el aeropuerto.
    titulo: 'E-Ticket migratorio (Rep. Dominicana)',
    // Y la ayuda dice QUÉ es y DÓNDE se saca, no dónde se guarda: éste no llega solo a ningún
    // correo, hay que ir a rellenarlo.
    ayuda: 'El PDF con el código QR que sale al llenar el formulario de Migración dominicana. No es el billete de avión.',
    icono: 'qrcode',
  },
] as const;

export type TipoDoc = (typeof CATALOGO_DOCUMENTOS)[number]['tipo'];

/**
 * Lo que se pide cuando la respuesta NO trae la lista.
 *
 * 🔥 **`undefined` y `[]` NO son lo mismo, y confundirlos borra el panel entero.**
 * `undefined` es «esta respuesta no me lo dijo»; `[]` es «no se le pide nada». El store de `pax`
 * guarda `detalle` en `localStorage`, así que tras desplegar un campo nuevo hay gente navegando con
 * una respuesta VIEJA que no lo trae — y con un `?? []` esa gente veía desaparecer sus documentos y
 * un «no tienes que hacer nada más» que era mentira.
 *
 * ⚠️ Eso no se ve desplegando: el que despliega recarga con datos frescos. Lo sufre quien tenía la
 * app abierta de antes, que es todo el mundo menos tú.
 *
 * Espejo del relleno de `Version20260915090000`: lo que pedían todos los expedientes antes de que
 * esto fuera configurable. Es un respaldo para respuestas viejas, **no** la fuente de verdad — la
 * fuente es `CotizacionFile::$documentosPedidos`.
 */
export const PEDIDOS_POR_DEFECTO: readonly string[] = ['pasaporte', 'dni_anverso', 'dni_reverso'];
// ⚠️ Espejo de `CotizacionFile::DOCUMENTOS_PEDIDOS_POR_DEFECTO`. Allí es lo que pide un expediente
// nuevo; aquí, lo que se asume cuando la respuesta guardada no trae el campo. **Hay que tocar los
// dos.**

/** Resuelve el «no me lo dijo» sin pisar el «no se pide nada». */
export const pedidosEfectivos = (pedidos?: readonly string[] | null): readonly string[] =>
  pedidos ?? PEDIDOS_POR_DEFECTO;

/**
 * Los que este expediente pide, en el orden del catálogo.
 *
 * ⚠️ Se filtra el catálogo en vez de recorrer lo que manda la API: así el ORDEN lo decide esta
 * lista —pasaporte, DNI, y lo de destino al final— y no el orden en que el operador marcó las
 * casillas. Y un valor desconocido que llegara de la API se queda fuera solo.
 */
export const documentosPedidos = (pedidos?: readonly string[] | null) =>
  CATALOGO_DOCUMENTOS.filter(d => pedidosEfectivos(pedidos).includes(d.tipo));

/** ¿Le queda algo por mandar, de lo que ESTE expediente le pide? */
export const faltanDocumentos = (
  yaEnviados?: readonly string[] | null,
  pedidos?: readonly string[] | null,
): boolean => documentosPedidos(pedidos).some(d => !(yaEnviados ?? []).includes(d.tipo));
</script>

<script setup lang="ts">
import { acotarSiEsFotoGrande } from '@/utils/imagenParaSubir';
/**
 * El pasajero fotografía sus documentos desde su propio móvil.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Perseguir 133 pasaportes por WhatsApp —y luego renombrarlos y subirlos uno a uno— es el trabajo
 * que esto quita. Quien tiene el documento delante es él.
 *
 * ── La previsualización NO es un adorno ─────────────────────────────────────
 * 🔥 Es la única oportunidad de ver si la foto salió movida, cortada o con el flash reventando el
 * holograma. **Después no se puede mirar**: por seguridad, un escaneo de identidad no se le
 * devuelve nunca a quien lo subió —no le damos nada que no tenga ya, y cada vía por la que esa
 * imagen puede salir es una vía de más—. Así que la comprobación es aquí o no es.
 *
 * Por eso el flujo es *elegir → mirar en grande → confirmar*, y no un `input` que envía al soltar.
 *
 * ── `capture` y no una cámara propia ────────────────────────────────────────
 * ⚠️ `<input type="file" accept="image/*" capture="environment">` abre **la cámara del sistema**,
 * con su enfoque, su HDR y su recorte. Una cámara hecha con `getUserMedia` da peor foto, pide un
 * permiso que asusta y falla en la mitad de los navegadores embebidos —el de Instagram, el de
 * Gmail—, que es justo por donde llega un enlace de viaje.
 *
 * Y sin `capture` fijo en cámara: mucha gente ya tiene la foto del pasaporte en la galería.
 */
import { ref, computed, watch } from 'vue';
import { useMaestroStore } from '@/stores/maestroStore';

const props = defineProps<{
  localizador: string;
  /**
   * Los que ya mandó, **de una visita anterior**.
   *
   * 🔥 Sin esto la pantalla se olvida: sube el pasaporte, vuelve al día siguiente y los tres
   * botones dicen «Subir» otra vez. O lo manda de nuevo, o da por hecho que no funcionó y deja de
   * intentarlo — y eso segundo no se descubre nunca, porque nadie escribe para decir que se
   * rindió.
   *
   * ⚠️ Llega sólo el TIPO, sin enlace: un escaneo de identidad no se le devuelve ni a su dueño.
   */
  yaEnviados?: string[];
  /**
   * Los que ya revisó alguien del equipo: **no se ofrece cambiarlos**.
   *
   * 🔥 Antes la tarjeta decía «Cambiar» igual, el pasajero elegía la foto, esperaba la subida y
   * **entonces** le llegaba un 409. El servidor ya bloqueaba; la pantalla no lo sabía. Sale de la
   * misma regla que bloquea —`CotizacionFilepasajero::tieneVerificado()`—, así que no pueden decir
   * cosas distintas.
   */
  yaVerificados?: string[];
  /**
   * Lo que hay que pedirle que repita, **según lo guardado**.
   *
   * 🔥 Sin esto, «necesitamos otro» vivía sólo en memoria: si cerraba la app y volvía, la tarjeta
   * decía «Recibido, gracias» en verde y el pasajero creía que estaba resuelto. Lo calcula el
   * servidor con la misma regla que al subir (`CotizacionFilePublicProvider::documentosAPedir()`).
   */
  yaPedidos?: Array<{ tipo: string; motivos: string[] }>;
  /**
   * Los tipos que ESTE expediente exige.
   *
   * ⚠️ `[]` es «no se le pide nada»; **ausente** es «la respuesta no lo dijo» y cae en
   * {@link PEDIDOS_POR_DEFECTO}. No son lo mismo: ver el aviso de ahí.
   */
  pedidos?: readonly string[];
}>();

const maestroStore = useMaestroStore();

/**
 * 🔥 **Lo que se acaba de subir tiene que SALIR de aquí.**
 *
 * `recienSubidos` es estado local, y la cabecera del panel que envuelve a esto la pinta el padre con
 * lo que dice el SERVIDOR. Sin este aviso, el pasajero subía su último documento, veía las filas
 * ponerse verdes… y justo encima seguía un «Te falta alguno por mandar» en ámbar, hasta recargar —
 * y ni eso, porque el store cachea la respuesta. Dos mitades de la misma tarjeta diciéndose que no
 * justo en el momento en que se pregunta «¿ha funcionado?».
 */
// `pideOtro` además de `subido`: la cabecera del panel cuenta lo pendiente, y un documento que se
// subió pero hay que repetir tiene que seguir contando como pendiente allí también.
const emit = defineEmits<{ subido: [tipo: string]; pideOtro: [tipo: string] }>();


const eligiendo = ref<TipoDoc | null>(null);
const vistaPrevia = ref<string | null>(null);
const ficheroElegido = ref<File | null>(null);
const enviando = ref(false);
const error = ref<string | null>(null);
/**
 * Lo que ya tiene, venga de esta sesión o de la anterior.
 *
 * Se une en vez de sustituir: el prop llega con la respuesta del servidor y lo que acaba de subir
 * todavía no está en ella.
 */
const recienSubidos = ref<Set<string>>(new Set());

const subidos = computed(() => new Set<string>([...(props.yaEnviados ?? []), ...recienSubidos.value]));

/**
 * Lo que el servidor le pidió repetir, por documento, **en el momento de subirlo**.
 *
 * 🔥 **Antes se le decía «Recibido, gracias» a una foto cortada**, y días después alguien del equipo
 * tenía que perseguirle para pedirle otra. Ahora el servidor la lee al llegar y, si hay algo que
 * ÉL puede arreglar con otra foto, lo dice aquí mismo. Qué se pide y qué no lo decide
 * `QueLePedimosAlPasajero` en el servidor — aquí sólo se pinta.
 *
 * ⚠️ **Manda sobre «recibido».** Si ya había uno subido antes, `yaEnviados` lo trae y la tarjeta
 * saldría en verde justo cuando se le está pidiendo repetir.
 */
const pideOtroDe = ref<Record<string, string[]>>({});

// Lo que dice el servidor al abrir. Una subida posterior lo sobrescribe en memoria, y la siguiente
// vez que se cargue la identidad el servidor ya dirá lo mismo.
watch(() => props.yaPedidos, (pedidos) => {
  pideOtroDe.value = Object.fromEntries((pedidos ?? []).map(p => [p.tipo, p.motivos]));
}, { immediate: true });

const verificados = computed(() => new Set<string>(props.yaVerificados ?? []));

/** Lo que hay que enseñarle a ESTA persona en ESTE expediente. */
const pedidos = computed(() => documentosPedidos(props.pedidos));

const tituloDe = (tipo: TipoDoc) => CATALOGO_DOCUMENTOS.find(d => d.tipo === tipo)?.titulo ?? '';

const alElegir = (tipo: TipoDoc, evento: Event) => {
  const archivo = (evento.target as HTMLInputElement).files?.[0];
  if (!archivo) return;

  error.value = null;
  ficheroElegido.value = archivo;
  eligiendo.value = tipo;

  // ⚠️ `createObjectURL` y no un `FileReader` con base64: una foto de móvil son varios megas y
  // pasarlos a texto los infla un tercio y bloquea el hilo mientras tanto. Esto es instantáneo.
  vistaPrevia.value = URL.createObjectURL(archivo);
};

const descartar = () => {
  if (vistaPrevia.value) URL.revokeObjectURL(vistaPrevia.value);
  vistaPrevia.value = null;
  ficheroElegido.value = null;
  eligiendo.value = null;
  error.value = null;
};

const confirmar = async () => {
  if (!ficheroElegido.value || !eligiendo.value) return;

  enviando.value = true;
  error.value = null;

  const cuerpo = new FormData();
  // ⚠️ Acotada ANTES de salir: la subida espera a que el documento se lea, y una foto de móvil de
  // 4 MB por datos es la mayor parte de esa espera. Ver `utils/imagenParaSubir.ts` —y por qué es
  // un espejo del de `util`—.
  cuerpo.append('documento', await acotarSiEsFotoGrande(ficheroElegido.value));
  cuerpo.append('tipo', eligiendo.value);

  try {
    const respuesta = await fetch(`/file/${props.localizador}/mis-documentos`, {
      method: 'POST',
      body: cuerpo,
      // La identidad va en la sesión, así que la cookie tiene que viajar.
      credentials: 'same-origin',
    });

    const datos = await respuesta.json().catch(() => ({}));

    if (!respuesta.ok) {
      error.value = datos.error || 'No se pudo enviar. Inténtalo otra vez.';
      enviando.value = false;

      return;
    }

    const pedido: string[] = Array.isArray(datos.pideOtro) ? datos.pideOtro : [];
    const tipo = eligiendo.value;

    if (pedido.length > 0) {
      // Se guardó, pero no sirve tal cual: la tarjeta se queda pidiendo otro y NO se avisa al padre
      // de que está hecho, para que el contador no diga «completo» mientras aquí se pide repetir.
      pideOtroDe.value = { ...pideOtroDe.value, [tipo]: pedido };
      emit('pideOtro', tipo);
    } else {
      const { [tipo]: _resuelto, ...resto } = pideOtroDe.value;
      pideOtroDe.value = resto;
      recienSubidos.value = new Set([...recienSubidos.value, tipo]);
      emit('subido', tipo);
    }

    descartar();
  } catch {
    // Sin conexión: el mensaje dice qué hacer, no qué falló.
    error.value = 'No hay conexión. Inténtalo cuando vuelvas a tener señal.';
  }

  enviando.value = false;
};

/**
 * ⚠️ **Aquí vivía un plegado propio** —una línea compacta que se abría al pulsarla— y el
 * contenedor tenía otro. Dos mecanismos para el mismo gesto: uno se creía abierto y el otro
 * seguía cerrado. Ahora pliega `PanelPlegable` y esto se limita a su contenido; la decisión que
 * había detrás —cerrado cuando ya no pide nada— se conserva, pero la toma quien pinta el panel.
 *
 * ⚠️ Y hubo aquí un `quedanPorSubir` expuesto con `defineExpose` que **no leía nadie**: el padre no
 * tiene un `ref` de plantilla sobre este componente. Parecía que la cuenta viajaba hacia fuera y no
 * viajaba — un cálculo correcto que no alimentaba nada. Ahora lo que sale es el evento `subido`,
 * que sí se escucha, y la cuenta la hace quien la necesita.
 */
</script>

<template>
  <div>
    <p class="text-slate-500 text-xs leading-relaxed mb-5">
      {{ maestroStore.t('cot_mis_documentos_motivo')
        || 'Los necesitamos para emitir tus boletos y para el control migratorio. Sólo los ve el equipo que arma tu viaje, y se borran un mes después de tu regreso.' }}
    </p>

    <div class="space-y-2">
      <div v-for="doc in pedidos" :key="doc.tipo"
           class="flex flex-wrap items-center gap-3 p-3 rounded-2xl border transition-colors"
           :class="pideOtroDe[doc.tipo] ? 'bg-amber-50 border-amber-300'
             : subidos.has(doc.tipo) ? 'bg-emerald-50 border-emerald-200' : 'bg-slate-50 border-slate-200'">
        <span class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
              :class="pideOtroDe[doc.tipo] ? 'bg-amber-100 text-amber-600'
                : subidos.has(doc.tipo) ? 'bg-emerald-100 text-emerald-600' : 'bg-white text-slate-400 border border-slate-200'">
          <!-- El icono lo dice el documento: una cámara invita a hacer una foto, y el e-ticket
               no se fotografía — se busca en el correo. -->
          <i class="fas" :class="pideOtroDe[doc.tipo] ? 'fa-rotate-right'
            : subidos.has(doc.tipo) ? 'fa-check' : `fa-${doc.icono}`"></i>
        </span>

        <div class="min-w-0 flex-1">
          <p class="text-sm font-black text-gray-800">{{ doc.titulo }}</p>
          <p class="text-[11px] leading-snug" :class="pideOtroDe[doc.tipo] ? 'text-amber-700 font-bold' : 'text-slate-500'">
            {{ pideOtroDe[doc.tipo] ? 'Lo recibimos, pero necesitamos otro:'
              : verificados.has(doc.tipo) ? 'Revisado y correcto. Si hay que cambiarlo, escríbenos.'
              : subidos.has(doc.tipo) ? 'Recibido, gracias.' : doc.ayuda }}
          </p>
        </div>

        <!-- El `label` es el botón: un `input file` estilizado se rompe en cuanto el navegador
             decide pintarlo a su manera. -->
        <!-- ⚠️ Verificado = sin botón, no un botón deshabilitado: uno gris invita a pulsarlo para ver
             por qué, y la respuesta ya está escrita al lado. -->
        <span v-if="verificados.has(doc.tipo) && !pideOtroDe[doc.tipo]"
              class="shrink-0 px-3 py-2 text-[11px] font-black uppercase tracking-wider text-emerald-700">
          <i class="fas fa-shield-halved mr-1"></i>Revisado
        </span>
        <label v-else class="shrink-0 px-4 py-2 rounded-xl text-[11px] font-black uppercase tracking-wider cursor-pointer transition-colors"
               :class="pideOtroDe[doc.tipo] ? 'bg-amber-500 hover:bg-amber-600 text-white'
                 : subidos.has(doc.tipo)
                 ? 'text-emerald-700 hover:bg-emerald-100'
                 : 'bg-[#376875] hover:bg-[#2b525d] text-white'">
          {{ pideOtroDe[doc.tipo] ? 'Subir otro' : subidos.has(doc.tipo) ? 'Cambiar' : 'Subir' }}
          <input type="file" accept="image/*,application/pdf" class="hidden"
                 @change="alElegir(doc.tipo, $event)" />
        </label>

        <!-- ⚠️ `basis-full` para que las razones bajen a su propia línea: al lado del botón, en un
             móvil de 375 px, se partían en columnas de tres palabras. -->
        <ul v-if="pideOtroDe[doc.tipo]" class="basis-full space-y-1.5 pl-12">
          <li v-for="(motivo, i) in pideOtroDe[doc.tipo]" :key="i" class="text-[11px] text-amber-900 leading-snug">
            {{ motivo }}
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- ═══ LA PREVISUALIZACIÓN ═══
       A pantalla completa y en grande, porque es la ÚNICA vez que se puede comprobar que la foto
       vale: después no se devuelve. -->
  <!-- ⚠️ **`Teleport` y banda de modal.** Este componente se monta DENTRO de la guía, entre
       tarjetas; un ancestro con `sticky`, `transform` o `filter` crea contexto de apilamiento y
       encierra este `fixed` dentro de él. Y aquí eso no es un detalle estético: esta
       previsualización es la ÚNICA oportunidad de comprobar que el escaneo se lee, porque después
       no se le devuelve. Si queda debajo de algo, el pasajero manda una foto movida sin saberlo. -->
  <Teleport to="body">
  <div v-if="vistaPrevia" class="fixed inset-0 z-[1000] bg-slate-900/90 flex flex-col p-4">
    <div class="flex items-center justify-between text-white mb-3 shrink-0">
      <p class="font-black text-sm">{{ tituloDe(eligiendo!) }}</p>
      <button @click="descartar" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 transition-colors">
        <i class="fas fa-xmark"></i>
      </button>
    </div>

    <div class="flex-1 min-h-0 flex items-center justify-center overflow-hidden rounded-2xl bg-black/30">
      <img v-if="ficheroElegido?.type !== 'application/pdf'" :src="vistaPrevia" alt=""
           class="max-h-full max-w-full object-contain" />
      <p v-else class="text-white/70 text-xs font-bold text-center px-6">
        <i class="far fa-file-pdf text-3xl block mb-2"></i>
        {{ ficheroElegido?.name }}
      </p>
    </div>

    <p class="text-white/70 text-[11px] text-center leading-snug my-3 shrink-0">
      Míralo bien antes de enviar: que se lea todo, sin reflejos y sin cortar los bordes.
      <strong class="text-white">Después no vas a poder verlo otra vez.</strong>
    </p>

    <p v-if="error" class="text-amber-200 bg-amber-900/40 rounded-xl py-2.5 px-4 text-xs font-bold text-center mb-2 shrink-0">
      {{ error }}
    </p>

    <div class="flex gap-2 shrink-0">
      <button @click="descartar" :disabled="enviando"
              class="flex-1 py-3.5 rounded-2xl bg-white/10 hover:bg-white/20 text-white font-black text-xs uppercase tracking-widest transition-colors disabled:opacity-50">
        Repetir
      </button>
      <button @click="confirmar" :disabled="enviando"
              class="flex-1 py-3.5 rounded-2xl bg-[#E07845] hover:bg-[#c96835] text-white font-black text-xs uppercase tracking-widest transition-colors disabled:opacity-50">
        <i v-if="enviando" class="fas fa-spinner fa-spin mr-1"></i>
        {{ enviando ? 'Revisando…' : 'Enviar' }}
      </button>
    </div>

    <!-- ⚠️ Tarda de verdad —unos diez segundos, lo que cuesta leer el documento— y sin decirlo el
         pasajero cree que se colgó y lo manda otra vez. -->
    <p v-if="enviando" class="text-white/60 text-[11px] text-center leading-snug mt-2 shrink-0">
      Estamos comprobando que se lea bien. Tarda unos segundos, no cierres esta pantalla.
    </p>
  </div>
  </Teleport>
</template>
