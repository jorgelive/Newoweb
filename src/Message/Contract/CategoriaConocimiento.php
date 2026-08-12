<?php

declare(strict_types=1);

namespace App\Message\Contract;

/**
 * De qué habla un trozo de conocimiento, para poder restarlo por canal.
 *
 * ### Qué NO es
 *
 * No es {@see \App\Pms\Enum\PmsGuiaVisibilidad}, y no lo sustituye. Aquélla dice CUÁNTA
 * confianza hace falta para ver un ítem —la escalera público → cliente → confirmado →
 * ventana—. Ésta dice DE QUÉ TRATA, que es una pregunta independiente: la dirección del
 * alojamiento y el número de camas están hoy los dos en el escalón `Publico`, y sin embargo
 * por una OTA sin confirmar uno se puede dar y el otro no.
 *
 * Sin este eje, la única forma de tapar la dirección era subirla de escalón, y eso la habría
 * escondido también en el catálogo público de la web, donde debe estar.
 *
 * ### Para qué sirve
 *
 * {@see RestriccionCanal::categoriasBloqueadas()} devuelve las categorías que no pueden salir
 * por un canal, y el filtro de la fuente descarta esos ítems ANTES de construir el turno. El
 * modelo no llega a verlos.
 *
 * Sin categoría explícita, un ítem se trata como {@see self::General}: siempre se puede
 * contar. Es el default correcto porque lo que se restringe es la excepción, y hacerlo al
 * revés dejaría mudo al agente en cuanto alguien olvidara clasificar algo.
 */
enum CategoriaConocimiento: string
{
    /** Distribución, camas, equipamiento, normas, check-in… Lo que casi todo el contenido es. */
    case General = 'general';

    /** Calle, número, referencias que permiten plantarse en la puerta. */
    case DireccionExacta = 'direccion_exacta';

    /** Teléfonos, correos, nombres de contacto, redes. */
    case Contacto = 'contacto';

    /** Invitaciones a seguir la conversación fuera: WhatsApp, web propia, reserva directa. */
    case CanalAlternativo = 'canal_alternativo';

    /** Códigos de puerta, caja fuerte, WiFi. Ya protegidos por la escalera, se etiquetan por claridad. */
    case Credenciales = 'credenciales';

    public function getLabel(): string
    {
        return match ($this) {
            self::General => 'General (siempre se puede contar)',
            self::DireccionExacta => 'Dirección exacta',
            self::Contacto => 'Datos de contacto',
            self::CanalAlternativo => 'Canal alternativo (WhatsApp, web, directo)',
            self::Credenciales => 'Credenciales de acceso',
        };
    }

    /**
     * ¿Puede salir por un canal que bloquea estas categorías?
     *
     * @param list<string> $bloqueadas
     */
    public function permitidaCon(array $bloqueadas): bool
    {
        return !in_array($this->value, $bloqueadas, true);
    }
}
