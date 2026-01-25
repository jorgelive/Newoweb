<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request; // Necesario para la sesión
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait; // 👇 Importante

class SecurityController extends AbstractController
{
    // Usamos el Trait para leer la ruta guardada en sesión de forma segura
    use TargetPathTrait;

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        // 1. Intentamos recuperar la URL a la que el usuario quería ir (si existe en sesión)
        // 'main' es el nombre de tu firewall en security.yaml
        $targetPath = $this->getTargetPath($request->getSession(), 'main');

        // 2. Si el usuario YA está logueado y entra a /login por error:
        if ($this->getUser()) {
            // Si tenemos una ruta pendiente, lo mandamos ahí. Si no, al dashboard.
            return $this->redirect($targetPath ?? $this->generateUrl('panel_dashboard'));
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
            'remember_me_enabled' => true,
            'remember_me_parameter' => '_remember_me',
            'remember_me_label' => 'Recordarme',

            // 👇 3. Pasamos esta variable a la vista.
            // La mayoría de plantillas de login (incluida EasyAdmin) detectan esta variable
            // y crean un input hidden name="_target_path" automáticamente.
            'target_path' => $targetPath,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('Intercepted by firewall');
    }
}