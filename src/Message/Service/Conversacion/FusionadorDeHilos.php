<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Message\Entity\MessageConversation;
use App\Message\Enum\IdentidadTipo;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Une dos hilos de la misma persona en uno.
 *
 * ## Por qué es un servicio y no sólo un comando
 *
 * 🔥 La fusión era la salida que el sistema **recomienda por escrito** —«si son la misma persona,
 * únelas con `app:message:fusionar-hilos`»— y sólo existía en la consola. Quien se topa con el
 * choque es un operador en una pantalla, no alguien con acceso a un terminal, así que en la
 * práctica la salida recomendada no existía: se acababa borrando algo o dejando el número
 * atascado. Con la lógica aquí, el comando sigue haciendo su barrido y el panel puede ofrecer el
 * botón — misma regla, dos puertas.
 *
 * `FusionarHilosCommand` conserva lo suyo: **encontrar** los duplicados y contarlos. Esto sólo
 * sabe **unir** dos que ya se le señalan.
 *
 * ## Los mensajes no se pisan
 *
 * Son filas con su fecha: reasignarlas produce una sola línea de tiempo continua. No hay nada que
 * decidir ahí. Lo que sí choca es la cabecera, y cada campo tiene su regla —ver
 * {@see self::fusionarCabecera()}—.
 *
 * ⚠️ **El absorbido NO se borra**: se archiva y se le anota en `contextData` a dónde fue. Un hilo
 * que desaparece deja enlaces colgando y a nadie a quien preguntar qué pasó.
 */
final class FusionadorDeHilos
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
    ) {
    }

    /**
     * Mueve todo al superviviente y archiva los absorbidos.
     *
     * ⚠️ **No se borra nada**, que es la regla del repo: un hilo absorbido queda archivado y con
     * una nota de dónde fue su contenido. Así un enlace del panel o una notificación que
     * apuntara a él sigue llevando a algo que explica qué pasó, en vez de a un 404.
     *
     * @param list<MessageConversation> $absorbidos
     */
    /**
     * Une UNO en otro y guarda. Es la puerta del panel.
     *
     * ⚠️ Devuelve cuántos mensajes se movieron **antes** de moverlos: después ya son del
     * superviviente y no hay forma de decir cuántos venían de dónde. Es lo que el panel enseña
     * para confirmar, así que si se contara después diría siempre el total.
     */
    public function unir(
        MessageConversation $superviviente,
        MessageConversation $absorbido,
        ?string $telefonoPrincipal = null,
    ): int {
        $movidos = $this->db->fetchOne(
            'SELECT COUNT(*) FROM msg_message WHERE conversation_id = UNHEX(REPLACE(?, \'-\', \'\'))',
            [(string) $absorbido->getId()],
        );
        // El driver devuelve el `COUNT(*)` como entero o como texto numérico según la versión.
        $movidos = is_numeric($movidos) ? (int) $movidos : 0;

        // Se decide ANTES de mover: después las identidades ya están juntas y no se sabe cuál
        // venía del hilo que tenía la ventana de WhatsApp abierta.
        $principal = $telefonoPrincipal ?? $this->telefonoPropuesto($superviviente, $absorbido);

        $this->fusionar($superviviente, [$absorbido]);

        if ($principal !== null) {
            $this->principales[(string) $superviviente->getId()] = $principal;
        }

        $this->em->flush();
        $this->recalcularBloqueos();

        return $movidos;
    }

    /**
     * Los teléfonos que pueden quedar de salida tras unir: vivos y sin vetar, de los dos hilos.
     *
     * @return list<string>
     */
    public function telefonosCandidatos(MessageConversation $superviviente, MessageConversation $absorbido): array
    {
        $valores = [];

        foreach ([$superviviente, $absorbido] as $hilo) {
            foreach ($hilo->getIdentidades() as $identidad) {
                if ($identidad->getTipo() === IdentidadTipo::TELEFONO && $identidad->estaViva() && !$identidad->isBloqueado()) {
                    $valores[$identidad->getValor()] = $identidad->getValor();
                }
            }
        }

        return array_values($valores);
    }

    /**
     * El teléfono que debería quedar de salida tras unir, o `null` si no hay ninguno.
     *
     * 🔥 **Sin esto, la unión dejaba de salida el número que nunca nos escribió.** Camille Boisfer
     * (12/10/2026): su hilo de Booking tenía un teléfono puesto a mano; ella escribía desde otro
     * por WhatsApp, en su propio hilo. Al unirlos quedaron dos teléfonos vivos y ninguno
     * principal, y {@see MessageConversation::recalcularTelefonoPrincipal()} —con razón— no elige
     * en ese caso: dejó el del superviviente. La respuesta del agente salió a un número sin
     * ventana abierta y Meta la rechazó con 131047, trece segundos después de que ella escribiera.
     *
     * La regla, por orden:
     *   1. El del hilo con la **ventana de WhatsApp abierta** más larga: es el número que acaba
     *      de escribirnos, y el único al que se le puede contestar con texto libre.
     *   2. El principal del superviviente, o su `guestPhone`: lo que ya funcionaba.
     *   3. El del absorbido.
     * Siempre entre los candidatos: un número vetado o retirado no se propone nunca.
     */
    public function telefonoPropuesto(MessageConversation $superviviente, MessageConversation $absorbido): ?string
    {
        $candidatos = $this->telefonosCandidatos($superviviente, $absorbido);

        if ($candidatos === []) {
            return null;
        }

        $ahora = new DateTimeImmutable();
        $conVentana = array_filter(
            [$superviviente, $absorbido],
            static fn (MessageConversation $h): bool => $h->getWhatsappSessionValidUntil() !== null
                && $h->getWhatsappSessionValidUntil() > $ahora,
        );
        usort(
            $conVentana,
            static fn (MessageConversation $a, MessageConversation $b): int
                => $b->getWhatsappSessionValidUntil() <=> $a->getWhatsappSessionValidUntil(),
        );

        foreach ([...$conVentana, $superviviente, $absorbido] as $hilo) {
            foreach ([$hilo->getTelefonoPrincipal()?->getValor(), $this->normalizado($hilo->getGuestPhone())] as $valor) {
                if ($valor !== null && in_array($valor, $candidatos, true)) {
                    return $valor;
                }
            }
        }

        return $candidatos[0];
    }

    private function normalizado(?string $telefono): ?string
    {
        $valor = IdentidadTipo::TELEFONO->normalizar((string) $telefono);

        return $valor === '' ? null : $valor;
    }

    /** @param list<MessageConversation> $absorbidos */
    public function fusionar(MessageConversation $superviviente, array $absorbidos): void
    {
        $destino = (string) $superviviente->getId();

        foreach ($absorbidos as $absorbido) {
            $origen = (string) $absorbido->getId();

            $this->mover('msg_message', 'conversation_id', $origen, $destino);
            $this->mover('pms_conversacion_enlace', 'conversacion_id', $origen, $destino);
            $this->mover('cotizacion_conversacion_enlace', 'conversacion_id', $origen, $destino);
            $this->mover('msg_identidad', 'conversacion_id', $origen, $destino);

            $this->supervivientes[$destino] = $destino;

            $this->fusionarCabecera($superviviente, $absorbido);

            // Ya están unidos: la sugerencia de unirlos —esté en cualquiera de los dos— sobra.
            $superviviente->olvidarFusionCon($absorbido);
            $absorbido->olvidarFusionCon($superviviente);

            $absorbido->setStatus(MessageConversation::STATUS_ARCHIVED);
            $datos = $absorbido->getContextData() ?? [];
            $datos['fusionado_en'] = $destino;
            $absorbido->setContextData($datos);
        }
    }

    /** @var array<string, string> Los hilos que sobrevivieron, indexados para no repetirlos. */
    private array $supervivientes = [];

    /** @var array<string, string> Teléfono que queda de salida, por hilo superviviente. */
    private array $principales = [];

    /**
     * El veto de WhatsApp del hilo, a partir de las identidades que acabe teniendo.
     *
     * Vetado sólo si TIENE teléfonos vivos y **todos** están bloqueados. Antes esto se resolvía
     * propagando a lo bruto —«si alguno lo estaba, se queda desactivado»—, que era el lado
     * prudente cuando el flag era del hilo; ahora es de cada número y unir a alguien con uno
     * muerto y otro vivo tiene que dejarle WhatsApp encendido.
     */
    /**
     * El veto de WhatsApp de todos los supervivientes de esta tanda.
     *
     * ⚠️ **DESPUÉS del flush, y por SQL.** Las identidades se mueven con `UPDATE` directo, así que
     * la colección de la entidad no las ve; y hacerlo antes tampoco valdría, porque el flush
     * escribiría encima el valor viejo que la entidad lleva en memoria.
     */
    public function recalcularBloqueos(): void
    {
        foreach ($this->supervivientes as $destino) {
            $this->recalcularCabecera($destino);
        }
    }

    /**
     * El número al que se escribe (`guestPhone`) y el veto de WhatsApp, con las identidades que el
     * hilo tiene AHORA. Las dos con la regla de la entidad, no con una copia en SQL.
     *
     * 🔥 **Faltaba el teléfono, y dejaba al superviviente sin WhatsApp.** Clémence (8YXYNK,
     * 02/10/2026): el hilo de su reserva de Booking no tenía teléfono; el de WhatsApp desde el que
     * escribió, sí. Al unirlos el número se movió por SQL, pero `guestPhone` —la copia que mira el
     * envío— se quedó vacío, y ninguno de sus avisos ganó la cola de WhatsApp.
     *
     * ⚠️ **`refresh()`, NO `detach()` + `find()`.** La primera versión soltaba el hilo y lo volvía a
     * cargar, y reventaba: el `postFlush` de la fusión ya había hidratado sus mensajes y enlaces
     * apuntando al objeto viejo, y el flush siguiente los veía colgando de una entidad «nueva»
     * (`A new entity was found through the relationship PmsConversacionEnlace#conversacion`) —con
     * mensajes e identidades ya movidos y sin transacción—. Reproducido en la copia de producción
     * con la revisión del 02/10/2026. `refresh()` relee las columnas del MISMO objeto y deja sus
     * colecciones sin inicializar, así que `identidades` vuelve a leerse con lo movido.
     *
     * El teléfono va antes del veto: cambiar de número lo levanta, y el veto lo vuelve a poner si toca.
     */
    private function recalcularCabecera(string $conversacion): void
    {
        $hilo = $this->em->getRepository(MessageConversation::class)->find($conversacion);

        if ($hilo === null) {
            return;
        }

        $this->em->refresh($hilo);

        $principal = $this->principales[$conversacion] ?? null;

        if ($principal !== null) {
            foreach ($hilo->getIdentidades() as $identidad) {
                if ($identidad->getTipo() === IdentidadTipo::TELEFONO) {
                    $identidad->setPrincipal($identidad->getValor() === $principal);
                }
            }
        }

        $hilo->recalcularTelefonoPrincipal()->recalcularBloqueoWhatsapp();
        $this->em->flush();
    }

    /**
     * `UPDATE IGNORE` porque las tablas destino tienen índices únicos —`(conversacion, reserva)`,
     * `(tipo, valor)`— y una fila que chocara dejaría al superviviente con la suya, que es la
     * correcta. Sin `IGNORE` la fusión entera se caería por un duplicado inofensivo.
     */
    private function mover(string $tabla, string $columna, string $origen, string $destino): void
    {
        $this->db->executeStatement(
            sprintf('UPDATE IGNORE %s SET %s = UNHEX(REPLACE(:destino, \'-\', \'\')) WHERE %s = UNHEX(REPLACE(:origen, \'-\', \'\'))', $tabla, $columna, $columna),
            ['destino' => $destino, 'origen' => $origen]
        );
    }

    /**
     * Las reglas de la cabecera, que es lo único que de verdad choca.
     *
     * - **nombre**: el más completo, no el más nuevo. «Susana Jiménez Bustamante» dice más que
     *   «Jimenez Susana», y la fecha ya decide quién sobrevive — no tiene por qué decidir todo.
     * - **idioma**: gana el fijado a mano; si ninguno, se queda el del superviviente.
     * - **estado**: abierto si alguno lo estaba. Cerrar es «no hay nada pendiente», y si en el
     *   otro hilo lo había, lo sigue habiendo.
     * - **WhatsApp desactivado**: si alguno lo estaba, se queda desactivado. Es una decisión de
     *   seguridad y va por el lado prudente.
     * - **ventana de 24 h**: la más lejana. Es del número, no del hilo.
     * - **resumen IA**: se borra. Resume una conversación que ya no es ésa; se regenera solo.
     */
    private function fusionarCabecera(MessageConversation $superviviente, MessageConversation $absorbido): void
    {
        $nombreA = trim((string) $superviviente->getGuestName());
        $nombreB = trim((string) $absorbido->getGuestName());
        if (mb_strlen($nombreB) > mb_strlen($nombreA)) {
            $superviviente->setGuestName($nombreB);
        }

        if (!$superviviente->isIdiomaFijado() && $absorbido->isIdiomaFijado()) {
            $superviviente->setIdioma($absorbido->getIdioma());
            $superviviente->setIdiomaFijado(true);
        }

        if ($absorbido->getStatus() === MessageConversation::STATUS_OPEN) {
            $superviviente->setStatus(MessageConversation::STATUS_OPEN);
        }

        // El veto de WhatsApp YA NO se propaga a lo bruto.
        //
        // Antes: «si alguno lo estaba, se queda desactivado» — el lado prudente cuando el flag
        // era del hilo. Ahora es de cada NÚMERO y viaja con su identidad, así que unir a alguien
        // con un número muerto y otro vivo tiene la respuesta correcta sin decidir nada aquí:
        // el hilo sólo queda vetado si TODOS sus teléfonos vivos lo están.
        //
        // Se recalcula al final de la fusión, cuando las identidades ya se movieron.

        $ventanaB = $absorbido->getWhatsappSessionValidUntil();
        $ventanaA = $superviviente->getWhatsappSessionValidUntil();
        if ($ventanaB !== null && ($ventanaA === null || $ventanaB > $ventanaA)) {
            $superviviente->setWhatsappSessionValidUntil($ventanaB);
        }

        $superviviente->setResumenIa(null);
    }
}
