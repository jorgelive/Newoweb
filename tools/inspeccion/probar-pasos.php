<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$k = new App\Kernel('dev', false); $k->boot();
$em = $k->getContainer()->get('doctrine')->getManager();
$em->getConnection()->beginTransaction();
$paso = function(string $q, callable $f) { $t=microtime(true); $r=$f(); printf("  %-28s %d ms\n", $q, (int)((microtime(true)-$t)*1000)); return $r; };

$file = $em->getRepository(App\Cotizacion\Entity\CotizacionFile::class)->findOneBy(['localizador'=>'2KVBMX']);
$conf = $em->getRepository(App\Cotizacion\Entity\Cotizacion::class)->findOneBy(['file'=>$file,'estado'=>App\Cotizacion\Enum\CotizacionEstadoEnum::CONFIRMADO]);

$copia = $paso('duplicar', fn() => $conf->duplicar());
$copia->setPropuesta($conf->getPropuesta());
$copia->setEstado(App\Cotizacion\Enum\CotizacionEstadoEnum::OPERATIVA);
$copia->setDerivadaDe($conf);
$copia->setPublicado(false);

$paso('cancelar DQL', function() use ($em, $conf) {
    return $em->createQuery('UPDATE App\Operacion\Entity\OperacionServicio os SET os.estadoOperacion = :c
        WHERE os.cotizacionServicio IN (SELECT cs.id FROM App\Cotizacion\Entity\CotizacionCotservicio cs WHERE cs.cotizacion = :cot)')
        ->setParameter('c', App\Operacion\Enum\EstadoOperacionEnum::CANCELADO->value)
        ->setParameter('cot', $conf->getId(), Symfony\Bridge\Doctrine\Types\UuidType::NAME)->execute();
});

$paso('persist', fn() => $em->persist($copia));
$paso('flush', fn() => $em->flush());
$em->getConnection()->rollBack();
echo "OK\n";
