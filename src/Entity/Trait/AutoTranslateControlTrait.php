<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Los dos interruptores con los que una entidad controla su autotraducción.
 *
 * Reparto, que no es simétrico:
 *
 * - `ejecutarTraduccion` es **virtual** y decide si el listener trabaja o no. Se apaga en
 *   comandos de carga masiva para que no llame a Google por cada fila.
 * - `sobreescribirTraduccion` es **físico** y decide si se rehace lo que ya está traducido.
 *
 * ⚠️ El caso normal —«corregí el español, propaga»— **no lo decide ninguno de los dos**: lo
 * decide el `origenHash` que cada fila traducida lleva dentro del JSON. Ver
 * {@see \App\Service\Translate\AutoTranslationService} y `docs/Autotraduccion.md`.
 */
trait AutoTranslateControlTrait
{
    /**
     * Flag virtual (no mapeado en base de datos) para activar/desactivar el proceso en tiempo de
     * ejecución. Ideal para apagar el listener durante importaciones masivas (fixtures, comandos).
     *
     * Lo usa `app:traduccion:sellar-hash` para garantizar que el sellado **no puede** traducir:
     * apagado aquí, el servicio sale antes de mirar nada.
     *
     * ⛔ **No cambies este `true`.** Lo comparten las 29 entidades traducibles y todo lo que las
     * guarda —el editor de util, la API, los comandos, el panel—: a `false`, nada volvería a
     * traducirse y las fichas en otros idiomas se quedarían viejas sin un solo error. Para que un
     * MAESTRO DE RÓTULOS no se retraduzca al editarlo, se pone por defecto en SU formulario:
     * {@see \App\Panel\Field\CamposDeTraduccion::deMaestroDeRotulos()} (16/10/2026).
     *
     * Y es virtual —sin columna— a propósito: guardado, «apagado una vez» pasaría a significar
     * «no se traduce nunca más», sin que se viera en ningún sitio.
     */
    private bool $ejecutarTraduccion = true;

    /**
     * «Rehaz TODAS las traducciones ahora, cambie o no el origen.» Es la excepción, no el modo
     * de trabajo.
     *
     * ```
     * false (Default)  traduce lo que falta + lo que quedó desfasado (origenHash no cuadra)
     * true             traduce TODO, aunque el hash cuadre
     * ```
     *
     * ⚠️ **`false` NO es «no se toca nada».** Con el flag apagado se rehace igualmente cualquier
     * fila cuyo `origenHash` no case con el español actual — que es justo lo que se quiere al
     * corregir un texto. El flag está para lo que el hash no puede detectar: una traducción que
     * quedó mal sin que el origen cambiara (se cayó Google, se perdió un marcador), un cambio de
     * glosario, o rehacer una corrección manual que no convenció.
     *
     * Hasta el 31/08/2026 este flag era el ÚNICO camino para propagar una corrección del
     * español, y por eso había que pulsarlo cada vez. Nadie lo hacía, y las siete traducciones
     * se quedaban diciendo lo viejo para siempre.
     *
     * Al estar mapeado en la base de datos, cualquier cambio desde EasyAdmin obliga a Doctrine a
     * calcular un ChangeSet y disparar `preUpdate`. El servicio lo devuelve a `false` en cuanto
     * se ejecuta: es un disparo único y no debe quedarse pegado.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $sobreescribirTraduccion = false;

    // --- Getters & Setters ---

    public function getEjecutarTraduccion(): bool
    {
        return $this->ejecutarTraduccion;
    }

    public function setEjecutarTraduccion(bool $ejecutarTraduccion): self
    {
        $this->ejecutarTraduccion = $ejecutarTraduccion;

        return $this;
    }

    public function getSobreescribirTraduccion(): bool
    {
        return $this->sobreescribirTraduccion;
    }

    public function setSobreescribirTraduccion(bool $sobreescribirTraduccion): self
    {
        $this->sobreescribirTraduccion = $sobreescribirTraduccion;

        return $this;
    }
}