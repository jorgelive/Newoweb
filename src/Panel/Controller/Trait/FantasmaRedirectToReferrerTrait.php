<?php

namespace App\Panel\Controller\Trait;

use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Trait para forzar la redirección al 'referrer' tras guardar en EasyAdmin.
 * Útil para volver al Calendario en lugar del Index estándar.
 */
trait FantasmaRedirectToReferrerTrait
{
    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        // 1. Detectar qué botón pulsó el usuario
        $submitButtonName = $context->getRequest()->request->all('ea')['newForm']['btn']
            ?? $context->getRequest()->request->all('ea')['editForm']['btn']
            ?? 'saveAndReturn';

        // 2. Si pulsó "Guardar y continuar editando", respetamos el comportamiento estándar
        if ('saveAndContinue' === $submitButtonName) {
            return parent::getRedirectResponseAfterSave($context, $action);
        }

        // 3. BUSCAR REFERRER: La "Opción Nuclear"
        // Capturamos el parámetro manualmente de la URL query string
        $referrer = $context->getRequest()->query->get('referrer');

        if (!empty($referrer)) {
            return $this->redirect($referrer);
        }

        // 4. Si no hay referrer, comportamiento estándar (ir al Index o Detail)
        return parent::getRedirectResponseAfterSave($context, $action);
    }
}