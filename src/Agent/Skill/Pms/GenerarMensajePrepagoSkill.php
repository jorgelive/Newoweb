<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Conversation\PotenciaRequerida;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Entity\PmsCargoFinanciero;
use App\Pms\Finanzas\PmsProcedenciaHuesped;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsReserva;
use App\Pms\Enum\PmsMedioPago;
use App\Pms\Enum\PmsPoliticaPrepago;
use App\Pms\Enum\PmsTipoCargo;
use App\Pms\Service\Finance\PmsPrepagoCalculador;
use App\Pms\Service\Finance\TipoCambioDelDia;
use App\Security\Roles;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Genera la cotización y mensaje de prepago (adelanto) estructurado, listo para copiar a WhatsApp.
 *
 * Aplica matemáticamente las políticas del establecimiento, tipo de cambio SUNAT del día,
 * recargos de tarjeta del 5.5%, y filtra los métodos de pago según si el huésped es nacional o extranjero.
 */
final readonly class GenerarMensajePrepagoSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private PmsPrepagoCalculador $prepagoCalculador,
        private PmsProcedenciaHuesped $procedencia,
        private TipoCambioDelDia $tipoCambio,
    ) {}

    public function nombre(): string
    {
        return 'generar_mensaje_prepago';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Genera el mensaje y cotización estructurada de prepago (adelanto) para una reserva, '
                . 'listo para copiar y pegar a WhatsApp. Calcula automáticamente el total, noches, primera noche (prepago), '
                . 'conversión a soles según tipo de cambio del día, recargo del 5.5% para tarjeta, y adapta los medios '
                . 'de pago según si el huésped es nacional (Yape/Plin/Transferencia/Tarjeta) o extranjero (Tarjeta/Western Union). '
                . 'Requiere el reserva_id.',
            parametros: [
                SkillParameter::texto('reserva_id', 'Identificador de la reserva (UUID).', requerido: true),
            ],
            siguientePaso: PotenciaRequerida::Baja,
        );
    }

    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
    }

    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_SHOW, Roles::HUESPED];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $reservaId = trim((string) ($entrada['reserva_id'] ?? ''));

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error('El reserva_id no es válido. Localiza primero la reserva.');
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);
        if ($reserva === null) {
            return SkillResult::error('No se encontró la reserva indicada.');
        }

        $info = $reserva->getInformacionFinanciera();
        if ($info === null) {
            return SkillResult::error('Esta reserva no tiene cuenta financiera abierta.');
        }

        // Si es canal que ya cobró todo (ej: Airbnb, VRBO)
        if (in_array($reserva->getChannel()?->getId(), PmsChannel::CANAL_PAGO_TOTAL, true)) {
            return SkillResult::ok([
                'mensaje_texto' => 'Esta reserva proviene de un canal que ya gestionó el cobro total (' . $reserva->getChannel()->getNombre() . '). No corresponde solicitar prepago.',
                'aplica_prepago' => false,
            ]);
        }

        // ⚠️ `pendiente()` y NO `calcular()`. `calcular()` dice cuánto PIDE la política; éste,
        // cuánto queda por pedir — y si ya hay un pago registrado, ese pago ES el prepago. Con
        // `calcular()` esta skill redactaba un «solicitamos el pago adelantado…», con importes
        // y todo, para alguien que ya había pagado. Ver PmsPrepagoCalculador::pendiente().
        $prepagoData = $this->prepagoCalculador->pendiente($info);
        if ($prepagoData === null) {
            // Se distinguen los dos motivos: «ya pagó» y «no hay política» piden respuestas
            // distintas del operador, y un único mensaje para ambos hace que mande el que no era.
            $yaPago = $this->prepagoCalculador->calcular($info) !== null;

            return SkillResult::ok([
                'mensaje_texto' => $yaPago
                    ? 'Esta reserva ya tiene un pago registrado: el adelanto está cubierto y no '
                        . 'corresponde volver a pedirlo. Si quieres el saldo, usa consultar_cuenta.'
                    : 'La política del establecimiento está configurada como sin prepago.',
                'aplica_prepago' => false,
            ]);
        }

        $montoPrepagoUsd = (float) $prepagoData['monto'];
        $politicaEnum = PmsPoliticaPrepago::tryFrom($prepagoData['politica']);
        $politicaNombre = $politicaEnum ? $politicaEnum->etiquetaCorta() : 'Primera noche';

        // Tipo de cambio del día
        // Sin tipo de cambio del día NO se inventa uno: una constante escrita hace meses
        // cotiza dinero real con la tasa equivocada y no se nota al leer el mensaje.
        //
        // ⚠️ Se comprueba la TASA, no el registro. `MaestroTipocambio::getVenta()` es `?string`,
        // así que con una fila del día sin venta cargada, `(float) null` da `0.0` y la guarda de
        // abajo no saltaba: el mensaje salía con «Tipo de cambio aplicado: 0.00» y «Monto a
        // transferir: S/ 0.00». `TipoCambioDelDia::venta()` ya resuelve fecha y fallback.
        $ventaTc = $this->tipoCambio->venta();
        $tasaTc = ($ventaTc !== null && trim($ventaTc) !== '') ? (float) $ventaTc : null;

        if ($tasaTc !== null && $tasaTc <= 0.0) {
            $tasaTc = null;
        }

        // Desglose de cargos
        $cargosDesglose = $this->desgloseCargos($info);
        $totalCargos = array_sum(array_column($cargosDesglose, 'monto'));

        // Recargo tarjeta (+5.5%)
        $porcentajeTarjeta = (float) PmsMedioPago::TARJETA_CREDITO->comisionPorcentaje();
        $comisionTarjetaUsd = round($montoPrepagoUsd * ($porcentajeTarjeta / 100), 2);
        $totalTarjetaUsd = round($montoPrepagoUsd + $comisionTarjetaUsd, 2);

        // Conversión a Soles
        $montoPrepagoPen = $tasaTc !== null ? round($montoPrepagoUsd * $tasaTc, 2) : null;

        // Datos del huésped y estancia
        $huespedNombre = trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente());
        $adultos = $reserva->getCantidadAdultos() ?? 1;
        $ninos = $reserva->getCantidadNinos() ?? 0;
        $totalPax = $adultos + $ninos;
        $paxTexto = $totalPax > 1 ? sprintf('%s y %d acompañante%s', $reserva->getNombreCliente(), $totalPax - 1, ($totalPax - 1) > 1 ? 's' : '') : $huespedNombre;

        $casitas = $this->unidadesNombres($reserva);
        $casitaTexto = $casitas !== [] ? implode(', ', $casitas) : 'Alojamiento';

        $noches = $reserva->getNoches();
        $fechasTexto = $this->formatearFechas($reserva->getFechaLlegada(), $reserva->getFechaSalida(), $noches);

        // La misma respuesta que dan `consultar_medios_pago`, `consultar_cuenta` y la guía del
        // huésped. Un `=== 'PE'` propio aquí sería una cuarta versión de la misma pregunta.
        // `null` (no se sabe) NO es «peruano»: ver PmsProcedenciaHuesped::pagaDesdePeru().
        $esNacional = $this->procedencia->pagaDesdePeru($reserva) === true;

        // El mensaje a quien paga desde Perú lleva el importe en soles, y ése sale del tipo de
        // cambio del día. Sin él NO se redacta a medias ni con una tasa inventada: se dice qué
        // falta, que es accionable, en vez de mandar una cifra que nadie va a verificar.
        if ($esNacional && $tasaTc === null) {
            return SkillResult::ok([
                'mensaje_texto' => 'No hay tipo de cambio del día cargado, y el mensaje para quien '
                    . 'paga desde Perú lleva el importe en soles. Carga el tipo de cambio y vuelve '
                    . 'a pedírmelo, o pide el mensaje en dólares.',
                'aplica_prepago' => false,
            ]);
        }

        // ─────────────────────────────────────────────────────────────────────────────
        // 🔗 EL ENLACE DE PAGO VA AQUÍ — DESACTIVADO (19/08/2026)
        // ─────────────────────────────────────────────────────────────────────────────
        //
        // El mensaje que se compone abajo ANUNCIA un «Enlace de pago seguro» en sus dos ramas y
        // hoy no lo lleva: `FINANZAS_ENLACES_PREPAGO=0`, la pasarela no está habilitada.
        //
        // ⚠️ **NO basta con quitar las barras.** Este bloque dejó de ser un `//` de quita y pon
        // en cuanto se revisó de verdad; hacen falta CINCO cosas, y las tres últimas no son
        // técnicas:
        //
        //  1. **Inyectar el servicio.** `PmsPrepagoEnlaceService` NO está en el constructor de
        //     esta skill ni importado. Sin eso, `$this->prepagoEnlaces` es propiedad indefinida
        //     y revienta en la primera ejecución.
        //  2. **Capturar `DomainException`.** `emitir()` lanza con el flag apagado, sin cuenta
        //     de cobro y sin prepago pendiente. `GenerarEnlacePrepagoSkill` lo envuelve en
        //     try/catch; aquí haría falta lo mismo, o la skill deja de contestar en vez de
        //     redactar el mensaje sin enlace.
        //  3. **`emitir()` CREA UN COBRO** (`FinEnlacePago` persistido y flusheado). Esta skill
        //     es hoy `NivelRiesgo::Lectura`, o sea que se ejecuta SIN que nadie confirme.
        //     Emitir desde aquí la convierte en escritura: hay que subirle el nivel y pasar por
        //     la previsualización, como hace `generar_enlace_prepago`.
        //  4. **O no emitir aquí**: que esta skill sólo LEA un enlace ya emitido y deje la
        //     emisión donde está. Es la que no toca el nivel de riesgo, y probablemente la
        //     buena: dos skills creando cobros para lo mismo es lo que se quiere evitar.
        //  5. **Mientras tanto el texto promete algo que no viaja.** Si la espera va para
        //     largo, lo honesto es quitar la línea del mensaje antes que dejarla puesta.
        //
        // Ver docs/Pendientes.md.
        //
        // try {
        //     $enlace = $this->prepagoEnlaces->emitir($reserva);   // ← CREA EL COBRO
        //     $urlPago = $enlace['url'];
        // } catch (DomainException) {
        //     $urlPago = null;   // sin enlace: el mensaje no debe prometerlo
        // }
        //
        // …y abajo, dentro de las dos ramas, junto a «Enlace de pago seguro»:
        // if ($urlPago !== null) { $lineas[] = sprintf('▪️ Paga aquí: %s', $urlPago); }

        // Construir el mensaje formateado
        $lineas = [];
        $lineas[] = "🏨 DETALLE DE RESERVA - {$casitaTexto}";
        $lineas[] = "👤 Huésped: {$paxTexto}";
        $lineas[] = "📅 Estancia: {$fechasTexto}";
        $lineas[] = "";
        $lineas[] = "💰 RESUMEN DE LA RESERVA";

        foreach ($cargosDesglose as $c) {
            $lineas[] = sprintf("▪️ %s: US$ %.2f", $c['concepto'], $c['monto']);
        }

        $lineas[] = "---";
        $lineas[] = sprintf("✅ TOTAL DE LA RESERVA: US$ %.2f", $totalCargos);
        $lineas[] = "";
        $lineas[] = "💳 PAGO ADELANTADO ({$politicaNombre})";
        $lineas[] = "";

        if ($esNacional) {
            $lineas[] = sprintf(
                "Para confirmar y garantizar tu reserva, solicitamos el pago adelantado correspondiente a la %s (US$ %.2f). Te ofrecemos dos opciones para realizarlo (Tipo de cambio aplicado: %.2f):",
                mb_strtolower($politicaNombre),
                $montoPrepagoUsd,
                $tasaTc
            );
            $lineas[] = "";
            $lineas[] = "Opción 1: Sin tarjeta (Depósito, transferencia, Plin o Yape)";
            $lineas[] = sprintf("▪️ Monto a transferir: S/ %.2f (US$ %.2f)", $montoPrepagoPen, $montoPrepagoUsd);
            $lineas[] = "";
            $lineas[] = "Opción 2: Con tarjeta (Enlace de pago seguro)";
            $lineas[] = sprintf("(Se adiciona un %.1f%% de comisión de la pasarela de pago)", $porcentajeTarjeta);
            $lineas[] = sprintf("▪️ Monto base: US$ %.2f", $montoPrepagoUsd);
            $lineas[] = sprintf("▪️ Comisión (%.1f%%): US$ %.2f", $porcentajeTarjeta, $comisionTarjetaUsd);
            $lineas[] = "---";
            $lineas[] = sprintf("✅ Total a pagar con tarjeta: US$ %.2f", $totalTarjetaUsd);
        } else {
            $lineas[] = sprintf(
                "Para confirmar y garantizar tu reserva, solicitamos el pago adelantado correspondiente a la %s (US$ %.2f). Disponemos de las siguientes opciones de pago internacional:",
                mb_strtolower($politicaNombre),
                $montoPrepagoUsd
            );
            $lineas[] = "";
            $lineas[] = "Opción 1: Tarjeta de crédito / débito (Enlace de pago seguro)";
            $lineas[] = sprintf("(Se adiciona un %.1f%% de comisión de la pasarela de pago)", $porcentajeTarjeta);
            $lineas[] = sprintf("▪️ Monto base: US$ %.2f", $montoPrepagoUsd);
            $lineas[] = sprintf("▪️ Comisión (%.1f%%): US$ %.2f", $porcentajeTarjeta, $comisionTarjetaUsd);
            $lineas[] = "---";
            $lineas[] = sprintf("✅ Total a pagar con tarjeta: US$ %.2f", $totalTarjetaUsd);
            $lineas[] = "";
            $lineas[] = "Opción 2: Western Union";
            $lineas[] = sprintf("▪️ Monto neto a enviar: US$ %.2f", $montoPrepagoUsd);
        }

        $mensajeFinal = implode("\n", $lineas);

        return SkillResult::ok([
            'reserva_id' => $reservaId,
            'huesped' => $huespedNombre,
            'pais' => $reserva->getPais()?->getId(),
            'es_nacional' => $esNacional,
            'prepago_usd' => sprintf('%.2f', $montoPrepagoUsd),
            'prepago_pen' => $montoPrepagoPen !== null ? sprintf('%.2f', $montoPrepagoPen) : null,
            'prepago_tarjeta_usd' => sprintf('%.2f', $totalTarjetaUsd),
            'tipo_cambio' => $tasaTc !== null ? sprintf('%.3f', $tasaTc) : null,
            'mensaje_whatsapp' => $mensajeFinal,
        ]);
    }

    /**
     * @return list<array{concepto: string, monto: float}>
     */
    private function desgloseCargos(\App\Pms\Entity\PmsInformacionFinanciera $info): array
    {
        $desglose = [];
        $noches = $info->getReserva()?->getNoches() ?? 1;

        foreach ($info->getCargos() as $cargo) {
            /** @var PmsCargoFinanciero $cargo */
            $monto = (float) ($cargo->getTotalLinea() ?? $cargo->getMonto() ?? '0');
            if ($monto <= 0.0) {
                continue;
            }

            $tipo = $cargo->getTipoCargo();
            $concepto = match ($tipo) {
                PmsTipoCargo::ALOJAMIENTO => sprintf('Alojamiento (%d noche%s)', $noches, $noches > 1 ? 's' : ''),
                PmsTipoCargo::LIMPIEZA => 'Suplemento de limpieza',
                PmsTipoCargo::SERVICIO => 'Cargo por servicio',
                default => trim((string) $cargo->getDescripcion()) ?: 'Otros cargos',
            };

            $desglose[] = [
                'concepto' => $concepto,
                'monto' => $monto,
            ];
        }

        return $desglose;
    }

    /**
     * @return list<string>
     */
    private function unidadesNombres(PmsReserva $reserva): array
    {
        $nombres = [];
        foreach ($reserva->getEventosCalendario() as $evento) {
            $nombre = $evento->getPmsUnidad()?->getNombre();
            if ($nombre !== null && $nombre !== '' && !in_array($nombre, $nombres, true)) {
                $nombres[] = $nombre;
            }
        }
        return $nombres;
    }

    private function formatearFechas(?DateTimeInterface $llegada, ?DateTimeInterface $salida, int $noches): string
    {
        if ($llegada === null || $salida === null) {
            return 'Fechas por confirmar';
        }

        $meses = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
        ];

        $diaIn = (int) $llegada->format('d');
        $mesIn = (int) $llegada->format('m');
        $anoIn = (int) $llegada->format('Y');

        $diaOut = (int) $salida->format('d');
        $mesOut = (int) $salida->format('m');
        $anoOut = (int) $salida->format('Y');

        $nochesTexto = sprintf('(%d noche%s)', $noches, $noches > 1 ? 's' : '');

        if ($anoIn === $anoOut) {
            if ($mesIn === $mesOut) {
                return sprintf('%d al %d de %s de %d %s', $diaIn, $diaOut, $meses[$mesIn], $anoIn, $nochesTexto);
            }
            return sprintf('%d de %s al %d de %s de %d %s', $diaIn, $meses[$mesIn], $diaOut, $meses[$mesOut], $anoIn, $nochesTexto);
        }

        return sprintf('%d de %s de %d al %d de %s de %d %s', $diaIn, $meses[$mesIn], $anoIn, $diaOut, $meses[$mesOut], $anoOut, $nochesTexto);
    }
}
