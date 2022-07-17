<?php

namespace App\Service;

use \Symfony\Component\DependencyInjection\ContainerAwareInterface;
use \Symfony\Component\DependencyInjection\ContainerAwareTrait;
use \Doctrine\ORM\EntityManagerInterface;


class MainTipocambio implements ContainerAwareInterface{

    use ContainerAwareTrait;

    private $doctrine;

    function getDoctrine(){
        return $this->doctrine;
    }


    function __construct(EntityManagerInterface $em)
    {
        $this->doctrine = $em;
    }

    /**
     * @param mixed $mensaje
     * @return boolean
     */
    public function getTipodecambio(\DateTime $fecha)
    {

        $enDB = $this->getDoctrine()->getRepository('App\Entity\MaestroTipocambio')
            ->findOneBy(['moneda' => 2, 'fecha' => $fecha]);

        if ($enDB){
            return $enDB;
        }
        $valores = $this->leerPagina($fecha);

        return $this->insertTipo(end($this->formatearValoresJson($valores)), $fecha);

    }

    private function insertTipo($tipo, $fecha){

        $em = $this->getDoctrine();

        $moneda = $em->getReference('App\Entity\MaestroMoneda', 2);

        $entity = new \App\Entity\MaestroTipocambio();
        $entity->setCompra($tipo['compra']);
        $entity->setVenta($tipo['venta']);
        $entity->setFecha($fecha);
        $entity->setMoneda($moneda);

        $em->persist($entity);
        $em->flush();

        return $entity;

    }

    private function leerPagina(\DateTime $fecha){

        $token = 'apis-token-1.aTSI1U7KEuT-6bbbCguH-4Y8TI6KS73N';

        try {
            $ch = curl_init();

            // Check if initialization had gone wrong*
            if ($ch === false) {
                throw new \Exception('failed to initialize');
            }

            curl_setopt_array($ch, array(
                CURLOPT_URL => 'https://api.apis.net.pe/v1/tipo-cambio-sunat?month=' . $fecha->format('m') . '&year=' .  $fecha->format('Y'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_HTTPHEADER => array(
                    'Referer: https://apis.net.pe/tipo-de-cambio-sunat-api',
                    'Authorization: Bearer ' . $token
                ),
            ));

            $content = curl_exec($ch);

            // Check the return value of curl_exec(), too
            if ($content === false) {
                throw new Exception(curl_error($ch), curl_errno($ch));
            }

            $data = json_decode($content,true);

            curl_close($ch);

            return $data;

        } catch(Exception $e) {

            trigger_error(sprintf(
                'Curl failed with error #%d: %s',
                $e->getCode(), $e->getMessage()),
                E_USER_ERROR);

        }

    }


    private function formatearValoresJson($json){
        $result = [];

        foreach ($json as $index => $valor) {
            $fecha = $valor['fecha'];
            $result[$fecha]['date'] = new \DateTime($fecha);
            $result[$fecha]['compra'] = $valor['compra'];
            $result[$fecha]['venta'] = $valor['venta'];
        }
        return $result;
    }

}