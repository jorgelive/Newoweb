<?php

declare(strict_types=1);

namespace App\Agent\Action;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.bot_action_handler')]
interface BotActionHandlerInterface
{
    /**
     * El código interno que se guarda en la base de datos (ej: 'disable_channel')
     */
    public function getActionKey(): string;

    /**
     * El nombre legible que aparecerá en el select de EasyAdmin
     */
    public function getActionLabel(): string;

    /**
     * Hacer lo que la regla pide, sobre el mensaje que la disparó.
     *
     * ⚠️ **Recibe el ID, no la entidad.** Esto es un contrato con `#[AutoconfigureTag]`: lo puede
     * implementar cualquier dominio, y meterle dentro una entidad de mensajería obligaría a
     * todos a conocerla para hacer algo que quizá no tiene nada que ver con un chat. Quien
     * implementa carga de SU dominio lo que necesite — es el mismo patrón que los handlers
     * asíncronos de este repo, que llevan un id y resuelven dentro.
     *
     * Antes era `execute(Message $inboundMessage, array $parameters)`: una entidad ajena en una
     * firma que otro tiene que implementar, y un `array` sin decir qué lleva —con su entrada en
     * `phpstan-baseline.neon`—.
     *
     * @param string $mensajeEntranteId El `Message` que disparó la regla. Puede haber
     *        desaparecido entre el disparo y la ejecución: quien implementa lo comprueba y
     *        vuelve sin hacer nada, que es el comportamiento correcto.
     */
    public function execute(string $mensajeEntranteId, ParametrosDeAccion $parametros): void;
}