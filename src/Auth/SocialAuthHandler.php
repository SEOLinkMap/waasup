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
        $clientName = $_SESSION['oauth_request']['client_name'] ?? 'Unknown Application';
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

        $html = "<!DOCTYPE html>
<html>
<head>
    <title>Authorize {$clientName}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 400px; margin: 80px auto; padding: 20px; background: #f9f9f9; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .error { color: #d73a49; background: #ffeef0; padding: 12px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #fdbdbd; }
        .form-group { margin-bottom: 16px; }
        label { display: block; margin-bottom: 6px; font-weight: 500; color: #24292e; }
        input[type=email], input[type=password] { width: 100%; padding: 12px; border: 1px solid #d1d5da; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; margin-bottom: 8px; }
        .primary { background: #0366d6; color: white; }
        .social { background: #f6f8fa; color: #24292e; border: 1px solid #d1d5da; }
        .google { background: #4285f4; color: white; border: none; }
        .linkedin { background: #0077b5; color: white; border: none; }
        .github { background: #24292e; color: white; border: none; }
        .divider { text-align: center; margin: 20px 0; color: #6a737d; position: relative; }
        .divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: #e1e4e8; }
        .divider span { background: white; padding: 0 16px; }
        h1 { color: #24292e; margin-bottom: 24px; font-size: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Authorize {$clientName}</h1>
        " . ($error ? "<div class='error'>{$error}</div>" : "") . "

        " . ($socialButtons ? "
        <form method='POST'>
            {$socialButtons}
        </form>

        <div class='divider'><span>or</span></div>
        " : "") . "

        <form method='POST'>
            <div class='form-group'>
                <label>Email</label>
                <input type='email' name='email' required>
            </div>
            <div class='form-group'>
                <label>Password</label>
                <input type='password' name='password' required>
            </div>
            <button type='submit' name='provider' value='email' class='btn primary'>Sign In</button>
        </form>
    </div>
</body>
</html>";

        $stream = $this->streamFactory->createStream($html);
        return $this->responseFactory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'text/html');
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
