<?php

namespace App\Service;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FullcalendarEventsfinder
{
    protected TokenStorageInterface $tokenStorage;
    protected ManagerRegistry $managerRegistry;
    protected array $calendars; //configuracion
    protected ObjectManager $manager;
    protected ObjectRepository $repository;
    protected array $options;
    protected AuthorizationCheckerInterface $authorizationChecker;
    protected ParameterBagInterface $params;
    protected UrlGeneratorInterface $router;

    public function __construct(ManagerRegistry $managerRegistry, TokenStorageInterface $tokenStorage, AuthorizationCheckerInterface $authorizationChecker, ParameterBagInterface $params, UrlGeneratorInterface $router)
    {
        $this->managerRegistry = $managerRegistry;
        $this->tokenStorage = $tokenStorage;
        $this->authorizationChecker = $authorizationChecker;
        $this->params = $params;
        $this->router = $router;
    }

    public function setCalendar(string $calendar): void
    {

        $this->calendars = $this->params->get('calendars');
        if(!array_key_exists($calendar, $this->calendars)){
            throw new HttpException(500, sprintf("El calendario %s no esta en los parametros de configuración.", $calendar));
        }
        //procesamos los parametros de configracion en ylm;
        $this->options['entity'] = $this->calendars[$calendar]['entity'];
        $this->options['parameters'] = $this->calendars[$calendar]['parameters'];
        if(isset($this->calendars[$calendar]['resource'])) {
            $this->options['resource'] = $this->calendars[$calendar]['resource'];
        }
        if(isset($this->calendars[$calendar]['filters'])) {
            $this->options['filters'] = $this->calendars[$calendar]['filters'];
        }

        if(isset($this->calendars[$calendar]['repositorymethod'])){
            $this->options['repositorymethod'] = $this->calendars[$calendar]['repositorymethod'];
        }

        $this->manager = $this->managerRegistry->getManagerForClass($this->options['entity']);
        $this->repository = $this->manager->getRepository($this->options['entity']);
    }

    /**
     * Obtiene eventos para FullCalendar.
     * - Repo (Opción B): si hay repositorymethod, lo invoca (debe devolver QB).
     * - Si no, arma un QB simple por rango fechas.
     * - Aplica filtros declarativos desde YAML:
     *     * field sin alias: se aplica sobre la entidad principal (detecta asociación vs escalar).
     *     * field con alias explícito: lo usa tal cual (p. ej. "cot.estadocotizacion").
     */
    public function getEvents(array $data): array
    {
        $token = $this->tokenStorage->getToken();
        $user  = is_object($token?->getUser()) ? $token->getUser() : null;

        // 1) Base QueryBuilder
        if (!empty($this->options['repositorymethod'])) {
            $data['user'] = $user; // el repo decide si lo usa
            $qb = $this->repository->{$this->options['repositorymethod']}($data);

            if ($qb instanceof Query) {
                throw new \LogicException(sprintf(
                    'El método %s::%s debe devolver un QueryBuilder (no Query).',
                    get_class($this->repository),
                    $this->options['repositorymethod']
                ));
            }
            if (!$qb instanceof QueryBuilder) {
                throw new \LogicException('El repositorymethod debe devolver un QueryBuilder.');
            }
        } else {
            /** @var QueryBuilder $qb */
            $qb = $this->manager
                ->getRepository($this->options['entity'])
                ->createQueryBuilder('me');

            $qb->where(sprintf(
                'me.%s >= :firstDate AND me.%s <= :lastDate',
                $this->options['parameters']['end'],
                $this->options['parameters']['start']
            ))
                ->setParameter('firstDate', $data['from'])
                ->setParameter('lastDate',  $data['to']);
        }

        // 2) Filtros YAML
        if (!empty($this->options['filters'])) {
            // Alias real que esté usando el QB del repo (rr, cc, me, etc.)
            $rootAlias = $qb->getAllAliases()[0] ?? 'me';
            $meta      = $this->manager->getClassMetadata($this->options['entity']);

            foreach ($this->options['filters'] as $i => $filter) {
                $field     = $filter['field']     ?? null;   // ej: 'dependencia' | 'cot.estadocotizacion'
                $rawValue  = $filter['value']     ?? null;   // ej: 'id' | 'empresa.id' | literal
                $exception = $filter['exception'] ?? null;

                if (!$field) {
                    continue;
                }

                // Resolver valor desde el usuario si es string (id, empresa.id, ...) o tomar literal si no lo es
                $valor = is_string($rawValue) ? $this->getValueFromUser($user, $rawValue) : $rawValue;

                // Aplicar solo si es escalar, no null y distinto de la excepción
                if ($valor === null || is_object($valor) || $valor === $exception) {
                    continue;
                }

                $param = 'filter'.$i;

                // **Caso A: field con alias explícito ("cot.estado", "cs.proveedor")**
                if (str_contains($field, '.')) {
                    // Asumimos escalar; si necesitas comparar por id en asociaciones aliased,
                    // puedes escribir IDENTITY(cot.relacion) directamente en YAML (ver ejemplos).
                    $qb->andWhere(sprintf('%s = :%s', $field, $param))
                        ->setParameter($param, $valor);
                    continue;
                }

                // **Caso B: field de la entidad raíz (sin alias)**
                if ($meta->hasAssociation($field)) {
                    // Asociación → comparar por id
                    $qb->andWhere(sprintf('IDENTITY(%s.%s) = :%s', $rootAlias, $field, $param))
                        ->setParameter($param, $valor);
                } else {
                    // Escalar
                    $qb->andWhere(sprintf('%s.%s = :%s', $rootAlias, $field, $param))
                        ->setParameter($param, $valor);
                }
            }
        }

        // 3) Ejecutar
        return $qb->getQuery()->getResult();
    }

    /**
     * Resuelve un valor navegando getters sobre $user.
     * - "id"             => $user?->getId()
     * - "empresa.id"     => $user?->getEmpresa()?->getId()
     * Devuelve null si no hay user o falta algún getter intermedio.
     */
    private function getValueFromUser(object $user = null, ?string $path = null): mixed
    {
        if (!$user || !$path) {
            return null;
        }

        if (!str_contains($path, '.')) {
            $getter = 'get' . ucfirst($path);
            return method_exists($user, $getter) ? $user->$getter() : null;
        }

        $current = $user;
        foreach (explode('.', $path) as $segment) {
            $getter = 'get' . ucfirst($segment);
            if (!is_object($current) || !method_exists($current, $getter)) {
                return null;
            }
            $current = $current->$getter();
        }
        return $current;
    }

    public function serializeResources(array $elements): string
    {
        $result = [];
        $aux = [];
        if(isset($this->options['resource'])){
            $i=0;
            foreach($elements as $element) {
                foreach($this->options['resource'] as $key => $parameter){
                    if(strpos($parameter, '.') > 0){
                        $methods = explode('.', $parameter);
                    }else{
                        $methods = [$parameter];
                    }

                    $clonedElement = clone $element; //var_dump($element);
                    foreach($methods as $method){
                        $methodFormated = 'get' . ucfirst($method);
                        $clonedElement = $clonedElement->$methodFormated();
                    }

                    if($key == 'id'){
                        if(false !== array_search($clonedElement, $aux, true)){
                            unset($result[$i]);
                            $agregado = false;
                            break;
                        }else{
                            $aux[] = $clonedElement;
                            $agregado = true;
                        }
                    }
                    $result[$i][$key] = $clonedElement;
                }
                if($agregado === true){
                    $i++;
                }

            }

            usort($result, function($a, $b) {
                return $a['id'] <=> $b['id'];
            });
        }else{
            $result[] = ['id' => 'default', 'title' => 'Default'];
        }

        return json_encode($result);
    }

    public function serialize(array $elements): string
    {

        $result = [];

        $i=0;
        //elements son los resultados del query
        //var_dump($elements); die;
        foreach($elements as $element) {
            foreach($this->options['parameters'] as $key => $parameter){
                if($key == 'url'){ // el parametro url es array proceso el subparametro id
                    $subject = $parameter['id'];
                }else{
                    $subject = $parameter;
                }

                if(strpos($subject, '.') > 0){
                    $methods = explode('.', $subject);
                }else{
                    $methods = [$subject];
                }

                $copiedElement = $element; //ya no clono;
                foreach($methods as $method){
                    $methodFormated = 'get' . ucfirst($method);
                    $copiedElement = $copiedElement->$methodFormated();
                }

                if($key == 'start' || $key == 'end'){
                    $result[$i][$key] = $copiedElement->format("Y-m-d\TH:i:sP");
                }elseif($key == 'url'){
//todo recibir locale y generalo como link
                    if(isset($parameter['edit']) && true === $this->authorizationChecker->isGranted($parameter['edit']['role'])){
                        $result[$i]['urledit'] = $this->router->generate($parameter['edit']['route'], ['id' => $copiedElement, 'tl' => 'es']);
                    }
                    if(isset($parameter['show']) && true === $this->authorizationChecker->isGranted($parameter['show']['role'])){
                        $result[$i]['urlshow'] = $this->router->generate($parameter['show']['route'], ['id' => $copiedElement, 'tl' => 'es']);
                    }
                }else{
                    $result[$i][$key] = $copiedElement;
                }

            }
            if($this->options['resource']){
                $copiedElement = $element; //ya no clono

                if(strpos($this->options['resource']['id'], '.') > 0){
                    $methods = explode('.', $this->options['resource']['id']);
                }else{
                    $methods = [$this->options['resource']['id']];
                }

                foreach($methods as $method){
                    $methodFormated = 'get' . ucfirst($method);
                    $copiedElement = $copiedElement->$methodFormated();
                }

                $result[$i]['resourceId'] = $copiedElement;
            }else{
                $result[$i]['resourceId'] = 'default';
            }

            $i++;

        }
        return json_encode($result);
    }
}