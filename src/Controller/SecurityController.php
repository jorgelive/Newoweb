<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Controlador encargado de gestionar la seguridad de la aplicación.
 * Maneja el proceso de inicio de sesión y la delegación del cierre de sesión al firewall.
 */
class SecurityController extends AbstractController
{
    // Usamos el Trait para leer la ruta guardada en sesión de forma segura
    use TargetPathTrait;

    /**
     * Maneja la ruta de inicio de sesión y renderiza el formulario de autenticación.
     *
     * Este método comprueba si existe una URL de destino previa para redirigir al usuario
     * tras un inicio de sesión exitoso. Si el usuario ya está autenticado, lo redirige
     * directamente para evitar que vea el formulario nuevamente. Se integra con la plantilla
     * de EasyAdmin proporcionando los parámetros necesarios para habilitar "Recordarme" (Remember Me).
     *
     * @param AuthenticationUtils $authenticationUtils Utilidad para acceder a errores de autenticación y al último usuario introducido.
     * @param Request $request La petición HTTP actual, necesaria para acceder a la sesión y leer el target path.
     * @return Response Retorna la vista renderizada del formulario de inicio de sesión o una redirección si ya está logueado.
     */
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

    /**
     * Nuevo endpoint para el Chat (Vue/Pinia).
     * Symfony interceptará el JSON enviado y autenticará al usuario.
     */
    #[Route('/ajax_login', name: 'ajax_login', methods: ['POST'])]
    public function jsonLogin(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['message' => 'Credenciales inválidas'], 401);
        }

        // Si llega aquí, Symfony ya validó la password y creó la PHPSESSID
        return $this->json([
            'user' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'status' => 'success'
        ]);
    }

    /**
     * Intercepta la ruta de cierre de sesión.
     *
     * Este método nunca debe ser ejecutado directamente. Symfony y el firewall
     * configurado en security.yaml interceptan esta ruta y manejan la invalidación
     * de la sesión y la limpieza de cookies (como la de Remember Me) automáticamente.
     *
     * @throws \LogicException Lanza excepción si se alcanza, indicando que la intercepción del firewall falló o no está configurada.
     */
    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('Intercepted by firewall');
    }
}