<?php

namespace App\Controller;


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


    /**
     *
     */
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


        $status = Response::HTTP_OK;
        return $this->makeFileResponse($archivoexcel, $status);
    }

    function makeFileResponse($archivo, $status): Response
    {

        $mimeType = $archivo->getContentType();
        $filename = $archivo->getFilename();

        $response = new Response();
        $response->headers->set('Content-Type', sprintf('%s; charset=utf-8', $mimeType));
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s', $filename));
        $response->setContent($archivo->export());
        $response->setStatusCode($status);
        return $response;
    }

}
