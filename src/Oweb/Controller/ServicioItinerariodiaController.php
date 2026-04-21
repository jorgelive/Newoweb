<?php

declare(strict_types=1);

namespace App\Oweb\Controller;

use App\Oweb\Entity\ServicioItinerariodia;
use App\Service\GoogleTranslateService;
use Doctrine\ORM\EntityManagerInterface;
use Google\ApiCore\ApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controlador para la gestión de Itinerarios de Servicio.
 * * Extiende de CRUDController (Sonata Admin) y gestiona operaciones personalizadas
 * fuera del flujo estándar de Sonata, como la traducción automática de campos
 * utilizando la API de Google Cloud Translation V3.
 */
class ServicioItinerariodiaController extends CRUDController
{
    private EntityManagerInterface $entityManager;
    private GoogleTranslateService $translateService;

    /**
     * Inyecta las dependencias necesarias para la traducción y persistencia.
     *
     * @param EntityManagerInterface $entityManager    Manejador de entidades de Doctrine.
     * @param GoogleTranslateService $translateService Servicio migrado a Google Translate V3 para soportar glosarios y modelos avanzados.
     * * @example
     * // El contenedor de dependencias inyecta esto automáticamente, pero en un test unitario:
     * $controller = new ServicioItinerariodiaController($entityManagerMock, $googleTranslateMock);
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleTranslateService $translateService
    ) {
        $this->entityManager = $entityManager;
        $this->translateService = $translateService;
    }

    /**
     * Acción para traducir el contenido del itinerario al idioma actual de la petición.
     * * Este método intercepta el idioma destino desde el Request, obtiene los textos
     * en el idioma por defecto (Source Locale), invoca la API de Google, y persiste
     * los nuevos valores en la tabla de traducciones de la entidad.
     *
     * @param Request $request La petición HTTP actual que contiene el 'id' del objeto y el 'locale' destino.
     * * @return RedirectResponse Redirige al listado de Sonata con un mensaje flash (éxito o error).
     * * @throws NotFoundHttpException Si el ID proporcionado en la ruta no corresponde a un objeto existente.
     * @throws ApiException          Si falla la comunicación con la infraestructura de Google Cloud.
     * * @example
     * // Al acceder a la ruta: /admin/app/oweb/servicioitinerariodia/1/traducir?_locale=en
     * // Traducirá el itinerario ID 1 desde el idioma por defecto (ej. 'es') al inglés ('en').
     */
    public function traducirAction(Request $request): RedirectResponse
    {
        // Se valida que el objeto exista dentro del contexto de Sonata
        $object = $this->assertObjectExists($request, true);

        if (!$object) {
            // Casteo a (string) para evitar TypeError en PHP 8.4 si 'id' viene null
            throw $this->createNotFoundException(sprintf('unable to find the object with id: %s', (string) $request->get('id')));
        }

        $id = $object->getId();
        $targetLocale = $request->getLocale();
        $defaultLocale = $request->getDefaultLocale();

        // Validación de locales para evitar sobreescritura accidental del idioma base (origen)
        if ($defaultLocale === $targetLocale) {
            $this->addFlash('sonata_flash_error', 'El idioma actual debe ser diferente al idioma por defecto de la aplicación');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        // Verifica que el usuario tenga permisos de edición sobre esta entidad específica
        $this->admin->checkAccess('edit', $object);

        /** @var ServicioItinerariodia $itinerariodiaDL */
        $itinerariodiaDL = $this->entityManager->getRepository(ServicioItinerariodia::class)->find($id);

        // 1. Obtener contenido en el idioma por defecto (Source)
        $itinerariodiaDL->setLocale($defaultLocale);
        $this->entityManager->refresh($itinerariodiaDL);

        $tituloDL = $itinerariodiaDL->getTitulo();
        $contenidoDL = $itinerariodiaDL->getContenido();

        // 2. Volver al idioma destino para la persistencia
        $itinerariodiaDL->setLocale($targetLocale);
        $this->entityManager->refresh($itinerariodiaDL);

        try {
            // Traducción del Título
            if (!empty($tituloDL)) {
                $translatedTitles = $this->translateService->translate(
                    $tituloDL,
                    $targetLocale,
                    $defaultLocale
                );

                $tituloTexto = $translatedTitles[0] ?? '';

                // Mantener capitalización original si el origen empezaba por Mayúscula.
                // Se utiliza la función nativa mb_ucfirst disponible desde PHP 8.4.
                if (mb_substr($tituloDL, 0, 1) === mb_strtoupper(mb_substr($tituloDL, 0, 1))) {
                    $tituloTexto = mb_ucfirst($tituloTexto);
                }

                $object->setTitulo($tituloTexto);
            }

            // Traducción del Contenido
            if (!empty($contenidoDL)) {
                $translatedContents = $this->translateService->translate(
                    $contenidoDL,
                    $targetLocale,
                    $defaultLocale
                );

                $object->setContenido($translatedContents[0] ?? '');
            }

            // Persistir los cambios en la base de datos a través del Admin de Sonata
            $this->admin->update($object);
            $this->addFlash('sonata_flash_success', 'Día de itinerario traducido correctamente mediante API V3');

        } catch (ApiException $e) {
            $this->addFlash('sonata_flash_error', 'Error en Google Translate: ' . $e->getMessage());
        }

        return new RedirectResponse($this->admin->generateUrl('list'));
    }
}