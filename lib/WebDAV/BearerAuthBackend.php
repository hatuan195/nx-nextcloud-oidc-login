<?php

namespace OCA\NxOIDCLogin\WebDAV;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\NxOIDCLogin\Service\LoginService;
use OCP\Defaults;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserSession;
use OCP\SabrePluginEvent;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Auth\Backend\AbstractBearer;
use Sabre\DAV\Auth\Plugin;

class BearerAuthBackend extends AbstractBearer implements IEventListener
{
    private string $appName;
    private IUserSession $userSession;
    private ISession $session;
    private IConfig $config;
    private LoggerInterface $logger;
    private LoginService $loginService;
    private string $principalPrefix;

    /**
     * @param string $principalPrefix
     */
    public function __construct(
        string $appName,
        IUserSession $userSession,
        ISession $session,
        IConfig $config,
        LoggerInterface $logger,
        LoginService $loginService,
        $principalPrefix = 'principals/users/'
    ) {
        $this->appName = $appName;
        $this->userSession = $userSession;
        $this->session = $session;
        $this->config = $config;
        $this->logger = $logger;
        $this->loginService = $loginService;
        $this->principalPrefix = $principalPrefix;

        // setup realm
        $defaults = new Defaults();
        $this->realm = $defaults->getName();
    }

    public function validateBearerToken($bearerToken)
    {
        \OC_Util::setupFS(); // login hooks may need early access to the filesystem

        if (!$this->userSession->isLoggedIn()) {
            try {
                $this->login($bearerToken);
            } catch (\Exception $e) {
                $this->logger->debug("WebDAV bearer token validation failed with: {$e->getMessage()}", ['app' => $this->appName]);

                return false;
            }
        }

        if ($this->userSession->isLoggedIn()) {
            return $this->setupUserFs($this->userSession->getUser()->getUID());
        }

        return false;
    }

    /**
     * Implements IEventListener::handle.
     * Registers this class as an authentication backend with Sabre WebDav.
     */
    public function handle(Event $event): void
    {
        if (!$event instanceof SabrePluginAuthInitEvent
            && !$event instanceof SabrePluginEvent) {
            return;
        }

        $authPlugin = $event->getServer()->getPlugin('auth');
        if ($authPlugin instanceof Plugin) {
            $webdav_enabled = $this->config->getSystemValue('oidc_login_webdav_enabled', false);

            if ($webdav_enabled) {
                $authPlugin->addBackend($this);
            }
        }
    }

    private function setupUserFs(string $userId)
    {
        \OC_Util::setupFS($userId);
        $this->session->close();

        return $this->principalPrefix.$userId;
    }

    /**
     * Tries to log in a user based on the given $bearerToken.
     *
     * @param string $bearerToken an OIDC JWT bearer token
     */
    private function login(string $bearerToken)
    {
        $client = $this->loginService->createOIDCClient();
        if (null === $client) {
            throw new \Exception("Couldn't create OIDC client!");
        }

        $client->validateBearerToken($bearerToken);

        $profile = $client->getTokenProfile($bearerToken);

        $this->loginService->login($profile);
    }
}
