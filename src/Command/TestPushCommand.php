<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Service\WebPushNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:test-push', description: 'Prueba el envío de notificaciones WebPush')]
class TestPushCommand extends Command
{
    public function __construct(
        private WebPushNotificationService $pushService,
        private UserRepository $userRepository
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 🔥 Pon aquí el ID o username de tu usuario logueado en la captura (ej: Jorge)
        $user = $this->userRepository->find(1);

        if (!$user) {
            $output->writeln('<error>Usuario no encontrado.</error>');
            return Command::FAILURE;
        }

        $this->pushService->sendToUser($user, [
            'title' => '¡Sistema Operativo!',
            'body' => 'Esto es una prueba de WebPush desde la consola.',
            'type' => 'success',
            'actionUrl' => '/chat'
        ]);

        $output->writeln('<info>Notificación enviada.</info>');
        return Command::SUCCESS;
    }
}