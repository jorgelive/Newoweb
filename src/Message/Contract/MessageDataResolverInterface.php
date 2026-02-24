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
     * Obtiene IDs externos o configuraciones de enrutamiento específicas para los Providers
     * (Ej: API de Beds24, IDs de integraciones).
     *
     * @param string $contextId El UUID de la entidad.
     * @return array<string, mixed>
     *
     * @example
     * return ['beds24_book_id' => '12345678'];
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
    public function getTemplateVariables(string $contextId): array;
}