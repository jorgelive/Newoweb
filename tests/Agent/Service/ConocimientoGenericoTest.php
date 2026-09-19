<?php

declare(strict_types=1);

namespace App\Tests\Agent\Service;

use App\Agent\Access\AgentActor;
use App\Agent\Entity\AgentConocimiento;
use App\Agent\Service\ConocimientoGenerico;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * La red que se tiende ANTES de escalar: qué cuenta como «esto ya estaba contestado».
 *
 * No es una comparación cualquiera: lo que aquí salga se le entrega al modelo con un «díselo
 * AHORA» ({@see \App\Agent\Skill\Pms\EscalarAlEquipoSkill}), así que un falso positivo no se
 * queda en el log — se lo lleva el huésped como respuesta a otra cosa.
 */
final class ConocimientoGenericoTest extends TestCase
{
    /**
     * Las etiquetas reales de la ficha que destapó el fallo, recortadas a lo que importa.
     */
    private const string RECEPCION = 'recepcion, hay recepcion, quien me recibe, nadie me abre, '
        . 'entrada autonoma, llego de madrugada, llego muy tarde, llego de noche';

    private function servicio(AgentConocimiento ...$items): ConocimientoGenerico
    {
        $repositorio = $this->createStub(EntityRepository::class);
        $repositorio->method('findBy')->willReturn(array_values($items));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repositorio);

        return new ConocimientoGenerico($em, new NullLogger());
    }

    private function ficha(string $etiquetas): AgentConocimiento
    {
        return (new AgentConocimiento())
            ->setNombreInterno('Recepción y entrada autónoma')
            ->setEtiquetas($etiquetas)
            ->setContenido('No tenemos recepción…')
            ->setActivo(true);
    }

    /**
     * 🔥 El caso que lo motivó: el motivo de escalado más repetido que hay.
     *
     * «tarde» está en la etiqueta «llego muy tarde», y con la comparación palabra a palabra eso
     * bastaba para contestarle «no hay recepción» a quien pedía salir más tarde.
     */
    #[Test]
    public function una_palabra_suelta_de_una_etiqueta_larga_ya_no_casa(): void
    {
        $servicio = $this->servicio($this->ficha(self::RECEPCION));

        self::assertSame([], $servicio->candidatosPara(
            'El huésped quiere salir más tarde, sobre las 15:00',
            AgentActor::huesped('whatsapp', 'pms_reserva', '1')
        ));
    }

    /** Y la etiqueta entera sigue casando, aunque en el texto no vaya seguida. */
    #[Test]
    public function la_etiqueta_completa_casa_aunque_las_palabras_no_vayan_juntas(): void
    {
        $servicio = $this->servicio($this->ficha(self::RECEPCION));

        self::assertCount(1, $servicio->candidatosPara(
            'Pregunta qué pasa si llego de noche, muy tarde',
            AgentActor::huesped('whatsapp', 'pms_reserva', '1')
        ));
    }

    /**
     * Una etiqueta de UNA palabra se comporta igual que antes: es el caso corriente.
     */
    #[Test]
    public function la_etiqueta_de_una_palabra_sigue_saltando_sola(): void
    {
        $servicio = $this->servicio($this->ficha('mascotas, viajo con mi perro'));

        self::assertCount(1, $servicio->candidatosPara(
            'Pregunta si aceptan mascotas',
            AgentActor::huesped('whatsapp', 'pms_reserva', '1')
        ));
    }
}
