<?php

declare(strict_types=1);

namespace App\Api\Provider\Pms;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Pms\Entity\PmsGuia;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaArbolFiltro;
use App\Pms\Guia\PmsGuiaContexto;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Escaparate público de una unidad: `/casita/casita-1`.
 *
 * NO es la guía del huésped con las partes tachadas. Es otro árbol, construido
 * con otro filtro: PmsGuiaAcceso::publico() solo deja pasar los ítems marcados
 * como `PmsGuiaVisibilidad::Publico`, y PmsGuiaContexto::construir() con evento
 * a null ni siquiera carga las credenciales en memoria. Aunque un ítem público
 * llevara por error un `{{ door_code }}`, no habría valor que sustituir.
 *
 * Ese era el problema del endpoint anterior
 * (/public/pax/pms/pms_guia/pms_unidad/{uuid}): servía el árbol íntegro a
 * cualquiera que tuviera el UUID de la unidad. Las contraseñas no viajaban
 * —no tienen grupo de serialización— pero sí las normas de la casa, los avisos
 * y el texto que rodea a la caja fuerte.
 *
 * El catálogo arranca vacío a propósito: la migración deja todo el contenido
 * existente en `privado`, y publicar es una decisión editorial que se toma
 * ítem a ítem desde el panel.
 */
final class PmsUnidadCatalogoProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PmsGuiaArbolFiltro $filtro,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PmsGuia
    {
        $unidad = $this->em->getRepository(PmsUnidad::class)
            ->findOneBy(['slug' => $uriVariables['unidad'] ?? null]);

        if (!$unidad || !$unidad->isActivo()) {
            return null; // 404 uniforme
        }

        // El slug de unidad es único a nivel global, así que la búsqueda de
        // arriba ya devuelve una sola fila. El segmento del establecimiento se
        // valida igualmente: una URL con el hotel equivocado tiene que dar 404
        // y no servir la unidad correcta bajo una dirección inventada, que
        // duplicaría el contenido en los buscadores.
        if ($unidad->getEstablecimiento()?->getSlug() !== ($uriVariables['establecimiento'] ?? null)) {
            return null;
        }

        $guia = $unidad->getGuia();

        if (!$guia || !$guia->isActivo()) {
            return null;
        }

        $acceso = PmsGuiaAcceso::publico();
        $contexto = PmsGuiaContexto::construir($unidad, null);

        $secciones = $this->filtro->podar($guia, $acceso, $contexto);

        // Sin nada publicado no hay escaparate que enseñar. Devolver la
        // cáscara —título y foto, cero contenido— solo serviría para que los
        // buscadores indexaran una página vacía.
        if ([] === $secciones) {
            return null;
        }

        return $guia
            ->setSeccionesParaCliente($secciones)
            ->setAccesoParaCliente($acceso);
    }
}
