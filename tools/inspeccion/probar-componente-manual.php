<?php
// Sonda: un componente MANUAL de punta a punta, en transacción con rollback.
// No cubre esto ningún test unitario: toca base de datos y el resolvedor de La Biblia.
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();
$em->getConnection()->beginTransaction();

try {
    $servicio = $em->getRepository(App\Cotizacion\Entity\CotizacionCotservicio::class)
        ->find(Symfony\Component\Uid\Uuid::fromString('019f7d55-cc54-7934-a2e4-a1a760516419'));

    $comp = new App\Cotizacion\Entity\CotizacionCotcomponente();
    $comp->setCotservicio($servicio)
        ->setEsManual(true)
        ->setNombreInternoSnapshot('  Traslado a La Olla de Juanita (ida)  ')
        ->setTituloSnapshot([['language' => 'es', 'content' => 'Los dejamos en el restaurante']])
        ->setTipo('transporte')
        ->setSinHorario(false)
        ->setFechaHoraInicio(new DateTimeImmutable('2026-09-02 13:00'))
        ->setFechaHoraFin(new DateTimeImmutable('2026-09-02 13:30'));
    $em->persist($comp);
    $em->flush();

    printf("esManual .............. %s\n", var_export($comp->isEsManual(), true));
    printf("nombre interno ........ «%s»   (se recorta al guardar)\n", $comp->getNombreInternoSnapshot());
    printf("tipo .................. %s\n", $comp->getTipo());
    printf("puntos del tipo ....... %s\n", App\Travel\Enum\ComponenteTipoEnum::from($comp->getTipo())->puntosDeServicio()->name);

    // El servicio está inlineado en el contenedor compilado, así que se instancia a mano:
    // `resolverDescripcion()` es puro respecto a sus dependencias.
    $biblia = new App\Operacion\Service\BibliaSnapshotService($em);
    printf("→ La Biblia lo rotula . «%s»\n", $biblia->resolverDescripcion(null, $comp));

    // Y sin nombre interno, para ver que sigue cayendo al título público:
    $comp->setNombreInternoSnapshot(null);
    printf("→ sin nombre interno .. «%s»\n", $biblia->resolverDescripcion(null, $comp));
} finally {
    $em->getConnection()->rollBack();
    echo "\n(rollback: no se escribió nada)\n";
}
