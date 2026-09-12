<?php

declare(strict_types=1);

namespace App\Cotizacion\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Cotizacion\Entity\CotizacionFilearchivo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/** @implements ProcessorInterface<CotizacionFilearchivo, CotizacionFilearchivo|null> */
final readonly class CotizacionFilearchivoMultipartProcessor implements ProcessorInterface
{
    /** @param ProcessorInterface<CotizacionFilearchivo, CotizacionFilearchivo|null> $persistProcessor */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private RequestStack $requestStack
    ) {}

    /**

     * @param array<string, mixed> $uriVariables

     * @param array<string, mixed> $context

     */

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof CotizacionFilearchivo) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        $request = $this->requestStack->getCurrentRequest();

        // 🔥 Buscamos 'documento' en lugar de 'file' para evitar chocar con la relación $file de Doctrine
        if ($request && $request->files->has('documento')) {
            /** @var UploadedFile|null $uploadedFile */
            $uploadedFile = $request->files->get('documento');

            if ($uploadedFile) {
                $data->setImageFile($uploadedFile);
            }
        }

        $this->nombrarSiEsDeIdentidad($data);

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    /**
     * A un escaneo de identidad se le pone el nombre solo.
     *
     * ── Por qué ────────────────────────────────────────────────────────────
     * El campo `nombre` existe para lo que sube el operador a mano y hay que distinguir de un
     * vistazo: «Entrada Machupicchu», «Voucher del hotel». En un pasaporte no distingue nada —el
     * tipo ya lo dice, y tras el OCR el sistema sabe de quién es—, así que obligar a teclearlo es
     * pedir un dato que nadie va a leer justo en el paso más repetitivo del expediente: subir
     * documento tras documento de un grupo entero.
     *
     * Se rellena con la etiqueta del propio enum, que es la convención que ya usa el resto de la
     * interfaz. `esEscaneoDeIdentidad()` decide quiénes son, y **es la misma pregunta que ya
     * responde para la compresión de alta fidelidad**: no se abre una segunda lista que mañana
     * diga otra cosa.
     *
     * ⚠️ **Va aquí y no sólo en el front.** El formulario deja de exigirlo, pero un POST directo
     * a la API lo dejaría vacío, y un archivo sin nombre se ve como una fila en blanco en la
     * bóveda. El servidor es quien garantiza la convención.
     *
     * ⚠️ **Sólo si viene vacío.** Quien escriba «Pasaporte de la madre» manda: la convención es un
     * defecto, no una regla que pise lo que alguien decidió.
     */
    private function nombrarSiEsDeIdentidad(CotizacionFilearchivo $archivo): void
    {
        $tipo = $archivo->getTipoArchivo();

        if ($tipo === null || !$tipo->esEscaneoDeIdentidad()) {
            return;
        }

        foreach ($archivo->getNombre() as $fila) {
            if (trim((string) ($fila['content'] ?? '')) !== '') {
                return;
            }
        }

        // Sólo el español: `#[AutoTranslate]` se encarga del resto… y ahí está el detalle que
        // conviene no perder de vista — ver el aviso de `CotizacionFilearchivo::$nombre`.
        $archivo->setNombre([['language' => 'es', 'content' => $tipo->getLabel()]]);
    }
}