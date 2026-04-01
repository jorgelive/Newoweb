<?php

namespace App\Command;

use App\Entity\PushSubscription;
use App\Service\WebPushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:test-push', description: 'Prueba el envío de notificaciones WebPush')]
class TestPushCommand extends Command
{
    public function __construct(
        private WebPushNotificationService $pushService,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Buscamos cualquier suscripción activa en la base de datos
        $subscription = $this->em->getRepository(PushSubscription::class)->findOneBy([]);

        if (!$subscription) {
            $output->writeln('<error>No hay ninguna suscripción Push registrada en la base de datos. Inicia sesión en la PWA primero.</error>');
            return Command::FAILURE;
        }

        // 2. Extraemos al dueño de esa suscripción (Tú)
        $user = $subscription->getUser();

        if (!$user) {
            $output->writeln('<error>La suscripción encontrada no tiene un usuario válido asignado.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Enviando Push al usuario: ' . $user->getUserIdentifier() . '</info>');

        // 3. Disparamos la notificación
        $this->pushService->sendToUser($user, [
            'title' => '¡Conexión Exitosa!',
            'body' => 'El Service Worker está recibiendo notificaciones desde Symfony en Producción.',
            'type' => 'success',
            'actionUrl' => '/app_util/chat' // Ajusta si tu ruta base es otra
        ]);

        $output->writeln('<info>¡Notificación despachada a los servidores VAPID!</info>');
        return Command::SUCCESS;
    }
}