<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El texto para el asistente de los diecinueve ítems que se escribieron a mano.
 *
 * {@see Version20260810140000} sembró `agente_contenido` con el cuerpo publicado, pero **sólo
 * donde estaba a `NULL`**. Estos diecinueve se reescribieron después, revisándolos uno a uno
 * contra las respuestas reales del agente. Esa revisión no viaja sola: en un entorno nuevo la
 * siembra los dejaría con el texto de la guía y se perdería el trabajo.
 *
 * ⚠️ **SOBRESCRIBE**, al revés que la siembra. Lleva una versión concreta, no un punto de
 * partida. Si alguien los edita en el panel ANTES de que esto corra en su entorno, se pierde su
 * edición. Corre una vez, en un despliegue.
 *
 * ### La regla que ordena estos textos: lo específico gana
 *
 * Cuando una skill dedicada cubre un tema, **la guía deja de contarlo y remite a ella**. No es
 * estilo: la skill comprueba permisos que la guía no puede comprobar, y es la que se actualiza
 * cuando cambia el dato.
 *
 * - **Llaves** ya no da el código: manda a `consultar_codigos`, que además mira si a ese huésped
 *   le toca tenerlo. Es el ítem que llevaba `{{ keybox_main }}`, así que hasta hoy el código
 *   llegaba por dos caminos distintos.
 * - **Pago** ya no nombra Yape ni las cuentas: manda a `consultar_medios_pago`. Si los nombrara,
 *   el día que se desactive un medio en el catálogo la guía seguiría ofreciéndolo.
 *
 * ### Qué se corrigió en cada grupo
 *
 * - **Puerta (casas 1-7)**: fuera las referencias a fotos y croquis. En la app el huésped ve la
 *   imagen debajo; en el chat el agente prometía «la foto a continuación» y no llegaba nada. La
 *   Casa 1 anunciaba un «[Video de apertura]». También se quitó de la Casa 6 la mención al
 *   almacén del personal, que no es información de huésped.
 * - **Ducha (casas 1-7)**: la tabla de temperatura se leía al revés —«Mayor caudal = agua
 *   caliente · Menor caudal = agua muy caliente», las dos filas decían caliente—. Ahora dice
 *   explícitamente que **a más caudal, menos temperatura**, que es contraintuitivo y por eso hay
 *   que decirlo tal cual. Las casas 6 y 7 llevan variante propia: tienen dos baños distintos.
 * - **Llaves**: remite a la skill, y aclara que **sólo hay un juego** — la pregunta por el
 *   «segundo juego» apareció varias veces y se contestaba escalando.
 * - **Calefactor**: la justificación del precio (el kWh a S/ 0.93) era la mitad del ítem. En la
 *   guía es un argumento legítimo; recitado a quien sólo preguntó si hay calefacción, es vender
 *   sin que pregunten. Queda detrás de «sólo si él lo menciona».
 * - **Pago**: la condición por canal, el depósito con su «sólo si pregunta», por qué el importe
 *   del canal no cuadra, y que **por el chat de Booking no se pueden adjuntar imágenes** — un
 *   huésped lo dijo textualmente y las notas del catálogo le pedían justo eso.
 * - **Horario solicitudes**: los dos teléfonos, con la aclaración de que el segundo es también
 *   el del Yape.
 * - **Early check in Late Check Out**: la política de flexibilidad, el almacén y el enlace de la
 *   guía. Nombra **las dos vías** por las que llegan los datos de ocupación —el contexto en el
 *   chat del huésped, `casita_ese_dia` desde el panel— porque el mismo texto lo leen dos actores
 *   distintos y el campo de la skill es sólo para el equipo.
 *
 * Las siete «Descripción» no están aquí: su único cambio fue quitar las horas fijas, y de eso se
 * encarga {@see Version20260810200000}.
 *
 * Ver docs/PmsGuiaHuesped.md para cómo se escribe este campo y por qué omitir un hecho hace que
 * el agente lo niegue en vez de callárselo.
 */
final class Version20260810180000 extends AbstractMigration
{
    /** @var array<string, array{contenido: string, terminos?: string}> */
    private const array ITEMS = [
        'Calefactor (general)' => [
            'contenido' => 'Alquilamos calefactores: 20 soles por turno de 12 horas. Los turnos son noche (7 pm a 7 am) y
dia (7 am a 7 pm). No se alquilan por horas sueltas, se cobra el turno completo.

Se encienden en remoto desde nuestro sistema, asi que basta con avisarnos por el chat. Tienen
que quedarse enchufados, y llevan termostato que regula la temperatura solo.

Si comenta que le parece caro o pregunta por que se cobra aparte: en Cusco la electricidad esta
a S/ 0.93 el kWh y un calefactor consume unos 24 kWh en un turno completo, casi lo mismo que
cuesta el alquiler. No saques este argumento tu; solo si el lo menciona.
',
        ],
        'Ducha (casa 1)' => [
            'contenido' => 'La manija izquierda es la del agua caliente.

Abrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente, abrela
todavia mas: en estos calentadores a gas, cuanta mas agua pasa, menos caliente sale. Es al reves
de lo que uno espera, y conviene decirselo tal cual.

Solo si aun asi sigue muy caliente, que abra poco a poco la manija derecha, la fria.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale mas
caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso se
recomienda dejarla siempre con un chorro medio o alto.

Funcionan con gas propano en balones. Si se acaba el agua caliente, lo mas probable es que el
balon este vacio: que nos avise y lo cambiamos. Si ya se lo explicaste y vuelve a decir que
sigue sin salir caliente, no repitas las instrucciones: avisa al equipo.',
        ],
        'Ducha (casa 2)' => [
            'contenido' => 'La manija izquierda es la del agua caliente.

Abrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente, abrela
todavia mas: en estos calentadores a gas, cuanta mas agua pasa, menos caliente sale. Es al reves
de lo que uno espera, y conviene decirselo tal cual.

Solo si aun asi sigue muy caliente, que abra poco a poco la manija derecha, la fria.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale mas
caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso se
recomienda dejarla siempre con un chorro medio o alto.

Funcionan con gas propano en balones. Si se acaba el agua caliente, lo mas probable es que el
balon este vacio: que nos avise y lo cambiamos. Si ya se lo explicaste y vuelve a decir que
sigue sin salir caliente, no repitas las instrucciones: avisa al equipo.',
        ],
        'Ducha (casa 3)' => [
            'contenido' => 'La manija izquierda es la del agua caliente.

Abrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente, abrela
todavia mas: en estos calentadores a gas, cuanta mas agua pasa, menos caliente sale. Es al reves
de lo que uno espera, y conviene decirselo tal cual.

Solo si aun asi sigue muy caliente, que abra poco a poco la manija derecha, la fria.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale mas
caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso se
recomienda dejarla siempre con un chorro medio o alto.

Funcionan con gas propano en balones. Si se acaba el agua caliente, lo mas probable es que el
balon este vacio: que nos avise y lo cambiamos. Si ya se lo explicaste y vuelve a decir que
sigue sin salir caliente, no repitas las instrucciones: avisa al equipo.',
        ],
        'Ducha (casa 4)' => [
            'contenido' => 'La manija izquierda es la del agua caliente.

Abrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente, abrela
todavia mas: en estos calentadores a gas, cuanta mas agua pasa, menos caliente sale. Es al reves
de lo que uno espera, y conviene decirselo tal cual.

Solo si aun asi sigue muy caliente, que abra poco a poco la manija derecha, la fria.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale mas
caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso se
recomienda dejarla siempre con un chorro medio o alto.

Funcionan con gas propano en balones. Si se acaba el agua caliente, lo mas probable es que el
balon este vacio: que nos avise y lo cambiamos. Si ya se lo explicaste y vuelve a decir que
sigue sin salir caliente, no repitas las instrucciones: avisa al equipo.',
        ],
        'Ducha (casa 5)' => [
            'contenido' => 'La manija izquierda es la del agua caliente.

Abrela del todo y espera unos segundos a que salga caliente. Si sale DEMASIADO caliente, abrela
todavia mas: en estos calentadores a gas, cuanta mas agua pasa, menos caliente sale. Es al reves
de lo que uno espera, y conviene decirselo tal cual.

Solo si aun asi sigue muy caliente, que abra poco a poco la manija derecha, la fria.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale mas
caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso se
recomienda dejarla siempre con un chorro medio o alto.

Funcionan con gas propano en balones. Si se acaba el agua caliente, lo mas probable es que el
balon este vacio: que nos avise y lo cambiamos. Si ya se lo explicaste y vuelve a decir que
sigue sin salir caliente, no repitas las instrucciones: avisa al equipo.',
        ],
        'Ducha (casa 6)' => [
            'contenido' => 'Esta casita tiene dos banos y no funcionan igual:

- Bano A, mezclador de una sola palanca: el agua caliente es hacia la izquierda.
- Bano B, dos manijas: la caliente es la izquierda.

En los dos, abrir del todo la caliente y esperar unos segundos. Si sale DEMASIADO caliente, hay
que abrirla todavia mas: en estos calentadores a gas, cuanta mas agua pasa, menos caliente sale.
Es al reves de lo que uno espera, y conviene decirselo tal cual.

Solo si aun asi sigue muy caliente, que regule con la fria poco a poco.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale mas
caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso se
recomienda dejarla siempre con un chorro medio o alto.

Funcionan con gas propano en balones. Si se acaba el agua caliente, lo mas probable es que el
balon este vacio: que nos avise y lo cambiamos. Si ya se lo explicaste y vuelve a decir que
sigue sin salir caliente, no repitas las instrucciones: avisa al equipo.',
        ],
        'Ducha (casa 7)' => [
            'contenido' => 'Esta casita tiene dos banos y no funcionan igual:

- Bano A, mezclador de una sola palanca: el agua caliente es hacia la izquierda.
- Bano B, dos manijas: la caliente es la izquierda.

En los dos, abrir del todo la caliente y esperar unos segundos. Si sale DEMASIADO caliente, hay
que abrirla todavia mas: en estos calentadores a gas, cuanta mas agua pasa, menos caliente sale.
Es al reves de lo que uno espera, y conviene decirselo tal cual.

Solo si aun asi sigue muy caliente, que regule con la fria poco a poco.

NO debe cerrar la caliente para bajar la temperatura: al reducir el chorro el agua sale mas
caliente todavia, y si pasa de 80 grados el calentador se apaga solo por seguridad. Por eso se
recomienda dejarla siempre con un chorro medio o alto.

Funcionan con gas propano en balones. Si se acaba el agua caliente, lo mas probable es que el
balon este vacio: que nos avise y lo cambiamos. Si ya se lo explicaste y vuelve a decir que
sigue sin salir caliente, no repitas las instrucciones: avisa al equipo.',
        ],
        'Early check in Late Check Out' => [
            'contenido' => 'Horarios: entrada desde "hora_check_in", salida hasta "hora_check_out", que vienen en esta
misma respuesta. Usa esas, no des una hora estandar.

SU GUIA. Cuando hable de su llegada, recuerdale que las instrucciones de ingreso estan en su
guia y pasale el enlace "guide_url" de consultar_mi_reserva.

EQUIPAJE. Tenemos almacen: puede dejar las maletas si llega antes de la entrada o si ya hizo
el check-out y sigue en la ciudad. Ofrecelo con confianza. Si pregunta el costo, avisa al
equipo.

FLEXIBILIDAD. Si la casita esta libre alrededor de su estancia lo sabras por dos vias segun
quien pregunte: con el huesped viene en el contexto; desde el panel, en "casita_ese_dia" y
"casita_tras_su_salida" de consultar_mi_reserva.

- Sale otro huesped ese dia: no hay margen. Ofrece almacen y avisa al equipo.
- La casita esta libre: cabe pedir hasta unas 3 horas antes de la entrada. Dile que lo ves
  posible y que lo confirma el equipo, y avisale de que la limpieza podria estar aun
  trabajando y de que la llave quiza no este todavia en la caja.
- Entra otro huesped el dia que se va: la salida es a su hora. Almacen y avisar.
- Sin nadie entrando: cabe pedir hasta 1 hora extra a la salida, misma formula.

Eso es interno: da la conclusion, nunca digas que hay otro huesped ni sus horas.

NUNCA lo concedas tu, ni con la casita libre: dices que parece posible y escalas. Es para
margenes pequenos: entrar a media manana o quedarse hasta las 3 ya es entrada temprana o salida
tarde de verdad, tiene costo, se coordina el dia anterior segun disponibilidad y lo confirma el
equipo. Si quiere asegurarlo, la unica forma es reservar una noche adicional; ofrecelo solo si
insiste en tener certeza.',
            'terminos' => 'horario, check in, check out, a que hora entro, a que hora salgo, entrada, salida, llegar antes, salir tarde, quedarme mas, early check in, late check out, what time, arrival time, departure time, equipaje, maletas, almacen, guardar maletas, dejar maletas, storage, luggage, early check in, late check out, llego temprano, salgo tarde',
        ],
        'Horario solicitudes (general)' => [
            'contenido' => 'Horario de atencion: 9:00 a.m. a 9:00 p.m.

Si necesita papel higienico, jabon liquido u otros suministros, que avise con un dia de
anticipacion para poder reponerlos.

Telefonos, si los pide: +51 961 281 953 y +51 958 191 965. Por los dos contestamos. El
segundo es ademas el numero del Yape, asi que si pregunta si es el mismo, la respuesta es que si.',
        ],
        'Llaves (general)' => [
            'contenido' => 'Las llaves se sacan de una caja fuerte digital que esta en el pasaje. El codigo NO lo des de aqui: pidelo con consultar_codigos, que es el bueno y comprueba ademas si a este huesped ya le toca tenerlo. Si esa skill dice que todavia no, explicale el motivo que te da y no lo busques por otro lado.

Se entrega UN solo juego de llaves. Si pregunta por un segundo juego, la respuesta es que no hay: solo hay uno. No le digas que deberia estar sobre la mesa.

Si extravia las llaves, que avise de inmediato para cambiar los pines de la cerradura. El costo del cerrajero corre por cuenta del huesped.',
        ],
        'Pago (general)' => [
            'contenido' => 'Quien cobra depende del canal. Compruebalo con consultar_mi_reserva, campo channel_name.

- Airbnb: la plataforma ya cobro todo. No paga nada al llegar y NO se le pide deposito.
- Booking.com y reservas directas: el pago no lo cobra la plataforma, se abona directamente a
  nosotros. Se hace al llegar, o se puede adelantar antes.

POR DONDE se paga NO lo digas de aqui: pidelo con consultar_medios_pago. Esa skill tiene el
catalogo real y ya filtra por si el huesped paga desde Peru o desde fuera. Si los nombraramos aqui,
el dia que se desactive uno seguiriamos ofreciendolo.

Deposito de garantia: SOLO si pregunta por el. No lo saques tu.
Aplica a Booking.com y reservas directas; a los de Airbnb no. Si pregunta: son S/ 300 que se
dejan al hacer el check-in y se devuelven integros al salir, cuando se comprueba que estan las
llaves y no hay danos. No es un cobro, es un deposito en garantia.

Si dice que en la app del canal ve OTRO importe: es normal y no es un error. El canal muestra
su tarifa, que puede no incluir el suplemento de limpieza ni el cargo por servicio, y nunca
refleja los pagos que ya nos hizo directamente. La cifra buena es la de consultar_cuenta.
Explicaselo asi y, si insiste o los numeros no cuadran, avisa al equipo.

Si pregunta por que se cobra el suplemento de limpieza o el cargo por servicio: son cargos
fijos de la reserva, no extras opcionales, y ya estaban en el total desde que reservo. NO te
inventes una justificacion de en que se gasta cada uno; si quiere el detalle, avisa al equipo.

Comprobantes: por el chat de Booking NO se pueden adjuntar imagenes. Si te dice que ya pago y
quiere mandar la captura, no le pidas que la adjunte aqui: dile que nos la mande por WhatsApp
o que nos avise y el equipo se la pide.',
        ],
        'Puerta (casa 1)' => [
            'contenido' => 'La entrada de la Casa 1 esta en la calle, no en el pasaje: justo a la izquierda del porton de rejas verde. Es una puerta a nivel de calle, facil de reconocer al llegar a la direccion.',
        ],
        'Puerta (casa 2)' => [
            'contenido' => 'La entrada de la Casa 2 esta directamente sobre la calle, no en el pasaje. Es una puerta de madera verde, a nivel de calle y facil de reconocer al llegar a la direccion.',
        ],
        'Puerta (casa 3)' => [
            'contenido' => 'La entrada de la Casa 3 esta en el pasaje: es la segunda puerta, de color verde, justo al pie de las gradas.',
        ],
        'Puerta (casa 4)' => [
            'contenido' => 'La puerta de la Casa 4 esta en el pasaje: es la primera puerta verde a la izquierda, muy cerca de la caja fuerte donde se recogen las llaves.',
        ],
        'Puerta (casa 5)' => [
            'contenido' => 'Sube las gradas del final del pasaje. Al terminarlas, de frente esta la puerta del departamento, de color verde.',
        ],
        'Puerta (casa 6)' => [
            'contenido' => 'Sube las gradas del final del pasaje, gira a la izquierda, luego a la derecha, y de nuevo a la izquierda para subir otras gradas. El departamento esta en la puerta verde de la derecha.

Despues de la puerta verde se entra a un recibidor. La puerta del departamento es la roja, a la izquierda. Pidele que deje ambas puertas cerradas al pasar.',
        ],
        'Puerta (casa 7)' => [
            'contenido' => 'Sube las gradas del final del pasaje y gira a la derecha. El departamento es la primera puerta del lado derecho, de color rojo.',
        ],
    ];

    public function getDescription(): string
    {
        return 'Aplica el texto revisado de agente_contenido en los 19 ítems reescritos a mano.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ITEMS as $nombre => $campos) {
            $this->addSql(
                'UPDATE pms_guia_item SET agente_contenido = :contenido WHERE nombre_interno = :nombre',
                ['contenido' => $campos['contenido'], 'nombre' => $nombre]
            );

            if (isset($campos['terminos'])) {
                $this->addSql(
                    'UPDATE pms_guia_item SET agente_terminos = :terminos WHERE nombre_interno = :nombre',
                    ['terminos' => $campos['terminos'], 'nombre' => $nombre]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Volver a `NULL` es devolverlos al cuerpo publicado, que es el comportamiento por
        // defecto y nunca es incorrecto. Reconstruir lo que sembró la migración anterior
        // exigiría repetir aquí su transformación de HTML, y esa copia envejece sola.
        $this->addSql(
            'UPDATE pms_guia_item SET agente_contenido = NULL WHERE nombre_interno IN (:nombres)',
            ['nombres' => array_keys(self::ITEMS)],
            ['nombres' => ArrayParameterType::STRING]
        );
    }
}
