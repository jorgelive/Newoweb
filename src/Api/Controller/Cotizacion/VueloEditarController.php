<?php

declare(strict_types=1);

namespace App\Api\Controller\Cotizacion;

use App\Cotizacion\Entity\CotizacionVuelo;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Corrige **un** vuelo a mano.
 *
 * ## Por qué esto SÍ es un formulario, y el JSON no
 *
 * 🔥 **La entidad no es anidada: el formato de carga sí.** Un `CotizacionVuelo` son siete campos
 * planos —número, aerolínea, origen, destino, salida, llegada— y eso es un formulario corriente.
 * Lo que está anidado es el JSON, y lo está porque va **por PNR**, que es como escribe la
 * aerolínea: «el localizador BONT3N ahora vuela estos cuatro tramos».
 *
 * Son dos trabajos distintos y por eso conviven:
 *
 * | Qué pasó | Con qué se arregla |
 * |---|---|
 * | La aerolínea movió el JA7013 veinte minutos | **este formulario**: se abre el vuelo y se cambia la hora |
 * | Llega el correo con las reservas emitidas | el **JSON**: 24 PNR de una vez |
 * | Un PNR se reubicó en otro vuelo | el **JSON**: el vínculo es de la reserva, no del vuelo |
 *
 * ⚠️ **Lo que este formulario NO toca son los vínculos.** Quién viaja en este vuelo lo decide el
 * PNR, y eso se declara por reserva. Aquí se corrige el HECHO —a qué hora sale—, no a quién le
 * pasa. Mezclarlo daría dos sitios para cambiar lo mismo.
 *
 * ⚠️ **`salida` fija también `fecha`**, que es la mitad de la identidad del vuelo
 * ({@see CotizacionVuelo::setSalida()}). Por eso cambiar la hora de salida a otro día puede
 * chocar con el índice único `(file, numero, fecha)` — pasa cuando ese número ya vuela ese día—,
 * y eso se contesta con una frase que se entiende, no con un 500.
 */
final class VueloEditarController extends AbstractController
{
    #[Route(
        '/cotizacion/user/vuelos/{id}',
        name: 'cotizacion_vuelo_editar',
        requirements: ['id' => '[0-9a-fA-F-]{36}'],
        methods: ['PATCH'],
    )]
    #[IsGranted(Roles::RESERVAS_WRITE, message: 'No tienes permiso para editar vuelos.')]
    public function __invoke(string $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $vuelo = $em->getRepository(CotizacionVuelo::class)->find(Uuid::fromString($id));

        if ($vuelo === null) {
            return $this->json(['error' => 'No encontré ese vuelo.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $datos */
        $datos = json_decode($request->getContent(), true) ?: [];

        if (isset($datos['numero'])) {
            $numero = trim((string) $datos['numero']);

            if ($numero === '') {
                return $this->json(['error' => 'El número de vuelo no puede quedar vacío.'], Response::HTTP_BAD_REQUEST);
            }

            $vuelo->setNumero($numero);
        }

        if (array_key_exists('aerolinea', $datos)) {
            $vuelo->setAerolinea($this->textoONulo($datos['aerolinea']));
        }

        // Mayúsculas: los códigos IATA lo son, y así «lim» y «LIM» no son dos aeropuertos.
        if (array_key_exists('origen', $datos)) {
            $vuelo->setOrigen($this->codigo($datos['origen']));
        }

        if (array_key_exists('destino', $datos)) {
            $vuelo->setDestino($this->codigo($datos['destino']));
        }

        foreach (['salida', 'llegada'] as $campo) {
            if (!array_key_exists($campo, $datos)) {
                continue;
            }

            $momento = $this->momento($datos[$campo]);

            if ($momento === false) {
                return $this->json(
                    ['error' => sprintf('No entendí la %s. Se escribe «2026-09-18 03:00».', $campo)],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            $campo === 'salida' ? $vuelo->setSalida($momento) : $vuelo->setLlegada($momento);
        }

        try {
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // ⚠️ Es el caso real, no una precaución: el JA7027 vuela el 25 y el 27, así que mover
            // una salida al otro día choca con el vuelo que ya existe. Decirlo es lo único útil.
            return $this->json([
                'error' => sprintf(
                    'Ya hay un %s ese día en este expediente. Dos vuelos no pueden compartir número y fecha.',
                    (string) $vuelo->getNumero(),
                ),
            ], Response::HTTP_CONFLICT);
        }

        return $this->json(['ok' => true]);
    }

    private function textoONulo(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function codigo(mixed $valor): ?string
    {
        $texto = $this->textoONulo($valor);

        return $texto === null ? null : strtoupper($texto);
    }

    /** `false` cuando no se entiende; `null` cuando se quiere vaciar. */
    private function momento(mixed $valor): DateTimeImmutable|false|null
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($texto);
        } catch (\Exception) {
            return false;
        }
    }
}
