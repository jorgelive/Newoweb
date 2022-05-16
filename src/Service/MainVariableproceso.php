<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class MainVariableproceso implements ContainerAwareInterface{

    use ContainerAwareTrait;

    protected $tokenStorage;

    private static $mensajes;

    private $stack;

    private $suma;

    public function __construct(TokenStorageInterface $tokenStorage)
    {

        $this->tokenStorage = $tokenStorage;
    }

    /**
     * @param mixed $mensaje
     * @return boolean
     */
    public function setMensajes($contenido, $tipo = 'info')
    {

        if (!is_string($contenido) || !is_string($tipo)){
            return false;
        }

        if(!isset(MainVariableproceso::$mensajes)){
            MainVariableproceso::$mensajes = [];
        }

        $elementos = count( MainVariableproceso::$mensajes);
        MainVariableproceso::$mensajes[$elementos]['contenido'] = $contenido;
        MainVariableproceso::$mensajes[$elementos]['tipo'] = $tipo;

        return true;

    }

    /**
     * @return array
     */
    public function getMensajes()
    {
        return MainVariableproceso::$mensajes;
    }

    /**
     * @return string
     */
    protected function getUserName()
    {
        $usuario = $this->tokenStorage->getToken()->getUser();
        if (!is_string($usuario)) {
            $usuario = $usuario->getUsername();
        }
        return $usuario;
    }

    /**
     * @param mixed $valor
     * @param mixed $key
     * @param array $vars
     * @return \App\Service\MainVariableproceso
     */
    protected function setSumaForWalk($valor, $key, $vars)
    {

        if (empty($vars[1]) || $key == $vars[1]) {
            $this->setSuma($vars[0], $valor);
        }

        return $this;
    }

    /**
     * @param string $id
     * @param number $monto
     * @return \App\Service\MainVariableproceso
     */
    protected function setSuma($id, $monto)
    {
        if(!isset($this->suma[$id])){
            $this->suma[$id] = $monto;
        }else{
            $this->suma[$id] = $this->suma[$id] + $monto;
        }

        return $this;
    }


    /**
     * @param string $id
     * @return \App\Service\MainVariableproceso
     */
    protected function resetSuma($id)
    {
        $this->suma[$id] = 0;

        return $this;
    }

    /**
     * @param string $id
     * @return integer
     */
    protected function getSuma($id)
    {
        if (empty($this->suma[$id])) {
            return 0;
        }

        return $this->suma[$id];
    }

    /**
     * @param mixed $id
     * @return array
     */
    protected function getStack($id)
    {
        if(isset($this->stack[$id])){
            return $this->stack[$id];
        }else{
            return array();
        }

    }

    /**
     * @param string $nombreStack
     * @param mixed $valor
     * @param string $nombreIndice
     * @return \App\Service\MainVariableproceso
     */
    protected function setStack($nombreStack, $valor, $nombreIndice = null)
    {
        if (empty($nombreIndice)) {
            $this->stack[$nombreStack][] = $valor;
        } else {
            $this->stack[$nombreStack][][$nombreIndice] = $valor;
        }
        return $this;
    }

    /**
     * @param string $id
     * @return \App\Service\MainVariableproceso
     */
    protected function resetStack($id)
    {
        $this->stack[$id] = array();

        return $this;
    }

    /**
     * @param mixed $valor
     * @param mixed $key
     * @param mixed $vars
     * @return boolean
     */
    protected function setStackForWalk($valor, $key, $vars)
    {
        if (is_string($vars)) {
            $this->setStack($vars, $valor);
            return true;
        } elseif (is_array($vars) && (count($vars) == 2 || (count($vars) == 3 && $key == $vars[2]))) {
            $this->setStack($vars[0], $valor, $vars[1]);
            return true;
        } else {
            return false;
        }

    }

    /**
     * @param array $contenedor
     * @param mixed $nombreStacks
     * @param mixed $keys
     * @param mixed $newKeyNames
     * @param mixed $formaters
     * @param boolean $resetStacks
     * @return boolean
     */
    protected function seekAndStack($contenedor, $nombreStacks, $keys, $newKeyNames = NULL, $formaters = NULL, $resetStacks = true)
    {
        if(!is_array($nombreStacks)){
            $nombreStacks = array($nombreStacks);
        }
        if(!is_array($keys)){
            $keys = array($keys);
        }

        if(!empty($newKeyNames) && !is_array($newKeyNames)){
            $newKeyNames = array($newKeyNames);
        }

        if(!empty($formaters) && !is_array($formaters)){
            $formaters = array($formaters);
        }

        if(count($nombreStacks) != count($keys)
            || (!empty($newKeyNames) && count($newKeyNames) != count($nombreStacks))
            || (!empty($formaters) && count($formaters) != count($nombreStacks))
        ){
            return false;
        }

        $i = 0;

        foreach ($contenedor as $k => $v) {

            foreach ($nombreStacks as $nroStack => $nombreStack){
                if ($i = 0 && ($resetStacks === true || !isset($this->stack[$nombreStack]))){
                    $this->resetStack($nombreStack . 'Aux');
                    $this->resetStack($nombreStack);
                }

                if(!is_callable($formaters[$nroStack])){
                    $formaters[$nroStack] = function($value){
                        return $value;
                    };
                }

                if ($keys[$nroStack] === $k) {
                    if (is_array($v)) {
                        foreach ($v as $subkey => $subv) {
                            //se descarta la infoirmacion de la llave
                            if (!is_array($subv)) {
                                if ($subv !== null && (!isset($this->stack[$nombreStack . 'Aux']) || !in_array($subv, $this->stack[$nombreStack . 'Aux']))) {
                                    $this->setStack($nombreStack, $formaters[$nroStack]($subv), $newKeyNames[$nroStack]);
                                    $this->setStack($nombreStack . 'Aux', $formaters[$nroStack]($subv));
                                    $i++;
                                }
                            }
                        }
                    } else {
                        if ($v !== null && (!isset($this->stack[$nombreStack . 'Aux']) || !in_array($formaters[$nroStack]($v), $this->stack[$nombreStack . 'Aux']))) {
                            $this->setStack($nombreStack, $formaters[$nroStack]($v), $newKeyNames[$nroStack]);
                            $this->setStack($nombreStack . 'Aux', $formaters[$nroStack]($v));
                            $i++;
                        }
                    }
                } elseif (is_array($v)) {
                    $this->seekAndStack($v, $nombreStacks, $keys, $newKeyNames, $formaters);
                }
            }
        }

        if ($i > 1) {
            return true;
        }
        return false;
    }


    public function sanitizeString($str, $with = '', $what = array())
    {
        if(!is_array($what)){
            $what = [$what];
        }
        $what[] = "/[\\x00-\\x20]+/";
        $what[] = "/[']+/";
        $what[] = "/[(]+/";
        $what[] = "/[)]+/";
        $what[] = "/[-]+/";
        $what[] = "/[+]+/";
        $what[] = "/[*]+/";
        $what[] = "/[,]+/";
        $what[] = "/[\/]+/";
        $what[] = "/[\\\\]+/";
        $what[] = "/[?]+/";

        $withArray = [];
        foreach ($what as $dummy):
            $withArray[] = $with;
        endForeach;

        $proceso = trim(preg_replace($what, $withArray, $str));
        return $proceso;
    }

    public function sanitizeQuery($query, $tipo='select')
    {
        if($tipo=='select'){
            $what[] = "/insert/i";
            $what[] = "/update/i";
        }
        $what[] = "/;/";
        $what[] = '/"/';
        $with=array();

        foreach ($what as $dummy):
            $with[]='';
        endForeach;

        $what[] = "/\s/";
        $with[] = ' ';

        $proceso = trim(preg_replace($what, $with, $query));
        return preg_replace('/\s+/', ' ', $proceso);;
    }

    public function utf($variable,$tipo='to')
    {
        $encodings[]='Windows-1250';
        $encodings[]='UTF-8';
        if($tipo!='to'){
            array_reverse($encodings);
        }
        if(is_string($variable)){
            return iconv($encodings[0], $encodings[1], $variable);
        }elseif(is_array($variable)){
            array_walk_recursive(
                $variable,
                function (&$entry,$key,$encodings) {
                    $entry = iconv($encodings[0], $encodings[1], $entry);
                },
                $encodings
            );
            return $variable;
        }
        return null;
    }

    public function exceltime($variable, $tipo='from')
    {
        if(empty($variable)){
            return '00:00:00';
        }
        if($tipo=='from'){

            if((!is_numeric($variable) && strpos($variable, ':') > 0) || $variable >= 1 ){
                $variable = str_replace(':', '', $variable);
                if(strlen($variable) == 4){
                    $variable = $variable . '00';
                }
                if(strlen($variable) != 6){
                    return $variable;
                }else{
                    return(date('H:i:s', strtotime(substr($variable, 0, 2) . ':' . substr($variable, 2, 2) . ':' . substr($variable, 4, 2))));
                }
            }elseif(is_numeric($variable)){
                $variable = $variable * 24;
                $hora = intval($variable, 0);
                $variable = ($variable - intval($variable)) * 60;
                $minuto = intval($variable, 0);
                $segundo = round(($variable - intval($variable)) * 60, 0);
                return date('H:i:s', strtotime($hora . ':' . $minuto . ':' . $segundo));
            }else{
                return $variable;
            }

        }else{
            $variable = str_replace(':', '', $variable);
            if(strlen($variable) != 6 || !is_numeric($variable)){
                return $variable;
            }
            return (substr($variable, 0, 2) / 24) + (substr($variable, 2, 2) / 1440) + (substr($variable, 4, 2) / 86400);
        }
    }

    public function exceldate($variable, $tipo='from')
    {
        if(empty($variable)){
            return $variable;
        }
        if($tipo == 'from'){

            if(!is_numeric($variable) && (strpos($variable, '-') > 0 || strpos($variable, '/') > 0)){
                return date('Y-m-d', strtotime(str_replace('/', '-', $variable)));
            }elseif(is_numeric($variable)){
                return date('Y-m-d', mktime(0,0,0,1,$variable-1,1900));
            }else{
                return $variable;
            }

        }else{
            return unixtojd(strtotime($variable . ' GMT-5')) - gregoriantojd(1, 1, 1900) + 2;
        }
    }

    public function is_multi_array($array) {
        return (count($array) != count($array, 1));
    }


}