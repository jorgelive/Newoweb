<?php

declare(strict_types=1);

namespace App\Panel\Controller\Trait;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;

/**
 * Renderiza una galería de thumbnails (Liip) + modal para el index de EasyAdmin.
 * Contrato: el controlador que lo use debe exponer $this->imagineCacheManager
 * (inyecta CacheManager en su constructor).
 */
trait RenderGaleriaTrait
{
    // 🔒 Contrato explícito: cada controller que use el trait debe proveer el CacheManager.
    abstract protected function getImagineCacheManager(): CacheManager;
    private function resolveThumbUrl(string $uploadPath, string $imageName, string $filterSet): string
    {
        $relativePath = ltrim($uploadPath, '/') . '/' . $imageName;
        return $this->getImagineCacheManager()->getBrowserPath($relativePath, $filterSet);
    }

    /**
     * @param iterable<object> $imagenes cada ítem debe tener getImageName() (opcional getIsPortada())
     */
    private function renderGaleriaThumbnails(
        iterable $imagenes,
        object $entity,
        string $uploadPath,
        string $modalPrefix,
        string $thumbFilter = 'pms_thumb_admin',
        string $fullFilter = 'pms_compress_initial',
    ): string {
        $modalId = $modalPrefix . '-' . str_replace('-', '', (string) $entity->getId());
        $thumbsHtml = '<div class="d-flex flex-wrap gap-1" style="max-width:260px;">';
        $modalItemsHtml = '';
        $tieneImagenes = false;
        $i = 0;

        foreach ($imagenes as $imagen) {
            if (!method_exists($imagen, 'getImageName') || !$imagen->getImageName()) {
                continue;
            }
            $tieneImagenes = true;

            $thumbUrl = $this->resolveThumbUrl($uploadPath, $imagen->getImageName(), $thumbFilter);
            $fullUrl  = $this->resolveThumbUrl($uploadPath, $imagen->getImageName(), $fullFilter);
            $alt = htmlspecialchars(sprintf('Foto %d', $i + 1));

            $portadaBadge = (method_exists($imagen, 'getIsPortada') && $imagen->getIsPortada())
                ? '<span class="badge bg-warning text-dark position-absolute top-0 start-0" style="font-size:8px;padding:1px 4px;">★</span>'
                : '';

            $thumbsHtml .= sprintf(
                '<div class="position-relative" style="width:42px;height:42px;">
                    <img src="%s" alt="%s" loading="lazy" class="rounded border"
                         style="width:100%%;height:100%%;object-fit:cover;cursor:pointer;"
                         data-bs-toggle="modal" data-bs-target="#%s">%s
                </div>',
                htmlspecialchars($thumbUrl), $alt, $modalId, $portadaBadge
            );

            $modalItemsHtml .= sprintf(
                '<div class="col-6 col-md-4 mb-3"><img src="%s" alt="%s" class="img-fluid rounded shadow-sm w-100" style="object-fit:cover;max-height:260px;"></div>',
                htmlspecialchars($fullUrl), $alt
            );
            $i++;
        }
        $thumbsHtml .= '</div>';

        if (!$tieneImagenes) {
            return '<span class="text-muted small"><i class="fas fa-images"></i> Sin fotos</span>';
        }

        $modalHtml = sprintf(
            '<div class="modal fade" id="%s" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header"><h6 class="modal-title">Galería — %s</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body"><div class="row">%s</div></div>
                </div></div>
            </div>',
            $modalId, htmlspecialchars((string) $entity), $modalItemsHtml
        );

        return $thumbsHtml . $modalHtml;
    }
}