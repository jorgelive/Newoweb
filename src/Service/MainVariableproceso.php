<?php

namespace App\Service;

use PhpParser\Node\Expr\Array_;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class MainVariableproceso
{

    private static array $mensajes;

    public function stripAccents(string $string): string
    {
        return strtr($string,'àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ','aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY');
    }

    public function prependtofile($file, $text): bool
    {

        $file_to_read = @fopen($file, "r");
        $old_text = @fread($file_to_read, 8184); // max 1024
        @fclose($file_to_read);
        $file_to_write = fopen($file, "w");
        fwrite($file_to_write, $text . $old_text);
        return true;
    }

    public function setMensajes(String $contenido, String $tipo = 'info'): bool
    {

        if(!isset(MainVariableproceso::$mensajes)){
            MainVariableproceso::$mensajes = [];
        }

        $elementos = count(MainVariableproceso::$mensajes);
        MainVariableproceso::$mensajes[$elementos]['contenido'] = $contenido;
        MainVariableproceso::$mensajes[$elementos]['tipo'] = $tipo;

        return true;

    }

    public function getMensajes(): array
    {
        return MainVariableproceso::$mensajes;
    }

    public function sanitizeString(string $str, string $with = '', array $what = []): string
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
        foreach($what as $dummy):
            $withArray[] = $with;
        endForeach;

        $proceso = trim(preg_replace($what, $withArray, $str));
        return $proceso;
    }

    public function exceltime(string $variable, string $tipo='from'): string
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

    public function exceldate(int|string $variable, $tipo='from'): int|string
    {
        if(empty($variable)){
            return '';
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

    public function is_multi_array(array $array): bool
    {
        return (count($array) != count($array, 1));
    }

    function buildUrl(array $parts): string
    {
        return (isset($parts['scheme']) ? "{$parts['scheme']}:" : '') .
            ((isset($parts['user']) || isset($parts['host'])) ? '//' : '') .
            (isset($parts['user']) ? "{$parts['user']}" : '') .
            (isset($parts['pass']) ? ":{$parts['pass']}" : '') .
            (isset($parts['user']) ? '@' : '') .
            (isset($parts['host']) ? "{$parts['host']}" : '') .
            (isset($parts['port']) ? ":{$parts['port']}" : '') .
            (isset($parts['path']) ? "{$parts['path']}" : '') .
            (isset($parts['query']) ? "?{$parts['query']}" : '') .
            (isset($parts['fragment']) ? "#{$parts['fragment']}" : '');
    }
}