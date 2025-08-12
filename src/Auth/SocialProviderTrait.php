<?php

namespace Seolinkmap\Waasup\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Seolinkmap\Waasup\Auth\Providers\{GithubProvider, GoogleProvider, LinkedinProvider};

trait SocialProviderTrait
{
    /**
     * Handle Google OAuth callback for verification
     *
     * @param Request $request
     * @param Response $response
     * @return Response Consent screen or auth form with error
     */
    public function handleGoogleVerifyCallback(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['oauth_verification_mode']) || !isset($_SESSION['oauth_request'])) {
            return $this->errorResponse('invalid_request', 'Invalid callback state');
        }

        unset($_SESSION['oauth_verification_mode']);

        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;

        if (!$code) {
            return $this->renderOAuthVerification(['error' => 'Google authentication failed']);
        }

        $result = $this->verifyGoogleCallback($code);
        if (!$result) {
            return $this->renderOAuthVerification(['error' => 'Google authentication failed']);
        }

        $_SESSION['oauth_user'] = $result;
        return $this->renderConsentScreen();
    }

    /**
     * Handle LinkedIn OAuth callback for verification
     *
     * @param Request $request
     * @param Response $response
     * @return Response Consent screen or auth form with error
     */
    public function handleLinkedinVerifyCallback(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['oauth_verification_mode']) || !isset($_SESSION['oauth_request'])) {
            return $this->errorResponse('invalid_request', 'Invalid callback state');
        }

        unset($_SESSION['oauth_verification_mode']);

        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;

        if (!$code || !$this->validateState($state)) {
            return $this->renderOAuthVerification(['error' => 'LinkedIn authentication failed']);
        }

        $result = $this->verifyLinkedinCallback($code, $state);
        if (!$result) {
            return $this->renderOAuthVerification(['error' => 'LinkedIn authentication failed']);
        }

        $_SESSION['oauth_user'] = $result;
        return $this->renderConsentScreen();
    }

    /**
     * Handle GitHub OAuth callback for verification
     *
     * @param Request $request
     * @param Response $response
     * @return Response Consent screen or auth form with error
     */
    public function handleGithubVerifyCallback(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['oauth_verification_mode']) || !isset($_SESSION['oauth_request'])) {
            return $this->errorResponse('invalid_request', 'Invalid callback state');
        }

        unset($_SESSION['oauth_verification_mode']);

        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;

        if (!$code || !$this->validateState($state)) {
            return $this->renderOAuthVerification(['error' => 'GitHub authentication failed']);
        }

        $result = $this->verifyGithubCallback($code, $state);
        if (!$result) {
            return $this->renderOAuthVerification(['error' => 'GitHub authentication failed']);
        }

        $_SESSION['oauth_user'] = $result;
        return $this->renderConsentScreen();
    }


    /**
     * Initialize social auth providers from configuration
     */
    private function initializeSocialProviders(): void
    {
        $providers = $this->config['oauth']['auth_server']['providers'] ?? [];

        if (isset($providers['google'])) {
            $googleConfig = $providers['google'];
            if ($googleConfig['client_id'] && $googleConfig['client_secret'] && $googleConfig['redirect_uri']) {
                $this->googleProvider = new GoogleProvider(
                    $googleConfig['client_id'],
                    $googleConfig['client_secret'],
                    $googleConfig['redirect_uri']
                );
            }
        }

        if (isset($providers['linkedin'])) {
            $linkedinConfig = $providers['linkedin'];
            if ($linkedinConfig['client_id'] && $linkedinConfig['client_secret'] && $linkedinConfig['redirect_uri']) {
                $this->linkedinProvider = new LinkedinProvider(
                    $linkedinConfig['client_id'],
                    $linkedinConfig['client_secret'],
                    $linkedinConfig['redirect_uri']
                );
            }
        }

        if (isset($providers['github'])) {
            $githubConfig = $providers['github'];
            if ($githubConfig['client_id'] && $githubConfig['client_secret'] && $githubConfig['redirect_uri']) {
                $this->githubProvider = new GithubProvider(
                    $githubConfig['client_id'],
                    $githubConfig['client_secret'],
                    $githubConfig['redirect_uri']
                );
            }
        }
    }

    /**
     * Redirect to Google OAuth
     */
    private function redirectToGoogle(): Response
    {
        if (!$this->googleProvider) {
            return $this->renderOAuthVerification(['error' => 'Google authentication not configured']);
        }

        $authUrl = $this->googleProvider->getAuthUrl();
        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $authUrl);
    }

    /**
     * Redirect to LinkedIn OAuth
     */
    private function redirectToLinkedin(): Response
    {
        if (!$this->linkedinProvider) {
            return $this->renderOAuthVerification(['error' => 'LinkedIn authentication not configured']);
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $authUrl = $this->linkedinProvider->getAuthUrl($state);
        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $authUrl);
    }

    /**
     * Redirect to GitHub OAuth
     */
    private function redirectToGithub(): Response
    {
        if (!$this->githubProvider) {
            return $this->renderOAuthVerification(['error' => 'GitHub authentication not configured']);
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        $authUrl = $this->githubProvider->getAuthUrl($state);
        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $authUrl);
    }

    /**
     * Verify Google OAuth callback
     */
    private function verifyGoogleCallback(string $code): ?array
    {
        if (!$this->googleProvider) {
            return null;
        }

        try {
            $result = $this->googleProvider->handleCallback($code);
            if (!$result) {
                return null;
            }

            return $this->findOrCreateUserByGoogleId($result['provider_id'], $result['email'], $result['name']);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Verify LinkedIn OAuth callback
     */
    private function verifyLinkedinCallback(string $code, ?string $state): ?array
    {
        if (!$this->linkedinProvider) {
            return null;
        }

        try {
            $result = $this->linkedinProvider->handleCallback($code, $state);
            if (!$result) {
                return null;
            }

            return $this->findOrCreateUserByLinkedinId($result['provider_id'], $result['email'], $result['name']);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Verify GitHub OAuth callback
     */
    private function verifyGithubCallback(string $code, ?string $state): ?array
    {
        if (!$this->githubProvider) {
            return null;
        }

        try {
            $result = $this->githubProvider->handleCallback($code, $state);
            if (!$result) {
                return null;
            }

            return $this->findOrCreateUserByGithubId($result['provider_id'], $result['email'], $result['name']);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Find or create user by Google ID
     */
    private function findOrCreateUserByGoogleId(string $googleId, string $email, string $name): ?array
    {
        if (method_exists($this->storage, 'findUserByGoogleId')) {
            $user = $this->storage->findUserByGoogleId($googleId);
            if ($user) {
                return [
                    'user_id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }

        $user = $this->storage->findUserByEmail($email);
        if ($user) {
            if (method_exists($this->storage, 'updateUserGoogleId')) {
                $this->storage->updateUserGoogleId($user['id'], $googleId);
            }
            return [
                'user_id' => $user['id'],
                'agency_id' => $user['agency_id'],
                'name' => $user['name'],
                'email' => $user['email']
            ];
        }

        return null;
    }

    /**
     * Find or create user by LinkedIn ID
     */
    private function findOrCreateUserByLinkedinId(string $linkedinId, string $email, string $name): ?array
    {
        if (method_exists($this->storage, 'findUserByLinkedinId')) {
            $user = $this->storage->findUserByLinkedinId($linkedinId);
            if ($user) {
                return [
                    'user_id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }

        $user = $this->storage->findUserByEmail($email);
        if ($user) {
            if (method_exists($this->storage, 'updateUserLinkedinId')) {
                $this->storage->updateUserLinkedinId($user['id'], $linkedinId);
            }
            return [
                'user_id' => $user['id'],
                'agency_id' => $user['agency_id'],
                'name' => $user['name'],
                'email' => $user['email']
            ];
        }

        return null;
    }

    /**
     * Find or create user by GitHub ID
     */
    private function findOrCreateUserByGithubId(string $githubId, string $email, string $name): ?array
    {
        if (method_exists($this->storage, 'findUserByGithubId')) {
            $user = $this->storage->findUserByGithubId($githubId);
            if ($user) {
                return [
                    'user_id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }

        $user = $this->storage->findUserByEmail($email);
        if ($user) {
            if (method_exists($this->storage, 'updateUserGithubId')) {
                $this->storage->updateUserGithubId($user['id'], $githubId);
            }
            return [
                'user_id' => $user['id'],
                'agency_id' => $user['agency_id'],
                'name' => $user['name'],
                'email' => $user['email']
            ];
        }

        return null;
    }
}
