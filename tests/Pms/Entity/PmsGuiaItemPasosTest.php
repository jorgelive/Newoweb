<?php

declare(strict_types=1);

namespace App\Tests\Pms\Entity;

use App\Pms\Entity\PmsGuiaItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La escalera de un tema: qué se dice en cada vuelta y cuándo se acaba.
 *
 * Es la mitad del mecanismo que se puede probar sin base de datos. La otra —contar los peldaños
 * ya servidos leyendo los mensajes— se verifica con `var/probar-escalera.php`, que necesita datos
 * reales.
 */
final class PmsGuiaItemPasosTest extends TestCase
{
    private function item(): PmsGuiaItem
    {
        return (new PmsGuiaItem())->setAgenteContenido('Así funciona la ducha.');
    }

    /**
     * Lo normal: sin pasos, un ítem se comporta como antes de que esto existiera.
     *
     * Importa que sea así porque los 44 ítems de la guía nacieron sin escalera, y la mayoría no
     * la quiere: al que sólo pregunta cómo funciona algo no se le contesta con avisos de avería.
     */
    #[Test]
    public function sin_pasos_solo_esta_el_contenido_de_siempre(): void
    {
        $item = $this->item();

        self::assertSame(0, $item->pasosDisponibles());
        self::assertSame('Así funciona la ducha.', $item->agenteTextoParaPeldano(0));
        self::assertNull($item->agenteTextoParaPeldano(1));
    }

    /** Con escalera, cada vuelta entrega el siguiente y sólo el siguiente. */
    #[Test]
    public function cada_peldano_entrega_su_paso(): void
    {
        $item = $this->item()->setAgentePasos(['Cierra y abre al máximo.', 'Enciende una hornilla.']);

        self::assertSame(2, $item->pasosDisponibles());
        self::assertSame('Así funciona la ducha.', $item->agenteTextoParaPeldano(0));
        self::assertSame('Cierra y abre al máximo.', $item->agenteTextoParaPeldano(1));
        self::assertSame('Enciende una hornilla.', $item->agenteTextoParaPeldano(2));
    }

    /**
     * Agotados los pasos devuelve `null`, que es la señal de escalar.
     *
     * ⚠️ NO se vuelve al principio. Repetir la primera explicación a alguien que ya la leyó y
     * probó dos cosas es exactamente lo que hace que la gente pierda la paciencia, y era lo que
     * pasaba cuando la escalera vivía escrita en prosa dentro del contenido.
     */
    #[Test]
    public function agotados_los_pasos_no_se_repite_el_principio(): void
    {
        $item = $this->item()->setAgentePasos(['Cierra y abre al máximo.']);

        self::assertNull($item->agenteTextoParaPeldano(2));
        self::assertNull($item->agenteTextoParaPeldano(9));
    }

    /**
     * Con TRES pasos, el tope de la escalera manda antes que el texto.
     *
     * `EscaleraDeTemas::peldanoPara()` satura en `MAX_PELDANOS`, así que un ítem con tres pasos
     * se clavaba en el peldaño 3 sirviendo el último **en bucle y sin escalar nunca**: con dos o
     * menos funcionaba de casualidad, porque el peldaño 3 se quedaba sin texto. Quien decide que
     * se acabó es la skill mirando las dos cosas.
     */
    #[Test]
    public function con_tres_pasos_el_texto_del_ultimo_existe_y_por_eso_manda_el_tope(): void
    {
        $item = $this->item()->setAgentePasos(['Uno.', 'Dos.', 'Tres.']);

        // El texto del peldaño saturado SÍ existe: por sí solo no delataría el final.
        self::assertSame('Tres.', $item->agenteTextoParaPeldano(3));
        self::assertSame(3, $item->pasosDisponibles());
    }

    /** Un peldaño negativo no es un caso real, pero no puede reventar: cae en la respuesta normal. */
    #[Test]
    public function un_peldano_raro_cae_en_la_respuesta_normal(): void
    {
        self::assertSame('Así funciona la ducha.', $this->item()->agenteTextoParaPeldano(-3));
    }

    /**
     * Los huecos se caen al guardar.
     *
     * Un paso en blanco en medio dejaría un peldaño mudo, y un peldaño mudo es una respuesta
     * vacía justo cuando el huésped ya insistió una vez.
     */
    #[Test]
    public function los_pasos_vacios_no_se_guardan(): void
    {
        $item = $this->item()->setAgentePasos(['  ', 'Paso bueno.', '', "\n"]);

        self::assertSame(['Paso bueno.'], $item->getAgentePasos());
    }

    /** Sin contenido para el agente no hay peldaño 0, y la escalera no inventa uno. */
    #[Test]
    public function sin_contenido_de_agente_el_primer_peldano_es_nulo(): void
    {
        $item = (new PmsGuiaItem())->setAgentePasos(['Un paso.']);

        self::assertNull($item->agenteTextoParaPeldano(0));
        self::assertSame('Un paso.', $item->agenteTextoParaPeldano(1));
    }
}
