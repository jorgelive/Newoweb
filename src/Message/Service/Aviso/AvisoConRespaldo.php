<?php

declare(strict_types=1);

namespace App\Message\Service\Aviso;

use App\Repository\UserRepository;
use App\Service\WebPushNotificationService;

/**
 * Un aviso al equipo por WhatsApp que, si no llega a nadie, sale por el push del panel.
 *
 * WhatsApp no llega cuando nadie del rol tiene móvil, o cuando se está fuera de la ventana de 24 h
 * y la plantilla de respaldo todavía no la ha aprobado Meta. El push sólo llega a quien tenga el
 * panel instalado, pero es gratis y no depende de nada de eso: mejor eso que nada.
 *
 * Nace de juntar dos copias del mismo `if (!alguienFueAvisado()) foreach push` (el choque con una
 * noche extra y la hora que confirma un huésped). {@see AvisoAlEquipoService} se queda como está
 * a propósito: el escalado y el cobro deciden su propio respaldo.
 */
final readonly class AvisoConRespaldo
{
    public function __construct(
        private AvisoAlEquipoService $avisos,
        private WebPushNotificationService $push,
        private UserRepository $usuarios,
    ) {}

    /**
     * @param string $titulo Del push, por si hace falta. El cuerpo es el texto del aviso.
     * @param string $url    Dónde abre el push en el panel.
     */
    public function notificar(AvisoAlEquipo $aviso, string $titulo, string $url): ResultadoAviso
    {
        $resultado = $this->avisos->notificar($aviso);

        if (!$resultado->alguienFueAvisado()) {
            foreach ($this->usuarios->findByRole($aviso->rol) as $usuario) {
                $this->push->sendToUser($usuario, [
                    'title' => $titulo,
                    'body' => $aviso->texto,
                    'actionUrl' => $url,
                ]);
            }
        }

        return $resultado;
    }
}
