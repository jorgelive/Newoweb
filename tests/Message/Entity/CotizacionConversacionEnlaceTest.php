<?php

declare(strict_types=1);

namespace App\Tests\Message\Entity;

use App\Contract\MomentoDeFrente;
use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Cotizacion\Entity\CotizacionFile;
use App\Message\Entity\MessageConversation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Lo que Travel aporta al hilo, y lo que puede decirse al cliente. */
final class CotizacionConversacionEnlaceTest extends TestCase
{
    #[Test]
    public function seIdentificaComoExpedienteDeViaje(): void
    {
        $enlace = $this->enlace('Nune & Todd');

        self::assertSame('turistico', $enlace->getNegocio());
        self::assertSame('cotizacion_file', $enlace->getContextType());
        self::assertSame(MomentoDeFrente::Venta, $enlace->getMomento());
    }

    /**
     * La etiqueta es lo ÚNICO del enlace que puede acabar leyéndole el modelo al cliente: el
     * nombre del grupo sí, el número de expediente y los importes no.
     */
    #[Test]
    public function laEtiquetaDiceElViajeYNadaMas(): void
    {
        self::assertSame('Tu viaje «Nune & Todd»', $this->enlace('Nune & Todd')->getEtiqueta());
    }

    /** Un expediente sin nombre no deja un hueco raro: se cae a algo decible. */
    #[Test]
    public function sinNombreDeGrupoLaEtiquetaSigueSiendoDecible(): void
    {
        self::assertSame('Tu viaje', $this->enlace(null)->getEtiqueta());
        self::assertSame('Tu viaje', $this->enlace('   ')->getEtiqueta());
    }

    #[Test]
    public function elFrenteLlevaLaIdentidadDelAsunto(): void
    {
        $file = (new CotizacionFile())->setNombreGrupo('Nune & Todd');
        $frente = (new CotizacionConversacionEnlace($this->hilo(), $file))->comoFrente();

        self::assertSame('turistico', $frente->negocio);
        self::assertSame('cotizacion_file', $frente->entidadTipo);
        self::assertSame((string) $file->getId(), $frente->entidadId);
        // `null` es una respuesta legítima del contrato: «la procedencia no cambia nada».
        self::assertNull($frente->procedencia);
    }

    private function hilo(): MessageConversation
    {
        return new MessageConversation('manual', '+51984123456');
    }

    private function enlace(?string $nombreGrupo): CotizacionConversacionEnlace
    {
        $file = new CotizacionFile();
        if ($nombreGrupo !== null) {
            $file->setNombreGrupo($nombreGrupo);
        }

        return new CotizacionConversacionEnlace($this->hilo(), $file);
    }
}
