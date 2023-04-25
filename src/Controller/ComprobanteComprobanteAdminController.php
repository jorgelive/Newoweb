<?php

namespace App\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\MainVariableproceso;
use App\Service\MainTipocambio;

class ComprobanteComprobanteAdminController extends CRUDAdminController
{
    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'App\Service\MainVariableproceso' => MainVariableproceso::class,
                'App\Service\MainTipocambio' => MainTipocambio::class
            ] + parent::getSubscribedServices();
    }

    function generarcopiaAction()
    {
        $object = $this->admin->getSubject();
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        if(!$object) {
            throw new NotFoundHttpException(sprintf('No se puede encontrar el objeto con el identificador : %s', $this->admin->getIdParameter()));
        }

        if($object->getTipo()->getId() !== 1){
            $this->addFlash('sonata_flash_error', 'Solo se puede copiar facturas.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if(!($object->getEstado()->getId() == 3 || $object->getEstado()->getId() == 4)){
            $this->addFlash('sonata_flash_error', 'La factura debe tener estado emitida o pagada para generar una copia.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if(is_null($object->getDocumento())){
            $this->addFlash('sonata_flash_error', 'La factura debe estar emitida con número correlativo para generar una copia.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->admin->checkAccess('create', $object);

        $newObject = clone $object;

        $newObject->setUrl(null);
        $newObject->setNeto(null);
        $newObject->setImpuesto(null);
        $newObject->setTotal(null);
        $newObject->setSerie(null);
        $newObject->setDocumento(null);
        $newObject->setFechaemision(null);

        $newObject->setEstado($em->getReference('App\Entity\ComprobanteEstado', 1));

        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Copia generada correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    function generarnotacreditoAction()
    {
        $object = $this->admin->getSubject();
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        if(!$object) {
            throw new NotFoundHttpException(sprintf('No se puede encontrar el objeto con el identificador : %s', $this->admin->getIdParameter()));
        }

        if($object->getTipo()->getId() !== 1){
            $this->addFlash('sonata_flash_error', 'Solo se puede emitir notas de crédito de facturas.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if(!($object->getEstado()->getId() == 3 || $object->getEstado()->getId() == 4)){
            $this->addFlash('sonata_flash_error', 'La factura debe tener estado emitida o pagada para poder emitir una nota de crédito.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if(is_null($object->getDocumento())){
            $this->addFlash('sonata_flash_error', 'La factura debe estar emitida con número correlativo para generar una nota de crédito.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $this->admin->checkAccess('create', $object);

        $newObject = clone $object;

        $newObject->setOriginal($object);
        $newObject->setUrl(null);
        $newObject->setNeto(null);
        $newObject->setImpuesto(null);
        $newObject->setTotal(null);
        $newObject->setSerie(null);
        $newObject->setDocumento(null);
        $newObject->setFechaemision(null);

        $newObject->setEstado($em->getReference('App\Entity\ComprobanteEstado', 1));
        $newObject->setTipo($em->getReference('App\Entity\ComprobanteTipo', 3));

        $this->admin->create($newObject);

        $this->addFlash('sonata_flash_success', 'Nota de crédito generada correctamente');

        return new RedirectResponse($this->admin->generateUrl('list'));
    }

    function emitirAction(Request $request = null)
    {
        $fechaEmision = new \DateTime();


        //solo facturamos en el cas de que se use el servidor de produccion
        if($request->getHost() == 'oweb.openperu.pe' || $request->getHost() == 'openperu.pe'){
            $ruta = $this->getParameter('facturacion_ruta_produccion');
            $token = $this->getParameter('facturacion_token_produccion');
        }else{
            $ruta = $this->getParameter('facturacion_ruta_prueba');
            $token = $this->getParameter('facturacion_token_prueba');
        }

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $object = $this->admin->getSubject();

        if(!$object) {
            throw new NotFoundHttpException(sprintf('No se puede encontrar el objeto con el identificador : %s', $this->admin->getIdParameter()));
        }

        if(!$object->getTipo()
            || $object->getTipo()->getId() <= 0
        ){
            $this->addFlash('sonata_flash_error', 'Este documento no admite la emision de una factura.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $solicitud = [];
        $solicitud['items'] = [];
        $i = 0;
        $totalGeneral = 0;

        foreach($object->getServiciocontables() as $serviciocontable):

            $solicitud['items'][$i]['unidad_de_medida'] = 'ZZ';
            $solicitud['items'][$i]['codigo'] = $this->container->get('App\Service\MainVariableproceso')->sanitizeString($serviciocontable->getServicio()->getNombre(), '_', '[\s+]' );
            $solicitud['items'][$i]['codigo_producto_sunat'] = '78111800';
            $filesString = '';
            if($serviciocontable->getServicio()->getServiciocomponentes()){
                $filesArray = $serviciocontable->getServicio()->getServiciocomponentes()->toArray();

                foreach($filesArray as $files){
                    $filesString .= sprintf(' F.%s', $files->getCodigo());
                }
            }

            $cantidad = 1; //los servicios de transporte solo son unitarios
            $solicitud['items'][$i]['descripcion'] = $serviciocontable->getServicio()->getFechahorainicio()->format('Y-m-d') . ' ' . $serviciocontable->getDescripcion() . $filesString;
            $solicitud['items'][$i]['cantidad'] = $cantidad;
            $solicitud['items'][$i]['valor_unitario'] = number_format($serviciocontable->getTotal() / (1 + $this->getParameter('facturacion_igv_porcentaje') / 100), 2, '.', '');
            $solicitud['items'][$i]['precio_unitario'] = number_format($serviciocontable->getTotal(), 2, '.', '');
            $solicitud['items'][$i]['descuento'] = '';
            $solicitud['items'][$i]['subtotal'] = number_format($solicitud['items'][$i]['valor_unitario'] * $cantidad, 2, '.', ''); //total de valores unitarios
            $solicitud['items'][$i]['tipo_de_igv'] = 1;
            $solicitud['items'][$i]['igv'] = number_format(($solicitud['items'][$i]['precio_unitario'] - $solicitud['items'][$i]['valor_unitario']) * $cantidad, 2, '.', '');
            $solicitud['items'][$i]['total'] = number_format($solicitud['items'][$i]['precio_unitario'] * $cantidad, 2, '.', '');
            $solicitud['items'][$i]['anticipo_regularizacion'] = false;
            $solicitud['items'][$i]['anticipo_documento_serie'] = '';
            $solicitud['items'][$i]['anticipo_documento_numero'] = '';

            $totalGeneral += $solicitud['items'][$i]['total'];

            $i++;
        endforeach;

        foreach($object->getComprobanteitems() as $comprobanteitem):
            $solicitud['items'][$i]['unidad_de_medida'] = $comprobanteitem->getProductoservicio()->getTipoproductoservicio()->getCodigoexterno();
            $solicitud['items'][$i]['codigo'] = $this->container->get('App\Service\MainVariableproceso')->sanitizeString($comprobanteitem->getProductoservicio()->getCodigo(), '_', '[\s+]' );
            $solicitud['items'][$i]['codigo_producto_sunat'] = $comprobanteitem->getProductoservicio()->getCodigosunat();
            $solicitud['items'][$i]['descripcion'] = $comprobanteitem->getProductoservicio()->getNombre();
            $cantidad = $comprobanteitem->getCantidad();
            $solicitud['items'][$i]['cantidad'] = $cantidad;
            $solicitud['items'][$i]['valor_unitario'] = number_format($comprobanteitem->getUnitario() / (1 + $this->getParameter('facturacion_igv_porcentaje') / 100), 2, '.', '');
            $solicitud['items'][$i]['precio_unitario'] = number_format($comprobanteitem->getUnitario(), 2, '.', '');
            $solicitud['items'][$i]['descuento'] = '';
            $solicitud['items'][$i]['subtotal'] = number_format($solicitud['items'][$i]['valor_unitario'] * $cantidad, 2, '.', ''); //total de valores unitarios
            $solicitud['items'][$i]['tipo_de_igv'] = 1;
            $solicitud['items'][$i]['igv'] = number_format(($solicitud['items'][$i]['precio_unitario'] - $solicitud['items'][$i]['valor_unitario']) * $cantidad, 2, '.', '');
            $solicitud['items'][$i]['total'] = number_format($solicitud['items'][$i]['precio_unitario'] * $cantidad, 2, '.', '');
            $solicitud['items'][$i]['anticipo_regularizacion'] = false;
            $solicitud['items'][$i]['anticipo_documento_serie'] = '';
            $solicitud['items'][$i]['anticipo_documento_numero'] = '';

            $totalGeneral += $solicitud['items'][$i]['total'];
            $i++;
        endforeach;

        if(empty($solicitud['items'])){
            $this->addFlash('sonata_flash_error', 'No exixtem items por facturar.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if(!($object->getMoneda())){
            $this->addFlash('sonata_flash_error', 'No se puede obtener la moneda.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $solicitud['moneda'] = $object->getMoneda()->getCodigoexterno();

        $solicitud['operacion'] = "generar_comprobante";

        if(!($object->getTipo())){
            $this->addFlash('sonata_flash_error', 'No se puede obtener el tipo de documento.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $solicitud['tipo_de_comprobante'] = $object->getTipo()->getCodigoexterno();

        if($object->getSerie()
            || $object->getDocumento()
        ){
            $this->addFlash('sonata_flash_error', 'El documento ya se ha facturado.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if(!$object->getTipo()->getSerie()
            || !$object->getTipo()->getCorrelativo()
        ){
            $this->addFlash('sonata_flash_error', 'No se puedo obtener la serie del documento ni el número.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }



        $solicitud['serie'] = $object->getTipo()->getSerie();
        $solicitud['numero'] = (string)$object->getTipo()->getCorrelativo();
        $solicitud['sunat_transaction'] = 1; //fijo
        $solicitud['cliente_tipo_de_documento'] = 6; //fijo

        if(!$object->getDependencia()
            ||  !$object->getDependencia()->getOrganizacion()
        ){
            $this->addFlash('sonata_flash_error', 'No se puede obtener el cliente.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $solicitud['cliente_numero_de_documento'] = $object->getDependencia()->getOrganizacion()->getNumerodocumento();
        $solicitud['cliente_denominacion'] = $object->getDependencia()->getOrganizacion()->getRazonsocial();
        $solicitud['cliente_direccion'] = $object->getDependencia()->getDireccion();
        $solicitud['cliente_email'] = $object->getDependencia()->getEmail();
        $solicitud['fecha_de_emision'] = $fechaEmision->format('d-m-Y');
        $solicitud['fecha_de_vencimiento'] = '';

        $tipoCambioStr = '';
        $tipoCambioFactor = 1;

        if($object->getMoneda()->getId() != 1){
            $tipoCambio = $this->container->get('App\Service\MainTipocambio')->getTipodecambio($fechaEmision);

            if(!$tipoCambio){
                $this->addFlash('sonata_flash_error', sprintf('No se puede obtener la el tipo de cambio para %s del dia %s.', $object->getMoneda()->getNombre(), $fechaEmision->format('Y-m-d') ));
                return new RedirectResponse($this->admin->generateUrl('list'));
            }
            $tipoCambioStr = number_format($tipoCambio->getCompra(), 3, '.', '');
            $tipoCambioFactor = $tipoCambio->getCompra();
        }

        $solicitud['tipo_de_cambio'] = $tipoCambioStr;
        $solicitud['porcentaje_de_igv'] = $this->getParameter('facturacion_igv_porcentaje');
        $solicitud['descuento_global'] = '';
        $solicitud['total_descuento'] = '';
        $solicitud['total_anticipo'] = '';
        $solicitud['total_gravada'] = number_format($totalGeneral / (1 + $this->getParameter('facturacion_igv_porcentaje') / 100), 2, '.', '');
        $solicitud['total_inafecta'] = '';
        $solicitud['total_exonerada'] = '';
        $solicitud['total_igv'] = number_format($totalGeneral - $solicitud['total_gravada'], 2, '.', '');
        $solicitud['total_gratuita'] = '';
        $solicitud['total_otros_cargos'] = '';
        $solicitud['total'] = number_format($totalGeneral, 2, '.', '');
        $solicitud['percepcion_tipo'] = '';
        $solicitud['percepcion_base_imponible'] = '';
        $solicitud['total_percepcion'] = '';
        $solicitud['total_incluido_percepcion'] = '';
        $solicitud['detraccion'] = false;
        $solicitud['observaciones'] = '';

        if(!empty($object->getNota())){
            $observaciones[] = $object->getNota();
        }
        if($totalGeneral * $tipoCambioFactor > $this->getParameter('facturacion_detraccion_monto')){
            $observaciones[] = $this->getParameter('facturacion_detraccion_texto');
            $observaciones[] = sprintf('%s: %s', 'NÚMERO DE CUENTA', $this->getParameter('facturacion_detraccion_cuenta'));
        }
        if(isset($observaciones)){
            $solicitud['observaciones'] = implode('<br>', $observaciones);
        }

        $tipoAsociado = '';
        $documentoAsociado = '';
        $serieAsociado = '';
        $tipoNotaCredito = '';

        if( $object->getTipo()->getEsnotacredito() === true){

            if($object->getOriginal() && !empty($object->getOriginal()->getDocumento()) && $object->getOriginal()->getTipo()->getEsnotacredito() === false) {
                $tipoAsociado = $object->getOriginal()->getTipo()->getCodigoexterno();
                $documentoAsociado = $object->getOriginal()->getDocumento();
                $serieAsociado = $object->getOriginal()->getSerie();
                if($object->getOriginal()->getNeto() == $solicitud['total_gravada'] && $object->getOriginal()->getImpuesto() == $solicitud['total_igv'] && $object->getOriginal()->getTotal() == $solicitud['total']){
                    $tipoNotaCredito = '1';
                }elseif($object->getOriginal()->getNeto() > $solicitud['total_gravada'] && $object->getOriginal()->getImpuesto() > $solicitud['total_igv'] && $object->getOriginal()->getTotal() > $solicitud['total']){
                    $tipoNotaCredito = '9';
                }else{
                    $this->addFlash('sonata_flash_error', 'El valor de la nota de crédito debe ser menor al del documento.');
                    return new RedirectResponse($this->admin->generateUrl('list'));
                }
            }else{
                $this->addFlash('sonata_flash_error', 'El tipo de documento debe tener un documento asociado enviado a facturación.');
                return new RedirectResponse($this->admin->generateUrl('list'));
            }
        }

        $solicitud['documento_que_se_modifica_tipo'] = $tipoAsociado;
        $solicitud['documento_que_se_modifica_serie'] = $serieAsociado;
        $solicitud['documento_que_se_modifica_numero'] = $documentoAsociado;
        $solicitud['tipo_de_nota_de_credito'] = $tipoNotaCredito;
        $solicitud['tipo_de_nota_de_debito'] = '';
        $solicitud['enviar_automaticamente_a_la_sunat'] = true;
        $solicitud['enviar_automaticamente_al_cliente'] = false;
        $solicitud['codigo_unico'] = '';
        $solicitud['condiciones_de_pago'] = '';
        $solicitud['medio_de_pago'] = '';
        $solicitud['placa_vehiculo'] = '';
        $solicitud['orden_compra_servicio'] = '';
        $solicitud['tabla_personalizada_codigo'] = '';
        $solicitud['formato_de_pdf'] = '';

        $solicitudJson = json_encode($solicitud, JSON_PRETTY_PRINT);

        if(!$solicitudJson){
            $this->addFlash('sonata_flash_error', 'No se puede crear la solicitud serializada.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $ruta);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Token token="'.$token.'"',
                'Content-Type: application/json',
            )
        );
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $solicitudJson);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $respuesta  = curl_exec($ch);
        curl_close($ch);

        $respuesta = json_decode($respuesta, true);

        if(!is_array($respuesta)){
            $this->addFlash('sonata_flash_error', 'El servidor ha devuelto error indetermnado.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        if(isset($respuesta['errors']) && !empty($respuesta['errors'])){
            $this->addFlash('sonata_flash_error', $respuesta['errors']);
            return new RedirectResponse($this->admin->generateUrl('list'));
        }

        foreach($respuesta as $key => $value){
            if($key == 'serie'){
                $object->setSerie($value);
            }elseif($key == 'numero'){
                $object->setDocumento($value);
                $object->getTipo()->setCorrelativo($value + 1);
            }elseif($key == 'enlace'){
                $object->setUrl($value);
            }else{
                if(!(is_array($value) || empty($value))){
                    $mensaje = new Mensaje();
                    $mensaje->setClave($key);
                    $mensaje->setContenido($value);
                    $object->addMensaje($mensaje);
                }
            }
        }
        $object->setNeto($solicitud['total_gravada']);
        $object->setImpuesto($solicitud['total_igv']);
        $object->setTotal($solicitud['total']);
        $object->setFechaemision($fechaEmision);
        $object->setEstado($em->getReference('App\Entity\ComprobanteEstado', 3));

        $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Se ha facturado correctamente el item.');

        return new RedirectResponse($this->admin->generateUrl('list'));

    }

}
