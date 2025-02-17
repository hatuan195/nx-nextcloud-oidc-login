<?php

declare(strict_types=1);

namespace OCA\NxOIDCLogin\AppInfo;

use OC\AppFramework\Utility\ControllerMethodReflector;
use OCA\NxOIDCLogin\OIDCLoginOption;
use OCA\NxOIDCLogin\WebDAV\BasicAuthBackend;
use OCA\NxOIDCLogin\WebDAV\BearerAuthBackend;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;

class Application extends App implements IBootstrap
{
    protected IURLGenerator $url;
    protected IL10N $l;
    protected IConfig $config;

    private $appName = 'nx_nextcloud_oidc_login';

    public function __construct()
    {
        parent::__construct($this->appName);
    }

    public function register(IRegistrationContext $context): void
    {
        $context->registerAlternativeLogin(OIDCLoginOption::class);

        $context->registerEventListener(
            'OCA\DAV\Connector\Sabre::authInit',
            BearerAuthBackend::class
        );

        $context->registerEventListener(
            'OCA\DAV\Connector\Sabre::addPlugin',
            BearerAuthBackend::class
        );

        $context->registerEventListener(
            'OCA\DAV\Connector\Sabre::authInit',
            BasicAuthBackend::class
        );

        $context->registerEventListener(
            'OCA\DAV\Connector\Sabre::addPlugin',
            BasicAuthBackend::class
        );
    }

    public function boot(IBootContext $context): void
    {
        $container = $context->getAppContainer();
        $this->l = $container->get(IL10N::class);
        $this->url = $container->get(IURLGenerator::class);
        $this->config = $container->get(IConfig::class);

        /** @var IRequest */
        $request = $container->get(IRequest::class);

        // Check if automatic redirection is enabled
        $useLoginRedirect = $this->config->getSystemValue('oidc_login_auto_redirect', false);

        // Check if alternative login page is enabled
        $altLoginPage = $this->config->getSystemValue('oidc_login_alt_login_page', false);

        // URL for login without redirecting forcefully, false if we are not doing that
        $noRedirLoginUrl = $useLoginRedirect ? $this->url->linkToRouteAbsolute('core.login.showLoginForm').'?noredir=1' : false;

        // Get logged in user's session
        $userSession = $container->get(IUserSession::class);
        $session = $container->get(ISession::class);

        // Check if the user is logged in
        if ($userSession->isLoggedIn()) {
            // Halt processing if not logged in with OIDC
            if (!$session->exists('is_oidc')) {
                return;
            }

            // Disable password confirmation for user
            $session->set('last-password-confirm', $container->get(ITimeFactory::class)->getTime());

            /* Redirect to logout URL on completing logout
               If do not have logout URL, go to noredir on logout */
            if ($logoutUrl = $session->get('oidc_logout_url', $noRedirLoginUrl)) {
                $userSession->listen('\OC\User', 'postLogout', function () use ($logoutUrl, $session) {
                    // Do nothing if this is a CORS request
                    if ($this->getContainer()->get(ControllerMethodReflector::class)->hasAnnotation('CORS')) {
                        return;
                    }

                    // Properly close the session and clear the browsers storage data before
                    // redirecting to the logout url.
                    $session->set('clearingExecutionContexts', '1');
                    $session->close();
                    if (!$this->isApiRequest()) {
                        header('Clear-Site-Data: "cache", "storage"');
                        header('Location: '.$logoutUrl);

                        exit;
                    }
                });
            }

            // Hide password change form
            if ($this->config->getSystemValue('oidc_login_hide_password_form', false)) {
                Util::addStyle($this->appName, 'oidc.hidepasswordform');
            }

            return;
        }

        // Redirect automatically or show alt login page
        if (\array_key_exists('REQUEST_METHOD', $_SERVER)
            && 'GET' === $_SERVER['REQUEST_METHOD']
            && '/login' === $request->getPathInfo()
            && null === $request->getParam('noredir')
            && null === $request->getParam('user')
        ) {
            // Set redirection URL
            $redir = $request->getParam('redirect_url');
            if (null !== $redir && !empty($redir)) {
                $session->set('oidc_redir', $redir);
            } else {
                $session->set('oidc_redir', '/');
            }

            // Get URLs
            $loginLink = OIDCLoginOption::getLoginLink($request, $this->url);

            // Force redirect
            if ($useLoginRedirect) {
                header('Location: '.$loginLink);

                exit;
            }

            // Alt login page
            if ($altLoginPage) {
                $OIDC_LOGIN_URL = $loginLink; // available in alt login page
                header_remove('content-security-policy');

                require $altLoginPage;

                exit;
            }
        }
    }

    public function isApiRequest()
    {
        return isset($_SERVER['HTTP_ACCEPT']) && false !== strpos($_SERVER['HTTP_ACCEPT'], 'application/json');
    }
}
