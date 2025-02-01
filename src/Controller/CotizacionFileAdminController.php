<?php

namespace App\Controller;

use App\Entity\MaestroPais;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Service\MainArchivoexcel;
use App\Service\MainArchivozip;
use App\Service\MainVariableproceso;

class CotizacionFileAdminController extends CRUDAdminController
{

    private EntityManagerInterface $entityManager;

    private MainVariableproceso $variableproceso;

    private MainArchivoexcel $archivoexcel;

    private MainArchivozip $archivozip;

    function __construct(
        EntityManagerInterface $entityManager,
        MainVariableproceso $variableproceso,
        MainArchivoexcel $archivoexcel,
        MainArchivozip $archivozip
    )
    {
        $this->entityManager = $entityManager;

        $this->variableproceso = $variableproceso;

        $this->archivoexcel = $archivoexcel;

        $this->archivozip = $archivozip;

    }

    public function archivomcAction(Request $request): Response
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

        $qb = $this->entityManager->createQueryBuilder()
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
        $encabezado = [
            'Item',
            'Procedencia', 'idProcedencia', 'Pais', 'idPais',
            'tarifa', 'idTarifa', 'Tipo de Documento', 'idTipoDocumento', 'Nro Documento',
            'Fecha de nacimiento', 'Nombres', 'Apellido Paterno', 'Apellido Materno',
            'Cod File', 'Sexo'
        ];
        $i = 1;
        foreach($filePasajeros as $key => $filePasajero){

            $paisId = $filePasajero->getPais()->getId();
            $edad = $filePasajero->getEdad();
            $documentoId = $filePasajero->getTipodocumento()->getId();

            if ($edad < 3){ continue; }

            $tarifas = [
                'promocional' => ['nombre' => 'General Promocional', 'codigo' => 4],
                'menor_promocional' => ['nombre' => 'Menor de edad entre 3 - 17 años Promocional', 'codigo' => 6],
                'general' => ['nombre' => 'General', 'codigo' => 1],
                'menor' => ['nombre' => 'Menor de edad entre 3 - 17 años', 'codigo' => 3],
            ];

            $procedencias = [
                'peruano' => ['nombre' => 'Peruano', 'codigo' => 2],
                'can_residente' => ['nombre' => 'Países CAN y Residente extranjero', 'codigo' => 3],
                'extranjero' => ['nombre' => 'Extranjero', 'codigo' => 1],
            ];

            // Definir tarifa y procedencia por defecto
            if ($paisId == 117) {
                $procedencia = $procedencias['peruano'];
            } elseif (in_array($paisId, [20, 41, 32]) || $documentoId == 3) {
                $procedencia = $procedencias['can_residente'];
            } else {
                $procedencia = $procedencias['extranjero'];
            }

            // Determinar la tarifa
            if ($edad >= 18) {
                $tarifa = ($procedencia['codigo'] == 2 || $procedencia['codigo'] == 3) ? $tarifas['promocional'] : $tarifas['general'];
            } else {
                $tarifa = ($procedencia['codigo'] == 2 || $procedencia['codigo'] == 3) ? $tarifas['menor_promocional'] : $tarifas['menor'];
            }

            // Asignar valores finales
            $tarifaNombre = $tarifa['nombre'];
            $tarifaCodigo = $tarifa['codigo'];
            $procedenciaNombre = $procedencia['nombre'];
            $procedenciaCodigo = $procedencia['codigo'];

            $resultados[$key]['correlativo'] = $i;
            $i++;
            $resultados[$key]['Procedencia'] = $procedenciaNombre;
            $resultados[$key]['idProcedencia'] = $procedenciaCodigo;
            $resultados[$key]['paisnombre'] = $filePasajero->getPais()->getNombre();
            $resultados[$key]['paiscodigo'] = $filePasajero->getPais()->getCodigomc();
            $resultados[$key]['tarifanombre'] = $tarifaNombre;
            $resultados[$key]['tarifacodigo'] = $tarifaCodigo;
            $resultados[$key]['tipodocumentonombre'] = $filePasajero->getTipodocumento()->getNombremc();
            $resultados[$key]['tipodocumentocodigo'] = $filePasajero->getTipodocumento()->getCodigomc();
            $resultados[$key]['numerodocumento'] = $filePasajero->getNumerodocumento();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('d-m-Y');
            $resultados[$key]['nombre'] = $filePasajero->getNombre();
            $resultados[$key]['apellidoPaterno'] = $filePasajero->getApellidoPaterno();
            $resultados[$key]['apellidoMaterno'] = $filePasajero->getApellidoMaterno();
            $resultados[$key]['file'] = 'F' . sprintf('%07d', $object->getId());
            $resultados[$key]['sexo'] = $filePasajero->getSexo()->getNombre();
        }

        if(count($resultados) <= $maxLength) {
            return $this->archivoexcel
                ->setArchivo()
                ->setColumnaBase('B')
                ->setFilaBase(3)
                ->setParametrosWriter($resultados, $encabezado, 'MC_' . $object->getNombre(), 'xlsx')
                ->setAnchoColumna(['0:' => 20]) //['A'=>12,'B'=>'auto','0:'=>20]
                ->getResponse();
        }else{

            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->archivoexcel
                    ->setArchivo()
                    ->setColumnaBase('B')
                    ->setFilaBase(3)
                    ->setParametrosWriter($parte, $encabezado, 'MC_' . $object->getNombre(), 'xlsx')
                    ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
                    ->createFile();
                $archivos[$key]['nombre'] = 'MC_' . $object->getNombre() . '_Parte_' . $key + 1 . '.xlsx';
            }

            return $this->archivozip
                ->setParametros($archivos, 'MC_' . $object->getNombre())
                ->procesar()
                ->getResponse();

        }
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

        $qb = $this->entityManager->createQueryBuilder()
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
            $resultados[$key]['tipodoumento'] = $filePasajero->getTipodocumento()->getCodigomc();
            $resultados[$key]['numerodocumento'] = $filePasajero->getNumerodocumento();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('d/m/Y');
            $resultados[$key]['pais'] = $filePasajero->getPais()->getCodigomc();
            $resultados[$key]['sexo'] = $filePasajero->getSexo()->getInicial();
            $resultados[$key]['file'] = 'F' . sprintf('%010d', $object->getId());
            $resultados[$key]['categoria'] = $filePasajero->getCategoriaddc();
        }

        if(count($resultados) <= $maxLength) {
            return $this->archivoexcel
                ->setArchivo()
                ->setParametrosWriter($resultados, $encabezado, 'DDC_' . $object->getNombre(), 'csv', true) //true para quitar comillas de csv
                ->setAnchoColumna(['0:' => 20]) //['A'=>12,'B'=>'auto','0:'=>20]
                ->getResponse();
        }else{

            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->archivoexcel
                    ->setArchivo()
                    ->setParametrosWriter($parte, $encabezado, 'DCC_' . $object->getNombre(), 'csv', true) //true para quitar comillas de csv
                    ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
                    ->createFile();
                $archivos[$key]['nombre'] = 'DCC_' . $object->getNombre() . '_Parte_' . $key + 1 . '.csv';
            }

            return $this->archivozip
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

        $qb = $this->entityManager->createQueryBuilder()
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

        $encabezado = ['TIPO PASAJERO', 'GENERO(F/M)', 'TIPO DOC', 'NRO DOC', 'PRIMER NOMBRE', 'PRIMER APELLIDO', 'FECHA NAC', 'NACIONALIDAD'];
        foreach($filePasajeros as $key => $filePasajero){
            $resultados[$key]['tipopax'] = $filePasajero->getTipopaxperurail();
            $resultados[$key]['sexo'] = $filePasajero->getSexo()->getInicial();
            $resultados[$key]['tipodoumento'] = $filePasajero->getTipodocumento()->getCodigopr();
            $resultados[$key]['numerodocumento'] = $this->variableproceso->stripAccents($filePasajero->getNumerodocumento());
            $resultados[$key]['nombre'] = $filePasajero->getNombre();
            $resultados[$key]['apellido'] =  $filePasajero->getApellido();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('d/m/Y');
            $resultados[$key]['pais'] = $filePasajero->getPais()->getCodigopr();
        }

        if(count($resultados) <= $maxLength){
            return $this->archivoexcel
                ->setArchivoBasePath('perurail.xlsx')
                ->setArchivo()
                ->setFilaBase(2)
                ->setParametrosWriter($resultados, [], 'PERURAIL_' . $object->getNombre(), 'xlsx')
                ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
                ->getResponse();
        }else{
            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->archivoexcel
                    ->setArchivoBasePath('perurail.xlsx')
                    ->setArchivo()
                    ->setFilaBase(2)
                    ->setParametrosWriter($parte, [], 'PERURAIL_' . $object->getNombre(), 'xlsx')
                    ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
                    ->createFile();
                $archivos[$key]['nombre'] = 'PERURAIL_' . $object->getNombre() . '_Parte_' . $key + 1 . '.xlsx';

            }

            return $this->archivozip
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

        $qb = $this->entityManager->createQueryBuilder()
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
            if($filePasajero->getPais()->getId() == MaestroPais::DB_VALOR_PERU){//si es peruano pongo (1610) Lima
                $resultados[$key]['ciudad'] = '1610';
            }else{
                $resultados[$key]['ciudad'] = '';
            }
            $resultados[$key]['sexo'] = $filePasajero->getSexo()->getInicial();

        }

        if(count($resultados) <= $maxLength) {
            return $this->archivoexcel
                ->setArchivoBasePath('consettur.xlsx')
                ->setArchivo()
                ->setFilaBase(2)
                ->setParametrosWriter($resultados, [], 'consettur_' . $object->getNombre(), 'xlsx')
                ->setAnchoColumna(['0:' => 20]) //['A'=>12,'B'=>'auto','0:'=>20]
                ->getResponse();
        }else{
            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->archivoexcel
                    ->setArchivoBasePath('consettur.xlsx')
                    ->setArchivo()
                    ->setFilaBase(2)
                    ->setParametrosWriter($parte, [], 'consettur_' . $object->getNombre(), 'xlsx')
                    ->setAnchoColumna(['0:'=>20]) //['A'=>12,'B'=>'auto','0:'=>20]
                    ->createFile();
                $archivos[$key]['nombre'] = 'consettur_' . $object->getNombre() . '_Parte_' . $key + 1 . '.xlsx';

            }

            return $this->archivozip
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
