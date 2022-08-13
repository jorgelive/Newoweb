<?php

namespace App\Controller;


use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Service\MainArchivoexcel;

class CotizacionFileAdminController extends CRUDAdminController
{

    public static function getSubscribedServices(): array
    {
        return [
                'App\Service\MainArchivoexcel' => MainArchivoexcel::class,
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class
            ] + parent::getSubscribedServices();
    }

    public function archivodccAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);

        $this->assertObjectExists($request);

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
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
        foreach ($filePasajeros as $key => $filePasajero){
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

        return $this->container->get('App\Service\MainArchivoexcel')
            ->setArchivo()
            ->setParametrosWriter($resultados, $encabezado, 'DDC_' . $object->getNombre(), 'csv', true) //true para quitar comillas de csv
            ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
            ->getArchivo();
    }

    public function archivoprAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);

        $this->assertObjectExists($request);

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
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

        $encabezado = ['ITEM RESERVA', 'PRIMER NOMBRE', 'PRIMER APELLIDO', 'TIPO DOC', 'NRO DOC', 'NACIONALIDAD', 'FECHA NAC.', 'REF CLIENTE'];
        foreach ($filePasajeros as $key => $filePasajero){
            $resultados[$key]['item'] = 1;
            $resultados[$key]['nombre'] = $filePasajero->getNombre();
            $resultados[$key]['apellido'] = $filePasajero->getApellido();
            $resultados[$key]['tipodoumento'] = $filePasajero->getTipodocumento()->getCodigopr();
            $resultados[$key]['numerodocumento'] = $filePasajero->getNumerodocumento();
            $resultados[$key]['pais'] = $filePasajero->getPais()->getCodigopr();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('d/m/Y');
            $resultados[$key]['file'] = 'F' . sprintf('%010d', $object->getId());

        }

        return $this->container->get('App\Service\MainArchivoexcel')
            ->setArchivo()
            ->setParametrosWriter($resultados, $encabezado, 'PERURAIL_' . $object->getNombre(), 'xls')
            ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
            ->getArchivo();
    }

    public function archivoconAction(Request $request): Response
    {
        $object = $this->assertObjectExists($request, true);

        $this->assertObjectExists($request);

        $this->checkParentChildAssociation($request, $object);

        $this->admin->checkAccess('show', $object);

        $preResponse = $this->preShow($request, $object);
        if (null !== $preResponse) {
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
            $this->addFlash('sonata_flash_error', 'No exixte el servicio concetir para la cotización confirmada del file.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $fechaservicio = $cotcomponentes['0']->getFechahorainicio();
        $encabezado = ['fecha de uso', 'tramo', 'tipo de documento', 'numero de documento', 'nombres', 'apellido', 'apellido materno', 'fecha de nacimiento', 'genero', 'pais', 'ciudad', 'tipo de residente', 'es estudiante', 'es guia', 'es discapacitado'];
        foreach ($filePasajeros as $key => $filePasajero){
            $edad = $fechaservicio->diff($filePasajero->getFechanacimiento())->y;
            if($edad>=12 && $edad<=17 && $filePasajero->getPais()->getId() == 117){
                $esEstudiante = 'SI';
            }else{
                $esEstudiante = 'NO';
            }

            $resultados[$key]['fechauso'] = $fechaservicio->format('d-m-Y');
            $resultados[$key]['tramo'] = 'Subida y Bajada';
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

        return $this->container->get('App\Service\MainArchivoexcel')
            ->setArchivo()
            ->setParametrosWriter($resultados, $encabezado, 'consettur_' . $object->getNombre())
            ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
            ->getArchivo();
    }
    
}
