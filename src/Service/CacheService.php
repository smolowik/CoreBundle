<?php

namespace CommonGateway\CoreBundle\Service;

use App\Entity\Endpoint;
use App\Entity\Entity;
use App\Entity\ObjectEntity;
use App\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Exception;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Cache\Adapter\AdapterInterface as CacheInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Service to call external sources.
 *
 * This service provides a guzzle wrapper to work with sources in the common gateway.
 *
 * @Author Ruben van der Linde <ruben@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>, Robert Zondervan <robert@conduction.nl>
 *
 * @TODO add all backend developers here?
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Service
 */
class CacheService
{

    /**
     * @var Client
     */
    private Client $client;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var CacheInterface
     */
    private CacheInterface $cache;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;

    /**
     * @var ParameterBagInterface
     */
    private ParameterBagInterface $parameters;

    /**
     * @var SerializerInterface
     */
    private SerializerInterface $serializer;

    /**
     * @param EntityManagerInterface $entityManager The entity manager
     * @param CacheInterface         $cache         The cache interface
     * @param LoggerInterface        $cacheLogger   The logger for the cache channel.
     * @param ParameterBagInterface  $parameters    The Parameter bag
     * @param SerializerInterface    $serializer    The serializer
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        CacheInterface $cache,
        LoggerInterface $cacheLogger,
        ParameterBagInterface $parameters,
        SerializerInterface $serializer
    ) {
        $this->entityManager = $entityManager;
        $this->cache         = $cache;
        $this->logger        = $cacheLogger;
        $this->parameters    = $parameters;
        $this->serializer    = $serializer;
        if ($this->parameters->get('cache_url', false)) {
            $this->client = new Client($this->parameters->get('cache_url'));
        }

    }//end __construct()

    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style
     *
     * @return self
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;

        return $this;

    }//end setStyle()

    /**
     * Remove non-existing items from the cache.
     */
    public function cleanup()
    {
        isset($this->style) === true && $this->style->writeln(
            [
                'Common Gateway Cache Cleanup',
                '============',
                '',
            ]
        );

        isset($this->style) === true && $this->style->section('Cleaning Object\'s');
        $collection = $this->client->objects->json;
        $filter     = [];
        $objects    = $collection->find($filter)->toArray();
        isset($this->style) === true && $this->style->writeln('Found '.count($objects).'');

    }//end cleanup()

    /**
     * Throws all available objects into the cache.
     */
    public function warmup()
    {
        isset($this->style) === true && $this->style->writeln(
            [
                'Common Gateway Cache Warmup',
                '============',
                '',
            ]
        );

        isset($this->style) === true && $this->style->writeln('Connecting to'.$this->parameters->get('cache_url'));

        // Backwards compatablity
        if (isset($this->client) === false) {
            isset($this->io) ? $this->io->writeln('No cache client found, halting warmup') : '';

            return Command::SUCCESS;
        }

        // Objects
        isset($this->style) === true && $this->style->section('Caching Objects\'s');
        $objectEntities = $this->entityManager->getRepository('App:ObjectEntity')->findAll();
        isset($this->style) === true && $this->style->writeln('Found '.count($objectEntities).' objects\'s');

        foreach ($objectEntities as $objectEntity) {
            try {
                $this->cacheObject($objectEntity);
            } catch (Exception $exception) {
                $this->styleCatchException($exception);
                continue;
            }
        }

        // Schemas
        isset($this->style) === true && $this->style->section('Caching Schema\'s');
        $schemas = $this->entityManager->getRepository('App:Entity')->findAll();
        isset($this->style) === true && $this->style->writeln('Found '.count($schemas).' Schema\'s');

        foreach ($schemas as $schema) {
            try {
                $this->cacheShema($schema);
            } catch (Exception $exception) {
                $this->styleCatchException($exception);
                continue;
            }
        }

        // Endpoints
        isset($this->style) === true && $this->style->section('Caching Endpoint\'s');
        $endpoints = $this->entityManager->getRepository('App:Endpoint')->findAll();
        isset($this->style) === true && $this->style->writeln('Found '.count($endpoints).' Endpoint\'s');

        foreach ($endpoints as $endpoint) {
            try {
                $this->cacheEndpoint($endpoint);
            } catch (Exception $exception) {
                $this->styleCatchException($exception);
                continue;
            }
        }

        // Created indexes
        $this->client->objects->json->createIndex(['$**' => 'text']);
        $this->client->schemas->json->createIndex(['$**' => 'text']);
        $this->client->endpoints->json->createIndex(['$**' => 'text']);

        isset($this->style) === true && $this->style->writeln(['Removing deleted endpoints', '============']);
        $this->removeDataFromCache($this->client->endpoints->json, 'App:Endpoint');

        isset($this->style) === true && $this->style->writeln(['Removing deleted objects', '============']);
        $this->removeDataFromCache($this->client->objects->json, 'App:ObjectEntity');

        return Command::SUCCESS;

    }//end warmup()

    private function removeDataFromCache(Collection $collection, string $type): void
    {
        $endpoints = $collection->find()->toArray();
        foreach ($endpoints as $endpoint) {
            if ($this->entityManager->find($type, $endpoint['_id']) === null) {
                (isset($this->style) === true ?? $this->style->writeln("removing {$endpoint['_id']} from cache"));
                $collection->findOneAndDelete(['id' => $endpoint['_id']]);
            }
        }

    }//end removeDataFromCache()

    /**
     * Writes exception data to symfony IO.
     *
     * @param Exception $exception The Exception
     *
     * @return void
     */
    private function styleCatchException(Exception $exception)
    {
        $this->logger->error($exception->getMessage());
        if (isset($this->style) === true) {
            $this->style->warning($exception->getMessage());
            $this->style->block("File: {$exception->getFile()}, Line: {$exception->getLine()}");
            $this->style->block("Trace: {$exception->getTraceAsString()}");
        };

    }//end styleCatchException()

    /**
     * Put a single object into the cache.
     *
     * @param ObjectEntity $objectEntity
     *
     * @return ObjectEntity
     */
    public function cacheObject(ObjectEntity $objectEntity): ObjectEntity
    {
        // For when we can't generate a schema for an ObjectEntity (for example setting an id on ObjectEntity created with testData)
        if ($objectEntity->getEntity() === null) {
            return $objectEntity;
        }

        // Backwards compatablity
        if (isset($this->client) === false) {
            return $objectEntity;
        }

        if (isset($this->style) === true) {
            $this->style->writeln('Start caching object '.$objectEntity->getId()->toString().' of type '.$objectEntity->getEntity()->getName());
        }

        // todo: temp fix to make sure we have the latest version of this ObjectEntity before we cache it.
        $updatedObjectEntity = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['id' => $objectEntity->getId()->toString()]);
        if ($updatedObjectEntity !== null) {
            $objectEntity = $updatedObjectEntity;
        }

        if ($updatedObjectEntity === null) {
            isset($this->style) === true && $this->style->writeln('Could not find an ObjectEntity with id: '.$objectEntity->getId()->toString());
        }

        $collection = $this->client->objects->json;

        // Lets not cash the entire schema
        $array = $objectEntity->toArray(['embedded' => true, 'user' => $this->getObjectUser($objectEntity)]);

        // (isset($array['_schema']['$id'])?$array['_schema'] = $array['_schema']['$id']:'');
        $identification = $objectEntity->getId()->toString();

        // Add an id field to main object only if the object not already has an id field.
        if (key_exists('id', $array) === false) {
            $array['id'] = $identification;
        }

        // Add id field to level 1 subobjects for backwards compatibility reasons.
        if (key_exists('embedded', $array) === true) {
            foreach ($array['embedded'] as $key => $subObject) {
                if (key_exists('_self', $subObject) === true && key_exists('id', $subObject) === false) {
                    $array['embedded'][$key]['id'] = $subObject['_self']['id'];
                }
            }
        }

        if ($collection->findOneAndReplace(
            ['_id' => $identification],
            $array,
            ['upsert' => true]
        )
        ) {
            isset($this->style) === true && $this->style->writeln('Updated object '.$objectEntity->getId()->toString().' of type '.$objectEntity->getEntity()->getName().' to cache');
        } else {
            isset($this->style) === true && $this->style->writeln('Wrote object '.$objectEntity->getId()->toString().' of type '.$objectEntity->getEntity()->getName().' to cache');
        }

        return $objectEntity;

    }//end cacheObject()

    /**
     * Gets the User object of an ObjectEntity.
     *
     * @param ObjectEntity $objectEntity
     *
     * @return User|null
     */
    private function getObjectUser(ObjectEntity $objectEntity): ?User
    {
        if (Uuid::isValid($objectEntity->getOwner()) === false) {
            $this->logger->warning("User {$objectEntity->getOwner()} is not a user object but an external user.");

            return null;
        }

        $user = $this->entityManager->getRepository('App:User')->findOneBy(['id' => $objectEntity->getOwner()]);

        if ($user === null) {
            $this->logger->warning("Could not find a User with id = {$objectEntity->getOwner()} for Object: {$objectEntity->getId()->toString()}");
        }

        return $user;

    }//end getObjectUser()

    /**
     * Removes an object from the cache.
     *
     * @param ObjectEntity $objectEntity
     *
     * @return void
     */
    public function removeObject(ObjectEntity $object): void
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return;
        }

        $identification = $object->getId()->toString();
        $collection     = $this->client->objects->json;

        $collection->findOneAndDelete(['_id' => $identification]);

    }//end removeObject()

    /**
     * Get a single object from the cache.
     *
     * @param string $identification
     *
     * @return array|null
     */
    public function getObject(string $identification): ?array
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return null;
        }

        $collection = $this->client->objects->json;

        // Check if object is in the cache ????
        if ($object = $collection->findOne(['_id' => $identification])) {
            return json_decode(json_encode($object), true);
        }

        // Fall back tot the entity manager
        if ($object = $this->entityManager->getRepository('App:ObjectEntity')->findOneBy(['id' => $identification])) {
            return $this->cacheObject($object)->toArray(['embedded' => true]);
        }

        return null;

    }//end getObject()

    /**
     * Searches the object store for objects containing the search string.
     *
     * @param string|null $search   a string to search for within the given context
     * @param array       $filter   an array of dot.notation filters for wich to search with
     * @param array       $entities schemas to limit te search to
     *
     * @throws Exception
     *
     * @return array|null
     */
    public function searchObjects(string $search = null, array $filter = [], array $entities = []): ?array
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return [];
        }

        // Backwards compatibility
        $this->queryBackwardsCompatibility($filter);

        // Make sure we also have all filters stored in $completeFilter before unsetting
        $completeFilter = $filter;

        unset(
            $filter['_start'],
            $filter['_offset'],
            $filter['_limit'],
            $filter['_page'],
            $filter['_extend'],
            $filter['_search'],
            $filter['_order'],
            $filter['_fields']
        );

        // 'normal' Filters (not starting with _ )
        foreach ($filter as $key => &$value) {
            $this->handleFilter($key, $value);
        }

        // Search for the correct entity / entities
        if (empty($entities) === false) {
            $queryError = $this->handleEntities($filter, $completeFilter, $entities);
            if ($queryError !== null) {
                return $queryError;
            }
        }

        // Lets see if we need a search
        $this->handleSearch($filter, $completeFilter, $search);

        // Limit & Start for pagination
        $this->setPagination($limit, $start, $completeFilter);

        // Order
        $order                                                   = isset($completeFilter['_order']) === true ? str_replace(['ASC', 'asc', 'DESC', 'desc'], [1, 1, -1, -1], $completeFilter['_order']) : [];
        empty($order) === false && $order[array_keys($order)[0]] = (int) $order[array_keys($order)[0]];

        // Find / Search
        return $this->retrieveObjectsFromCache($filter, ['limit' => $limit, 'skip' => $start, 'sort' => $order], $completeFilter);

    }//end searchObjects()

    /**
     * Retrieves objects from a cache collection.
     *
     * @param array $filter
     * @param array $options
     * @param array $completeFilter
     *
     * @return array|null $this->handleResultPagination()
     */
    public function retrieveObjectsFromCache(array $filter, array $options, array $completeFilter = []): ?array
    {
        $collection = $this->client->objects->json;
        $results    = $collection->find($filter, $options)->toArray();
        $total      = $collection->count($filter);

        return $this->handleResultPagination($completeFilter, $results, $total);

    }//end retrieveObjectsFromCache()

    /**
     * Make sure we still support the old query params. By translating them to the new ones with _.
     *
     * @param array $filter
     *
     * @return void
     */
    private function queryBackwardsCompatibility(array &$filter)
    {
        isset($filter['_limit']) === false && isset($filter['limit']) === true && $filter['_limit']    = $filter['limit'];
        isset($filter['_start']) === false && isset($filter['start']) === true && $filter['_start']    = $filter['start'];
        isset($filter['_offset']) === false && isset($filter['offset']) === true && $filter['_offset'] = $filter['offset'];
        isset($filter['_page']) === false && isset($filter['page']) === true && $filter['_page']       = $filter['page'];
        isset($filter['_extend']) === false && isset($filter['extend']) === true && $filter['_extend'] = $filter['extend'];
        isset($filter['_search']) === false && isset($filter['search']) === true && $filter['_search'] = $filter['search'];
        isset($filter['_order']) === false && isset($filter['order']) === true && $filter['_order']    = $filter['order'];
        isset($filter['_fields']) === false && isset($filter['fields']) === true && $filter['_fields'] = $filter['fields'];

        unset(
            $filter['start'],
            $filter['offset'],
            $filter['limit'],
            $filter['page'],
            $filter['extend'],
            $filter['search'],
            $filter['order'],
            $filter['fields']
        );

    }//end queryBackwardsCompatibility()

    /**
     * Handles a single filter used on a get collection api call. This function makes sure special filters work correctly.
     *
     * @param $key
     * @param $value
     *
     * @throws Exception
     *
     * @return void
     */
    private function handleFilter($key, &$value)
    {
        if (substr($key, 0, 1) == '_') {
            // todo: deal with filters starting with _ like: _dateCreated
        }

        // Handle filters that expect $value to be an array
        if ($this->handleFilterArray($key, $value)) {
            return;
        }

        // If the value is a boolean we need a other format.
        if (is_bool($value) === true) {
            // Set as key '$eq' with the value.
            $value = ['$eq' => $value];

            return;
        }

        // Todo: This works, we should go to php 8.0 later.
        if (str_contains($value, '%') === true) {
            $regex = str_replace('%', '', $value);
            $regex = preg_replace('/([^A-Za-z0-9\s])/', '\\\\$1', $regex);
            $value = ['$regex' => $regex];

            return;
        }

        if ($value === 'IS NOT NULL') {
            $value = ['$ne' => null];

            return;
        }

        if ($value === 'IS NULL' || $value === 'null') {
            $value = null;

            return;
        }

        // todo: exact match is default, make case insensitive optional:
        $value = preg_replace('/([^A-Za-z0-9\s])/', '\\\\$1', $value);
        $value = [
            '$regex'   => "^$value$",
            '$options' => 'im',
        ];

    }//end handleFilter()

    /**
     * Handles a single filter used on a get collection api call. Specifically an filter where the value is an array.
     *
     * @param $key
     * @param $value
     *
     * @throws Exception
     *
     * @return bool
     */
    private function handleFilterArray($key, &$value): bool
    {
        // Lets check for the methods like in
        if (is_array($value) === true) {
            // int_compare
            if (array_key_exists('int_compare', $value) === true && is_array($value['int_compare']) === true) {
                $value = array_map('intval', $value['int_compare']);
            } else if (array_key_exists('int_compare', $value) === true) {
                $value = (int) $value['int_compare'];

                return true;
            }

            // bool_compare
            if (array_key_exists('bool_compare', $value) === true && is_array($value['bool_compare']) === true) {
                $value = array_map('boolval', $value['bool_compare']);
            } else if (array_key_exists('bool_compare', $value) === true) {
                $value = (bool) $value['bool_compare'];

                return true;
            }

            // after, before, strictly_after,strictly_before
            if (empty(array_intersect_key($value, array_flip(['after', 'before', 'strictly_after', 'strictly_before']))) === false) {
                $newValue = null;
                // Compare datetime
                if (empty(array_intersect_key($value, array_flip(['after', 'strictly_after']))) === false) {
                    $after       = array_key_exists('strictly_after', $value) ? 'strictly_after' : 'after';
                    $compareDate = new DateTime($value[$after]);
                    $compareKey  = $after === 'strictly_after' ? '$gt' : '$gte';

                    // Todo: add in someway an option for comparing string datetime or mongoDB datetime.
                    // $newValue["$compareKey"] = new UTCDateTime($compareDate);
                    $newValue["$compareKey"] = "{$compareDate->format('c')}";
                }

                if (empty(array_intersect_key($value, array_flip(['before', 'strictly_before']))) === false) {
                    $before      = array_key_exists('strictly_before', $value) ? 'strictly_before' : 'before';
                    $compareDate = new DateTime($value[$before]);
                    $compareKey  = $before === 'strictly_before' ? '$lt' : '$lte';

                    // Todo: add in someway an option for comparing string datetime or mongoDB datetime.
                    // $newValue["$compareKey"] = new UTCDateTime($compareDate);
                    $newValue["$compareKey"] = "{$compareDate->format('c')}";
                }

                $value = $newValue;

                return true;
            }//end if

            // like
            if (array_key_exists('like', $value) === true && is_array($value['like']) === true) {
                // $value = array_map('like', $value['like']);
            } else if (array_key_exists('like', $value) === true) {
                $value = preg_replace('/([^A-Za-z0-9\s])/', '\\\\$1', $value['like']);
                $value = [
                    '$regex'   => ".*$value.*",
                    '$options' => 'im',
                ];

                return true;
            }

            // regex
            if (array_key_exists('regex', $value) === true && is_array($value['regex']) === true) {
                // $value = array_map('like', $value['like']); @todo
            } else if (array_key_exists('regex', $value) === true) {
                $value = ['$regex' => $value['regex']];

                return true;
            }

            // >=
            if (array_key_exists('>=', $value) === true && is_array($value['>=']) === true) {
                // $value = array_map('like', $value['like']); @todo
            } else if (array_key_exists('>=', $value) === true) {
                $value = ['$gte' => (int) $value['>=']];

                return true;
            }

            // >
            if (array_key_exists('>', $value) === true && is_array($value['>']) === true) {
                // $value = array_map('like', $value['like']); @todo
            } else if (array_key_exists('>', $value) === true) {
                $value = ['$gt' => (int) $value['>']];

                return true;
            }

            // <=
            if (array_key_exists('<=', $value) === true && is_array($value['<=']) === true) {
                // $value = array_map('like', $value['like']); @todo
            } else if (array_key_exists('<=', $value) === true) {
                $value = ['$lte' => (int) $value['<=']];

                return true;
            }

            // <
            if (array_key_exists('<', $value) === true && is_array($value['<']) === true) {
                // $value = array_map('like', $value['like']); @todo
            } else if (array_key_exists('<', $value) === true) {
                $value = ['$lt' => (int) $value['<']];

                return true;
            }

            // exact
            if (array_key_exists('exact', $value) === true && is_array($value['exact']) === true) {
                // $value = array_map('like', $value['like']); @todo
            } else if (array_key_exists('exact', $value) === true) {
                $value = $value;

                return true;
            }

            // case_insensitive
            if (array_key_exists('case_insensitive', $value) === true && is_array($value['case_insensitive']) === true) {
                // $value = array_map('like', $value['like']); @todo
            } else if (array_key_exists('case_insensitive', $value) === true) {
                $value = [
                    '$regex'   => $value['case_insensitive'],
                    '$options' => 'i',
                ];

                return true;
            }

            // case_sensitive
            if (array_key_exists('case_sensitive', $value) === true && is_array($value['case_sensitive']) === true) {
                // $value = array_map('like', $value['like']); @todo
            } else if (array_key_exists('case_sensitive', $value)) {
                $value = ['$regex' => $value['case_sensitive']];

                return true;
            }

            // Handle filter value = array (example: ?property=a,b,c) also works if the property we are filtering on is an array
            $value = ['$in' => $value];

            return true;
        }//end if

        return false;

    }//end handleFilterArray()

    /**
     * Will add entity filters to the filters array.
     * Will also check if we are allowed to filter & order with the given filters and order query params.
     *
     * @param array $filter         The filter array
     * @param array $completeFilter The complete filter array, contains order & pagination queries/filters as well.
     * @param array $entities       An array with one or more entities we are searching objects for.
     *
     * @return array|null Will return an array if any query parameters are used that are not allowed.
     */
    private function handleEntities(array &$filter, array $completeFilter, array $entities): ?array
    {
        $filterCheck = $filter;
        $errorData   = [];
        foreach ($entities as $entity) {
            if (Uuid::isValid($entity) === true) {
                // $filter['_self.schema.id'] = 'b92a3a39-3639-4bf5-b2af-c404bc2cb005';
                $filter['_self.schema.id']['$in'][] = $entity;
                $entityObject                       = $this->entityManager->getRepository('App:Entity')->findOneBy(['id' => $entity]);
            } else {
                // $filter['_self.schema.ref'] = 'https://larping.nl/schema/example.schema.json';
                $filter['_self.schema.ref']['$in'][] = $entity;
                $entityObject                        = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $entity]);
            }

            if ($entityObject === null) {
                $this->logger->warning("Could not find an Entity with id or reference = $entity during searchObjects()");
                continue;
            }

            // Only allow ordering & filtering on attributes with sortable = true & searchable = true (respectively).
            // $orderError = $this->handleOrderCheck($entityObject, $completeFilter['_order'] ?? null);
            // $filterError = $this->handleFilterCheck($entityObject, $filterCheck ?? null);
            // todo: for now we do not check for allowed filters and ordering, because this breaks things.
            $orderError  = null;
            $filterError = null;
            if (empty($orderError) === true && empty($filterError) === true) {
                continue;
            }

            $errorData[$entityObject->getName()]['order']  = $orderError ?? null;
            $errorData[$entityObject->getName()]['filter'] = $filterError ?? null;
        }//end foreach

        if (empty($errorData) === false) {
            $this->logger->warning('There are some errors in your query parameters', $errorData);

            return [
                'message' => 'There are some errors in your query parameters',
                'type'    => 'error',
                'path'    => 'searchObjects',
            // todo: get path from session?
                'data'    => $errorData,
            ];
        }

        return null;

    }//end handleEntities()

    /**
     * Will check if we are allowed to order with the given $order query param.
     * Uses ObjectEntityRepository->getOrderParameters() to check if we are allowed to order, see eavService->handleSearch() $orderCheck.
     *
     * @param Entity           $entity The entity we are going to check for allowed attributes to order on.
     * @param mixed|array|null $order  The order query param, should be an array or null. (but could be a string)
     *
     * @return string|null Returns null if given order query param is correct/allowed or when it is not present. Else an error message.
     */
    private function handleOrderCheck(Entity $entity, $order): ?string
    {
        if (empty($order)) {
            return null;
        }

        // This checks for each attribute of the given Entity if $attribute->getSortable() is true.
        $orderCheck = $this->entityManager->getRepository('App:ObjectEntity')->getOrderParameters($entity, '', 1, true);

        if (is_array($order) === false) {
            $orderCheckStr = implode(', ', $orderCheck);
            $message       = 'Please give an attribute to order on. Like this: ?_order[attributeName]=desc/asc. Supported order query parameters: '.$orderCheckStr;
        }

        if (is_array($order) === true && count($order) > 1) {
            $message = 'Only one order query param at the time is allowed.';
        }

        if (is_array($order) === true && in_array(strtoupper(array_values($order)[0]), ['DESC', 'ASC']) === false) {
            $message = 'Please use desc or asc as value for your order query param, not: '.array_values($order)[0];
        }

        if (is_array($order) === true && in_array(array_keys($order)[0], $orderCheck) === false) {
            $orderCheckStr = implode(', ', $orderCheck);
            $message       = 'Unsupported order query parameter ('.array_keys($order)[0].'). Supported order query parameters: '.$orderCheckStr;
        }

        if (isset($message) === true) {
            return $message;
        }

        return null;

    }//end handleOrderCheck()

    /**
     * Will check if we are allowed to filter on the given $filters in the query params.
     * Uses ObjectEntityRepository->getFilterParameters() to check if we are allowed to filter, see eavService->handleSearch() $filterCheck.
     *
     * @param Entity     $entity  The entity we are going to check for allowed attributes to filter on.
     * @param array|null $filters The filters from query params.
     *
     * @return string|null Returns null if all filters are allowed or if none are present. Else an error message.
     */
    private function handleFilterCheck(Entity $entity, ?array $filters): ?string
    {
        if (empty($filters)) {
            return null;
        }

        // This checks for each attribute of the given Entity if $attribute->getSearchable() is true.
        $filterCheck = $this->entityManager->getRepository('App:ObjectEntity')->getFilterParameters($entity, '', 1, true);

        foreach ($filters as $param => $value) {
            if (in_array($param, $filterCheck) === false) {
                $unsupportedParams = isset($unsupportedParams) === false ? $param : "$unsupportedParams, $param";
            }
        }

        if (isset($unsupportedParams)) {
            $filterCheckStr = implode(', ', $filterCheck);

            return 'Unsupported queryParameters ('.$unsupportedParams.'). Supported queryParameters: '.$filterCheckStr;
        }

        return null;

    }//end handleFilterCheck()

    /**
     * Adds search filter to the query on MongoDB. Will use given $search string to search on entire object, unless
     * the _search query is present in $completeFilter query params, then we use that instead.
     * _search query param supports filtering on specific properties with ?_search[property1,property2]=value.
     *
     * @param array       $filter
     * @param array       $completeFilter
     * @param string|null $search
     *
     * @return void
     */
    private function handleSearch(array &$filter, array $completeFilter, ?string $search)
    {
        if (isset($completeFilter['_search']) && !empty($completeFilter['_search'])) {
            $search = $completeFilter['_search'];
        }

        if (empty($search) === true) {
            return;
        }

        // Normal search on every property with type text (includes strings)
        if (is_string($search) === true) {
            $filter['$text']
                = [
                    '$search'        => $search,
                    '$caseSensitive' => false,
                ];
        }
        // _search query with specific properties in the [method] like this: ?_search[property1,property2]=value
        else if (is_array($search) === true) {
            $searchRegex = preg_replace('/([^A-Za-z0-9\s])/', '\\\\$1', $search[array_key_first($search)]);
            if (empty($searchRegex)) {
                return;
            }

            $searchRegex = [
                '$regex'   => $searchRegex,
                '$options' => 'i',
            ];
            $properties  = explode(',', array_key_first($search));
            foreach ($properties as $property) {
                // todo: we might want to check if we are allowed to filter on this property? with $this->handleFilterCheck;
                $filter['$or'][][$property] = $searchRegex;
            }
        }

    }//end handleSearch()

    /**
     * Decides the pagination values.
     *
     * @param int   $limit   The resulting limit
     * @param int   $start   The resulting start value
     * @param array $filters The filters
     *
     * @return array
     */
    public function setPagination(&$limit, &$start, array $filters): array
    {
        if (isset($filters['_limit'])) {
            $limit = (int) $filters['_limit'];
        } else {
            $limit = 30;
        }

        if (isset($filters['_start']) || isset($filters['_offset'])) {
            $start = isset($filters['_start']) ? (int) $filters['_start'] : (int) $filters['_offset'];
        } else if (isset($filters['_page'])) {
            $start = (((int) $filters['_page'] - 1) * $limit);
        } else {
            $start = 0;
        }

        return $filters;

    }//end setPagination()

    /**
     * Adds pagination variables to an array with the results we found with searchObjects().
     *
     * @param array $filter
     * @param array $results
     * @param int   $total
     *
     * @return array the result with pagination.
     */
    public function handleResultPagination(array $filter, array $results, int $total = 0): array
    {
        $start = isset($filter['_start']) && is_numeric($filter['_start']) ? (int) $filter['_start'] : 0;
        $limit = isset($filter['_limit']) && is_numeric($filter['_limit']) ? (int) $filter['_limit'] : 30;
        $page  = isset($filter['_page']) && is_numeric($filter['_page']) ? (int) $filter['_page'] : 1;

        // Lets build the page & pagination
        if ($start > 1) {
            $offset = ($start - 1);
        } else {
            $offset = (($page - 1) * $limit);
        }

        $pages = ceil($total / $limit);

        return [
            'results' => $results,
            'count'   => count($results),
            'limit'   => $limit,
            'total'   => $total,
            'offset'  => $offset,
            'page'    => (floor($offset / $limit) + 1),
            'pages'   => $pages == 0 ? 1 : $pages,
        ];

    }//end handleResultPagination()

    /**
     * Put a single endpoint into the cache.
     *
     * @param Endpoint $endpoint
     *
     * @return Endpoint
     */
    public function cacheEndpoint(Endpoint $endpoint): Endpoint
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return $endpoint;
        }

        if (isset($this->style)) {
            $this->style->writeln('Start caching endpoint '.$endpoint->getId()->toString().' with name: '.$endpoint->getName());
        }

        $updatedEndpoint = $this->entityManager->getRepository('App:Endpoint')->find($endpoint->getId());
        if ($updatedEndpoint !== null) {
            $endpoint = $updatedEndpoint;
        } else if (isset($this->style)) {
            $this->style->writeln('Could not find an Endpoint with id: '.$endpoint->getId()->toString());
        }

        $collection = $this->client->endpoints->json;

        $endpointArray        = $this->serializer->normalize($endpoint, null, [AbstractNormalizer::IGNORED_ATTRIBUTES => ['object', 'inversedBy']]);
        $endpointArray['_id'] = $endpointArray['id'];

        if ($collection->findOneAndReplace(
            ['id' => $endpoint->getId()->toString()],
            $endpointArray,
            ['upsert' => true]
        )
        ) {
            isset($this->style) === true && $this->style->writeln('Updated endpoint '.$endpoint->getId()->toString().' to cache');
        } else {
            isset($this->style) === true && $this->style->writeln('Wrote object '.$endpoint->getId()->toString().' to cache');
        }

        return $endpoint;

    }//end cacheEndpoint()

    /**
     * Removes an endpoint from the cache.
     *
     * @param Endpoint $endpoint
     *
     * @return void
     */
    public function removeEndpoint(Endpoint $endpoint): void
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return;
        }

        $collection = $this->client->endpoints->json;

        $collection->findOneAndDelete(['id' => $endpoint->getId()->toString()]);

    }//end removeEndpoint()

    /**
     * Get a single endpoint from the cache.
     *
     * @param Uuid $identification
     *
     * @return array|null
     */
    public function getEndpoint(string $identification): ?array
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return [];
        }

        $collection = $this->client->endpoints->json;

        if ($object = $collection->findOne(['id' => $identification])) {
            return $object;
        }

        if ($object = $this->entityManager->getRepository('App:Endpoint')->find($identification)) {
            return $this->serializer->normalize($object);
        }

        return null;

    }//end getEndpoint()

    public function getEndpoints(array $filter): ?Endpoint
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return [];
        }

        $collection = $this->client->endpoints->json;

        if (isset($filter['path'])) {
            $path             = $filter['path'];
            $filter['$where'] = "\"$path\".match(this.pathRegex)";
            unset($filter['path']);
        }

        if (isset($filter['method'])) {
            $method        = $filter['method'];
            $filter['$or'] = [
                ['methods' => ['$in' => [$method]]],
                ['method' => $method],
            ];
            unset($filter['method']);
        }

        $endpoints = $collection->find($filter)->toArray();

        if (count($endpoints) > 1) {
            throw new NonUniqueResultException();
        } else if (count($endpoints) == 1) {
            // @TODO: We actually want to use the denormalizer, but that breaks on not setting ids
            return $this->entityManager->find('App\Entity\Endpoint', $endpoints[0]['id']);
        } else {
            return null;
        }

    }//end getEndpoints()

    /**
     * Put a single schema into the cache.
     *
     * @param Entity $entity
     *
     * @return Entity
     */
    public function cacheShema(Entity $entity): Entity
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return $entity;
        }

        $collection = $this->client->schemas->json;

        // Remap the array
        $array              = $entity->toSchema(null);
        $array['reference'] = $array['$id'];
        $array['schema']    = $array['$schema'];
        unset($array['$id']);
        unset($array['$schema']);

        /*
            var_dump($array);


            if ($collection->findOneAndReplace(
            ['_id' => $entity->getID()],
            $entity->toSchema(null),
            ['upsert' => true]
            )) {
            $this->style->writeln('Updated object '.$entity->getId().' to cache');
            } else {
            $this->style->writeln('Wrote object '.$entity->getId().' to cache');
            }
        */

        return $entity;

    }//end cacheShema()

    /**
     * Removes an Schema from the cache.
     *
     * @param Entity $entity
     *
     * @return void
     */
    public function removeSchema(Entity $entity): void
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return;
        }

        $collection = $this->client->schemas->json;

    }//end removeSchema()

    /**
     * Get a single schema from the cache.
     *
     * @param Uuid $identification
     *
     * @return array|null
     */
    public function getSchema(Uuid $identification): ?array
    {
        // Backwards compatablity
        if (isset($this->client) === false) {
            return [];
        }

        $collection = $this->client->schemas->json;

    }//end getSchema()
}//end class
