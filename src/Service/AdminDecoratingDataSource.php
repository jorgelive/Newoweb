<?php
namespace App\Service;

use Sonata\AdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\AdminBundle\Exporter\DataSourceInterface;
use Sonata\DoctrineORMAdminBundle\Exporter\DataSource;
use Sonata\Exporter\Source\DoctrineORMQuerySourceIterator;

class AdminDecoratingDataSource implements DataSourceInterface
{
    private DataSource $dataSource;

    public function __construct(DataSource $dataSource)
    {
        $this->dataSource = $dataSource;
    }

    public function createIterator(ProxyQueryInterface $query, array $fields): \Iterator
    {
        /** @var DoctrineORMQuerySourceIterator $iterator */
        $iterator = $this->dataSource->createIterator($query, $fields);

        if(array_key_exists('Fecha Inicio', $fields) || array_key_exists('Fecha Fin', $fields)){
            $iterator->setDateTimeFormat('Y-m-d');
        }else{
            $iterator->setDateTimeFormat('Y-m-d H:i:s');
        }

        $iterator->useBackedEnumValue(false);

        return $iterator;
    }
}