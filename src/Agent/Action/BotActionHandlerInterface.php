<?php

declare(strict_types=1);

namespace App\Agent\Action;

use App\Message\Entity\Message;
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
     * La ejecución de la lógica
     */
    public function execute(Message $inboundMessage, array $parameters): void;
}