<?php

declare(strict_types=1);

namespace App\Message\Validator;

use App\Message\Entity\Message;
use App\Message\Service\MessageDataResolverRegistry;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidTemplateScopeValidator extends ConstraintValidator
{
    public function __construct(
        private readonly MessageDataResolverRegistry $resolverRegistry
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidTemplateScope) {
            throw new UnexpectedTypeException($constraint, ValidTemplateScope::class);
        }

        if (!$value instanceof Message || $value->getTemplate() === null) {
            return; // No hay mensaje o plantilla
        }

        $template = $value->getTemplate();
        $conversation = $value->getConversation();

        if (!$conversation) {
            return;
        }

        $contextType = $conversation->getContextType();

        // 1. Validar Tipo de Módulo (Ej: pms_reserva)
        if ($template->getContextType() !== null && $template->getContextType() !== $contextType) {
            $this->context->buildViolation($constraint->messageTypeMismatch)
                ->setParameter('{{ type }}', $template->getContextType())
                ->atPath('template')
                ->addViolation();
            return;
        }

        // Si es plantilla global (sin filtros), terminamos aquí
        if (empty($template->getAllowedSources()) && empty($template->getAllowedAgencies())) {
            return;
        }

        // 2. Extraer Atributos del Resolver en vivo
        $resolver = $this->resolverRegistry->getResolver($contextType);
        if (!$resolver) {
            return; // Fallback de seguridad
        }

        $meta = $resolver->getMetadata($conversation->getContextId());

        // 3. Validar Fuente (OTA)
        $allowedSources = $template->getAllowedSources();
        if (!empty($allowedSources)) {
            $source = $meta['source'] ?? 'manual';
            if (!in_array($source, $allowedSources, true)) {
                $this->context->buildViolation($constraint->messageSourceMismatch)
                    ->setParameter('{{ source }}', (string)$source)
                    ->atPath('template')
                    ->addViolation();
            }
        }

        // 4. Validar Agencia
        $allowedAgencies = $template->getAllowedAgencies();
        if (!empty($allowedAgencies)) {
            $agency = (string)($meta['agency_id'] ?? '');
            if (!in_array($agency, $allowedAgencies, true)) {
                $this->context->buildViolation($constraint->messageAgencyMismatch)
                    ->atPath('template')
                    ->addViolation();
            }
        }
    }
}