<?php

declare(strict_types=1);

namespace App\Api\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Filtro exacto por una RELACIÓN cuyo identificador es un uuid.
 *
 * ⚠️ **Existe porque `SearchFilter` NO SIRVE para esto, y falla en silencio.**
 *
 * Los ids de este proyecto son `uuid` de Symfony y viven en columnas `binary(16)` (ver
 * `App\Entity\Trait\IdTrait`). `SearchFilter::addWhereByStrategy()` ata el valor con
 * `->setParameter($p, $valor)` **sin declarar su tipo**, así que Doctrine manda la cadena de 36
 * caracteres tal cual y la compara contra 16 bytes binarios. No casa nunca: la respuesta es un
 * **200 con la colección vacía**, sin excepción, sin aviso y sin nada en el log.
 *
 * Medido en DQL el 25/08/2026 sobre `OperacionOrdenServicio`, con 6 filas del mismo expediente:
 *
 * ```
 * COUNT sin WHERE                       → 6
 * WHERE o.file = :f  (string)           → 0
 * WHERE o.file = :f  (objeto Uuid)      → 0
 * WHERE o.file = :f  con el tipo 'uuid' → 6   ← toda la diferencia está aquí
 * ```
 *
 * Lo caro no es el fallo, es su disfraz: **una colección vacía se lee igual que «no hay nada»**.
 * Ya había mordido tres veces —el filtro por organización de Travel, el filtro por expediente de
 * La Biblia y el botón «Agregar a OS»—, y las dos primeras se parchearon con una extensión o un
 * controlador a medida por caso. Esto lo resuelve una vez para todos.
 *
 * Uso, igual que `SearchFilter` pero sólo con relaciones:
 *
 * ```php
 * #[ApiFilter(UuidRelacionFilter::class, properties: ['file' => 'exact', 'ordenServicio' => 'exact'])]
 * ```
 *
 * ⚠️ **Como MAPA, no como lista.** `isPropertyEnabled()` hace `array_key_exists($property, …)`,
 * así que con `['file']` la clave sería `0` y la propiedad no se activaría — el filtro se
 * ignoraría entero y la colección saldría sin filtrar. La estrategia no se usa (con un uuid sólo
 * cabe `exact`); se escribe por costumbre, para que se lea igual que un `SearchFilter`.
 *
 * Acepta el valor como **uuid pelado o como IRI**: la mitad del código del front manda una forma
 * y la otra mitad la otra, y obligar a acertar es volver a fallar en silencio.
 *
 * Las columnas de TEXTO (`estadoOs`, `tipoComponente`…) siguen con `SearchFilter`, que ahí va
 * perfectamente. La regla es corta: **relación → este filtro; texto → `SearchFilter`.**
 */
final class UuidRelacionFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (
            !$this->isPropertyEnabled($property, $resourceClass)
            || !$this->isPropertyMapped($property, $resourceClass, true)
        ) {
            return;
        }

        // Un array admite pedir varios: `?file[]=a&file[]=b`.
        $crudos = \is_array($value) ? $value : [$value];
        $valores = array_map($this->aUuid(...), $crudos);

        // ⚠️ **Basta UN valor ilegible para cortar.** Descartarlo y seguir con los demás enseña de
        // más —justo lo que este `if` existe para evitar— y encima de forma inconsistente: con un
        // solo valor malo cortaba, y con uno bueno y uno malo colaba. Un filtro que a veces
        // esconde y a veces no es peor que uno roto del todo, porque nadie sabe cuál le tocó.
        if ([] === $valores || \in_array(null, $valores, true)) {
            // Ignorar el filtro devuelve la colección entera, y quien pidió «lo de este
            // expediente» se llevaría lo de todos creyendo que es lo suyo. Enseñar de menos se
            // nota; enseñar de más, no.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $campo = $property;

        if ($this->isPropertyNested($property, $resourceClass)) {
            [$alias, $campo] = $this->addJoinsForNestedProperty(
                $property,
                $alias,
                $queryBuilder,
                $queryNameGenerator,
                $resourceClass,
                Join::INNER_JOIN,
            );
        }

        // ⚠️ **Un parámetro por valor, unidos con OR. NO un `IN` con un array.**
        //
        // La versión anterior hacía `IN (:p)` atando la lista con el tipo `'uuid[]'`, que **no
        // existe**: DBAL sólo expande los `ArrayParameterType::*`, y cualquier otro nombre acaba
        // en `Type::getType('uuid[]')`, que lanza. Medido: con UN elemento la lista colapsaba a la
        // rama de uno y pasaba; con DOS reventaba con
        // `Unknown column type "uuid[]" requested` — un **500**, no una colección vacía.
        //
        // Y era peor que un fallo cualquiera, porque `getDescription()` publica la forma `?file[]`
        // en el esquema y en `api.d.ts`: se ofrecía un contrato que sólo funcionaba con un valor.
        //
        // Con un parámetro por valor cada uno se ata con el tipo `'uuid'`, que sí existe y es el
        // que convierte a los 16 bytes de la columna. Son pocos valores; el OR no cuesta nada.
        $condiciones = [];

        foreach ($valores as $uuid) {
            $parametro = $queryNameGenerator->generateParameterName($campo);
            $condiciones[] = \sprintf('%s.%s = :%s', $alias, $campo, $parametro);
            // 👇 El tercer argumento ES el arreglo. Sin él, esto devuelve cero para siempre.
            $queryBuilder->setParameter($parametro, $uuid, 'uuid');
        }

        $queryBuilder->andWhere(\sprintf('(%s)', implode(' OR ', $condiciones)));
    }

    /** Acepta el uuid pelado o un IRI («/platform/sales/cotizacion_files/{uuid}»). */
    private function aUuid(mixed $valor): ?Uuid
    {
        if (!\is_string($valor) || '' === $valor) {
            return null;
        }

        // `explode` nunca devuelve un array vacío, así que el último elemento siempre existe:
        // con un uuid pelado es el uuid entero, y con un IRI es el segmento final.
        $partes = explode('/', $valor);
        $ultimo = (string) end($partes);

        return Uuid::isValid($ultimo) ? Uuid::fromString($ultimo) : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getDescription(string $resourceClass): array
    {
        $descripcion = [];

        foreach (array_keys($this->properties ?? []) as $property) {
            $property = (string) $property;

            $descripcion[$property] = [
                'property' => $property,
                'type'     => 'string',
                'required' => false,
                'description' => 'Identificador (uuid o IRI) del recurso relacionado.',
            ];
            $descripcion[$property . '[]'] = [
                'property' => $property,
                'type'     => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => 'Varios identificadores (uuid o IRI) del recurso relacionado.',
            ];
        }

        return $descripcion;
    }
}
