<?php
namespace App\EventListener;

use App\Entity\ReservaChannel;
use App\Entity\ReservaReserva;
use Doctrine\ORM\Event\LifecycleEventArgs;
use App\Service\MainVariableproceso;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;

class ReservaReservaDoctrineEventListener
{

    private $mainVariableproceso;

    public function __construct(MainVariableproceso $mainVariableproceso)
    {
        $this->mainVariableproceso = $mainVariableproceso;
    }

    public function prePersist(PrePersistEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof ReservaReserva) {

            $entity->setToken(mt_rand());

            if(empty($entity->getUid())){
                $entity->setUid(sprintf('%06d', $entity->getUnit()->getId()) . '-' . sprintf('%06d', $entity->getChannel()->getId()) . '-' . sprintf('%012d', mt_rand()) . '@openperu.pe');
            }

            if(!empty($entity->getEnlace())){
                $entity->setEnlace($this->cleanUrl($entity->getEnlace()));
            }

            if($entity->getChannel()->getId() == ReservaChannel::DB_VALOR_DIRECTO){
                $entity->setManual(true);
            }

        }
    }

    public function preUpdate(PreUpdateEventArgs $args)
    {
        $entity = $args->getObject();
        if($entity instanceof ReservaReserva) {

            if(!empty($entity->getEnlace())){
                $entity->setEnlace($this->cleanUrl($entity->getEnlace()));
            }

            if($entity->getChannel()->getId() == ReservaChannel::DB_VALOR_DIRECTO){
                $entity->setManual(true);
            }
        }

    }

    private function cleanUrl(String $enlace): String
    {
        $parsedUrl = parse_url($enlace);
        if(!isset($parsedUrl['query'])){
            return $enlace;
        }

        $params = explode('&', $parsedUrl['query']);
        foreach($params as $key => $param){
            if(is_int(strpos($param, 'ses')) || is_int(strpos($param, 'lang'))){
                unset($params[$key]);
            }
        }
        $parsedUrl['query'] = implode('&', $params);
        return $this->mainVariableproceso->buildUrl($parsedUrl);
    }

}