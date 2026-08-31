<?php

declare(strict_types=1);

namespace App\Pax\Command;

use App\Pax\Entity\UiI18n;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Las cadenas que faltaban para el resumen de pago de la ficha del huésped.
 *
 * ── Por qué un comando y no una migración ───────────────────────────────────
 * `UiI18n::$contenido` lleva `#[AutoTranslate(sourceLanguage: 'es')]`, y ese listener se
 * engancha a `prePersist`/`preUpdate`. Un `INSERT` en SQL **se lo salta**, así que las
 * cadenas nacerían sólo en español y el huésped que lee en inglés vería castellano sin que
 * fallara nada. Es la regla de CLAUDE.md: lo que dispara listeners entra por el ORM.
 *
 * **Idempotente por la clave natural** (el `id` de `UiI18n`): las que ya existan no se tocan,
 * ni siquiera para reescribir su español — puede haberlas afinado alguien desde el panel, y
 * pisarlas sería descartar ese trabajo sin avisar.
 *
 *   php bin/console pax:textos:cobro --dry-run   # dice qué crearía
 *   php bin/console pax:textos:cobro             # las crea y las traduce
 */
#[AsCommand(
    name: 'pax:textos:cobro',
    description: 'Crea las cadenas UiI18n del resumen de pago que faltan. Idempotente.',
)]
final class PaxCrearTextosCobroCommand extends Command
{
    private const string SCOPE = 'reserva';

    /**
     * Sólo lo que NO existe ya. Se reutiliza a propósito `res_recargo_nota` («incluye X% de
     * comisión») en vez de inventar un gemelo: dos claves que dicen lo mismo acaban diciendo
     * cosas distintas en el idioma número cinco.
     *
     * @var array<string, string>
     */
    private const array TEXTOS = [
        // Los dos medios que `FinMedioCobroTipo` separa y `PmsMedioPago` juntaba en
        // `plin_yape`. El catálogo de cobro los distingue, así que necesitan su cadena.
        'res_medio_yape' => 'Yape',
        'res_medio_plin' => 'Plin',

        // Qué se le está pidiendo. Son dos y no uno porque la diferencia importa: un adelanto
        // asegura la reserva y el total la cierra, y el huésped no reacciona igual.
        'res_pide_adelanto' => 'Adelanto para asegurar tu reserva',
        'res_pide_total' => 'Total a pagar',

        // El segundo peldaño de la tarjeta. `res_ver_mas`/`res_ver_menos` ya existen y ahora
        // abren el RESUMEN; éstas son las del detalle, que vive dentro.
        'res_ver_detalle' => 'Ver detalle',
        'res_ocultar_detalle' => 'Ocultar detalle',

        // La nota del asterisco, fuera del cuadro del prepago.
        'res_con_tarjeta' => 'Con tarjeta de crédito',
        'res_con_estos_medios' => 'Con estos medios de pago',

        // ⚠️ Faltaba y nadie lo había notado: la vista lleva meses cayendo al respaldo en
        // castellano, así que un huésped con la ficha en inglés leía «Pagar ahora» en medio
        // de todo lo demás traducido. El respaldo del `||` no falla — y por eso no se ve.
        'res_pagar_online' => 'Pagar ahora',

        // La X de la ficha de cuentas. En táctil no hay «quitar el ratón de encima»: el
        // cierre tiene que ser un botón, y un botón necesita nombre para el lector de
        // pantalla aunque sólo se vea un aspa.
        'res_cerrar' => 'Cerrar',

        // Cómo avisarnos de que ya pagó. Son DOS y no una porque el chat de Booking no
        // transporta imágenes y el resto sí: la variante la elige `PmsReservaPaxProvider`
        // según el canal de la reserva, no el texto. Estuvo metido dentro de las notas de Yape
        // y Plin del catálogo de cobro, y así se le hablaba del chat de Booking a huéspedes de
        // reservas directas. Ver `PmsChannel::CHAT_SIN_IMAGENES`.
        //
        // ⚠️ **Sin «este chat»** (31/08/2026). Estas dos frases se redactaron pensando en un
        // mensaje, y donde se leen es en la FICHA WEB del huésped: allí no hay ningún chat al
        // que referirse, y «avísanos por este chat» manda a un sitio que la persona no tiene
        // delante. Se mantiene la distinción entre las dos —el canal de Booking no transporta
        // imágenes— pero dicha sin señalar un «este» que no existe.
        'res_aviso_pago' => 'Avísanos cuando lo hayas hecho y el equipo lo confirma. Si quieres, mándanos la captura.',
        'res_aviso_pago_sin_imagenes' => 'Avísanos cuando lo hayas hecho y el equipo lo confirma. Si quieres mandarnos la captura, hazlo por WhatsApp.',

        // En qué moneda es la cuenta, con palabras. El símbolo a secas —«S/. +51 958191965»—
        // se lee como el prefijo de un precio que no está, no como «esta cuenta es en soles»:
        // en la columna del importe el símbolo es lo correcto, y como rótulo de una fila no.
        'res_en_soles' => 'En soles',
        'res_en_dolares' => 'En dólares',

        // El titular de la cuenta. Sin rótulo era un nombre suelto bajo unos números y no
        // decía su papel — que es el que el huésped tiene que teclear en su banca.
        'res_a_nombre_de' => 'A nombre de',

        // El cruce de monedas SALDADO: pagó en soles una cuenta emitida en dólares. La
        // contabilidad es por moneda y sin convertir (§12.2b), así que las dos líneas son
        // ciertas por separado —una cuenta en dólares, un pago en soles— y juntas no son dos
        // deudas: son las dos mitades de una misma transacción ya cerrada.
        'res_dos_monedas_saldado' => 'Pagaste en una moneda una cuenta emitida en otra. Está saldado: no queda nada pendiente.',
        'res_moneda_cuenta' => 'Cuenta',
        'res_moneda_pagaste' => 'Pagaste',

        // ── El SEGUNDO tramo del cobro (30/08/2026) ─────────────────────────────────
        //
        // `res_saldo` («Saldo por pagar») dice cuánto, y no dice CUÁNDO. En el mensaje de
        // pago eso no basta: el huésped acaba de leer un adelanto que sí se paga ahora, y
        // sin el momento delante lo natural es entender que las dos cifras se piden juntas.
        // Es la diferencia entre «te pido 35.91» y «te pido 107.74».
        //
        // Las llaves están a propósito: es lo que hace inconfundible el momento —cuando te
        // las entregamos— sin depender de que el huésped sepa a qué hora es el check-in.
        'res_saldo_al_llegar' => 'Saldo (a tu llegada, al entregarte las llaves)',

        // El gemelo de `res_recargo_nota` para la línea SIN recargo. Existe porque el hueco
        // se lee mal: una línea con «incluye 5.5% de comisión» y otra con nada al lado
        // invita a pensar que la comisión también está ahí y no se ha escrito.
        'res_sin_comision' => 'Sin comisión',

        // ── Lo YA COBRADO, en el mensaje (31/08/2026) ───────────────────────────────
        //
        // La ficha del huésped lo enseñaba con su barra de progreso; el mensaje no. Y sin él,
        // «Total de la reserva: 890» seguido de «Total a pagar: 590» son dos cifras sin
        // relación aparente: o parece un error nuestro, o el huésped escribe preguntando.
        //
        // `res_adelanto_pagado` no vale: dice «adelanto», y un pago a cuenta no siempre lo es.
        'res_ya_pagado' => 'Ya pagado',

        // El mismo segundo momento, dicho de otra forma según lo que se pida arriba.
        //
        // Con adelanto, «Saldo (a tu llegada…)» encaja: son dos cifras distintas y ésta es el
        // resto. Pidiendo el TOTAL es el MISMO número que arriba, y llamarlo «saldo» debajo de
        // «Total a pagar» se lee como si fueran dos deudas. Aquí no es otra cifra: es la misma
        // en otro momento, y con otros medios.
        'res_o_al_llegar' => 'O a tu llegada, al entregarte las llaves',

        // ── Los dos rótulos del detalle (31/08/2026) ────────────────────────────────
        //
        // «Detalle» no decía de qué: el bloque de abajo también es detalle. Y su gemelo, la
        // sección de pagos, no tenía rótulo ninguno — se distinguía sólo por una línea de
        // puntos, así que el ojo leía los cobros como una fila más de los cargos.
        'res_cargos' => 'Cargos',
        'res_pagos' => 'Pagos',

        // `res_adelanto_pagado` decía «Adelanto pagado» y dejó de ser cierto en cuanto hubo
        // segundos pagos: ahí es la SUMA de todos. Y con un pago único quedaba peor todavía
        // —«Tarjeta 65,10» y justo debajo «Adelanto pagado −65,10»—, dos veces el mismo
        // número, uno positivo y otro negativo, que se lee como si se anularan.
        'res_total_pagado' => 'Total pagado',

        // ── El rango de fechas de una estancia (31/08/2026) ─────────────────────────
        //
        // La GRAMÁTICA es de cada idioma —«del … al …», «from … to …», «du … au …»— y por eso
        // vive aquí y no en el código, igual que el saludo. Las fechas las formatea ICU con el
        // patrón que cada locale considera correcto («28 de agosto» / «August 28»).
        'res_estancia_tramo' => 'del {{ desde }} al {{ hasta }}',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sólo dice qué haría');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $simular = (bool) $input->getOption('dry-run');

        $repo = $this->em->getRepository(UiI18n::class);
        $creadas = [];
        $existentes = [];

        foreach (self::TEXTOS as $clave => $es) {
            if ($repo->find($clave) !== null) {
                $existentes[] = $clave;
                continue;
            }

            $creadas[] = [$clave, $es];

            if ($simular) {
                continue;
            }

            $texto = (new UiI18n())
                ->setId($clave)
                ->setScope(self::SCOPE)
                // El listener de traducción rellena los otros idiomas al persistir. Aquí sólo
                // se pone el origen, que es lo que declara `sourceLanguage: 'es'`.
                ->setContenido([['language' => 'es', 'content' => $es]]);

            $this->em->persist($texto);
        }

        if ($existentes !== []) {
            $io->writeln(sprintf('Ya existían (no se tocan): %s', implode(', ', $existentes)));
        }

        if ($creadas === []) {
            $io->success('No falta ninguna cadena.');

            return Command::SUCCESS;
        }

        $io->table(['Clave', 'Español'], $creadas);

        if ($simular) {
            $io->note(sprintf('Simulación: se crearían %d. Sin --dry-run se escriben y se traducen.', count($creadas)));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d cadenas creadas y encoladas para traducir.', count($creadas)));

        return Command::SUCCESS;
    }
}
