<?php

declare(strict_types=1);

namespace App\Service\Translate;

/**
 * Lo que el traductor dice mal, y cómo debe decirlo. Se aplica a **toda traducción nueva**, justo
 * al salir de Google, y a lo ya guardado con `app:traduccion:corregir`.
 *
 * ── Por qué aquí y no en cada comando de carga ──────────────────────────────
 * El traductor no conoce el sitio: «al pie de las gradas» le salió «at the foot of the stands» en
 * los seis idiomas, «al terminarlas, la tienes de frente» un «verás el resultado» y, en italiano,
 * «gira a la derecha» un «haz la llamada». El 14/10/2026 esas correcciones vivían en el comando de
 * las puertas, y eso tenía un agujero: en cuanto alguien tocaba el español de la ficha en
 * EasyAdmin, el `origenHash` dejaba de cuadrar, la ficha se retraducía y el error volvía sin que
 * nadie lo viera. Aquí, una traducción nueva sale ya corregida.
 *
 * ── El español no se toca ───────────────────────────────────────────────────
 * Es de Jorge, y se escribe para el huésped hispanohablante, no para complacer al traductor.
 *
 * ── Reglas de una corrección ────────────────────────────────────────────────
 * - **Frase concreta, no palabra suelta.** Se aplica a TODO lo traducible del proyecto —guía,
 *   catálogo, cotizaciones…—, así que «cadenas → serrures» sería un desastre en otra ficha; «Vous
 *   verrez deux cadenas» no casa donde no toca.
 * - **Corta, de una oración como mucho.** Google varía detalles entre una traducción y la
 *   siguiente —al retraducir una puerta, se comió un «solo» en italiano— y un párrafo entero deja
 *   de casar a la primera.
 * - **El reemplazo NO puede contener la búsqueda**, o cada pasada la volvería a aplicar.
 * - Se aplican en orden: una puede contar con lo que dejó la anterior.
 *
 * ⚠️ El francés lleva **espacio duro** (U+00A0) antes de «:», «;», «?» y «!»: esas búsquedas van
 * entre comillas dobles con `\u{a0}`.
 *
 * ── Registro ────────────────────────────────────────────────────────────────
 * El español tutea. El italiano y el neerlandés van de «tú» (Jorge, 14/10/2026): el traductor
 * mezclaba «voi»/«u» con «tu»/«je» dentro de la misma ficha. El alemán y el francés se quedan de
 * «Sie»/«vous», que es lo normal con un huésped y como ya estaba toda la guía.
 *
 * Una corrección que ya no encuentra su frase —porque Google cambió la traducción— no hace nada,
 * y `app:traduccion:corregir` la lista como huérfana: hay que mirarla otra vez.
 *
 * ── Los rótulos cortos van fijos, por su español ────────────────────────────
 * Una palabra suelta no tiene contexto y Google adivina: «Estar» (la sala de estar) salió «To be»
 * y «Ser», «Sala 2» salió «Quarto 2», «Caja fuerte» salió «Seguro» (la póliza). Corregir lo que
 * dice Google no sirve aquí —cambia de una vez a otra—, así que {@see self::FIJAS} va por el
 * ESPAÑOL: si el texto entero es uno de ésos, la traducción es la de la tabla y a Google ni se le
 * pregunta. Un número final se respeta: «Estar 2» es «Estar» + « 2».
 */
final class CorreccionesDeTraduccion
{
    /** @var array<string, array<string, string>> idioma → [frase de Google → frase corregida] */
    private const array CORRECCIONES = [
        'en' => [
            // Puertas (casas 1-7)
            'the second gate, right at the foot of the stands.'
                => 'the second door, right at the foot of the steps.',
            'When you finish them, you have it right in front of you.'
                => 'At the top of the steps, your door is right in front of you.',
            'the first one on the right, in red.'
                => 'the first one on the right, the red one.',
            // Llaves (general)
            'Enter the passageway 3 meters.'
                => 'Walk 3 meters into the passageway.',
            'Take the <span>{{ numero }}</span> key from inside.'
                => 'Take key <span>{{ numero }}</span> from inside.',
            'turn the knob to the right to open the box.'
                => 'turn the knob to the right to open the safe.',
            'Close the box by turning the knob'
                => 'Close the safe by turning the knob',
            'Below you can see the video of the safe opening.'
                => 'Below is a video showing how to open the safe.',
            'The digital safe password is changed regularly'
                => 'The digital safe code is changed regularly',
            // Limpieza (general)
            'comes <strong>every morning</strong>'
                => 'comes <strong>every night, before dawn</strong>',
            // Reglas (general)
            'and additional guests must be paid.'
                => 'and there is a charge for each additional guest.',
            'Do not wash clothes with the tap water from the apartment.'
                => 'Please don\'t do laundry in the apartment.',
            'In <strong>Services</strong> you have nearby laundromats and our washing service.'
                => 'Under <strong>Services</strong> you\'ll find nearby laundromats and our laundry service.',
            'If you need ironing, ask us for the ironing board: you can find it in <strong>Services</strong> .'
                => 'If you need to iron, ask us for the ironing board — see <strong>Services</strong>.',
            // Descripción (casas)
            'Beautiful <strong>, private, independent</strong> apartment'
                => 'Beautiful <strong>private, self-contained</strong> apartment',
            '<h3> Distribution'
                => '<h3> Layout',
            'Room heaters (stoves):'
                => 'Space heaters:',
            'in the social area:'
                => 'in the common area:',
            '<strong>To be.</strong>'
                => '<strong>Sitting area.</strong>',
            'Upper floor — rest'
                => 'Upper floor — bedrooms',
            'Ground floor — social area'
                => 'Ground floor — living area',
            '<strong>Full bathroom</strong> in the social area.'
                => '<strong>Full bathroom</strong> in the common area.',
            'the other in the social area'
                => 'the other in the common area',
            // Pago (general)
            'after verification.</p>'
                => 'after we check:</p>',
            'The correct use and care of the department.'
                => 'Proper use and care of the apartment.',
            'The absence of outstanding damages, consumption, or penalties.'
                => 'That there is no damage and no outstanding charges or penalties.',
            // Perdida llaves (general)
            'report it immediately to change the lock pins for security.'
                => 'let us know immediately so we can rekey the lock for security.',
            // Early check in Late Check Out
            '{{ data sheet: luggage-flexible-hours }}'
                => '{{ ficha: equipaje-horarios-flexibles }}',
            // Traslados (general)
            'We coordinate <strong>with them</strong> by sending them your flight details.'
                => 'You arrange it <strong>with them</strong> directly by sending them your flight details.',
            // Estacionamiento (general)
            'the free beach <strong>is not a supervised area</strong>'
                => 'the free parking lot <strong>is not a supervised area</strong>',
            'the guarded garage upstairs: on the free beach opposite it would be too exposed.'
                => 'the guarded garage mentioned above: in the free lot across the street it would be too exposed.',
            // Facturación (general)
            'You will receive it by mail.'
                => 'You will receive it by email.',
            // Cancelación (general)
            'it&#39;s not because it&#39;s occupied.'
                => 'that doesn&#39;t mean it&#39;s booked.',
            // Lavandería (general)
            'We ask that you do not wash clothes with the tap water in the apartment.'
                => 'Please don&#39;t do laundry in the apartment.',
            // Toallas y ropa de cama (general)
            'before that deadline'
                => 'sooner than that',
            // Plancha y secadora (general)
            'If you need ironing, <strong>'
                => 'If you need to iron, <strong>',
            // Puertas (casas 1-7)
            'Saphi Street 877-A and B'
                => 'Calle Saphi 877-A and B',
            '644 Saphy Street'
                => 'Calle Saphy 644',
        ],
        'pt' => [
            // Puertas (casas 1-7)
            'É o segundo portão, bem no pé das arquibancadas.'
                => 'É a segunda porta, bem ao pé da escada.',
            'Quando você terminar, terá o resultado bem na sua frente.'
                => 'No alto da escada, ela fica bem à sua frente.',
            'a primeira à direita, em vermelho.'
                => 'a primeira à direita, a vermelha.',
            '(a seta aponta para ele)'
                => '(a seta aponta para ela)',
            // Llaves (general)
            'Se encontrar a porta trancada,'
                => 'Se encontrar o portão fechado,',
            'a chave está no de baixo (abaixo).'
                => 'a chave está no de baixo.',
            'para a direita para abrir a caixa.'
                => 'para a direita para abrir o cofre.',
            'Feche a caixa girando o botão para a esquerda e certifique-se de que esteja bem fechada.'
                => 'Feche o cofre girando o botão para a esquerda e certifique-se de que esteja bem fechado.',
            // Wifi (general)
            'Escolha aquele com o'
                => 'Escolha a rede com o',
            // Limpieza (general)
            '<strong>todas as manhãs</strong>'
                => '<strong>todas as madrugadas</strong>',
            '<strong>Gestão de resíduos</strong>'
                => '<strong>Coleta de lixo</strong>',
            // Horario solicitudes (general)
            'Escreva-nos no WhatsApp para {{ whatsapp_numero }}'
                => 'Escreva-nos pelo WhatsApp no número {{ whatsapp_numero }}',
            // Reglas (general)
            'você pode encontrá-la na <strong>área de Serviços</strong>'
                => 'você faz o pedido na seção <strong>Serviços</strong> deste guia',
            'Os cilindros de gás duram'
                => 'Os botijões de gás duram',
            // Descripción (casas)
            'até 8 convidados'
                => 'até 8 hóspedes',
            'Aquecedores de ambiente (fogões):'
                => 'Aquecedores de ambiente:',
            'oferecemos troca de roupa de cama gratuita a cada'
                => 'oferecemos troca gratuita de toalhas e roupa de cama a cada',
            'até 4 convidados'
                => 'até 4 hóspedes',
            'até 2 convidados'
                => 'até 2 hóspedes',
            'até 7 convidados'
                => 'até 7 hóspedes',
            'até 10 convidados'
                => 'até 10 hóspedes',
            '<strong>Ser.</strong>'
                => '<strong>Sala de estar.</strong>',
            // Calefactor (general)
            '☀️ <strong>Horário:</strong>'
                => '☀️ <strong>Dia:</strong>',
            // Pago (general)
            'após verificação.</p>'
                => 'após a verificação de:</p>',
            'Devolvendo as chaves.'
                => 'A devolução das chaves.',
            'O uso e cuidado corretos do departamento.'
                => 'O uso e o cuidado adequados do apartamento.',
            // Registro de huéspedes (general)
            'lista de inscrição'
                => 'lista de cadastro',
            // Perdida llaves (general)
            'para que os pinos de segurança sejam trocados.'
                => 'para que possamos trocar o miolo da fechadura por segurança.',
            // Early check in Late Check Out
            '{{ data sheet: luggage-flexible-hours }}'
                => '{{ ficha: equipaje-horarios-flexibles }}',
            'Partida: até'
                => 'Saída: até',
            // Traslados (general)
            'sem escadas ou trechos para pedestres'
                => 'sem escadas nem trechos a pé',
            'Nós coordenamos <strong>com eles</strong> enviando os detalhes do seu voo.'
                => 'Isso deve ser combinado <strong>com eles</strong>, enviando a eles os dados do seu voo.',
            // Estacionamiento (general)
            'fica <strong>a Saphy&#39;s Garage</strong>'
                => 'fica o <strong>Garaje de Saphy</strong>',
            'caso prefira deixá-la em local fechado'
                => 'caso prefira deixar o veículo em local coberto',
            'Atenção: a praia gratuita <strong>não possui vigilância</strong>'
                => 'Atenção: o estacionamento gratuito <strong>não é vigiado</strong>',
            'Recomendamos deixá-la na <strong>Saphy&#39;s Garage</strong> , a garagem vigiada no andar de cima: na praia gratuita em frente, ela ficaria muito exposta.'
                => 'Recomendamos deixá-la no <strong>Garaje de Saphy</strong>, a garagem vigiada mencionada acima: no estacionamento gratuito em frente, ela ficaria muito exposta.',
            // Facturación (general)
            'Para emitir <strong>o documento, precisamos de uma foto</strong> e de saber <strong>em nome de quem</strong> ele está registrado.'
                => 'Para emiti-lo, precisamos de uma <strong>foto do seu documento de identidade</strong> e de saber <strong>em nome de quem</strong> deve ser emitido.',
            'Você o receberá pelo correio.'
                => 'Você o receberá por e-mail.',
            // Televisor (casas)
            'Ligue-o e os aplicativos'
                => 'Ligue-a e os aplicativos',
            // Lavandería (general)
            'lavanderias automáticas baratas'
                => 'lavanderias baratas',
            // Toallas y ropa de cama (general)
            '<strong>troca de roupa de cama gratuita a cada 4 dias</strong>'
                => '<strong>troca gratuita de toalhas e roupa de cama a cada 4 dias</strong>',
            // Equipaje y horarios flexibles (general)
            'A disponibilidade é obrigatória e haverá um custo adicional.'
                => 'Está sujeito à disponibilidade e tem um custo adicional.',
            'Também <strong>para reservas com vários dias de antecedência</strong>'
                => 'Também guardamos por <strong>vários dias</strong>',
        ],
        'fr' => [
            // Puertas (casas 1-7)
            'Il possède une seule serrure'
                => 'Elle a une seule serrure',
            'Vous verrez deux cadenas.'
                => 'Vous verrez deux serrures.',
            'Utilisez celui du bas'
                => 'Utilisez celle du bas',
            "laissé celui du haut ouvert\u{a0}:"
                => "laissé celle du haut ouverte\u{a0}:",
            'que de celui du bas'
                => 'que de celle du bas',
            'des clés des deux cadenas'
                => 'des clés des deux serrures',
            'également celui du haut'
                => 'également celle du haut',
            'tout au pied des tribunes.'
                => 'juste au pied des marches.',
            'Une fois que vous les aurez terminés, vous l&#39;aurez juste devant vous.'
                => 'En haut des marches, elle est juste devant vous.',
            'Tourner à gauche.'
                => 'Tournez à gauche.',
            'la première à droite, en rouge.'
                => 'la première à droite, la rouge.',
            '(la flèche le désigne)'
                => '(la flèche la désigne)',
            // Llaves (general)
            'Si vous trouvez la porte verrouillée,'
                => 'Si vous le trouvez fermé,',
            'Entrez dans le passage de 3 mètres.'
                => 'Avancez de 3 mètres dans le passage.',
            // Limpieza (general)
            'passe <strong>tous les matins</strong>'
                => 'passe <strong>chaque nuit, avant l&#39;aube</strong>',
            // Reglas (general)
            'les invités supplémentaires doivent être payés.'
                => 'toute personne supplémentaire est payante.',
            // Calefactor (general)
            '<strong>Opération :</strong>'
                => '<strong>Fonctionnement :</strong>',
            'Elles sont activées <strong>à distance</strong>'
                => 'Ils s&#39;activent <strong>à distance</strong>',
            '(nous ne pouvons pas les apporter sur place)'
                => '(nous ne pouvons pas les apporter à la dernière minute)',
            // Ducha (casas)
            'Ouvrez-le <strong>complètement</strong>'
                => 'Ouvrez <strong>complètement</strong>',
            'ouvrez-le <strong>encore plus</strong>'
                => 'ouvrez <strong>encore plus</strong>',
            'ouvrez-le encore plus'
                => 'ouvrez encore plus',
            // Pago (general)
            'après vérification.</p>'
                => 'après vérification de :</p>',
            'Rendre les clés.'
                => 'La restitution des clés.',
            'Utilisation et entretien corrects du département.'
                => 'Le bon usage et l&#39;entretien de l&#39;appartement.',
            // Registro de huéspedes (general)
            'la liste d&#39;inscription'
                => 'la liste d&#39;enregistrement',
            // Perdida llaves (general)
            'changer les goupilles de verrouillage'
                => 'changer le barillet de la serrure',
            // Descripción (casas)
            'depuis <strong>{{ hora_checkin }}</strong>'
                => 'à partir de <strong>{{ hora_checkin }}</strong>',
            'jusqu&#39;au <strong>{{ hora_checkout }}</strong>'
                => 'jusqu&#39;à <strong>{{ hora_checkout }}</strong>',
            'Chauffages d&#39;appoint (poêles) :'
                => 'Chauffages d&#39;appoint électriques :',
            '<strong>Être.</strong>'
                => '<strong>Coin salon.</strong>',
            'à induction à un brûleur'
                => 'à induction à un feu',
            // Cocina (casa 5)
            'plaque à induction à un brûleur'
                => 'plaque à induction à un feu',
            // Ubicación (general)
            'Rue Saphi 877-A et B'
                => 'Calle Saphi 877-A et B',
            'Adresse:</strong>'
                => 'Adresse :</strong>',
            // Early check in Late Check Out
            '{{ fiche technique : bagages-horaires-flexibles }}'
                => '{{ ficha: equipaje-horarios-flexibles }}',
            'Entrée : de {{ hora_checkin }}'
                => 'Arrivée : à partir de {{ hora_checkin }}',
            // Traslados (general)
            'Nous nous chargeons de communiquer vos informations de vol <strong>avec eux</strong> .'
                => 'Cela s&#39;organise <strong>directement avec eux</strong>, en leur envoyant vos informations de vol.',
            'sans escaliers ni zones piétonnes'
                => 'sans escaliers ni trajet à pied',
            // Estacionamiento (general)
            'Veuillez noter que la plage gratuite'
                => 'Veuillez noter que le parking gratuit',
            'le garage surveillé à l&#39;étage : sur la plage gratuite en face, elle serait'
                => 'le garage surveillé mentionné plus haut : sur le parking gratuit d&#39;en face, elle serait',
            '(644 rue Saphy,'
                => '(Calle Saphy 644,',
            // Facturación (general)
            'Vous le recevrez par courrier.'
                => 'Vous le recevrez par e-mail.',
            'de connaître <strong>le nom</strong> qui y figure'
                => 'de savoir <strong>à quel nom</strong> l&#39;établir',
            // Televisor (casas)
            'Activez-le et'
                => 'Allumez-le et',
            // Toallas y ropa de cama (general)
            'frais de manutention'
                => 'frais de service',
            // Equipaje y horarios flexibles (general)
            'quitté l&#39;hôtel'
                => 'libéré l&#39;appartement',
            'Nous stockons vos bagages'
                => 'Nous gardons vos bagages',
            'garder une trace de votre passage'
                => 'garder une trace de vos bagages',
            // Instrucciones de salida (general)
            'sur le quai devant la maison'
                => 'sur la plateforme devant la maison',
        ],
        'it' => [
            // Puertas (casas 1-7)
            'Come aprirlo:'
                => 'Come aprirla:',
            'girare la chiave'
                => 'gira la chiave',
            'Vedrete due serrature. Usate quella inferiore'
                => 'Vedrai due serrature. Usa quella inferiore',
            'Abbiamo lasciato aperta quella superiore per voi: al vostro arrivo, vi basterà'
                => 'Quella superiore te la lasciamo aperta: al tuo arrivo ti basterà',
            'Avete le chiavi di entrambe le serrature nel caso preferiate chiudere anche quella superiore durante il vostro soggiorno.'
                => 'Hai le chiavi di entrambe le serrature, nel caso preferissi chiudere anche quella superiore durante il tuo soggiorno.',
            'Tirate delicatamente la porta verso di voi mentre girate la chiave'
                => 'tira delicatamente la porta verso di te mentre giri la chiave',
            'All&#39;arrivo troverete due porte. Solo quella esterna è chiusa a chiave: girate la chiave in senso orario.'
                => 'All&#39;arrivo troverai due porte. Solo quella esterna è chiusa a chiave: gira la chiave in senso orario.',
            'Lasciamo la porta interna aperta in modo che possiate entrare direttamente; se preferite chiuderla a chiave durante il vostro soggiorno, le chiavi che avete con voi funzioneranno comunque.'
                => 'La porta interna te la lasciamo aperta perché tu possa entrare direttamente; se preferisci chiuderla a chiave durante il tuo soggiorno, le chiavi che hai funzionano anche per quella.',
            'Salite le scale'
                => 'Sali le scale',
            'Girate di nuovo a sinistra e salite le scale.'
                => 'Gira di nuovo a sinistra e sali le scale.',
            'Si prega di tenere chiuse le porte verde e rossa.'
                => 'Per favore, tieni chiuse la porta verde e quella rossa.',
            'È il secondo ingresso, proprio ai piedi delle tribune.'
                => 'È la seconda porta, proprio ai piedi della scalinata.',
            'le scale in fondo al corridoio'
                => 'le scale in fondo al passaggio',
            'Quando li avrai finiti, li avrai proprio davanti a te.'
                => 'In cima alle scale, la troverai proprio di fronte.',
            'Girare a '
                => 'Gira a ',
            'Poi, fai la chiamata.'
                => 'Poi gira a destra.',
            'la prima a destra, in rosso.'
                => 'la prima a destra, quella rossa.',
            '(la freccia lo indica)'
                => '(la freccia la indica)',
            // Llaves (general)
            'Se lo trovate chiuso a chiave, è sufficiente tirare'
                => 'Se lo trovi chiuso, basta tirare',
            'Entrate nel passaggio per 3 metri.'
                => 'Entra nel passaggio e avanza per 3 metri.',
            'troverete due casseforti digitali'
                => 'troverai due casseforti digitali',
            'per aprire la scatola'
                => 'per aprire la cassaforte',
            'Chiudere la scatola ruotando la manopola verso sinistra e assicurarsi che sia ben chiusa.'
                => 'Chiudi la cassaforte ruotando la manopola verso sinistra e assicurati che sia ben chiusa.',
            'dell&#39;appartamento troverete un'
                => 'dell&#39;appartamento troverai un',
            'Qui sotto potete vedere il video'
                => 'Qui sotto puoi vedere il video',
            'Si consiglia di restituire la chiave della cassaforte per utilizzarla in caso di emergenza'
                => 'Ti consigliamo di rimettere la chiave nella cassaforte, così potrai usarla in caso di emergenza',
            'La password della cassaforte digitale viene modificata regolarmente, ma è sempre aggiornata'
                => 'Il codice della cassaforte digitale viene cambiato regolarmente, ma è sempre aggiornato',
            // Despues de ingresar (general)
            'Appena sarete entrati nell&#39;appartamento 🏠, vi preghiamo di inviarci un breve messaggio per confermare il vostro arrivo.'
                => 'Appena entri nell&#39;appartamento 🏠, per favore inviaci un breve messaggio per confermare il tuo arrivo.',
            'Vi preghiamo gentilmente di effettuare'
                => 'Ti chiediamo gentilmente di effettuare',
            // Wifi (general)
            'Scegli quello con il'
                => 'Scegli quella con il',
            // Limpieza (general)
            'Si prega di notare che la pulizia'
                => 'Tieni presente che la pulizia',
            'Se durante il vostro soggiorno aveste bisogno di pulizie extra o di un cambio di asciugamani e lenzuola, non esitate a contattarci e saremo lieti di aiutarvi a organizzarlo'
                => 'Se durante il tuo soggiorno hai bisogno di pulizie extra o di un cambio di asciugamani e lenzuola, non esitare a contattarci: saremo lieti di aiutarti a organizzarlo',
            '<strong>tutte le mattine</strong>'
                => '<strong>ogni notte, prima dell&#39;alba</strong>',
            'Si consiglia di esporre i rifiuti'
                => 'Ti consigliamo di portare fuori i rifiuti',
            'troverete una piattaforma'
                => 'troverai una piattaforma',
            // Horario solicitudes (general)
            'Se avete bisogno di carta igienica, sapone liquido o altri prodotti, vi preghiamo di comunicarcelo'
                => 'Se hai bisogno di carta igienica, sapone liquido o altri prodotti, avvisaci',
            // Reglas (general)
            'si prega di sorvegliare i bambini'
                => 'per favore sorveglia i bambini',
            'troverete lavanderie a gettoni nelle vicinanze'
                => 'trovi le lavanderie vicine',
            'Se avete bisogno di stirare, chiedeteci l&#39;asse da stiro: la trovate'
                => 'Se hai bisogno di stirare, chiedici l&#39;asse da stiro: la trovi',
            'Segnalateci eventuali'
                => 'Segnalaci eventuali',
            'Se al momento del check-in riscontrate danni, vi preghiamo di informarci immediatamente.'
                => 'Se al momento del check-in noti dei danni, informaci immediatamente.',
            'Si prega di utilizzarli responsabilmente quando si cucina o si fa la doccia.'
                => 'Usale in modo responsabile quando cucini o fai la doccia.',
            'Prima di partire, controlla:'
                => 'Prima di uscire, controlla:',
            'Assicurarsi che i riscaldatori'
                => 'Assicurati che i riscaldatori',
            'Particolarmente importante se si viaggia con bambini.'
                => 'Particolarmente importante se viaggi con bambini.',
            // Descripción (casas)
            '<strong>Check-in:</strong> da <strong>'
                => '<strong>Check-in:</strong> dalle <strong>',
            '<strong>Check-out:</strong> entro <strong>'
                => '<strong>Check-out:</strong> entro le <strong>',
            '<h3> servizi e dettagli</h3>'
                => '<h3> Servizi e dettagli</h3>',
            '<strong>Essere.</strong>'
                => '<strong>Salottino.</strong>',
            'Piano superiore - riposo'
                => 'Piano superiore - zona notte',
            // Calefactor (general)
            '<strong>Operazione:</strong>'
                => '<strong>Funzionamento:</strong>',
            // Ducha (casas)
            'Aprilo <strong>completamente</strong> e attendi qualche secondo. Se l&#39;acqua che esce è troppo calda, aprilo <strong>ancora di più</strong>'
                => 'Aprila <strong>completamente</strong> e attendi qualche secondo. Se l&#39;acqua che esce è troppo calda, aprila <strong>ancora di più</strong>',
            'Se necessario, utilizzare il rubinetto dell&#39;acqua fredda per regolare la temperatura.'
                => 'Se necessario, regola la temperatura con il rubinetto dell&#39;acqua fredda.',
            'tirarla a sinistra'
                => 'spostala a sinistra',
            'Aprilo <strong>completamente</strong> e attendi qualche secondo. Se esce troppo caldo, aprilo ancora di più: più acqua passa attraverso, meno caldo sarà.'
                => 'Apri <strong>completamente</strong> e attendi qualche secondo. Se l&#39;acqua esce troppo calda, apri ancora di più: più acqua passa, meno calda esce.',
            // Pago (general)
            'L&#39;uso e la cura corretti del reparto.'
                => 'L&#39;uso e la cura corretti dell&#39;appartamento.',
            'previa verifica.'
                => 'previa verifica di:',
            // Registro de huéspedes (general)
            'è necessario inviare una foto'
                => 'devi inviarci una foto',
            'Se si aggiungono ospiti extra, sarà necessario pagare la'
                => 'Se aggiungi ospiti extra, dovrai pagare la',
            // Perdida llaves (general)
            'segnalarlo immediatamente per consentire la sostituzione dei perni di sicurezza.'
                => 'segnalacelo immediatamente: per sicurezza dovremo cambiare il cilindro della serratura.',
            // Ubicación (general)
            '{{ mappa:'
                => '{{ map:',
            'Via Saphi 877-A e B'
                => 'Calle Saphi 877-A e B',
            // Early check in Late Check Out
            'Voce: da {{ hora_checkin }}'
                => 'Ingresso: dalle {{ hora_checkin }}',
            'Partenza: fino a {{ hora_checkout }}'
                => 'Partenza: entro le {{ hora_checkout }}',
            'Arrivate in anticipo, partite più tardi o dovete depositare i bagagli?'
                => 'Arrivi in anticipo, parti più tardi o devi lasciare i bagagli?',
            '{{ scheda dati: orari flessibili per i bagagli }}'
                => '{{ ficha: equipaje-horarios-flexibles }}',
            // Traslados (general)
            'Il veicolo vi lascia davanti'
                => 'Il veicolo ti lascia davanti',
            'Ci coordiniamo <strong>con loro</strong> inviando loro i dettagli del tuo volo.'
                => 'Va coordinato direttamente <strong>con loro</strong>, inviando i dati del tuo volo.',
            // Estacionamiento (general)
            '<strong>il Saphy&#39;s Garage</strong> (644 Saphy Street'
                => '<strong>il Garaje de Saphy</strong> (Calle Saphy 644',
            'se preferite lasciare l&#39;auto al chiuso'
                => 'se preferisci lasciare l&#39;auto al coperto',
            'Si prega di notare che la spiaggia libera'
                => 'Tieni presente che il parcheggio gratuito',
            '<strong>Arrivate in moto?</strong> Vi consigliamo di lasciarla al <strong>Saphy&#39;s Garage</strong> , il garage custodito al piano superiore: sulla spiaggia libera di fronte sarebbe troppo esposta.'
                => '<strong>Arrivi in moto?</strong> Ti consigliamo di lasciarla al <strong>Garaje de Saphy</strong> , il garage custodito indicato sopra: nel parcheggio gratuito di fronte sarebbe troppo esposta.',
            // Facturación (general)
            'Per emetterlo, abbiamo bisogno di una <strong>foto del documento</strong>'
                => 'Per emetterla, abbiamo bisogno di una <strong>foto del tuo documento</strong>',
            'Lo riceverai per posta.'
                => 'La riceverai via e-mail.',
            // Lavandería (general)
            'lavanderie a gettoni economiche'
                => 'lavanderie economiche',
            'E se preferite non uscire'
                => 'E se preferisci non uscire',
            'Fatecelo sapere tramite chat'
                => 'Faccelo sapere tramite chat',
            'Vi preghiamo di non lavare'
                => 'Ti chiediamo di non lavare',
            // Plancha y secadora (general)
            'Se avete bisogno di un asciugacapelli'
                => 'Se hai bisogno di un asciugacapelli',
            // Equipaje y horarios flexibles (general)
            'Se desiderate effettuare il check-in prima delle'
                => 'Se desideri effettuare il check-in prima delle',
            'vi preghiamo di comunicarlo con un giorno di anticipo. La disponibilità è soggetta a variazioni e comporta un costo aggiuntivo.'
                => 'comunicacelo con un giorno di anticipo. È soggetto a disponibilità e ha un costo aggiuntivo.',
            'Conserviamo i vostri bagagli'
                => 'Conserviamo i tuoi bagagli',
            'Sia che arriviate prima dell&#39;orario di check-in, sia che abbiate già effettuato il check-out e siate ancora in città.'
                => 'Sia che arrivi prima dell&#39;orario di check-in, sia che tu abbia già effettuato il check-out e sia ancora in città.',
            'Salkantay: vi preghiamo di comunicarcelo'
                => 'Salkantay: comunicacelo',
            'Quando ci consegnate i vostri bagagli, vi preghiamo di comunicarci'
                => 'Quando ci consegni i tuoi bagagli, comunicaci',
            'l&#39;orario in cui li ritirerete'
                => 'l&#39;orario in cui li ritirerai',
            'Se tornate mentre il personale delle pulizie è al lavoro, ve li consegneranno loro; se tornate più tardi, ci coordineremo tramite chat e vi faremo sapere dove si trovano.'
                => 'Se torni mentre il personale delle pulizie è al lavoro, te li consegneranno loro; se torni più tardi, ci coordineremo tramite chat e ti faremo sapere dove si trovano.',
            'che potrete aprire autonomamente con il codice che vi forniremo'
                => 'che potrai aprire autonomamente con il codice che ti forniremo',
            '<strong>arrivate prima dell&#39;orario di check-in</strong> , vi preghiamo di comunicarci'
                => '<strong>arrivi prima dell&#39;orario di check-in</strong> , comunicaci',
            'non appena lo sapete, e di scriverci anche al vostro arrivo'
                => 'non appena lo sai, e scrivici anche al tuo arrivo',
            'il personale ritirerà i vostri bagagli'
                => 'il personale ritirerà i tuoi bagagli',
            'potete lasciarli nel deposito bagagli e vi invieremo il codice'
                => 'puoi lasciarli nel deposito bagagli e ti invieremo il codice',
            // Instrucciones de salida (general)
            'Chiudete tutte le finestre e le porte.'
                => 'Chiudi tutte le finestre e le porte.',
            'Spegnete le luci e scollegate gli elettrodomestici'
                => 'Spegni le luci e scollega gli elettrodomestici',
            'Se avete bisogno che custodiamo i vostri bagagli dopo il check-out, fatecelo sapere'
                => 'Se hai bisogno che custodiamo i tuoi bagagli dopo il check-out, faccelo sapere',
        ],
        'de' => [
            // Puertas (casas 1-7)
            'So öffnen Sie es:</strong> Es hat ein einzelnes Schloss'
                => 'So öffnen Sie die Tür:</strong> Sie hat ein einzelnes Schloss',
            'nicht in der Passage.'
                => 'nicht im Durchgang.',
            'Stufen am Ende des Ganges'
                => 'Stufen am Ende des Durchgangs',
            'Es ist das zweite Tor, direkt am Fuße der Tribüne.'
                => 'Es ist die zweite Tür, direkt am Fuß der Treppe.',
            'Wenn du sie fertig hast, hast du es direkt vor dir.'
                => 'Oben angekommen, befindet sie sich direkt vor Ihnen.',
            'die erste rechts, rot.'
                => 'die erste rechts, die rote.',
            // Llaves (general)
            'Sollte die Tür verschlossen sein, ziehen Sie einfach den Riegel von außen heraus.'
                => 'Sollte das Tor geschlossen sein, ziehen Sie einfach von außen am Riegel.',
            'der Schlüssel befindet sich im unteren (unten).'
                => 'der Schlüssel befindet sich im unteren.',
            'um die Box zu öffnen'
                => 'um den Safe zu öffnen',
            'Nehmen Sie den <span>{{ numero }}-</span> Schlüssel von innen heraus.'
                => 'Nehmen Sie den Schlüssel Nr. <span>{{ numero }}</span> heraus.',
            'Schließen Sie die Box, indem Sie den Knopf nach links drehen, und vergewissern Sie sich, dass sie richtig geschlossen ist.'
                => 'Schließen Sie den Safe, indem Sie den Knopf nach links drehen, und vergewissern Sie sich, dass er richtig geschlossen ist.',
            'das Video von der Tresoröffnung'
                => 'das Video zum Öffnen des Safes',
            'Das Passwort für den digitalen Tresor'
                => 'Der Code für den digitalen Safe',
            // Wifi (general)
            'Wählen Sie denjenigen mit'
                => 'Wählen Sie dasjenige mit',
            // Limpieza (general)
            '<strong>jeden Morgen</strong>'
                => '<strong>jeden Tag in den frühen Morgenstunden</strong>',
            'befindet sich ein dafür vorgesehener Bahnsteig.'
                => 'befindet sich eine dafür vorgesehene Plattform.',
            // Reglas (general)
            'Bitte essen Sie nicht auf den Betten 🍗🍕 und tragen Sie kein Make-up 💄 auf.'
                => 'Bitte essen Sie nicht 🍗🍕 und schminken Sie sich nicht 💄 auf den Betten.',
            'Falls Sie Bügeln benötigen, fragen Sie uns nach dem Bügelbrett: Sie finden es unter <strong>Service</strong>'
                => 'Falls Sie bügeln müssen, fragen Sie uns nach dem Bügelbrett – mehr dazu unter <strong>„Services“</strong>',
            // Descripción (casas)
            '<strong>Kasse:</strong>'
                => '<strong>Check-out:</strong>',
            '<strong>Einchecken:</strong> von'
                => '<strong>Check-in:</strong> ab',
            'Wunderschöne <strong>, private und separate</strong>'
                => 'Wunderschöne <strong>separate, private</strong>',
            'in ruhiger, breiter Lage'
                => 'an einer breiten, ruhigen Straße',
            '<h3> Verteilung'
                => '<h3> Aufteilung',
            '<strong>Komplettes Badezimmer</strong> im Gemeinschaftsbereich'
                => '<strong>Komplettes Badezimmer</strong> im Wohnbereich',
            'Erdgeschoss – Gemeinschaftsbereich'
                => 'Erdgeschoss – Wohnbereich',
            'Obergeschoss – Rest'
                => 'Obergeschoss – Schlafbereich',
            '<strong>Sein.</strong>'
                => '<strong>Sitzecke.</strong>',
            // Calefactor (general)
            '<strong>Operation:</strong>'
                => '<strong>Funktionsweise:</strong>',
            // Ducha (casas)
            'kühlt das Wasser umso weniger ab, je mehr Wasser durchfließt.'
                => 'wird das Wasser umso weniger heiß, je mehr Wasser durchfließt.',
            'Öffnen Sie den Deckel <strong>vollständig</strong>'
                => 'Öffnen Sie den Wasserhahn <strong>vollständig</strong>',
            'Ziehen Sie ihn nach links.'
                => 'Bewegen Sie ihn nach links.',
            '<strong>Zwei Griffe</strong> im Badezimmer:'
                => 'Badezimmer mit <strong>zwei Griffen</strong>:',
            // Perdida llaves (general)
            'damit die Verriegelungsstifte aus Sicherheitsgründen ausgetauscht werden können'
                => 'damit der Schließzylinder aus Sicherheitsgründen ausgetauscht werden kann',
            // Pago (general)
            'Die korrekte Verwendung und Pflege der Abteilung.'
                => 'Die sorgsame Nutzung und Pflege der Wohnung.',
            // Ubicación (general)
            'Saphi Straße 877-A und B, Cusco'
                => 'Calle Saphi 877-A und B, Cusco',
            // Early check in Late Check Out
            '{{ Datenblatt: Gepäck-flexible-Stunden }}'
                => '{{ ficha: equipaje-horarios-flexibles }}',
            '<strong>Zeitpläne</strong>'
                => '<strong>Uhrzeiten</strong>',
            '🚪 Eingang: von {{ hora_checkin }}'
                => '🚪 Check-in: ab {{ hora_checkin }}',
            '🗝️ Abfahrt: bis {{ hora_checkout }}'
                => '🗝️ Check-out: bis {{ hora_checkout }}',
            // Traslados (general)
            'Wir koordinieren die Abholung <strong>mit Booking.com</strong> , indem wir Ihre Flugdaten übermitteln.'
                => 'Die Abholung stimmen Sie <strong>direkt mit Booking.com</strong> ab, indem Sie dort Ihre Flugdaten angeben.',
            // Estacionamiento (general)
            '(644 Saphy Street,'
                => '(Calle Saphy 644,',
            'der Strandabschnitt <strong>nicht bewacht wird</strong>'
                => 'der kostenlose Parkplatz <strong>nicht bewacht wird</strong>',
            'der bewachten Garage im Obergeschoss'
                => 'der oben genannten bewachten Garage',
            'Am gegenüberliegenden, freien Strand wäre es zu ungeschützt.'
                => 'Auf dem kostenlosen Parkplatz gegenüber wäre es zu ungeschützt.',
            // Facturación (general)
            'Um es auszustellen, benötigen wir'
                => 'Um den Beleg auszustellen, benötigen wir',
            'es ausgestellt ist. Sie erhalten es per Post.'
                => 'er ausgestellt werden soll. Sie erhalten ihn per E-Mail.',
            // Cancelación (general)
            'werden keine verfügbaren Zimmer angezeigt: Das bedeutet nicht, dass das Zimmer belegt ist.'
                => 'wird keine Verfügbarkeit angezeigt: Das bedeutet nicht, dass die Unterkunft belegt ist.',
            // Televisor (casas)
            'mit der Taste <strong>„Quelle</strong> /Eingang“'
                => 'mit der Taste <strong>Quelle</strong> (Source/Input)',
            'Aktivieren Sie es, und'
                => 'Schalten Sie ihn ein, und',
            // Plancha y secadora (general)
            'Falls Sie Bügeln benötigen,'
                => 'Falls Sie bügeln müssen,',
            'und wir bringen es Ihnen.'
                => 'und wir bringen beides vorbei.',
            // Equipaje y horarios flexibles (general)
            'oder wird eingelagert. Diesen können Sie dann mit dem von uns bereitgestellten Code selbst öffnen.'
                => 'oder kommt in den Abstellraum, den Sie mit dem von uns mitgeteilten Code selbst öffnen können – ohne auf jemanden angewiesen zu sein.',
            // Instrucciones de salida (general)
            'Lassen Sie den nummerierten Schlüssel in dem Safe, wo Sie ihn abgeholt haben.'
                => 'Legen Sie den nummerierten Schlüssel wieder in den Safe, aus dem Sie ihn genommen haben.',
        ],
        'nl' => [
            // Puertas (casas 1-7)
            'Hoe open je het:</strong> Het heeft één slot'
                => 'Hoe open je hem:</strong> Er zit één slot op',
            'Het is de tweede ingang, helemaal aan de voet van de tribunes.'
                => 'Het is de tweede deur, direct onderaan de trap.',
            'de trap op aan het einde van de gang'
                => 'de trap op aan het einde van de doorgang',
            'Als je ze af hebt, heb je ze recht voor je liggen.'
                => 'Boven aan de trap zie je hem recht voor je.',
            'de eerste aan de rechterkant, in het rood.'
                => 'de eerste aan de rechterkant, de rode.',
            'dan gaat hij vanzelf open.'
                => 'dan gaat hij open zonder te forceren.',
            'U ziet twee sloten.'
                => 'Je ziet twee sloten.',
            'We hebben het bovenste slot voor u open gelaten: bij aankomst heeft u alleen het onderste slot nodig.'
                => 'Het bovenste laten we voor je open: bij aankomst heb je alleen het onderste nodig.',
            'U heeft sleutels voor beide sloten, mocht u het bovenste slot tijdens uw verblijf ook willen afsluiten.'
                => 'Je hebt sleutels voor beide sloten, mocht je het bovenste tijdens je verblijf ook willen afsluiten.',
            'Bij aankomst vindt u twee deuren.'
                => 'Bij aankomst vind je twee deuren.',
            'De binnendeur is niet op slot, zodat u direct naar binnen kunt. Als u deze liever op slot doet tijdens uw verblijf, kunt u ook de sleutels gebruiken die u heeft.'
                => 'De binnendeur laten we open, zodat je direct naar binnen kunt; doe je die tijdens je verblijf liever op slot, dan passen je sleutels er ook op.',
            // Llaves (general)
            'Als u merkt dat het slot op slot zit, trek dan gewoon aan de grendel vanaf de buitenkant.'
                => 'Als je merkt dat het hek dicht is, trek dan gewoon van buitenaf aan de grendel.',
            'vindt u twee digitale kluizen'
                => 'vind je twee digitale kluizen',
            'om de doos te openen'
                => 'om de kluis te openen',
            'Neem de <span>{{ numero }}-</span> sleutel van binnenuit.'
                => 'Pak sleutel <span>{{ numero }}</span> uit de kluis.',
            'Sluit de doos door de knop'
                => 'Sluit de kluis door de knop',
            'In het appartement vindt u een'
                => 'In het appartement vind je een',
            'Hieronder kunt u de video'
                => 'Hieronder kun je de video',
            'Het wachtwoord van de digitale kluis'
                => 'De code van de digitale kluis',
            // Despues de ingresar (general)
            'Zodra u in het appartement bent aangekomen'
                => 'Zodra je in het appartement bent aangekomen',
            'verzoeken wij u vriendelijk ons'
                => 'vragen we je vriendelijk ons',
            'ter bevestiging van uw aankomst'
                => 'om je aankomst te bevestigen',
            'Wij verzoeken u vriendelijk een'
                => 'We vragen je ook een',
            // Limpieza (general)
            'Mocht u tijdens uw verblijf'
                => 'Mocht je tijdens je verblijf',
            'Wij kunnen dit tegen een meerprijs voor u regelen.'
                => 'We kunnen dit tegen een meerprijs voor je regelen.',
            'De vuilniswagen komt <strong>elke ochtend</strong>'
                => 'De vuilniswagen komt <strong>elke nacht</strong>',
            'We raden aan uw afval'
                => 'We raden aan je afval',
            'vindt u een platform dat speciaal voor dit doel is bestemd'
                => 'vind je een platform dat speciaal hiervoor bedoeld is',
            // Horario solicitudes (general)
            'Als u toiletpapier, vloeibare zeep of andere benodigdheden nodig heeft'
                => 'Als je toiletpapier, vloeibare zeep of andere benodigdheden nodig hebt',
            // Reglas (general)
            'Bij <strong>Diensten</strong> vindt u wasserettes'
                => 'Bij <strong>Diensten</strong> vind je wasserettes',
            'Als u strijkhulp nodig heeft, vraag dan naar de strijkplank: u vindt deze bij <strong>&#39;Services&#39;</strong>'
                => 'Als je wilt strijken, vraag ons dan om de strijkplank: je vindt hem bij <strong>Diensten</strong>',
            'Als u bij het inchecken schade constateert, meldt u dit dan direct aan ons.'
                => 'Als je bij het inchecken schade ziet, meld het ons dan direct.',
            'Gebruik ze alstublieft verantwoordelijk'
                => 'Gebruik ze alsjeblieft verantwoord',
            'als u met kinderen reist'
                => 'als je met kinderen reist',
            // Descripción (casas)
            'Prachtig <strong>, privé en zelfstandig</strong> appartement'
                => 'Prachtig <strong>zelfstandig en privé</strong> appartement',
            '<h3> Verdeling</h3>'
                => '<h3> Indeling</h3>',
            'Als u later wilt uitchecken'
                => 'Als je later wilt uitchecken',
            'tegen een extra kosten'
                => 'tegen extra kosten',
            '</strong> in de gemeenschappelijke ruimte'
                => '</strong> in het woongedeelte',
            '<h3> Distributie (duplex)</h3>'
                => '<h3> Indeling (duplex)</h3>',
            'Begane grond — gemeenschappelijke ruimte'
                => 'Begane grond — woongedeelte',
            'Bovenverdieping — rust'
                => 'Bovenverdieping — slaapgedeelte',
            '<strong>Te zijn.</strong>'
                => '<strong>Zithoek.</strong>',
            // Calefactor (general)
            'Als u een kachel wilt huren'
                => 'Als je een kachel wilt huren',
            '<strong>Operatie:</strong>'
                => '<strong>Werking:</strong>',
            // Pago (general)
            'Dit bedrag wordt na verificatie bij vertrek volledig terugbetaald.'
                => 'Dit bedrag wordt bij vertrek volledig terugbetaald, na controle van:',
            'Correct gebruik en onderhoud van de afdeling.'
                => 'Correct gebruik en zorg voor het appartement.',
            // Registro de huéspedes (general)
            'U dient een foto van de'
                => 'Stuur ons een foto van de',
            'op te sturen om hen te kunnen registreren.'
                => 'zodat we hen kunnen registreren.',
            'Als u extra gasten toevoegt, dient u het'
                => 'Als je extra gasten toevoegt, moet je het',
            // Perdida llaves (general)
            'zodat de borgpinnen voor de veiligheid vervangen kunnen worden'
                => 'zodat we het slot voor de veiligheid kunnen laten aanpassen',
            // Ubicación (general)
            '<span>Saphistraat 877-A en B, Cusco</span>'
                => '<span>Calle Saphi 877-A en B, Cusco</span>',
            'Twee huizen verderop van hotel Mama Sara'
                => 'Twee huizen naast hotel Mama Sara',
            // Early check in Late Check Out
            '<strong>Roosters</strong>'
                => '<strong>Tijden</strong>',
            'Komt u eerder aan, vertrekt u later, of moet u nog uw bagage afgeven?'
                => 'Kom je eerder aan, vertrek je later of wil je je bagage ergens laten?',
            '{{ data sheet: luggage-flexible-hours }}'
                => '{{ ficha: equipaje-horarios-flexibles }}',
            // Traslados (general)
            'Het voertuig zet u <strong>voor de deur</strong> af'
                => 'De auto zet je <strong>voor de deur</strong> af',
            'zonder trappen of loopvlakken'
                => 'zonder trappen of stukken te voet',
            'Wij coördineren dit <strong>met hen</strong> door je vluchtgegevens naar hen door te sturen.'
                => 'Dit regel je zelf <strong>met hen</strong> door je vluchtgegevens naar hen te sturen.',
            // Estacionamiento (general)
            'Saphy Street 644'
                => 'Calle Saphy 644',
            'mocht u uw auto liever binnen parkeren'
                => 'als je je auto liever overdekt parkeert',
            'Let op: het gratis strand'
                => 'Let op: de gratis parkeerplaats',
            'Komt u met de motor?'
                => 'Kom je met de motor?',
            'We raden u aan deze te parkeren'
                => 'We raden je aan hem te parkeren',
            'de bewaakte garage boven: op het gratis strand aan de overkant'
                => 'de bewaakte garage die hierboven staat: op de gratis parkeerplaats aan de overkant',
            // Facturación (general)
            'de prijs die u ziet'
                => 'de prijs die je ziet',
            'Om <strong>het document te kunnen uitreiken, hebben we een foto ervan</strong> nodig'
                => 'Om de bon op te maken, hebben we een <strong>foto van je identiteitsbewijs</strong> nodig',
            'U ontvangt het per post.'
                => 'Je ontvangt hem per e-mail.',
            // Cancelación (general)
            'worden er geen beschikbaarheid weergegeven'
                => 'wordt er geen beschikbaarheid weergegeven',
            // Televisor (casas)
            'Schakel het in en de apps'
                => 'Zet hem aan en de apps',
            // Lavandería (general)
            'Wij verzoeken u vriendelijk om geen kleding te wassen'
                => 'We vragen je vriendelijk om geen kleding te wassen',
            // Toallas y ropa de cama (general)
            'Als je vóór die deadline vervangende artikelen nodig hebt'
                => 'Als je vóór die tijd een verschoning nodig hebt',
            // Plancha y secadora (general)
            'Als je strijkhulp nodig hebt,'
                => 'Als je wilt strijken,',
            // Cocina (casa 5)
            'met een platte bodem en een magneetsluiting'
                => 'met een platte, magnetische bodem',
            // Equipaje y horarios flexibles (general)
            'Als u vóór {{ hora_checkin }} wilt inchecken of na {{ hora_checkout }} wilt uitchecken, dient u dit één dag van tevoren te regelen.'
                => 'Als je vóór {{ hora_checkin }} wilt inchecken of na {{ hora_checkout }} wilt uitchecken, regel dit dan één dag van tevoren.',
            'Wij bewaren uw bagage'
                => 'Wij bewaren je bagage',
            'Wanneer u uw bagage afgeeft, laat ons dan weten <strong>hoeveel stuks</strong> u heeft en <strong>hoe laat u deze komt ophalen</strong>'
                => 'Wanneer je je bagage afgeeft, laat ons dan weten <strong>hoeveel stuks</strong> het zijn en <strong>hoe laat je ze komt ophalen</strong>',
            'Als u terugkomt terwijl de schoonmaakploeg aan het werk is, krijgt u uw bagage van hen terug.'
                => 'Als je terugkomt terwijl de schoonmaakploeg aan het werk is, krijg je je bagage van hen terug.',
            'Komt u later terug, dan overleggen we via de chat en laten we u weten waar uw bagage is.'
                => 'Kom je later terug, dan overleggen we via de chat en laten we je weten waar je bagage is.',
            'blijft uw bagage in het appartement'
                => 'blijft je bagage in het appartement',
            'Deze opslagruimte kunt u zelf openen met de code die wij u verstrekken, zonder dat u op iemand anders hoeft te vertrouwen.'
                => 'Deze opslagruimte kun je zelf openen met de code die we je geven, zonder dat je van iemand afhankelijk bent.',
            'Als <strong>u vóór de inchecktijd arriveert</strong>'
                => 'Als <strong>je vóór de inchecktijd aankomt</strong>',
            'weten hoe laat u aankomt'
                => 'weten hoe laat je aankomt',
            'neemt het personeel uw bagage in ontvangst'
                => 'neemt het personeel je bagage in ontvangst',
            'kunt u uw bagage in de bagageopslag achterlaten en sturen wij u de code.'
                => 'kun je je bagage zelf in de opslagruimte achterlaten en sturen we je de code.',
            // Instrucciones de salida (general)
            'waar u hem heeft opgehaald'
                => 'waar je hem hebt opgehaald',
            'Gebruikte keukengerei dient u af te wassen of af te spoelen.'
                => 'Heb je keukengerei gebruikt, was het dan af of spoel het om.',
            'Als u wilt dat wij uw bagage'
                => 'Als je wilt dat wij je bagage',
        ],
    ];

    /** @var array<string, array<string, string>> español entero → [idioma → traducción] */
    private const array FIJAS = [
        'Caja fuerte' => [
            'en' => 'Safe', 'pt' => 'Cofre', 'fr' => 'Coffre-fort', 'it' => 'Cassaforte', 'de' => 'Safe', 'nl' => 'Kluis',
        ],
        'Croquis' => [
            'en' => 'Sketch map', 'pt' => 'Croqui', 'fr' => 'Plan d\'accès', 'it' => 'Piantina', 'de' => 'Lageplan', 'nl' => 'Plattegrond',
        ],
        'Sala' => [
            'en' => 'Living room', 'pt' => 'Sala', 'fr' => 'Salon', 'it' => 'Soggiorno', 'de' => 'Wohnzimmer', 'nl' => 'Woonkamer',
        ],
        'Estar' => [
            'en' => 'Sitting area', 'pt' => 'Sala de estar', 'fr' => 'Coin salon', 'it' => 'Salotto', 'de' => 'Sitzecke', 'nl' => 'Zithoek',
        ],
        'Habitación' => [
            'en' => 'Room', 'pt' => 'Quarto', 'fr' => 'Chambre', 'it' => 'Camera', 'de' => 'Zimmer', 'nl' => 'Kamer',
        ],
        'Dormitorio' => [
            'en' => 'Bedroom', 'pt' => 'Quarto', 'fr' => 'Chambre', 'it' => 'Camera da letto', 'de' => 'Schlafzimmer', 'nl' => 'Slaapkamer',
        ],
        'Dormitorios' => [
            'en' => 'Bedrooms', 'pt' => 'Quartos', 'fr' => 'Chambres', 'it' => 'Camere da letto', 'de' => 'Schlafzimmer', 'nl' => 'Slaapkamers',
        ],
        'Comedor' => [
            'en' => 'Dining room', 'pt' => 'Sala de jantar', 'fr' => 'Salle à manger', 'it' => 'Sala da pranzo', 'de' => 'Esszimmer', 'nl' => 'Eetkamer',
        ],
        'Pasaje' => [
            'en' => 'Walkway', 'pt' => 'Passagem', 'fr' => 'Passage', 'it' => 'Passaggio', 'de' => 'Durchgang', 'nl' => 'Doorgang',
        ],
        'Puerta del departamento' => [
            'en' => 'Apartment door', 'pt' => 'Porta do apartamento', 'fr' => 'Porte de l\'appartement', 'it' => 'Porta dell\'appartamento', 'de' => 'Wohnungstür', 'nl' => 'Appartementsdeur',
        ],
        'Servicios' => [
            'en' => 'Services', 'pt' => 'Serviços', 'fr' => 'Services', 'it' => 'Servizi', 'de' => 'Services', 'nl' => 'Diensten',
        ],
        'Wifi' => [
            'en' => 'Wi-Fi', 'pt' => 'Wi-Fi', 'fr' => 'Wi-Fi', 'it' => 'Wi-Fi', 'de' => 'WLAN', 'nl' => 'Wifi',
        ],
        'Solicitudes y requerimientos' => [
            'en' => 'Requests', 'pt' => 'Pedidos e solicitações', 'fr' => 'Demandes', 'it' => 'Richieste', 'de' => 'Anfragen und Wünsche', 'nl' => 'Verzoeken',
        ],
        'Estacionamiento' => [
            'en' => 'Parking', 'pt' => 'Estacionamento', 'fr' => 'Parking', 'it' => 'Parcheggio', 'de' => 'Parken', 'nl' => 'Parkeren',
        ],
        'Boleta y comprobantes' => [
            'en' => 'Receipts and invoices', 'pt' => 'Recibos e comprovantes', 'fr' => 'Reçus et justificatifs', 'it' => 'Ricevute e documenti fiscali', 'de' => 'Quittungen und Belege', 'nl' => 'Bonnen en betaalbewijzen',
        ],
        'Alquiler de calefacción' => [
            'en' => 'Heater rental', 'pt' => 'Aluguel de aquecedor', 'fr' => 'Location de radiateurs', 'it' => 'Noleggio di stufe', 'de' => 'Heizgeräte mieten', 'nl' => 'Kachel huren',
        ],
        'Lavandería' => [
            'en' => 'Laundry', 'pt' => 'Lavanderia', 'fr' => 'Laverie', 'it' => 'Lavanderia', 'de' => 'Wäscherei', 'nl' => 'Wasservice',
        ],
        'Album de fotos' => [
            'en' => 'Photo album', 'pt' => 'Álbum de fotos', 'fr' => 'Album photo', 'it' => 'Album fotografico', 'de' => 'Fotoalbum', 'nl' => 'Fotoalbum',
        ],
        'Registro de huéspedes' => [
            'en' => 'Guest registration', 'pt' => 'Cadastro de hóspedes', 'fr' => 'Enregistrement des voyageurs', 'it' => 'Registrazione degli ospiti', 'de' => 'Gästeregistrierung', 'nl' => 'Gastenregistratie',
        ],
        'Registro, pagos y normas de la casa' => [
            'en' => 'Registration, payments and house rules', 'pt' => 'Cadastro, pagamentos e regras da casa', 'fr' => 'Enregistrement, paiements et règlement intérieur', 'it' => 'Registrazione, pagamenti e regole della casa', 'de' => 'Anmeldung, Zahlungen und Hausregeln', 'nl' => 'Registratie, betalingen en huisregels',
        ],
        'Pago y depósito de garantía' => [
            'en' => 'Payment and security deposit', 'pt' => 'Pagamento e depósito de segurança', 'fr' => 'Paiement et dépôt de garantie', 'it' => 'Pagamento e deposito cauzionale', 'de' => 'Zahlung und Kaution', 'nl' => 'Betaling en borgsom',
        ],
        'Equipaje y horarios flexibles' => [
            'en' => 'Luggage and flexible hours', 'pt' => 'Bagagem e horários flexíveis', 'fr' => 'Bagages et horaires flexibles', 'it' => 'Bagagli e orari flessibili', 'de' => 'Gepäck und flexible Uhrzeiten', 'nl' => 'Bagage en flexibele tijden',
        ],
        'Ingreso a la casa' => [
            'en' => 'House entrance', 'pt' => 'Entrada da casa', 'fr' => 'Entrée de la maison', 'it' => 'Accesso alla casa', 'de' => 'Hauseingang', 'nl' => 'Toegang tot het huis',
        ],
        'Que hacer después de ingresar a la casa?' => [
            'en' => 'What to do after entering the house?', 'pt' => 'O que fazer depois de entrar na casa?', 'fr' => 'Que faire après être entré dans la maison ?', 'it' => 'Cosa fare appena arrivi in casa?', 'de' => 'Was ist zu tun, nachdem man das Haus betreten hat?', 'nl' => 'Wat te doen als je binnen bent?',
        ],
        'Secadora de cabello y plancha para ropa' => [
            'en' => 'Hair dryer and clothes iron', 'pt' => 'Secador de cabelo e ferro de passar roupa', 'fr' => 'Sèche-cheveux et fer à repasser', 'it' => 'Asciugacapelli e ferro da stiro', 'de' => 'Haartrockner und Bügeleisen', 'nl' => 'Föhn en strijkijzer',
        ],
    ];

    /**
     * La traducción fija de un rótulo corto, o null si no lo es. Compara el español sin
     * mayúsculas ni espacios de más, y separa los números finales («Estar 2», «Dormitorio 1 2»).
     */
    public function fija(string $origen, string $idioma): ?string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', $origen));
        $numero = '';
        if (preg_match('/^(.+?)((?: \d+)+)$/u', $limpio, $m) === 1) {
            [, $limpio, $numero] = $m;
        }

        foreach (self::FIJAS as $es => $porIdioma) {
            if (mb_strtolower($es) === mb_strtolower($limpio) && isset($porIdioma[$idioma])) {
                return $porIdioma[$idioma] . $numero;
            }
        }

        return null;
    }

    public function aplicar(string $idioma, string $texto): string
    {
        foreach (self::CORRECCIONES[$idioma] ?? [] as $buscar => $reemplazo) {
            $texto = str_replace($buscar, $reemplazo, $texto);
        }

        return $texto;
    }

    /**
     * Si el texto ya lleva esa corrección o todavía la necesita. Lo usa el comando para encontrar
     * las huérfanas: las que no casan con nada de lo guardado.
     */
    public function casaCon(string $buscar, string $reemplazo, string $texto): bool
    {
        return str_contains($texto, $buscar) || str_contains($texto, $reemplazo);
    }

    /** @return array<string, array<string, string>> */
    public function todas(): array
    {
        return self::CORRECCIONES;
    }
}
