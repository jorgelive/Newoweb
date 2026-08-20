<?php

declare(strict_types=1);

namespace App\Message\Enum;

/**
 * Por dónde se reconoce a alguien.
 *
 * No es «el canal por el que escribió»: un mismo correo puede llegar por Graph o por IMAP y
 * sigue siendo la misma persona. Lo que identifica es el **valor**, no la puerta.
 */
enum IdentidadTipo: string
{
    case TELEFONO = 'telefono';
    case EMAIL = 'email';

    /**
     * Deja el valor en su forma canónica, que es lo que hace fiable la búsqueda exacta.
     *
     * ⚠️ **La normalización vive aquí y sólo aquí.** Si cada sitio que guarda una identidad
     * normaliza a su manera, dos formas del mismo correo acaban en dos hilos — que es el
     * problema que esta tabla viene a cerrar.
     */
    public function normalizar(string $valor): string
    {
        $valor = trim($valor);

        return match ($this) {
            // Minúsculas: los buzones no distinguen mayúsculas en la parte local en la
            // práctica, y `Jorge@` frente a `jorge@` son la misma persona escribiendo.
            self::EMAIL => mb_strtolower($valor),
            // Sólo dígitos y el `+`: lo que llega trae espacios, guiones y paréntesis según
            // quién lo escriba. `PhoneSanitizer` hace el trabajo fino de país; esto es el
            // mínimo para que la comparación exacta no falle por un guion.
            self::TELEFONO => (string) preg_replace('/[^\d+]/', '', $valor),
        };
    }
}
