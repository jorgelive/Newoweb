<?php

declare(strict_types=1);

namespace App\Message\Contract;

/**
 * Interface MessageDataResolverInterface
 *
 * El Resolver es llamado justo ANTES de enviar un mensaje (por el Worker)
 * para hidratar la plantilla y obtener los datos más "frescos" de la base de datos,
 * superando así el aislamiento del "Join Lógico".
 */
interface MessageDataResolverInterface
{
    /**
     * Define si este resolver es capaz de manejar el tipo de contexto solicitado.
     *
     * @param string $contextType El tipo almacenado en MessageConversation (Ej: 'pms_reserva')
     * @return bool True si este resolver sabe cómo buscar datos para este tipo.
     */
    public function supports(string $contextType): bool;

    /**
     * Obtiene el nombre del contacto consultando la base de datos en tiempo real.
     *
     * @param string $contextId El UUID de la entidad.
     * @return string|null El nombre actualizado, o null si la entidad fue borrada.
     */
    public function getContextName(string $contextId): ?string;

    /**
     * Obtiene el teléfono del contacto consultando la base de datos en tiempo real.
     *
     * @param string $contextId El UUID de la entidad.
     * @return string|null El teléfono, o null si no existe.
     */
    public function getPhoneNumber(string $contextId): ?string;

    /**
     * IDs externos y consecuencias del dominio que el núcleo transporta sin entender.
     *
     * ── Lo que va aquí y lo que NO ──────────────────────────────────────────
     * Van **identificadores opacos** (`beds24_book_id`, `beds24_config`, `source`) y
     * **consecuencias ya resueltas** (`es_plataforma`). No van datos crudos que obliguen al
     * núcleo a deducir la consecuencia él mismo: eso es conocimiento de dominio dentro de un
     * servicio transversal, y lo que produce es una copia de las reglas del dominio por cada
     * sitio que las necesita.
     *
     * Y ya pasó: la lista de canales «propios» del PMS estuvo escrita **tres veces dentro de
     * `src/Message/`**, dos de ellas sin normalizar, y una reserva podía ser directa para un
     * filtro y de plataforma para el otro.
     *
     * ── Las claves que hoy consume el núcleo ────────────────────────────────
     * | Clave | Tipo | Quién la lee | Para qué |
     * |---|---|---|---|
     * | `es_plataforma` | `bool` | `MessageFactory`, `Beds24SendEnqueuer` | Si hay una OTA entre el cliente y nosotros: decide si el canal del channel manager se ofrece y si se permite mandar por él |
     * | `source` | `string` | `ValidTemplateScopeValidator`, `MessageCrudController` | Acotar plantillas por origen. **Se compara, no se interpreta**: contra lo que alguien puso en `MessageTemplate::allowedSources` |
     * | `agency_id` | `string` | los mismos | Acotar plantillas por agencia, igual |
     * | `beds24_book_id`, `beds24_config` | `mixed` | `Beds24SendEnqueuer` | La dirección de la estancia en el channel manager |
     *
     * ⚠️ **Ausente se lee como `false`/vacío en todas.** Un dominio que no tenga channel manager
     * —Turismo, y mañana Travel— sencillamente no trae esas claves, y el canal se apaga solo. No
     * hace falta que devuelva `es_plataforma => false` para «desactivarlo»: no traerla ya lo
     * dice.
     *
     * @param string $contextId El UUID de la entidad.
     * @return array<string, mixed>
     *
     * @example
     * return ['beds24_book_id' => '12345678', 'source' => 'booking', 'es_plataforma' => true];
     */
    public function getMetadata(string $contextId): array;

    /**
     * Recupera todas las variables dinámicas disponibles para inyectar en las
     * plantillas de mensajes (Twig / WhatsApp Templates).
     *
     * @param string $contextId El UUID de la entidad.
     * @return array<string, scalar|null> Diccionario llave-valor con las variables.
     *
     * @example
     * return [
     * 'guest_name' => 'Juan',
     * 'checkin_date' => '25/12/2026'
     * ];
     */
    public function getMessageVariables(string $contextId): array;
}