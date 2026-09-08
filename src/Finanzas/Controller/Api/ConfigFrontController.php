<?php

declare(strict_types=1);

namespace App\Finanzas\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Los números de negocio que las dos apps necesitan para CALCULAR lo mismo que el servidor.
 *
 * ## Por qué existe
 *
 * 🔥 **El recargo de tarjeta estaba tecleado en la app del huésped** —`const RECARGO_TARJETA_PCT =
 * 5.5`— con un comentario que decía «si algún día cambia, este es el único sitio». Y no lo era: el
 * mismo número vive en `services_finanzas.yaml` y en `PmsMedioPago::comisionPorcentaje()`. Tres
 * copias, y la del huésped es **la que ve el cliente**: se podía subir el recargo en el servidor y
 * la pantalla del huésped seguiría enseñando el cálculo viejo, sin un solo error.
 *
 * El tope antifraude nace con el mismo problema el día que se ponga en un formulario, así que sale
 * por aquí desde el principio.
 *
 * ## Qué cabe aquí y qué no
 *
 * ⚠️ **Sólo valores que ya son públicos por su efecto.** El recargo se le enseña al cliente en su
 * pantalla; el tope se descubre intentando pagar. Publicar esto no cuenta nada que no se sepa ya
 * pagando una vez.
 *
 * ⚠️ **Y por eso NO lleva sesión.** `pax` no tiene la del operador —es la app del huésped— y
 * exigirle una obligaría a inventar un segundo camino para los mismos tres números. Lo que sí hay
 * es una lista blanca escrita a mano: esto **no se convierte** en «devuelve el parámetro que te
 * pidan», que es como un endpoint de configuración acaba filtrando una credencial.
 *
 * ⚠️ **Los valores son cadenas, no números.** Son importes y porcentajes: pasarlos por un `float`
 * en el camino es cómo `5.5` se convierte en `5.5000000000000004`.
 */
#[AsController]
final class ConfigFrontController extends AbstractController
{
    /** @param array<string, string> $limitePorCargo */
    public function __construct(
        #[Autowire('%finanzas.recargo_tarjeta_porcentaje%')]
        private readonly string $recargoTarjetaPorcentaje,
        #[Autowire('%finanzas.limite_por_cargo%')]
        private readonly array $limitePorCargo,
    ) {
    }

    #[Route('/config/front', name: 'app_config_front', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $respuesta = new JsonResponse([
            // Lo que se le suma al cliente por pagar con tarjeta.
            'recargoTarjetaPorcentaje' => $this->recargoTarjetaPorcentaje,
            // Tope antifraude de la pasarela, por divisa. Una divisa que no esté aquí no tiene
            // tope conocido, y el front no debe inventarse uno.
            'limitePorCargo' => $this->limitePorCargo,
        ]);

        // Cambia poco y lo pide cada arranque de las dos apps: un minuto de caché quita casi todas
        // las peticiones sin que un cambio tarde en verse.
        $respuesta->setPublic();
        $respuesta->setMaxAge(60);

        return $respuesta;
    }
}
