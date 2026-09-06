<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$k = new App\Kernel('dev', false); $k->boot();
$em = $k->getContainer()->get('doctrine')->getManager();
$em->getConnection()->beginTransaction();

$file = $em->getRepository(App\Cotizacion\Entity\CotizacionFile::class)->findOneBy(['localizador' => '2KVBMX']);
$repo = $em->getRepository(App\Cotizacion\Entity\Cotizacion::class);

$estado = fn() => implode(' · ', array_map(
    fn($c) => sprintf('p%d/%s%s', $c->getPropuesta(), $c->getEstado()->value, $c->isPublicado() ? ' [PUBLICADA]' : ''),
    $repo->findBy(['file' => $file], ['propuesta' => 'ASC'])));

printf("antes:   %s\n", $estado());

// Publicamos el histórico de la propuesta 1: su hermana confirmada debe despublicarse sola.
$hist = $repo->findOneBy(['file' => $file, 'propuesta' => 1, 'estado' => App\Cotizacion\Enum\CotizacionEstadoEnum::HISTORICO]);
$hist->setPublicado(true);
$em->flush();
$em->clear();

$file = $em->getRepository(App\Cotizacion\Entity\CotizacionFile::class)->findOneBy(['localizador' => '2KVBMX']);
printf("después: %s\n", $estado());

$em->getConnection()->rollBack();
echo "(rollback: la base queda como estaba)\n";
