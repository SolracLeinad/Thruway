<?php

namespace Thruway;

use Thruway\Authentication\AllPermissiveAuthorizationManager;
use Thruway\Authentication\AnonymousAuthenticator;
use Thruway\Authentication\AuthorizationManagerInterface;
use Thruway\Common\Utils;
use Thruway\Event\LeaveRealmEvent;
use Thruway\Event\MessageEvent;
use Thruway\Exception\InvalidRealmNameException;
use Thruway\Logging\Logger;
use Thruway\Manager\ManagerDummy;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\GoodbyeMessage;
use Thruway\Message\Message;
use Thruway\Message\PublishMessage;
use Thruway\Message\WelcomeMessage;
use Thruway\Module\RealmModuleInterface;
use Thruway\Role\Broker;
use Thruway\Role\Dealer;
use Thruway\Transport\DummyTransport;

/**
 * Class Realm
 *
 * @package Thruway
 */
class Realm implements RealmModuleInterface
{
    /** @var RealmModuleInterface[] */
    private $modules = [];

    /** @var string */
    private $realmName;

    /** @var \SplObjectStorage */
    private $sessions;

    /** @var \Thruway\Role\AbstractRole[] */
    private $roles;

    /** @var \Thruway\Manager\ManagerInterface */
    private $manager;

    /** @var \Thruway\Role\Broker */
    private $broker;

    /** @var \Thruway\Role\Dealer */
    private $dealer;

    /** @var AuthorizationManagerInterface */
    private $authorizationManager;

    /**
     * The metaSession is used as a dummy session to send meta events from
     *
     * @var \Thruway\Session
     */
    private $metaSession;

    /**
     * Constructor
     *
     * @param string $realmName
     */
    public function __construct($realmName)
    {
        $this->realmName = $realmName;
        $this->sessions  = new \SplObjectStorage();
        $this->broker    = new Broker();
        $this->dealer    = new Dealer();
        $this->roles     = [$this->broker, $this->dealer];

        $this->addModule($this->broker);
        $this->addModule($this->dealer);
        $this->addModule(new AnonymousAuthenticator());

        $this->setAuthorizationManager(new AllPermissiveAuthorizationManager());
        $this->setManager(new ManagerDummy());
    }

    /**
     * Events that we'll be listening on
     *
     * @return array
     */
    public function getSubscribedRealmEvents()
    {
        return [

          "GoodbyeMessageEvent"      => ["handleGoodbyeMessage", 10],
          "AbortMessageEvent"        => ["handleAbortMessage", 10],
          "AuthenticateMessageEvent" => ["handleAuthenticateMessage", 10],
          "LeaveRealm"               => ["handleLeaveRealm", 10],
          "SendWelcomeMessageEvent"  => ["handleSendWelcomeMessage", 10],
        ];
    }

    /**
     * @param \Thruway\Event\MessageEvent $event
     * @throws \Thruway\Exception\InvalidRealmNameException
     */
    public function handleSendWelcomeMessage(MessageEvent $event)
    {
        $this->processSendWelcome($event->session, $event->message);
    }

    /**
     * @param \Thruway\Event\MessageEvent $event
     */
    public function handleGoodbyeMessage(MessageEvent $event)
    {
        $this->processGoodbye($event->session, $event->message);
    }

    /**
     * @param \Thruway\Event\MessageEvent $event
     */
    public function handleAbortMessage(MessageEvent $event)
    {
        $this->processAbort($event->session, $event->message);
    }

    /**
     * @param \Thruway\Event\MessageEvent $event
     */
    public function handleAuthenticateMessage(MessageEvent $event)
    {
        $this->processAuthenticate($event->session, $event->message);
    }

    /**
     * @param \Thruway\Event\LeaveRealmEvent $event
     */
    public function handleLeaveRealm(LeaveRealmEvent $event)
    {
        $this->leave($event->session);
    }

    /**
     * @param \Thruway\Session $session
     * @param \Thruway\Message\Message $msg
     */
    public function processGoodbye(Session $session, Message $msg)
    {
        Logger::info($this, "Received a GoodBye, so shutting the session down");
        $session->sendMessage(new GoodbyeMessage(new \stdClass(), "wamp.error.goodbye_and_out"));
        $session->shutdown();
    }

    /**
     * Process AbortMessage
     *
     * @param \Thruway\Session $session
     * @param \Thruway\Message\Message $msg
     */
    private function processAbort(Session $session, Message $msg)
    {
        $session->shutdown();
    }

    /**
     * Process HelloMessage
     *
     * @param \Thruway\Session $session
     * @param \Thruway\Message\WelcomeMessage $msg
     * @throws InvalidRealmNameException
     */
    private function processSendWelcome(Session $session, WelcomeMessage $msg)
    {

        $details = $session->getHelloMessage()->getDetails();

        if (is_object($details) && isset($details->roles) && is_object($details->roles)) {
            $session->setRoleFeatures($details->roles);
        }

        $session->setState(Session::STATE_UP); // this should probably be after authentication

    }


    /**
     * Process AuthenticateMessage
     *
     * @param \Thruway\Session $session
     * @param \Thruway\Message\AuthenticateMessage $msg
     */
    private function processAuthenticate(Session $session, AuthenticateMessage $msg)
    {
        $session->abort(new \stdClass(), "thruway.error.internal");
        Logger::error($this, "Authenticate sent to realm without auth manager.");
    }

    /**
     * Get list sessions
     *
     * @return array
     */
    public function managerGetSessions()
    {
        $theSessions = [];

        /* @var $session \Thruway\Session */
        foreach ($this->sessions as $session) {

            $sessionRealm = null;
            // just in case the session is not in a realm yet
            if ($session->getRealm() !== null) {
                $sessionRealm = $session->getRealm()->getRealmName();
            }

            if ($session->getAuthenticationDetails() !== null) {
                $authDetails = $session->getAuthenticationDetails();
                $auth        = [
                  "authid"     => $authDetails->getAuthId(),
                  "authmethod" => $authDetails->getAuthMethod()
                ];
            } else {
                $auth = new \stdClass();
            }

            $theSessions[] = [
              "id"           => $session->getSessionId(),
              "transport"    => $session->getTransport()->getTransportDetails(),
              "messagesSent" => $session->getMessagesSent(),
              "sessionStart" => $session->getSessionStart(),
              "realm"        => $sessionRealm,
              "auth"         => $auth
            ];
        }

        return $theSessions;
    }

    /**
     * Get realm name
     *
     * @return mixed
     */
    public function getRealmName()
    {
        return $this->realmName;
    }

    /**
     * Process on session leave
     *
     * @param \Thruway\Session $session
     */
    public function leave(Session $session)
    {

        Logger::debug($this, "Leaving realm {$session->getRealm()->getRealmName()}");

//        // TODO: move to module
//        if ($this->getAuthenticationManager() !== null) {
//            $this->getAuthenticationManager()->onSessionClose($session);
//        }

        $this->sessions->detach($session);
    }

    /**
     * Set manager
     *
     * @param \Thruway\Manager\ManagerInterface $manager
     */
    public function setManager($manager)
    {
        $this->manager = $manager;

        $this->broker->setManager($manager);
        $this->dealer->setManager($manager);

        $manager->addCallable("realm.{$this->getRealmName()}.registrations", function () {
            return $this->dealer->managerGetRegistrations();
        });
    }

    /**
     * Get manager
     *
     * @return \Thruway\Manager\ManagerInterface
     */
    public function getManager()
    {
        return $this->manager;
    }

    /**
     * @return AuthorizationManagerInterface
     */
    public function getAuthorizationManager()
    {
        return $this->authorizationManager;
    }

    /**
     * @param AuthorizationManagerInterface $authorizationManager
     */
    public function setAuthorizationManager($authorizationManager)
    {
        $this->authorizationManager = $authorizationManager;
    }

    /**
     * Get list session
     *
     * @return \SplObjectStorage
     */
    public function getSessions()
    {
        return $this->sessions;
    }

    /**
     * Get broker
     *
     * @return \Thruway\Role\Broker
     */
    public function getBroker()
    {
        return $this->broker;
    }

    /**
     * Get dealer
     *
     * @return \Thruway\Role\Dealer
     */
    public function getDealer()
    {
        return $this->dealer;
    }

    /**
     * Publish meta
     *
     * @param string $topicName
     * @param mixed $arguments
     * @param mixed $argumentsKw
     * @param mixed $options
     */
    public function publishMeta($topicName, $arguments, $argumentsKw = null, $options = null)
    {
        if ($this->metaSession === null) {
            // setup a new metaSession
            $s                 = new Session(new DummyTransport());
            $this->metaSession = $s;
        }

        $messageEvent = new MessageEvent($this->metaSession,
          new PublishMessage(
            Utils::getUniqueId(),
            $options,
            $topicName,
            $arguments,
            $argumentsKw
          ));
        $this->getBroker()->handlePublishMessage($messageEvent);
    }

    /**
     * @param \Thruway\Module\RealmModuleInterface $module
     */
    public function addModule(RealmModuleInterface $module)
    {
        $this->modules[] = $module;
    }

    /**
     * @param \Thruway\Session $session
     */
    public function addSession(Session $session)
    {
        $this->sessions->attach($session);
        $session->setRealm($this);
        $session->dispatcher->addRealmSubscriber($this);
        foreach ($this->modules as $module) {
            $session->dispatcher->addRealmSubscriber($module);
        }
    }
}
