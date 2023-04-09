<?php

namespace App\Controller;


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
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class
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

        $qb = $em->createQueryBuilder()
            ->select('cc')
            ->from('App\Entity\CotizacionCotcomponente', 'cc')
            ->innerJoin('cc.cotservicio', 'cs')
            ->innerJoin('cs.cotizacion', 'c')
            ->innerJoin('c.file', 'f')
            ->where('f.id = :file')
            ->andWhere('c.estadocotizacion = :estado')
            ->andWhere($qb->expr()->in('cc.componente', [30, 85])) //Consettur RT y OW
            ->setParameter('file', $object->getId())
            ->setParameter('estado', 3) //aceptado
            ->orderBy('cc.id', 'ASC')
            ->setMaxResults(1);
        ;

        $cotcomponentes = $qb->getQuery()->getResult();
        if(empty($cotcomponentes)){
            $this->addFlash('sonata_flash_error', 'No existe el servicio CONSETTUR para la cotización confirmada del file o el file no esta confirmado.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $fechaservicio = $cotcomponentes['0']->getFechahorainicio();
        $encabezado = ['fecha de uso', 'tramo', 'tipo de documento', 'numero de documento', 'nombres', 'apellido', 'apellido materno', 'fecha de nacimiento', 'genero', 'pais', 'ciudad', 'tipo de residente', 'es estudiante', 'es guia', 'es discapacitado'];
        foreach($filePasajeros as $key => $filePasajero){
            $edad = $fechaservicio->diff($filePasajero->getFechanacimiento())->y;
            if($edad>=12 && $edad<=17 && $filePasajero->getPais()->getId() == 117){
                $esEstudiante = 'SI';
            }else{
                $esEstudiante = 'NO';
            }

            $resultados[$key]['fechauso'] = $fechaservicio->format('d-m-Y');
            $resultados[$key]['tramo'] = 'Subida y Bajada';
            if($filePasajero->getPais()->getId() != 117){//si no es peruano fuerzo a pasaporte
                $resultados[$key]['tipodoumento'] = 'PASAPORTE';
            }else{
                $resultados[$key]['tipodoumento'] = $filePasajero->getTipodocumento()->getCodigocon();
            }
            $resultados[$key]['tipodoumento'] = $filePasajero->getTipodocumento()->getCodigocon();
            $resultados[$key]['numerodocumento'] = $filePasajero->getNumerodocumento();
            $resultados[$key]['nombre'] = $filePasajero->getNombre();
            $resultados[$key]['apellidopaterno'] = $filePasajero->getApellidoPaterno();
            $resultados[$key]['apellidomaterno'] = $filePasajero->getApellidoMaterno();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('d-m-Y');
            $resultados[$key]['sexo'] = $filePasajero->getSexo()->getInicial();
            $resultados[$key]['pais'] = $filePasajero->getPais()->getCodigocon();
            $resultados[$key]['ciudad'] = $filePasajero->getPais()->getCiudadcon();
            $resultados[$key]['residente'] = '';
            $resultados[$key]['estudiante'] = $esEstudiante;
            $resultados[$key]['guia'] = 'NO';
            $resultados[$key]['discapacitado'] = 'NO';

        }

        if(count($resultados) <= $maxLength) {
            return $this->container->get('App\Service\MainArchivoexcel')
                ->setArchivo()
                ->setParametrosWriter($resultados, $encabezado, 'consettur_' . $object->getNombre(), 'xlsx')
                ->setAnchoColumna(['0:' => 20]) //['A'=>12,'B'=>'auto','0:'=>20]
                ->getResponse();
        }else{
            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->container->get('App\Service\MainArchivoexcel')
                    ->setArchivo()
                    ->setParametrosWriter($parte, $encabezado, 'consettur_' . $object->getNombre(), 'xlsx')
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
    
}
