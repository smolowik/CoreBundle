<?php

// src/Subscriber/DatabaseActivitySubscriber.php
namespace CommonGateway\CoreBundle\Subscriber;

use App\Entity\ObjectEntity;
use App\Event\ActionEvent;
use CommonGateway\CoreBundle\Service\CacheService;
use Doctrine\Bundle\DoctrineBundle\EventSubscriber\EventSubscriberInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Monolog\Logger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Provides commongateway events and logs based on doctrine events.
 *
 * This subscriber turns doctrine events into common gateway action events an provides those to listeners.
 * As a second function it also creates appropriate logging for doctrine events
 *
 * @Author Ruben van der Linde <ruben@conduction.nl>, Robert Zondervan <robert@conduction.nl>
 *
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @category Subscriber
 */
class DoctrineToGatewayEventSubscriber implements EventSubscriberInterface
{

    private CacheService $cacheService;

    private EntityManagerInterface $entityManager;

    private SessionInterface $session;

    private EventDispatcherInterface $eventDispatcher;

    private Logger $logger;

    /**
     * Load requiered services, schould not be aprouched directly.
     *
     * @param CacheService             $cacheService
     * @param EntityManagerInterface   $entityManager
     * @param SessionInterface         $session
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        CacheService $cacheService,
        EntityManagerInterface $entityManager,
        SessionInterface $session,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->cacheService    = $cacheService;
        $this->entityManager   = $entityManager;
        $this->session         = $session;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger          = new Logger('object');

    }//end __construct()

    // this method can only return the event names; you cannot define a
    // custom method name to execute when each event triggers
    public function getSubscribedEvents(): array
    {
        return [
            Events::preRemove,
            Events::prePersist,
            Events::preUpdate,
            Events::postPersist,
            Events::postUpdate,
            Events::postRemove,
            Events::postLoad,
            Events::preFlush,
            Events::postFlush,
        ];

    }//end getSubscribedEvents()

    /**
     * Deleting object from database.
     *
     * @param LifecycleEventArgs $args
     *
     * @return void
     */
    public function preRemove(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        // if this subscriber only applies to certain entity types,
        if (!$object instanceof ObjectEntity) {
            return;
        }

        // Write the log
        $this->logger->info(
            'Deleting object from database',
            [
                'object' => $object->getId(),
                'entity' => $object->getEntity()->getId(),
            ]
        );

        // Throw the event
        $event = new ActionEvent('commongateway.action.event', ['object' => $object], 'commongateway.object.pre.delete');
        $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

    }//end preRemove()

    /**
     * Creating object in database.
     *
     * @param LifecycleEventArgs $args
     *
     * @return void
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        // if this subscriber only applies to certain entity types,
        if (!$object instanceof ObjectEntity) {
            return;
        }

        // Write the log
        $this->logger->info(
            'Creating object in database',
            [
                'object' => $object->getId(),
                'entity' => $object->getEntity()->getId(),
            ]
        );

        // Throw the event
        $event = new ActionEvent('commongateway.action.event', ['object' => $object], 'commongateway.object.pre.create');
        $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

    }//end prePersist()

    /**
     * Updating object to database.
     *
     * @param LifecycleEventArgs $args
     *
     * @return void
     */
    public function preUpdate(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        // if this subscriber only applies to certain entity types,
        if (!$object instanceof ObjectEntity) {
            return;
        }

        // Write the log
        $this->logger->info(
            'Updating object to database',
            [
                'object' => $object->getId(),
                'entity' => $object->getEntity()->getId(),
            ]
        );

        // Throw the event
        $event = new ActionEvent('commongateway.action.event', ['object' => $object], 'commongateway.object.pre.update');
        $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

    }//end preUpdate()

    /**
     * Deleted object from database.
     *
     * @param LifecycleEventArgs $args
     *
     * @return void
     */
    public function postRemove(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        // if this subscriber only applies to certain entity types,
        if (!$object instanceof ObjectEntity) {
            return;
        }

        // Write the log
        $this->logger->info(
            'Deleted object from database',
            []
        );

        // Throw the event
        $event = new ActionEvent('commongateway.action.event', [], 'commongateway.object.post.delete');
        $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

    }//end postRemove()

    /**
     * Created object in database.
     *
     * @param LifecycleEventArgs $args
     *
     * @return void
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        // if this subscriber only applies to certain entity types,
        if (!$object instanceof ObjectEntity) {
            return;
        }

        // Write the log
        $this->logger->info(
            'Created object in database',
            [
                'object' => $object->getId(),
                'entity' => $object->getEntity()->getId(),
            ]
        );

        // Throw the event
        $event = new ActionEvent('commongateway.action.event', ['object' => $object], 'commongateway.object.post.create');
        $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

    }//end postPersist()

    /**
     * Updated object in database.
     *
     * @param LifecycleEventArgs $args
     *
     * @return void
     */
    public function postUpdate(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        // if this subscriber only applies to certain entity types,
        if (!$object instanceof ObjectEntity) {
            return;
        }

        // Write the log
        $this->logger->info(
            'Updated object in database',
            [
                'object' => $object->getId(),
                'entity' => $object->getEntity()->getId(),
            ]
        );

        // Throw the event
        $event = new ActionEvent('commongateway.action.event', ['object' => $object], 'commongateway.object.post.update');
        $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

    }//end postUpdate()

    /**
     * Read object from database.
     *
     * @param LifecycleEventArgs $args
     *
     * @return void
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $object = $args->getObject();

        // if this subscriber only applies to certain entity types,
        if (!$object instanceof ObjectEntity) {
            return;
        }

        // Write the log
        $this->logger->info(
            'Read object from database',
            [
                'object' => $object->getId(),
            ]
        );

        // Throw the event
        $event = new ActionEvent('commongateway.action.event', ['object' => $object], 'commongateway.object.post.read');
        $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

    }//end postLoad()

    /**
     * Flushing entity manager.
     *
     * @param LifecycleEventArgs $args
     *
     * @return void
     */
    public function preFlush(PreFlushEventArgs $args): void
    {
        // Write the log
        $this->logger->info(
            'Flushing entity manager',
            []
        );

        // Throw the event
        $event = new ActionEvent('commongateway.action.event', [], 'commongateway.object.pre.flush');
        $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

    }//end preFlush()

    /**
     * Flushed entity manager.
     *
     * @param LifecycleEventArgs $args
     *
     * @return void
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        // Write the log
        $this->logger->info(
            'Flushed entity manager',
            []
        );

        // Throw the event
        $event = new ActionEvent('commongateway.action.event', [], 'commongateway.object.post.flush');
        $this->eventDispatcher->dispatch($event, 'commongateway.action.event');

    }//end postFlush()
}//end class
