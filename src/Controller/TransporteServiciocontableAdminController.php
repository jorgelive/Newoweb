<?php

namespace App\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\MainVariableproceso;
use App\Service\MainTipocambio;

class TransporteServiciocontableAdminController extends CRUDAdminController
{

    public static function getSubscribedServices(): array
    {
        return [
                'doctrine.orm.default_entity_manager' => EntityManagerInterface::class,
                'App\Service\MainVariableproceso' => MainVariableproceso::class,
                'App\Service\MainTipocambio' => MainTipocambio::class
            ] + parent::getSubscribedServices();
    }
    
    function facturarAction()
    {
        $fechaEmision = new \DateTime();

        //$ruta = $this->getParameter('facturacion_ruta_prueba');
        //$token = $this->getParameter('facturacion_token_prueba');
        $ruta = $this->getParameter('facturacion_ruta_produccion');
        $token = $this->getParameter('facturacion_token_produccion');

        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $object = $this->admin->getSubject();

        if (!$object) {
            throw new NotFoundHttpException(sprintf('No se puede encontrar el objeto con el identificador : %s', $this->admin->getIdParameter()));
        }

        if (!$object->getTiposercontable()
            || $object->getTiposercontable()->getId() <= 0
        ){
            $this->addFlash('sonata_flash_error', 'Este documento no admite la emision de una factura.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
        $tipoAsociado = '';
        $documentoAsociado = '';
        $serieAsociado = '';
        $tipoNotaCredito = '';

        if( $object->getTiposercontable()->getEsnotacredito() === true){
            if($object->getOriginal() && !empty($object->getOriginal()->getDocumento()) && $object->getOriginal()->getTiposercontable()->getEsnotacredito() === false) {
                $tipoAsociado = $object->getOriginal()->getTiposercontable()->getCodigoexterno();
                $documentoAsociado = $object->getOriginal()->getDocumento();
                $serieAsociado = $object->getOriginal()->getSerie();
                if($object->getOriginal()->getNeto() == $object->getNeto() && $object->getOriginal()->getImpuesto() == $object->getImpuesto() && $object->getOriginal()->getTotal() == $object->getTotal()){
                    $tipoNotaCredito = '1';
                }elseif($object->getOriginal()->getNeto() > $object->getNeto() && $object->getOriginal()->getImpuesto() > $object->getImpuesto() && $object->getOriginal()->getTotal() > $object->getTotal()){
                    $tipoNotaCredito = '9';
                }else{
                    $this->addFlash('sonata_flash_error', 'el valor de la nota de crédito debe ser menor al del documento.');
                    return new RedirectResponse($this->admin->generateUrl('list'));
                }
            }else{
                $this->addFlash('sonata_flash_error', 'El tipo de documento debe tener un documento asociado enviado a facturación.');
                return new RedirectResponse($this->admin->generateUrl('list'));
            }
        }
        $solicitud = [];
        $solicitud['operacion'] = "generar_comprobante";
        if (!($object->getTiposercontable())){
            $this->addFlash('sonata_flash_error', 'No se puede obtener el tipo de documento.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
        $solicitud['tipo_de_comprobante'] = $object->getTiposercontable()->getCodigoexterno();
        if ($object->getSerie()
            || $object->getDocumento()
        ){
            $this->addFlash('sonata_flash_error', 'El documento ya se ha facturado.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
        if(!$object->getTiposercontable()->getSerie()
            || !$object->getTiposercontable()->getCorrelativo()
        ){
            $this->addFlash('sonata_flash_error', 'La serie del documento y el numero no pueden estar vacios.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
        $solicitud['serie'] = $object->getTiposercontable()->getSerie();
        $solicitud['numero'] = (string)$object->getTiposercontable()->getCorrelativo();
        $solicitud['sunat_transaction'] = 1; //fijo
        $solicitud['cliente_tipo_de_documento'] = 6; //fijo
        if (!$object->getServicio()
            ||  !$object->getServicio()->getDependencia()
            ||  !$object->getServicio()->getDependencia()->getOrganizacion()
        ){
            $this->addFlash('sonata_flash_error', 'No se puede obtener el cliente.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
        $solicitud['cliente_numero_de_documento'] = $object->getServicio()->getDependencia()->getOrganizacion()->getNumerodocumento();
        $solicitud['cliente_denominacion'] = $object->getServicio()->getDependencia()->getOrganizacion()->getRazonsocial();
        $solicitud['cliente_direccion'] = $object->getServicio()->getDependencia()->getDireccion();
        $solicitud['cliente_email'] = $object->getServicio()->getDependencia()->getEmail();
        $solicitud['fecha_de_emision'] = $fechaEmision->format('d-m-Y');
        $solicitud['fecha_de_vencimiento'] = '';
        if (!($object->getMoneda())){
            $this->addFlash('sonata_flash_error', 'No se puede obtener la moneda del documento.');
            return new RedirectResponse($this->admin->generateUrl('list'));
        }
        $solicitud['moneda'] = $object->getMoneda()->getCodigoexterno();
        $tipoCambioStr = '';
        if($object->getMoneda()->getId() != 1){
            $tipoCambio = $em->getRepository('App\Entity\MaestroTipocambio')->findOneBy(['moneda' => $object->getMoneda()->getId(), 'fecha' => $fechaEmision]);
            if(!$tipoCambio){
                $this->addFlash('sonata_flash_error', sprintf('No se puede obtener la el tipo de cambio para %s del dia %s.', $object->getMoneda()->getNombre(), $fechaEmision->format('Y-m-d') ));
                return new RedirectResponse($this->admin->generateUrl('list'));
            }
            $tipoCambioStr = (string)$tipoCambio->getCompra();
        }

        $solicitud['tipo_de_cambio'] = $tipoCambioStr;
        $solicitud['porcentaje_de_igv'] = $this->getParameter('facturacion_igv_porcentaje');
        $solicitud['descuento_global'] = '';
        $solicitud['total_descuento'] = '';
        $solicitud['total_anticipo'] = '';
        $solicitud['total_gravada'] = (string)$object->getNeto();
        $solicitud['total_inafecta'] = '';
        $solicitud['total_exonerada'] = '';
        $solicitud['total_igv'] = (string)$object->getImpuesto();
        $solicitud['total_gratuita'] = '';
        $solicitud['total_otros_cargos'] = '';
        $solicitud['total'] = (string)$object->getTotal();
        $solicitud['percepcion_tipo'] = '';
        $solicitud['percepcion_base_imponible'] = '';
        $solicitud['total_percepcion'] = '';
        $solicitud['total_incluido_percepcion'] = '';
        $solicitud['detraccion'] = false;
        $solicitud['observaciones'] = '';
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
        $solicitud['items'][0]['unidad_de_medida'] = 'ZZ';
        $solicitud['items'][0]['codigo'] = $this->container->get('App\Service\MainVariableproceso')->sanitizeString($object->getServicio()->getNombre(), '_', '[\s+]' );
        $filesString = '';
        if($object->getServicio()->getServiciocomponentes()){
            $filesArray = $object->getServicio()->getServiciocomponentes()->toArray();

            foreach ($filesArray as $files){
                $filesString .= sprintf(' F.%s', $files->getCodigo());
            }
        }
        $cantidad = 1;
        $solicitud['items'][0]['descripcion'] = $object->getServicio()->getFechahorainicio()->format('Y-m-d') . ' ' . $object->getDescripcion() . $filesString;
        $solicitud['items'][0]['cantidad'] = $cantidad;
        $solicitud['items'][0]['valor_unitario'] = (string)$object->getNeto();
        $solicitud['items'][0]['precio_unitario'] = (string)$object->getTotal();
        $solicitud['items'][0]['descuento'] = '';
        $solicitud['items'][0]['subtotal'] = (string)($object->getNeto() * $cantidad); //total de valores unitarios
        $solicitud['items'][0]['tipo_de_igv'] = 1;
        $solicitud['items'][0]['igv'] = (string)($object->getImpuesto() * $cantidad);
        $solicitud['items'][0]['total'] = (string)($object->getTotal() * $cantidad);
        $solicitud['items'][0]['anticipo_regularizacion'] = false;
        $solicitud['items'][0]['anticipo_documento_serie'] = '';
        $solicitud['items'][0]['anticipo_documento_numero'] = '';

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

        foreach ($respuesta as $key => $value){
            if($key == 'serie'){
                $object->setSerie($value);
            }elseif($key == 'numero'){
                $object->setDocumento($value);
                $object->getTiposercontable()->setCorrelativo($value + 1);
            }elseif($key == 'enlace'){
                $object->setUrl($value);
            }else{
                if (!(is_array($value) || empty($value))){
                    /*$mensaje = new Sercontablemensaje();
                    $mensaje->setClave($key);
                    $mensaje->setContenido($value);
                    $object->addSercontablemensaje($mensaje);*/
                }
            }
        }
        $object->setFechaemision($fechaEmision);
        $object->setEstadocontable($em->getReference('App\Entity\TransporteEstadocontable', 3));

        $this->admin->update($object);

        $this->addFlash('sonata_flash_success', 'Se ha facturado correctamente el item.');



        return new RedirectResponse($this->admin->generateUrl('list'));

    }

}
