<?php

declare(strict_types=1);

namespace App\Pms\EventListener;

use App\Entity\User;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;

/**
 * Al nacer una estancia, le pone quien la limpia.
 *
 * ── Por qué un listener y no la fábrica ─────────────────────────────────────
 * Los eventos se crean desde muchos sitios: el drawer de `util`, la API, `crear_estancia` del
 * agente, el pull de Beds24 y `PmsEventoCalendarioFactory`. Poner el defecto en uno solo
 * garantizaba que los otros cuatro nacieran sin asignar, y un hueco aquí no se ve —la estancia
 * simplemente no le sale a nadie de campo— hasta que alguien se queja de que no le avisaron.
 * En `prePersist` entra por todos los caminos a la vez.
 *
 * ── Es un DEFECTO, no una regla ─────────────────────────────────────────────
 * Sólo escribe si la colección viene vacía. Quien cree la estancia asignando ya a alguien
 * —porque sabe que esa semana la lleva otra persona— manda, y esto no le pisa la decisión.
 *
 * ── Quién es «la de por defecto» es un dato ─────────────────────────────────
 * Sale de `User::$esLimpiezaPorDefecto`, mismo patrón que `esCobradorPrincipal`. No hay ningún
 * nombre escrito en el código: el día que quien limpia hoy tome otro camino, se marca a otra
 * persona en el panel y las estancias nuevas la cogen sin desplegar nada.
 *
 * Si NO hay nadie marcada, la estancia nace sin asignar. Es el fallo seguro: no le sale a nadie
 * de campo, que es preferible a repartirla a ciegas.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: PmsEventoCalendario::class)]
final class PmsLimpiezaAsignacionListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function prePersist(PmsEventoCalendario $evento): void
    {
        // Una EXTENSIÓN no es una estancia: es la noche fantasma que bloquea un horario extra
        // (ver PmsExtensionEstanciaService). No se limpia, así que no se asigna — y de paso no
        // ensucia la lista de quien limpia con una noche que no existe.
        if ($evento->getEventoOrigen() !== null) {
            return;
        }

        // Un BLOQUEO por mantenimiento tampoco: no hay huésped que se vaya ni casita que
        // preparar para nadie. Se reconoce por no tener reserva detrás.
        if ($evento->getReserva() === null) {
            return;
        }

        // Una estancia que nace ya cancelada no da trabajo a nadie.
        if (PmsEventoEstado::CODIGO_CANCELADA === $evento->getEstado()?->getId()) {
            return;
        }

        if (!$evento->getLimpiezaAsignada()->isEmpty()) {
            return;
        }

        $usuario = $this->porDefecto();

        if ($usuario !== null) {
            $evento->addLimpiezaAsignada($usuario);
        }
    }

    /**
     * La persona marcada por defecto, o `null` si no hay ninguna.
     *
     * Se ordena por `username` para que con varias marcadas —que es un dato mal puesto— la
     * elegida sea siempre la misma y no dependa del orden que devuelva la base ese día.
     */
    private function porDefecto(): ?User
    {
        // 🚫 SIN CACHE, y es deliberado. Guardarla en una propiedad ahorra tres consultas al
        // crear una reserva de cuatro casitas, pero este listener es un servicio y los workers
        // de messenger viven HORAS: quien cambie la persona por defecto en el panel no vería
        // el efecto hasta el siguiente reinicio, y el síntoma —«las estancias nuevas siguen
        // saliendo a nombre de la anterior»— es de los que se investigan dos veces antes de
        // sospechar de un caché. Es una consulta indexada sobre una tabla de cinco filas.
        return $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.esLimpiezaPorDefecto = true')
            // 🚫 NO se filtra por `enabled`, y cuesta un párrafo explicar por qué.
            //
            // `enabled` dice si la persona ENTRA AL PANEL, no si trabaja aquí: quien limpia no
            // tiene login, así que está deshabilitada por norma —las dos que hay hoy lo están—.
            // La primera versión de este método exigía `enabled = true` «para no asignarle
            // estancias a quien ya no trabaja aquí», y el resultado era que la asignación
            // automática no hacía NADA para exactamente la persona para la que se construyó,
            // en silencio y sin error.
            //
            // Quien deje de trabajar aquí se quita marcando a otra persona por defecto, o
            // borrándole el móvil —que es lo que el agente usa para reconocerla—.
            ->orderBy('u.username', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
