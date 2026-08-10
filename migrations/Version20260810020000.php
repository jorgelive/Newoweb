<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * El catálogo de medios de cobro, con las cuentas reales del negocio.
 *
 * Sustituye a la columna `pms_establecimiento_virtual.medios_cobro` que creó
 * {@see Version20260810003000} un rato antes. Aquel diseño era un JSON por listing y estaba
 * mal: las cuentas son las mismas para todos los establecimientos —el dinero entra a la misma
 * empresa— y además hacen falta fuera del PMS, en las condiciones de pago de una cotización.
 * Se retira sin migrar datos porque nunca llegó a poblarse en producción.
 *
 * ### Por qué SÍ se siembran los datos aquí
 *
 * Doce filas tecleadas a mano en el panel son doce ocasiones de equivocarse en un dígito, y
 * este dato se lo lee el agente al cliente tal cual. Sembrarlas de una vez, revisadas contra
 * la fuente, es más seguro. A partir de aquí se editan en **Configuración → Medios de cobro**:
 * la migración pone el punto de partida, no manda sobre lo que venga después.
 *
 * ⚠️ Usa `INSERT` a secas y sólo corre una vez. Si alguien corrige una cuenta desde el panel,
 * esta migración ya habrá pasado y no la va a pisar.
 *
 * Las notas se siembran **sólo en español**: `AutoTranslationService` las traduce al resto de
 * idiomas la primera vez que se guarde cada fila desde el panel. Se hace así y no metiendo
 * siete idiomas a mano porque el traductor respeta lo que ya existe («modo seguro»), y una
 * traducción escrita aquí a ojo se quedaría congelada para siempre.
 */
final class Version20260810020000 extends AbstractMigration
{
    private const string TITULAR_BANCOS = 'Susan Acuña Romero';

    /** Cómo lo escribe la banca que no admite «ñ» ni tildes. Ver FinMedioCobro::$titularAlterno. */
    private const string TITULAR_SIN_TILDES = 'Susan Acuna Romero';

    /**
     * Cuenta por banco y moneda, tal como las tiene el negocio.
     *
     * @var array<string, array{PEN: string, USD: string}>
     */
    private const array CUENTAS = [
        'BCP'        => ['PEN' => '285-19544158-0-39',   'USD' => '285-03306263-1-25'],
        'BBVA'       => ['PEN' => '0011-0814-0274882358', 'USD' => '0011-0814-0270710935'],
        'Interbank'  => ['PEN' => '8983191259191',        'USD' => '4263078214241'],
        'Scotiabank' => ['PEN' => '780-7972562',          'USD' => '780-7500949'],
    ];

    public function getDescription(): string
    {
        return 'Crea fin_medio_cobro con las cuentas reales y retira medios_cobro del establecimiento virtual.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE fin_medio_cobro (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                tipo VARCHAR(30) NOT NULL,
                titular VARCHAR(180) DEFAULT NULL,
                titular_alterno VARCHAR(180) DEFAULT NULL,
                numero VARCHAR(60) DEFAULT NULL,
                banco VARCHAR(60) DEFAULT NULL,
                cci VARCHAR(40) DEFAULT NULL,
                moneda VARCHAR(3) DEFAULT NULL,
                audiencia VARCHAR(20) NOT NULL DEFAULT 'todos',
                nota JSON DEFAULT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                orden SMALLINT NOT NULL DEFAULT 0,
                sobreescribir_traduccion TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $orden = 0;

        // Yape y Plin: el mismo número y la misma titular, pero dos filas. Son dos apps
        // distintas y el cliente pregunta por una de las dos, no por «la billetera digital».
        foreach (['yape' => 'Yape', 'plin' => 'Plin'] as $tipo => $etiqueta) {
            $this->insertar([
                'tipo' => $tipo,
                'titular' => 'Susan Acuña Romero',
                'numero' => '+51 958191965',
                'moneda' => 'PEN',
                'audiencia' => 'peru',
                'nota' => sprintf('Envíanos la captura del %s por este chat para confirmarlo.', $etiqueta),
                'orden' => $orden++,
            ]);
        }

        // Las ocho cuentas. Van después de Yape/Plin porque una transferencia es más trabajo
        // para el cliente que abrir una app.
        foreach (self::CUENTAS as $banco => $porMoneda) {
            foreach ($porMoneda as $moneda => $numero) {
                $this->insertar([
                    'tipo' => 'transferencia_bancaria',
                    'titular' => self::TITULAR_BANCOS,
                    'titular_alterno' => self::TITULAR_SIN_TILDES,
                    'numero' => $numero,
                    'banco' => $banco,
                    'moneda' => $moneda,
                    // Nacionales: una transferencia internacional a una cuenta peruana se come
                    // en comisiones buena parte de un adelanto. Ver FinAudienciaCobro.
                    'audiencia' => 'peru',
                    // Sin nota a propósito: lo del CCI y el SWIFT es la MISMA frase para las
                    // ocho cuentas, así que es una instrucción y no un dato. Repetirla por fila
                    // engordaba la respuesta de la skill en ~900 caracteres en cada consulta.
                    // Vive en la descripción de ConsultarMediosPagoSkill, que se paga una vez.
                    'nota' => null,
                    'orden' => $orden++,
                ]);
            }
        }

        // Western Union va a nombre de OTRA persona, y por eso el titular es un campo por fila
        // y no una constante del negocio: quien recoge el giro no es quien tiene las cuentas.
        $this->insertar([
            'tipo' => 'western_union',
            'titular' => 'Jorge Luis Gomez Valencia',
            'numero' => 'Cusco, Perú',
            'audiencia' => 'internacional',
            'nota' => 'El cobro se hace en tienda, en persona. Envíanos el número de seguimiento '
                . '(MTCN) por este chat en cuanto lo tengas.',
            'orden' => $orden++,
        ]);

        $this->insertar([
            'tipo' => 'efectivo',
            'audiencia' => 'todos',
            'nota' => 'Puedes pagar en efectivo al hacer el check-in, en soles o en dólares.',
            'orden' => $orden,
        ]);

        $this->addSql('ALTER TABLE pms_establecimiento_virtual DROP medios_cobro');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento_virtual ADD medios_cobro JSON DEFAULT NULL');
        $this->addSql('DROP TABLE fin_medio_cobro');
    }

    /**
     * @param array{tipo: string, titular?: string, titular_alterno?: string, numero?: string,
     *              banco?: string, moneda?: string, audiencia: string, nota: string|null, orden: int} $fila
     */
    private function insertar(array $fila): void
    {
        // La nota viaja como el resto de campos i18n del proyecto: lista de {language, content}.
        $nota = $fila['nota'] === null ? null : json_encode(
            [['language' => 'es', 'content' => $fila['nota']]],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $this->addSql(
            'INSERT INTO fin_medio_cobro
                (id, tipo, titular, titular_alterno, numero, banco, cci, moneda, audiencia,
                 nota, activo, orden, sobreescribir_traduccion, created_at)
             VALUES (:id, :tipo, :titular, :alterno, :numero, :banco, NULL, :moneda, :audiencia,
                     :nota, 1, :orden, 0, NOW())',
            [
                'id' => Uuid::v7()->toBinary(),
                'tipo' => $fila['tipo'],
                'titular' => $fila['titular'] ?? null,
                'alterno' => $fila['titular_alterno'] ?? null,
                'numero' => $fila['numero'] ?? null,
                'banco' => $fila['banco'] ?? null,
                'moneda' => $fila['moneda'] ?? null,
                'audiencia' => $fila['audiencia'],
                'nota' => $nota,
                'orden' => $fila['orden'],
            ]
        );
    }
}
