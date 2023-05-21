<?php

namespace App\Controller;

use App\Entity\MaestroPais;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Service\MainArchivoexcel;
use App\Service\MainArchivozip;
use App\Service\MainVariableproceso;

class CotizacionFileAdminController extends CRUDAdminController
{

    public static function getSubscribedServices(): array
    {
        return [
                'App\Service\MainVariableproceso' => MainVariableproceso::class,
                'App\Service\MainArchivoexcel' => MainArchivoexcel::class,
                'App\Service\MainArchivozip' => MainArchivozip::class,
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
            ] + parent::getSubscribedServices();
    }

    public function archivodccAction(Request $request): Response
    {
        $maxLength = 10;

        $object = $this->assertObjectExists($request, true);

        $this->assertObjectExists($request);

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $qb = $em->createQueryBuilder()
            ->select('fp')
            ->from('App\Entity\CotizacionFilepasajero', 'fp')
            ->where('fp.file = :file')
            ->setParameter('file', $object->getId())
            ->orderBy('fp.id', 'ASC')
        ;

        $filePasajeros = $qb->getQuery()->getResult();

        if(empty($filePasajeros)){
            $this->addFlash('sonata_flash_error', 'El file no tiene pasajeros ingresados.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $resultados = [];
        $encabezado = []; //['Apellido', 'Nombre', 'Tipo Doc', 'Número Doc', 'Nacimiento', 'Pais', 'Sexo', 'File', 'Categoria'];
        foreach($filePasajeros as $key => $filePasajero){
            $resultados[$key]['apellido'] = $filePasajero->getApellido();
            $resultados[$key]['nombre'] = $filePasajero->getNombre();
            $resultados[$key]['tipodoumento'] = $filePasajero->getTipodocumento()->getCodigoddc();
            $resultados[$key]['numerodocumento'] = $filePasajero->getNumerodocumento();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('d/m/Y');
            $resultados[$key]['pais'] = $filePasajero->getPais()->getCodigodcc();
            $resultados[$key]['sexo'] = $filePasajero->getSexo()->getInicial();
            $resultados[$key]['file'] = 'F' . sprintf('%010d', $object->getId());
            $resultados[$key]['categoria'] = $filePasajero->getCategoriaddc();
        }

        if(count($resultados) <= $maxLength) {
            return $this->container->get('App\Service\MainArchivoexcel')
                ->setArchivo()
                ->setParametrosWriter($resultados, $encabezado, 'DDC_' . $object->getNombre(), 'csv', true) //true para quitar comillas de csv
                ->setAnchoColumna(['0:' => 20]) //['A'=>12,'B'=>'auto','0:'=>20]
                ->getResponse();
        }else{

            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->container->get('App\Service\MainArchivoexcel')
                    ->setArchivo()
                    ->setParametrosWriter($parte, $encabezado, 'DCC_' . $object->getNombre(), 'csv', true) //true para quitar comillas de csv
                    ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
                    ->createFile();
                $archivos[$key]['nombre'] = 'DCC_' . $object->getNombre() . '_Parte_' . $key + 1 . '.csv';
            }

            return $this->container->get('App\Service\MainArchivozip')
                ->setParametros($archivos, 'DCC_' . $object->getNombre())
                ->procesar()
                ->getResponse();

        }
    }

    public function archivoprAction(Request $request): Response
    {
        $maxLength = 100;

        $object = $this->assertObjectExists($request, true);

        $this->assertObjectExists($request);

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $qb = $em->createQueryBuilder()
            ->select('fp')
            ->from('App\Entity\CotizacionFilepasajero', 'fp')
            ->where('fp.file = :file')
            ->setParameter('file', $object->getId())
            ->orderBy('fp.id', 'ASC')
        ;

        $filePasajeros = $qb->getQuery()->getResult();

        if(empty($filePasajeros)){
            $this->addFlash('sonata_flash_error', 'El file no tiene pasajeros ingresados.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $variableProceso = $this->container->get('App\Service\MainVariableproceso');

        $encabezado = ['TIPO PASAJERO', 'GENERO(F/M)', 'TIPO DOC', 'NRO DOC', 'PRIMER NOMBRE', 'PRIMER APELLIDO', 'FECHA NAC', 'NACIONALIDAD'];
        foreach($filePasajeros as $key => $filePasajero){
            $resultados[$key]['tipopax'] = $filePasajero->getTipopaxperurail();
            $resultados[$key]['sexo'] = $filePasajero->getSexo()->getInicial();
            $resultados[$key]['tipodoumento'] = $filePasajero->getTipodocumento()->getCodigopr();
            $resultados[$key]['numerodocumento'] = $variableProceso->stripAccents($filePasajero->getNumerodocumento());
            $resultados[$key]['nombre'] = $filePasajero->getNombre();
            $resultados[$key]['apellido'] =  $filePasajero->getApellido();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('d/m/Y');
            $resultados[$key]['pais'] = $filePasajero->getPais()->getCodigopr();
        }

        if(count($resultados) <= $maxLength){
            return $this->container->get('App\Service\MainArchivoexcel')
                ->setArchivoBasePath('perurail.xlsx')
                ->setArchivo()
                ->setFilaBase(2)
                ->setParametrosWriter($resultados, [], 'PERURAIL_' . $object->getNombre(), 'xlsx')
                ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
                ->getResponse();
        }else{
            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->container->get('App\Service\MainArchivoexcel')
                    ->setArchivoBasePath('perurail.xlsx')
                    ->setArchivo()
                    ->setFilaBase(2)
                    ->setParametrosWriter($parte, [], 'PERURAIL_' . $object->getNombre(), 'xlsx')
                    ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
                    ->createFile();
                $archivos[$key]['nombre'] = 'PERURAIL_' . $object->getNombre() . '_Parte_' . $key + 1 . '.xlsx';

            }

            return $this->container->get('App\Service\MainArchivozip')
                ->setParametros($archivos, 'PERURAIL_' . $object->getNombre())
                ->procesar()
                ->getResponse();
        }

    }

    public function archivoconAction(Request $request): Response
    {
        $maxLength = 50;

        $object = $this->assertObjectExists($request, true);

        $this->assertObjectExists($request);

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $qb = $em->createQueryBuilder()
            ->select('fp')
            ->from('App\Entity\CotizacionFilepasajero', 'fp')
            ->where('fp.file = :file')
            ->setParameter('file', $object->getId())
            ->orderBy('fp.id', 'ASC')
        ;

        $filePasajeros = $qb->getQuery()->getResult();

        if(empty($filePasajeros)){
            $this->addFlash('sonata_flash_error', 'El file no tiene pasajeros ingresados.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $encabezado = ['Apellido Paterno', 'Apellido Materno', 'Nombres', 'IdTipoDoc', 'NroDoc', 'Fecha de Nacimiento', 'IdPais', 'Ciudad', 'Sexo'];
        foreach($filePasajeros as $key => $filePasajero){

            $resultados[$key]['apellidopaterno'] = $filePasajero->getApellidoPaterno();
            $resultados[$key]['apellidomaterno'] = $filePasajero->getApellidoMaterno();
            $resultados[$key]['nombre'] = $filePasajero->getNombre();
            if($filePasajero->getPais()->getId() != 117){//si no es peruano fuerzo a pasaporte
                $resultados[$key]['tipodocumento'] = '4';
            }else{
                $resultados[$key]['tipodocumento'] = $filePasajero->getTipodocumento()->getCodigocon();
            }
            $resultados[$key]['numerodocumento'] = $filePasajero->getNumerodocumento();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('Y-m-d');

            $resultados[$key]['pais'] = $filePasajero->getPais()->getCodigocon();
            if($filePasajero->getPais()->getId() == MaestroPais::DB_VALOR_PERU){//si es peruano pongo Lima
                $resultados[$key]['ciudad'] = 'Lima';
            }else{
                $resultados[$key]['ciudad'] = '';
            }
            $resultados[$key]['sexo'] = $filePasajero->getSexo()->getInicial();

        }

        if(count($resultados) <= $maxLength) {
            return $this->container->get('App\Service\MainArchivoexcel')
                ->setArchivoBasePath('consettur.xlsx')
                ->setArchivo()
                ->setFilaBase(2)
                ->setParametrosWriter($resultados, [], 'consettur_' . $object->getNombre(), 'xlsx')
                ->setAnchoColumna(['0:' => 20]) //['A'=>12,'B'=>'auto','0:'=>20]
                ->getResponse();
        }else{
            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->container->get('App\Service\MainArchivoexcel')
                    ->setArchivoBasePath('consettur.xlsx')
                    ->setArchivo()
                    ->setFilaBase(2)
                    ->setParametrosWriter($parte, [], 'consettur_' . $object->getNombre(), 'xlsx')
                    ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
                    ->createFile();
                $archivos[$key]['nombre'] = 'consettur_' . $object->getNombre() . '_Parte_' . $key + 1 . '.xlsx';

            }

            return $this->container->get('App\Service\MainArchivozip')
                ->setParametros($archivos, 'consettur_' . $object->getNombre())
                ->procesar()
                ->getResponse();

        }
    }

    public function resumenAction(Request $request = null): Response | RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        //verificamos token
        if($request->get('token') != $object->getToken()){
            $this->addFlash('sonata_flash_error', 'El código de autorización no coincide');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->checkParentChildAssociation($request, $object);

        //$this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();

        //$template = $this->templateRegistry->getTemplate('show'); es privado en la clase padre
        $template = 'cotizacion_file_admin/show.html.twig';

        return $this->renderWithExtraParams($template,
            [
                'object' => $object,
                'action' => 'resumen',
                'elements' => $fields,
            ]);

    }
    
}
