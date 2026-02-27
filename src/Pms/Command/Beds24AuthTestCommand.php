<?php

namespace App\Pms\Command;

use App\Exchange\Entity\Beds24Config;
use App\Pms\Service\Exchange\Auth\Beds24AuthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class Beds24AuthTestCommand extends Command
{
    protected static $defaultName = 'pms:beds24:auth:test';
    protected static $defaultDescription = 'Prueba la autenticación contra Beds24 usando refresh token';

    private EntityManagerInterface $em;
    private Beds24AuthService $authService;

    public function __construct(
        EntityManagerInterface $em,
        Beds24AuthService $authService
    ) {
        parent::__construct();
        $this->em = $em;
        $this->authService = $authService;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var Beds24Config|null $config */
        $config = $this->em
            ->getRepository(Beds24Config::class)
            ->findOneBy(['activo' => true]);

        if (!$config) {
            $io->error('No existe una configuración activa de Beds24.');
            return Command::FAILURE;
        }

        $io->section('Configuración encontrada');
        $io->text(sprintf(
            'ID config: %d | Activa: %s',
            $config->getId(),
            $config->isActivo() ? 'sí' : 'no'
        ));

        try {
            $token = $this->authService->getAuthToken($config);
        } catch (\Throwable $e) {
            $io->error('Error obteniendo token: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Token obtenido correctamente');
        $io->text([
            'Token (primeros 40 chars): ' . substr($token, 0, 40) . '...',
            'Expira en: ' . ($config->getAuthTokenExpiresAt()?->format('Y-m-d H:i:s') ?? 'N/A'),
            'Refresh token presente: ' . ($config->getRefreshToken() ? 'sí' : 'no'),
        ]);

        return Command::SUCCESS;
    }
}