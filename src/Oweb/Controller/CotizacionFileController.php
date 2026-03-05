<?php

namespace App\Oweb\Controller;

use App\Oweb\Entity\MaestroPais;
use App\Oweb\Service\MainArchivoexcel;
use App\Oweb\Service\MainArchivozip;
use App\Oweb\Service\MainVariableproceso;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controlador para la gestión y exportación de archivos relacionados con CotizacionFile.
 * * Gestiona la generación de archivos Excel/CSV para integraciones gubernamentales
 * y de proveedores turísticos locales (Llaqta/MC, PeruRail, Consettur).
 */
class CotizacionFileController extends CRUDController
{
    /**
     * @param EntityManagerInterface $entityManager
     * @param MainVariableproceso $variableproceso
     * @param MainArchivoexcel $archivoexcel
     * @param MainArchivozip $archivozip
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MainVariableproceso $variableproceso,
        private MainArchivoexcel $archivoexcel,
        private MainArchivozip $archivozip
    ) {
    }

    /**
     * Genera el archivo Excel de pasajeros para el Ministerio de Cultura (Llaqta / TuBoletoCultura).
     * * UTILIZA ESTRATEGIA NULL PARA EVITAR BORRAR FÓRMULAS: Inyecta valores en texto puro
     * dejando los campos de ID en `null` para que PhpSpreadsheet no sobrescriba el =BUSCARV interno.
     * * @param Request $request
     * @return Response
     */
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
            ->from('App\Oweb\Entity\CotizacionFilepasajero', 'fp')
            ->where('fp.file = :file')
            ->setParameter('file', $object->getId())
            ->orderBy('fp.id', 'ASC')
        ;

        $filePasajeros = $qb->getQuery()->getResult();

        if(empty($filePasajeros)){
            $this->addFlash('sonata_flash_error', 'El file no tiene pasajeros ingresados.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        // --- MAPEOS ESTRICTOS SEGÚN CSV DEL MINISTERIO DE CULTURA ---
        $tarifasMincul = [
            'promocional'       => 'General promocional',
            'menor_promocional' => 'Menor de edad entre 3-17 años promocional',
            'general'           => 'General',
            'menor'             => 'Menor de edad entre 3 - 17 años', // Nótese los espacios que exige el MC
        ];

        $procedenciasMincul = [
            'peruano'       => 'Peruano',
            'can'           => 'Países CAN',
            'extranjero'    => 'Extranjero',
            'residente'     => 'Residente extranjero',
        ];

        // Mapeo de IDs locales a nombres estrictos de países según Tablas.csv del MC
        $mapaPaisesMincul = [
            117 => 'Perú',
            158 => 'México',
            64  => 'España',
            66  => 'Estados Unidos',
            44  => 'Chile',
            47  => 'Colombia',
            28  => 'Bolivia',
            57  => 'Ecuador',
            32  => 'Brasil',
            189 => 'Reino Unido',
            72  => 'Francia',
            3   => 'Alemania',
            126 => 'Italia',
            41  => 'Canadá',
            16  => 'Australia',
            129 => 'Japón',
            45  => 'China',
            246 => 'Venezuela',
            183 => 'Paraguay',
            243 => 'Uruguay',
            97  => 'Irlanda',
            177 => 'Paises Bajos', // Escrito así en su CSV (sin tilde)
        ];

        // Mapeo de IDs locales a abreviaturas de documentos del MC
        $mapaDocumentosMincul = [
            1 => 'DNI',
            2 => 'PAS',
            3 => 'CE',
            4 => 'RUC',
        ];
        // -----------------------------------------------------------

        $resultados = [];
        $encabezado = []; // No lo necesitamos, la plantilla ya lo trae

        $i = 1;
        foreach($filePasajeros as $key => $filePasajero){

            $paisId = $filePasajero->getPais()->getId();
            $edad = $filePasajero->getEdad();
            $documentoId = $filePasajero->getTipodocumento()->getId();

            if ($edad < 3){ continue; }

            // 1. Determinar procedencia
            if ($paisId == 117) {
                $procedenciaNombre = $procedenciasMincul['peruano'];
            } elseif (in_array($paisId, [20, 41, 32])) {
                $procedenciaNombre = $procedenciasMincul['can'];
            } elseif ($documentoId == 3) {
                $procedenciaNombre = $procedenciasMincul['residente'];
            } else {
                $procedenciaNombre = $procedenciasMincul['extranjero'];
            }

            // 2. Determinar la tarifa
            $esPromocional = in_array($procedenciaNombre, [$procedenciasMincul['peruano'], $procedenciasMincul['can'], $procedenciasMincul['residente']]);
            if ($edad >= 18) {
                $tarifaNombre = $esPromocional ? $tarifasMincul['promocional'] : $tarifasMincul['general'];
            } else {
                $tarifaNombre = $esPromocional ? $tarifasMincul['menor_promocional'] : $tarifasMincul['menor'];
            }

            // 3. Obtener nombres finales desde el mapa o fallback local
            $nombrePaisFinal = $mapaPaisesMincul[$paisId] ?? $filePasajero->getPais()->getNombre();
            $nombreDocFinal = $mapaDocumentosMincul[$documentoId] ?? $filePasajero->getTipodocumento()->getNombremc();

            // 4. Armado de la matriz con inyección de NULL en columnas de ID
            $resultados[$key]['correlativo'] = $i++;

            $resultados[$key]['Procedencia'] = $procedenciaNombre;
            $resultados[$key]['idProcedencia'] = null; // No sobrescribir fórmula

            $resultados[$key]['paisnombre'] = $nombrePaisFinal;
            $resultados[$key]['paiscodigo'] = null; // No sobrescribir fórmula

            $resultados[$key]['tarifanombre'] = $tarifaNombre;
            $resultados[$key]['tarifacodigo'] = null; // No sobrescribir fórmula

            $resultados[$key]['tipodocumentonombre'] = $nombreDocFinal;
            $resultados[$key]['tipodocumentocodigo'] = null; // No sobrescribir fórmula

            $resultados[$key]['numerodocumento'] = $filePasajero->getNumerodocumento();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('d-m-Y');
            $resultados[$key]['nombre'] = $filePasajero->getNombre();
            $resultados[$key]['apellidoPaterno'] = $filePasajero->getApellidoPaterno();
            $resultados[$key]['apellidoMaterno'] = $filePasajero->getApellidoMaterno();
            $resultados[$key]['file'] = 'F' . sprintf('%07d', $object->getId());
            $resultados[$key]['sexo'] = $filePasajero->getSexo()->getNombre();
        }

        // FORZAR MODO TEXTO PURO: Evita que PhpSpreadsheet borre ceros a la izquierda o mute fechas
        Cell::setValueBinder(new StringValueBinder());

        if(count($resultados) <= $maxLength) {
            $response = $this->archivoexcel
                ->setArchivoBasePath('tuboletocultura.xlsx')
                ->setArchivo()
                ->setColumnaBase('B')
                ->setFilaBase(4)
                ->setParametrosWriter($resultados, $encabezado, 'MC_' . $object->getNombre(), 'xlsx')
                ->getResponse();
        } else {
            $partes = array_chunk($resultados, $maxLength);
            $archivos = [];

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->archivoexcel
                    ->setArchivoBasePath('tuboletocultura.xlsx')
                    ->setArchivo()
                    ->setColumnaBase('B')
                    ->setFilaBase(4)
                    ->setParametrosWriter($parte, $encabezado, 'MC_' . $object->getNombre(), 'xlsx')
                    ->createFile();
                $archivos[$key]['nombre'] = 'MC_' . $object->getNombre() . '_Parte_' . ($key + 1) . '.xlsx';
            }

            $response = $this->archivozip
                ->setParametros($archivos, 'MC_' . $object->getNombre())
                ->procesar()
                ->getResponse();
        }

        // RESTAURAR AL BINDER POR DEFECTO PARA NO AFECTAR OTROS EXPORTADORES
        Cell::setValueBinder(new DefaultValueBinder());

        return $response;
    }

    /**
     * Genera el archivo CSV de pasajeros para la DDC (Dirección Desconcentrada de Cultura).
     */
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
            ->from('App\Oweb\Entity\CotizacionFilepasajero', 'fp')
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
        $encabezado = [];
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
                ->setParametrosWriter($resultados, $encabezado, 'DDC_' . $object->getNombre(), 'csv', true)
                ->setAnchoColumna(['0:' => 20])
                ->getResponse();
        }else{

            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->archivoexcel
                    ->setArchivo()
                    ->setParametrosWriter($parte, $encabezado, 'DCC_' . $object->getNombre(), 'csv', true)
                    ->setAnchoColumna(['0:'=>20])
                    ->createFile();
                $archivos[$key]['nombre'] = 'DCC_' . $object->getNombre() . '_Parte_' . $key + 1 . '.csv';
            }

            return $this->archivozip
                ->setParametros($archivos, 'DCC_' . $object->getNombre())
                ->procesar()
                ->getResponse();
        }
    }

    /**
     * Genera el archivo Excel de pasajeros formateado específicamente para PeruRail.
     */
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
            ->from('App\Oweb\Entity\CotizacionFilepasajero', 'fp')
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
                ->setAnchoColumna(['0:'=>20])
                ->getResponse();
        }else{
            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->archivoexcel
                    ->setArchivoBasePath('perurail.xlsx')
                    ->setArchivo()
                    ->setFilaBase(2)
                    ->setParametrosWriter($parte, [], 'PERURAIL_' . $object->getNombre(), 'xlsx')
                    ->setAnchoColumna(['0:'=>20])
                    ->createFile();
                $archivos[$key]['nombre'] = 'PERURAIL_' . $object->getNombre() . '_Parte_' . $key + 1 . '.xlsx';

            }

            return $this->archivozip
                ->setParametros($archivos, 'PERURAIL_' . $object->getNombre())
                ->procesar()
                ->getResponse();
        }
    }

    /**
     * Genera el archivo Excel de pasajeros formateado para Consettur (Buses Machu Picchu).
     */
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
            ->from('App\Oweb\Entity\CotizacionFilepasajero', 'fp')
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
            if($filePasajero->getPais()->getId() != 117){
                $resultados[$key]['tipodocumento'] = '4';
            }else{
                $resultados[$key]['tipodocumento'] = $filePasajero->getTipodocumento()->getCodigocon();
            }
            $resultados[$key]['numerodocumento'] = $filePasajero->getNumerodocumento();
            $resultados[$key]['fechanacimiento'] = $filePasajero->getFechanacimiento()->format('Y-m-d');

            $resultados[$key]['pais'] = $filePasajero->getPais()->getCodigocon();
            if ($filePasajero->getPais()->getIso2() === MaestroPais::ISO_PERU) {
                $resultados[$key]['ciudad'] = MaestroPais::CODIGO_CIUDAD_DEFAULT_COSETTUR_PERU;
            } else {
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
                ->setAnchoColumna(['0:' => 20])
                ->getResponse();
        }else{
            $partes = array_chunk($resultados, $maxLength);

            foreach($partes as $key => $parte){
                $archivos[$key]['path'] = $this->archivoexcel
                    ->setArchivoBasePath('consettur.xlsx')
                    ->setArchivo()
                    ->setFilaBase(2)
                    ->setParametrosWriter($parte, [], 'consettur_' . $object->getNombre(), 'xlsx')
                    ->setAnchoColumna(['0:'=>20])
                    ->createFile();
                $archivos[$key]['nombre'] = 'consettur_' . $object->getNombre() . '_Parte_' . $key + 1 . '.xlsx';

            }

            return $this->archivozip
                ->setParametros($archivos, 'consettur_' . $object->getNombre())
                ->procesar()
                ->getResponse();

        }
    }

    /**
     * Muestra la vista detallada (resumen) de la cotización o file.
     */
    public function resumenAction(?Request $request = null): Response | RedirectResponse
    {
        $object = $this->assertObjectExists($request, true);
        \assert(null !== $object);

        if($request->get('token') != $object->getToken()){
            $this->addFlash('sonata_flash_error', 'El código de autorización no coincide');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->checkParentChildAssociation($request, $object);

        $preResponse = $this->preShow($request, $object);
        if(null !== $preResponse) {
            return $preResponse;
        }

        $this->admin->setSubject($object);

        $fields = $this->admin->getShow();
        $template = 'oweb/admin/cotizacion_file/show.html.twig';

        return $this->renderWithExtraParams($template,
            [
                'object' => $object,
                'action' => 'resumen',
                'elements' => $fields,
            ]);
    }
}