<?php
namespace App\Service;

use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Exporter\DataSourceInterface;
use Sonata\DoctrineORMAdminBundle\Exporter\DataSource;
use Sonata\Exporter\Source\DoctrineORMQuerySourceIterator;

/**
 * Decorador del DataSource de Sonata usado exclusivamente en EXPORTACIONES.
 *
 * Objetivo:
 * - Ajustar el formato de fechas según el tipo de exportación.
 * - En exportes de RESERVAS (con Fecha Inicio / Fecha Fin),
 *   las fechas de estadía se exportan SIN hora (Y-m-d).
 * - En cualquier otro export, se mantiene el formato completo con hora.
 *
 * Contexto de uso:
 * - Es invocado automáticamente por Sonata Admin al exportar (CSV/XLS),
 *   no se llama manualmente desde ningún Admin ni Controller.
 */
class AdminDecoratingDataSource implements DataSourceInterface
{
    /**
     * DataSource original de Sonata (inyectado como .inner mediante decorates)
     */
    private DataSource $dataSource;

    public function __construct(DataSource $dataSource)
    {
        $this->dataSource = $dataSource;
    }

    public function createIterator(ProxyQueryInterface $query, array $fields): \Iterator
    {
        /** @var DoctrineORMQuerySourceIterator $iterator */
        $iterator = $this->dataSource->createIterator($query, $fields);

        // Normalizamos los labels (keys) para evitar problemas de mayúsculas/minúsculas.
        // En este exportador los labels siempre vienen como keys desde configureExportFields().
        $normalizedKeys = array_map('mb_strtolower', array_keys($fields));

        $hasBusinessDates =
            in_array('fecha inicio', $normalizedKeys, true)
            || in_array('fecha fin', $normalizedKeys, true);

        // Si el export incluye fechas de estadía (reservas),
        // se exportan como fechas "de negocio" sin hora.
        if ($hasBusinessDates) {
            $iterator->setDateTimeFormat('Y-m-d');
        } else {
            // Para fechas técnicas (createdAt, updatedAt, etc.)
            $iterator->setDateTimeFormat('Y-m-d H:i:s');
        }

        // Exporta enums como su valor respaldado (no como nombre del enum)
        $iterator->useBackedEnumValue(false);

        return $iterator;
    }
}