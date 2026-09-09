<?php

declare(strict_types=1);

namespace App\Exchange\Service\Client;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * La nube de Tuya: token, firma y las cinco consultas que necesita Domótica.
 *
 * ── Por qué NO implementa `ExchangeClientInterface` ─────────────────────────
 *
 * Vive con los demás clientes porque firmar HMAC y pedir un token es infraestructura, no
 * conocimiento del dominio —la regla de `CLAUDE.md` §Dominios y contratos—, pero **no encaja en
 * ese contrato**: `send(MappingResult)` está hecho para la maquinaria de colas —una tarea
 * encolada, su `ChannelConfig`, la auditoría del envío— y esto es una API de lectura movida por
 * reloj, sin cola. `docs/Domotica.md` §10 descartó a propósito montar Domótica sobre Exchange.
 * Implementar la interfaz obligaría a fabricar un `MappingResult` que nadie usa: un contrato
 * cumplido de mentira estorba más que uno no cumplido.
 *
 * ── Las trampas, todas pagadas ya ───────────────────────────────────────────
 *
 * 1. **La firma se calcula sobre los parámetros ORDENADOS alfabéticamente**, y la URL que se llama
 *    tiene que ser exactamente la misma cadena. Con un parámetro no se nota; con dos, Tuya
 *    responde `sign invalid` y no dice por qué.
 * 2. **La coma de un lote NO se escapa.** `http_build_query()` convierte `device_ids=a,b` en
 *    `a%2Cb` y la firma deja de cuadrar — mismo mensaje mudo, otra causa. Por eso aquí las rutas
 *    se construyen a mano y no se vuelven a codificar.
 * 3. **HTTP/1.1 obligatorio.** El endpoint cierra mal los streams HTTP/2 y la petición muere antes
 *    de leer el cuerpo.
 * 4. **El token va DENTRO de la cadena a firmar** en las llamadas de negocio, entre el client_id y
 *    el timestamp; en la del propio token, no.
 *
 * Verificado contra la nube real el 08/09/2026. Ver `docs/Domotica.md` §13.
 */
final class TuyaClient
{
    /** Un token de Tuya dura 2 h; se renueva antes por si el proceso es largo. */
    private const VIDA_TOKEN = 5400;

    private string $token = '';

    private int $tokenExpiraEn = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%domotica.tuya.host%')] private readonly string $host,
        #[Autowire('%domotica.tuya.client_id%')] private readonly string $clientId,
        #[Autowire('%domotica.tuya.secret%')] private readonly string $secret,
    ) {}

    /**
     * Los aparatos del proyecto, tal cual los devuelve Tuya.
     *
     * Es el paso previo al alta: de aquí salen el id, el nombre y el modelo, y de
     * `codigosDeEstado()` las capacidades — que son DERIVADAS y no se teclean (§3).
     *
     * @return list<array{id: string, nombre: string, modelo: string, producto: string, enLinea: bool}>
     */
    public function listarDispositivos(): array
    {
        $r = $this->pedir('/v1.0/iot-01/associated-users/devices?size=100');
        $crudos = $r['result']['devices'] ?? [];

        if (!is_array($crudos)) {
            return [];
        }

        $salida = [];

        foreach ($crudos as $d) {
            if (!is_array($d) || !isset($d['id']) || !is_string($d['id'])) {
                continue;
            }

            $salida[] = [
                'id' => $d['id'],
                'nombre' => is_string($d['name'] ?? null) ? $d['name'] : '(sin nombre)',
                'modelo' => is_string($d['model'] ?? null) ? $d['model'] : '',
                'producto' => is_string($d['product_id'] ?? null) ? $d['product_id'] : '',
                'enLinea' => (bool) ($d['online'] ?? false),
            ];
        }

        return $salida;
    }

    /**
     * Qué sabe hacer un aparato: los códigos de su especificación.
     *
     * `add_ele` → mide; `switch_1` → conmuta. No se pregunta al usuario, se pregunta al aparato.
     *
     * @return list<string>
     */
    public function codigosDeEstado(string $deviceId): array
    {
        $r = $this->pedir('/v1.0/devices/' . $deviceId . '/specifications');
        $status = $r['result']['status'] ?? [];

        if (!is_array($status)) {
            return [];
        }

        $codigos = [];

        foreach ($status as $dp) {
            if (is_array($dp) && isset($dp['code']) && is_string($dp['code'])) {
                $codigos[] = $dp['code'];
            }
        }

        return $codigos;
    }

    /**
     * El estado de MUCHOS aparatos en UNA llamada.
     *
     * Comprobado con los 28 del parque de golpe. Es lo que hace que el monitor general cueste dos
     * llamadas por ciclo y no cincuenta y seis (§13.3).
     *
     * ⚠️ Lo que devuelve NO trae marca de tiempo: son los últimos valores conocidos, y un aparato
     * desconectado hace tres días sigue contestando tan campante. Para saber si el dato es de
     * ahora hacen falta `infoDeVarios()` (el `online`) o `propiedadesConHora()` (§13.2).
     *
     * @param  list<string>                        $deviceIds
     * @return array<string, array<string, mixed>> id del aparato → código de DP → valor
     */
    public function estadoDeVarios(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        $r = $this->pedir('/v1.0/devices/status?device_ids=' . implode(',', $deviceIds));
        $resultado = $r['result'] ?? [];

        if (!is_array($resultado)) {
            return [];
        }

        $salida = [];

        foreach ($resultado as $id => $dps) {
            if (!is_string($id) || !is_array($dps)) {
                continue;
            }

            $valores = [];

            foreach ($dps as $dp) {
                if (is_array($dp) && isset($dp['code']) && is_string($dp['code'])) {
                    $valores[$dp['code']] = $dp['value'] ?? null;
                }
            }

            $salida[$id] = $valores;
        }

        return $salida;
    }

    /**
     * Si están en línea, en UNA llamada.
     *
     * ⚠️ De este endpoint sólo es fiable `online`. El `update_time` que trae **no** dice cuándo
     * habló el aparato: se midió un accionamiento a las 18:11:59 con ese campo clavado en las
     * 16:03:04 (§13.2). Por eso no se expone aquí — un dato que invita a confiar en él y miente
     * es peor que no tenerlo.
     *
     * @param  list<string>              $deviceIds
     * @return array<string, bool>       id del aparato → si está en línea
     */
    public function enLineaDeVarios(array $deviceIds): array
    {
        if ($deviceIds === []) {
            return [];
        }

        $r = $this->pedir('/v1.0/devices?device_ids=' . implode(',', $deviceIds));
        $dispositivos = $r['result']['devices'] ?? [];

        if (!is_array($dispositivos)) {
            return [];
        }

        $salida = [];

        foreach ($dispositivos as $d) {
            if (is_array($d) && isset($d['id']) && is_string($d['id'])) {
                $salida[$d['id']] = (bool) ($d['online'] ?? false);
            }
        }

        return $salida;
    }

    /**
     * Los datos de UN aparato **con la hora en que el aparato reportó cada uno**.
     *
     * Es el único endpoint que contesta «¿de cuándo es este número?», y la respuesta cambia por
     * campo: se midió el mismo aparato con `switch_1` de hace un minuto y `cur_power` de hace
     * NUEVE DÍAS, porque la potencia sólo se emite cuando cambia (§13.2).
     *
     * ⚠️ No admite lote: la variante con `device_ids` responde `No space permission`. Se pide uno
     * a uno, lo cual es asumible para los tres que miden y desaconsejable para los veintiocho.
     *
     * @return array<string, array{valor: mixed, momento: DateTimeImmutable}>
     */
    public function propiedadesConHora(string $deviceId): array
    {
        $r = $this->pedir('/v2.0/cloud/thing/' . $deviceId . '/shadow/properties');
        $propiedades = $r['result']['properties'] ?? [];

        if (!is_array($propiedades)) {
            return [];
        }

        $salida = [];

        foreach ($propiedades as $p) {
            if (!is_array($p) || !isset($p['code']) || !is_string($p['code'])) {
                continue;
            }

            // `time` viene en milisegundos.
            $ms = is_numeric($p['time'] ?? null) ? (int) $p['time'] : 0;

            // ⚠️ Sale en UTC, y se queda en UTC A PROPÓSITO.
            //
            // Tuya manda un instante absoluto y aquí se devuelve tal cual, etiquetado con el huso
            // que le corresponde. Convertirlo a hora local sería tentador —el proyecto guarda hora
            // de pared— pero **la hora de pared de un aparato es la del establecimiento donde
            // cuelga**, y un cliente HTTP no puede saber eso sin cargar dentro medio dominio.
            //
            // Quien normaliza es `DomoticaDispositivo`, que sí sabe llegar a su establecimiento:
            // sus `registrar*()` lo hacen solos, para que no dependa de que nadie se acuerde.
            //
            // ⚠️ Lo que NO vale es guardar esto directamente en una columna: `new
            // DateTimeImmutable('@…')` es siempre UTC y Doctrine escribe la hora de pared tal cual
            // venga, así que quedarían dígitos de un huso con la etiqueta de otro. Ya pasó: cinco
            // horas de desfase que hacían que `potenciaEsReciente()` diera «fresco» para siempre.
            $salida[$p['code']] = [
                'valor' => $p['value'] ?? null,
                'momento' => new DateTimeImmutable('@' . intdiv($ms, 1000)),
            ];
        }

        return $salida;
    }

    /**
     * El consumo por hora, que es lo que se factura.
     *
     * ⚠️ **Máximo 24 h por llamada** en granularidad horaria, y `energy_action=consume` es
     * obligatorio. Requiere la suscripción Power Management activa en el proyecto — verificada el
     * 08/09/2026.
     *
     * Que la ventana sea de 24 h es también la razón de que no haga falta cola: un muestreo
     * perdido se recupera pidiendo el rango otra vez (§10).
     *
     * ⚠️ La ESCALA del valor está **sin confirmar**: los cubos medidos salieron todos a cero
     * porque no había consumo. `add_ele` viene con escala 3 (÷1000 para kW·h) y lo esperable es
     * que aquí sea igual, pero eso hay que verlo con carga real antes de facturar un sol. Por eso
     * este método devuelve el ENTERO CRUDO tal como llega y no divide nada: quien lo llame decide,
     * y el día que se confirme se cambia en un solo sitio.
     *
     * @return array<string, int> cubo horario `YYYYMMDDHH` → valor crudo
     */
    public function consumoHorarioCrudo(string $deviceId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $ruta = sprintf(
            '/v1.0/iot-03/energy/electricity/devices/statistics-trend?device_id=%s&energy_action=consume&energy_type=electricity&end_time=%s&start_time=%s&statistics_type=hour',
            $deviceId,
            $hasta->format('YmdH'),
            $desde->format('YmdH')
        );

        $r = $this->pedir($ruta);
        $cubos = $r['result'] ?? [];

        if (!is_array($cubos)) {
            return [];
        }

        $salida = [];

        foreach ($cubos as $cubo) {
            if (!is_array($cubo) || !isset($cubo['time']) || !is_scalar($cubo['time'])) {
                continue;
            }

            $salida[(string) $cubo['time']] = is_numeric($cubo['value'] ?? null) ? (int) $cubo['value'] : 0;
        }

        return $salida;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Una llamada firmada, con el token puesto.
     *
     * @return array<string, mixed>
     */
    private function pedir(string $ruta): array
    {
        $respuesta = $this->llamar($ruta, $this->token());

        if (($respuesta['success'] ?? false) !== true) {
            $mensaje = is_string($respuesta['msg'] ?? null) ? $respuesta['msg'] : 'sin detalle';

            $this->logger->warning(sprintf('[Tuya] %s → %s', $ruta, $mensaje));

            throw new RuntimeException(sprintf('Tuya rechazó «%s»: %s', $ruta, $mensaje));
        }

        return $respuesta;
    }

    /** El token, renovado sólo cuando toca. */
    private function token(): string
    {
        if ($this->token !== '' && time() < $this->tokenExpiraEn) {
            return $this->token;
        }

        if ($this->clientId === '' || $this->secret === '') {
            throw new RuntimeException(
                'Faltan TUYA_CLIENT_ID / TUYA_SECRET. Van en .env.local, nunca en .env: con ese par '
                . 'se CONTROLAN los enchufes, no sólo se leen.'
            );
        }

        $r = $this->llamar('/v1.0/token?grant_type=1', '');
        $token = $r['result']['access_token'] ?? null;

        if (($r['success'] ?? false) !== true || !is_string($token) || $token === '') {
            $msg = is_string($r['msg'] ?? null) ? $r['msg'] : 'sin detalle';

            throw new RuntimeException(
                'Tuya no dio token: ' . $msg . '. Si dice «permission deny», el data center no '
                . 'coincide: TUYA_HOST apunta a la región equivocada.'
            );
        }

        $this->token = $token;
        $this->tokenExpiraEn = time() + self::VIDA_TOKEN;

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function llamar(string $ruta, string $token): array
    {
        $rutaFirmada = $this->ordenarParametros($ruta);
        $t = (string) (int) (microtime(true) * 1000);

        $cabeceras = [
            'client_id' => $this->clientId,
            'sign' => $this->firmar($rutaFirmada, $t, $token),
            't' => $t,
            'sign_method' => 'HMAC-SHA256',
        ];

        if ($token !== '') {
            $cabeceras['access_token'] = $token;
        }

        try {
            $respuesta = $this->httpClient->request('GET', $this->host . $rutaFirmada, [
                'headers' => $cabeceras,
                'timeout' => 20,
                // El endpoint cierra mal los streams HTTP/2 y la petición muere antes del cuerpo.
                'http_version' => '1.1',
            ]);

            $decodificado = json_decode($respuesta->getContent(false), true);
        } catch (Throwable $e) {
            $this->logger->error('[Tuya] Fallo de red en ' . $ruta . ': ' . $e->getMessage());

            throw new RuntimeException('No se pudo hablar con Tuya: ' . $e->getMessage(), 0, $e);
        }

        return is_array($decodificado) ? $decodificado : [];
    }

    /**
     * ⚠️ Los parámetros se ordenan alfabéticamente para firmar, y la URL que se llama tiene que
     * ser esta misma cadena.
     *
     * Se reconstruye a mano y NO con `http_build_query()`, porque ése escapa la coma de un lote
     * (`device_ids=a,b` → `a%2Cb`) y entonces la firma no cuadra: `sign invalid`, sin más pistas.
     */
    private function ordenarParametros(string $ruta): string
    {
        if (!str_contains($ruta, '?')) {
            return $ruta;
        }

        [$base, $query] = explode('?', $ruta, 2);
        $partes = explode('&', $query);
        sort($partes);

        return $base . '?' . implode('&', $partes);
    }

    private function firmar(string $ruta, string $t, string $token): string
    {
        $aFirmar = "GET\n" . hash('sha256', '') . "\n\n" . $ruta;

        return strtoupper(hash_hmac('sha256', $this->clientId . $token . $t . $aFirmar, $this->secret));
    }
}
