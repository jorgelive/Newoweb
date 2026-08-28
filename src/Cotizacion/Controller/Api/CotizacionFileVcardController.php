<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Api;

use App\Cotizacion\Entity\CotizacionFile;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use JeroenDesloovere\VCard\VCard;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * El .vcf de contacto de un EXPEDIENTE, para la agenda del celular.
 *
 * Gemelo de {@see \App\Pms\Controller\Api\PmsReservaVcardController}, y gemelo de verdad: la
 * mecánica es idéntica —descarga directa con `Content-Disposition`, un `<a href>` basta y no
 * hace falta AJAX— pero **lo que va dentro no se copia**, se toma de lo que expone ESTE
 * módulo.
 *
 * ── Qué cambia respecto al de reservas, y por qué ───────────────────────────
 * Una reserva es una estancia: su vCard se ordena por fecha de llegada, casita y canal,
 * porque así es como el operador la busca en la agenda. Un expediente **no tiene fechas
 * propias** —las tienen sus cotizaciones, y puede haber varias versiones— así que ordenarlo
 * por fecha sería inventarse un dato. Lo que sí lo identifica es su **localizador** y el
 * nombre del grupo, que es exactamente lo que enseña la ficha.
 *
 * De ahí el nombre de agenda: `2KVBMX · Nune & Todd` — se teclea el localizador y aparece, o
 * se teclea el grupo y aparece. Sin fechas que caduquen.
 *
 * ⚠️ **El teléfono se guarda sin `+`** (lo sanea `CotizacionFile::sanitizarCampos()` con
 * `PhoneSanitizer`), y `getTelefono()` lo devuelve ya formateado en INTERNATIONAL para
 * pintarlo. Para la vCard hace falta **E164**, que es lo que entiende cualquier agenda, así
 * que se vuelve a parsear en vez de reutilizar el formato de pantalla.
 */
#[Route('/cotizacion/files')]
final class CotizacionFileVcardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%pax_host_url%')]
        private readonly string $paxHostUrl,
    ) {}

    #[Route('/{id}/vcard', name: 'app_cotizacion_file_vcard', methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        // Mismo rol que lee el expediente por la API (`CotizacionFile`): quien puede ver la
        // ficha puede llevarse el contacto, que es un subconjunto de lo que ya está viendo.
        $this->denyAccessUnlessGranted(Roles::RESERVAS_SHOW);

        $file = $this->entityManager->getRepository(CotizacionFile::class)->find($id);

        if (!$file instanceof CotizacionFile) {
            throw new NotFoundHttpException('Expediente no encontrado.');
        }

        $localizador = $file->getLocalizadorPublico() ?? 'Sin-Loc';
        $grupo = $file->getNombreGrupo() ?? 'Expediente sin nombre';
        $titular = $file->getPasajeroPrincipal();
        $pais = $file->getPais()?->getNombre();
        $idioma = $file->getIdioma()?->getNombre();
        $estado = $file->getEstado()->getLabel();
        $pax = $file->getFilepasajeros()->count();

        // El nombre de la agenda: localizador primero, que es lo único que no se repite.
        $campoNombre = sprintf('%s · %s', $localizador, $grupo);

        $vcard = new VCard();
        $vcard->addName('', $campoNombre);

        $telefono = $this->comoE164($file->getTelefono());

        if ($telefono !== '') {
            $vcard->addPhoneNumber($telefono, 'CELL');
        }

        if ($file->getEmail()) {
            $vcard->addEmail($file->getEmail());
        }

        // La nota lleva lo mismo que la cabecera de la ficha, en el mismo orden: quien abre
        // el contacto en el móvil ve lo que vería en pantalla, sin tener que entrar.
        $lineas = array_filter([
            sprintf('Expediente: %s', $localizador),
            sprintf('Grupo: %s', $grupo),
            $titular ? sprintf('Titular: %s', $titular) : null,
            $pais ? sprintf('País de origen: %s', $pais) : null,
            $idioma ? sprintf('Idioma: %s', $idioma) : null,
            sprintf('Estado: %s', $estado),
            $pax > 0 ? sprintf('Pasajeros: %d', $pax) : null,
        ]);

        $vcard->addNote(implode("\n", $lineas));

        // La vista del CLIENTE, no la interna: es la que se le puede reenviar a alguien desde
        // el propio contacto. Misma URL que el botón «Abrir vista cliente» de la ficha.
        if ($file->getLocalizadorPublico()) {
            $vcard->addURL(rtrim($this->paxHostUrl, '/') . '/file/' . $file->getLocalizadorPublico());
        }

        return new Response(
            $vcard->getOutput(),
            200,
            [
                'Content-Type' => 'text/vcard',
                'Content-Disposition' => 'attachment; filename="' . $localizador . '_contacto.vcf"',
            ]
        );
    }

    /**
     * El número en E164 (`+51967007752`), que es lo que entiende una agenda.
     *
     * Entra ya formateado para pantalla (`+51 967 007 752`) porque es lo que devuelve
     * `getTelefono()`; `parse()` se traga los espacios sin problema. Si no es un número
     * válido se devuelve tal cual: un contacto con el número raro es más útil que uno sin
     * número, y el dato es del operador, no nuestro.
     */
    private function comoE164(?string $telefono): string
    {
        $crudo = trim((string) $telefono);

        if ($crudo === '') {
            return '';
        }

        $conPlus = str_starts_with($crudo, '+') ? $crudo : '+' . $crudo;

        try {
            $util = PhoneNumberUtil::getInstance();
            $numero = $util->parse($conPlus, null);

            return $util->isValidNumber($numero)
                ? $util->format($numero, PhoneNumberFormat::E164)
                : $conPlus;
        } catch (NumberParseException) {
            return $conPlus;
        }
    }
}
