<?php

namespace Seolinkmap\Waasup\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamFactoryInterface;
use Seolinkmap\Waasup\Auth\Providers\{GithubProvider, GoogleProvider, LinkedinProvider};
use Seolinkmap\Waasup\Storage\StorageInterface;

class SocialAuthHandler
{
    use RenderingTrait;

    private StorageInterface $storage;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private array $config;
    private ?GoogleProvider $googleProvider = null;
    private ?LinkedinProvider $linkedinProvider = null;
    private ?GithubProvider $githubProvider = null;

    public function __construct(
        StorageInterface $storage,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->config = $config;

        $this->initializeProviders();
    }

    public function handleVerify(Request $request, Response $response): Response
    {
        if (!isset($_SESSION['oauth_request'])) {
            return $this->errorResponse($response, 'invalid_request', 'OAuth request session expired');
        }

        $data = $request->getParsedBody();
        if (!is_array($data)) {
            return $this->errorResponse($response, 'invalid_request', 'Invalid request data');
        }

        $provider = $data['provider'] ?? 'email';

        if ($provider === 'email') {
            return $this->handleEmailAuth($data, $response);
        }

        return $this->handleSocialAuth($provider, $response);
    }

    public function handleGoogleCallback(Request $request, Response $response): Response
    {
        if (!$this->googleProvider || !isset($_SESSION['oauth_verification_mode'])) {
            return $this->errorResponse($response, 'invalid_request', 'Invalid callback state');
        }

        unset($_SESSION['oauth_verification_mode']);

        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;

        if (!$code) {
            return $this->renderAuthForm($response, ['error' => 'Google authentication failed']);
        }

        $result = $this->googleProvider->handleCallback($code);
        if (!$result) {
            return $this->renderAuthForm($response, ['error' => 'Google authentication failed']);
        }

        $userData = $this->findOrCreateUser($result);
        if (!$userData) {
            return $this->renderAuthForm($response, ['error' => 'User creation failed']);
        }

        $_SESSION['oauth_user'] = $userData;
        return $this->redirectToConsent($response);
    }

    public function handleLinkedinCallback(Request $request, Response $response): Response
    {
        if (!$this->linkedinProvider || !isset($_SESSION['oauth_verification_mode'])) {
            return $this->errorResponse($response, 'invalid_request', 'Invalid callback state');
        }

        unset($_SESSION['oauth_verification_mode']);

        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;

        if (!$code || !$this->validateState($state)) {
            return $this->renderAuthForm($response, ['error' => 'LinkedIn authentication failed']);
        }

        $result = $this->linkedinProvider->handleCallback($code, $state);
        if (!$result) {
            return $this->renderAuthForm($response, ['error' => 'LinkedIn authentication failed']);
        }

        $userData = $this->findOrCreateUser($result);
        if (!$userData) {
            return $this->renderAuthForm($response, ['error' => 'User creation failed']);
        }

        $_SESSION['oauth_user'] = $userData;
        return $this->redirectToConsent($response);
    }

    public function handleGithubCallback(Request $request, Response $response): Response
    {
        if (!$this->githubProvider || !isset($_SESSION['oauth_verification_mode'])) {
            return $this->errorResponse($response, 'invalid_request', 'Invalid callback state');
        }

        unset($_SESSION['oauth_verification_mode']);

        $params = $request->getQueryParams();
        $code = $params['code'] ?? null;
        $state = $params['state'] ?? null;

        if (!$code || !$this->validateState($state)) {
            return $this->renderAuthForm($response, ['error' => 'GitHub authentication failed']);
        }

        $result = $this->githubProvider->handleCallback($code, $state);
        if (!$result) {
            return $this->renderAuthForm($response, ['error' => 'GitHub authentication failed']);
        }

        $userData = $this->findOrCreateUser($result);
        if (!$userData) {
            return $this->renderAuthForm($response, ['error' => 'User creation failed']);
        }

        $_SESSION['oauth_user'] = $userData;
        return $this->redirectToConsent($response);
    }

    private function initializeProviders(): void
    {
        if (isset($this->config['google'])) {
            $this->googleProvider = new GoogleProvider(
                $this->config['google']['client_id'],
                $this->config['google']['client_secret'],
                $this->config['google']['redirect_uri']
            );
        }

        if (isset($this->config['linkedin'])) {
            $this->linkedinProvider = new LinkedinProvider(
                $this->config['linkedin']['client_id'],
                $this->config['linkedin']['client_secret'],
                $this->config['linkedin']['redirect_uri']
            );
        }

        if (isset($this->config['github'])) {
            $this->githubProvider = new GithubProvider(
                $this->config['github']['client_id'],
                $this->config['github']['client_secret'],
                $this->config['github']['redirect_uri']
            );
        }
    }

    private function handleEmailAuth(array $data, Response $response): Response
    {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            return $this->renderAuthForm($response, ['error' => 'Email and password required']);
        }

        $userData = $this->storage->verifyUserCredentials($email, $password);
        if (!$userData) {
            return $this->renderAuthForm($response, ['error' => 'Invalid credentials']);
        }

        $_SESSION['oauth_user'] = $userData;
        return $this->redirectToConsent($response);
    }

    private function handleSocialAuth(string $provider, Response $response): Response
    {
        $_SESSION['oauth_verification_mode'] = true;
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;

        switch ($provider) {
            case 'google':
                return $this->googleProvider ?
                            $this->googleProvider->redirectResponse($response, $state) :
                            $this->renderAuthForm($response, ['error' => 'Google authentication not configured']);

            case 'linkedin':
                return $this->linkedinProvider ?
                            $this->linkedinProvider->redirectResponse($response, $state) :
                            $this->renderAuthForm($response, ['error' => 'LinkedIn authentication not configured']);

            case 'github':
                return $this->githubProvider ?
                            $this->githubProvider->redirectResponse($response, $state) :
                            $this->renderAuthForm($response, ['error' => 'GitHub authentication not configured']);

            default:
                return $this->renderAuthForm($response, ['error' => 'Invalid authentication provider']);
        }
    }

    private function findOrCreateUser(array $providerData): ?array
    {
        $findMethod = 'findUserBy' . ucfirst($providerData['provider']) . 'Id';

        if (method_exists($this->storage, $findMethod)) {
            $user = $this->storage->$findMethod($providerData['provider_id']);
            if ($user) {
                return [
                    'user_id' => $user['id'],
                    'agency_id' => $user['agency_id'],
                    'name' => $user['name'],
                    'email' => $user['email']
                ];
            }
        }

        $user = $this->storage->findUserByEmail($providerData['email']);
        if ($user) {
            $updateMethod = 'updateUser' . ucfirst($providerData['provider']) . 'Id';
            if (method_exists($this->storage, $updateMethod)) {
                $this->storage->$updateMethod($user['id'], $providerData['provider_id']);
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

    private function validateState(string $state): bool
    {
        return isset($_SESSION['oauth_state']) && hash_equals($_SESSION['oauth_state'], $state);
    }

    private function renderAuthForm(Response $response, array $data = []): Response
    {
        $clientName = htmlspecialchars(
            $_SESSION['oauth_request']['client_name'] ?? 'Unknown Application',
            ENT_QUOTES,
            'UTF-8'
        );
        $error = $data['error'] ?? '';

        $socialButtons = '';
        if ($this->googleProvider) {
            $socialButtons .= '<button type="submit" name="provider" value="google" class="btn social google">Continue with Google</button>';
        }
        if ($this->linkedinProvider) {
            $socialButtons .= '<button type="submit" name="provider" value="linkedin" class="btn social linkedin">Continue with LinkedIn</button>';
        }
        if ($this->githubProvider) {
            $socialButtons .= '<button type="submit" name="provider" value="github" class="btn social github">Continue with GitHub</button>';
        }

        if ($socialButtons) {
            $socialButtons = "<form method='POST'>{$socialButtons}</form><div class='divider'><span>or</span></div>";
        }

        $body = ($error ? "<div class='error'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</div>" : "") . "
{$socialButtons}
<form method='POST'>
<div class='form-group'>
<label for='email'>Email</label>
<input type='email' id='email' name='email' required>
</div>
<div class='form-group'>
<label for='password'>Password</label>
<input type='password' id='password' name='password' required>
</div>
<button type='submit' name='provider' value='email' class='btn primary'>Sign In</button>
</form>";

        return $this->renderPage("Authorize {$clientName}", $body);
    }

    private function redirectToConsent(Response $response): Response
    {
        return $response->withHeader('Location', '/oauth/consent')->withStatus(302);
    }

    private function errorResponse(Response $response, string $error, string $description = ''): Response
    {
        $data = ['error' => $error];
        if ($description) {
            $data['error_description'] = $description;
        }

        $jsonContent = json_encode($data);
        if ($jsonContent === false) {
            $jsonContent = '{"error":"JSON encoding failed"}';
        }
        $stream = $this->streamFactory->createStream($jsonContent);
        return $this->responseFactory->createResponse(400)
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }
}
