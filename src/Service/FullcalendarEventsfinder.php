<?php

namespace App\Service;

use Doctrine\ORM\EntityRepository;
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

    public function getEvents(array $data): mixed
    {

        $user = $this->tokenStorage->getToken()->getUser();

        if(!empty($this->options['repositorymethod'])){

            //Para consultas complejas
            //Me significa main entity
            $data['user'] = $user;
            $query = $this->repository->{$this->options['repositorymethod']}($data);

        }else{
            //Para consultas simples
            $query = $this->manager
                ->getRepository($this->options['entity'])
                ->createQueryBuilder()
                ->select('me')
                ->where('me.' . $this->options['parameters']['end'] . ' >= :firstDate AND me.'. $this->options['parameters']['start'] . ' <= :lastDate');

            $query->setParameter('firstDate', $data['from'])
                ->setParameter('lastDate', $data['to'])
            ;
        }

        if(isset($this->options['filters']) && !empty($this->options['filters'])){
            foreach($this->options['filters'] as $i => $filter):
                $valor = false;
                if(!str_contains($filter['value'], '.')){
                    $valor = $filter['value'];
                }else{

                    $partes = explode('.', $filter['value']);

                    $clonedElement = clone $user;
                    foreach($partes as $parte){
                        $methodFormated = 'get' . ucfirst($parte);
                        //var_dump($methodFormated); die;
                        if($clonedElement !== null){
                            $clonedElement = $clonedElement->$methodFormated();
                        }

                    }
                    if(!is_object($clonedElement) && $filter['exception'] != $clonedElement){
                        $valor = $clonedElement;
                    }
                }

                //$query->getAllAliases() obtiene la lista de alias de las entidades usamos la primera
                //todo averiguar si siempre es la primera
                if($valor !== false && !empty($query->getAllAliases()) && is_array($query->getAllAliases())) {
                    $query->andWhere($query->getAllAliases()[0] . '.' . $filter['field'] . ' = :filter' . $i)
                        ->setParameter('filter' . $i, $valor);
                }

            endforeach;
        }

        return $query->getQuery()->getResult();

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