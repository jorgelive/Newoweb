<?php

declare(strict_types=1);

namespace App\Oweb\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador para la gestión de la entidad Servicio.
 * Extiende de CRUDController (Sonata Admin) para añadir la funcionalidad
 * personalizada de clonación de servicios base.
 */
class ServicioServicioController extends CRUDController
{
    private EntityManagerInterface $entityManager;

    /**
     * Inyecta el EntityManager necesario para operaciones adicionales a las del ciclo de vida de Sonata.
     *
     * @param EntityManagerInterface $entityManager Manejador de entidades de Doctrine.
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * Acción para duplicar (clonar) un servicio base existente.
     *
     * Este método recupera un objeto existente, verifica los permisos de creación,
     * genera una copia en memoria y la persiste mediante el flujo estándar de Sonata Admin.
     *
     * IMPORTANTE: La clonación profunda (como la copia de relaciones con otras entidades,
     * galerías de imágenes o tarifas, y el seteo del ID a null) depende enteramente de la
     * implementación del método mágico __clone() dentro de la propia entidad gestionada.
     *
     * @param Request $request La petición HTTP actual que contiene el ID del objeto a clonar.
     * @return Response        Redirige al listado del admin con un mensaje flash de éxito.
     *
     * @example
     * // Al acceder a la ruta: /admin/app/oweb/servicioservicio/10/clonar
     * // Duplicará el servicio ID 10 y le añadirá " (Clone)" al final de su nombre actual.
     */
    public function clonarAction(Request $request): Response
    {
        // Se valida que el objeto exista dentro del contexto de la URL actual de Sonata
        $object = $this->assertObjectExists($request, true);

        // Verifica que el usuario tenga permisos explícitos para crear un nuevo registro
        $this->admin->checkAccess('create', $object);

        // Se genera el clon. La lógica de reseteo de ID y clonado de relaciones
        // debe residir en el método __clone() de la entidad.
        $newObject = clone $object;
        $newObject->setNombre($object->getNombre() . ' (Clone)');

        // Se persiste el nuevo objeto a través del manejador de ciclo de vida del Admin
        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Servicio clonado correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));
    }
}