<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Si ya está logueado, NUNCA redirijas a "/" hardcodeado:
        // redirige por RUTA del panel.
        if ($this->getUser()) {
            return $this->redirectToRoute('panel_dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('@EasyAdmin/page/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'page_title' => 'OpenPeru <strong>Panel</strong>',
            'username_label' => 'Email',
            'sign_in_label' => 'Entrar',
            'username_parameter' => '_username',
            'password_parameter' => '_password',
            'csrf_token_intention' => 'authenticate',
            'remember_me_enabled' => true,  // Esto activa el checkbox visualmente
            'remember_me_parameter' => '_remember_me', // El nombre del campo input
            'remember_me_label' => 'Recordarme', // El texto que verá el usuario
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('Intercepted by firewall');
    }
}