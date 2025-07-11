<?php

namespace Seolinkmap\Waasup\Auth\Providers;

use Psr\Http\Message\ResponseInterface as Response;

class GoogleProvider
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct(string $clientId, string $clientSecret, string $redirectUri)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
    }

    public function getAuthUrl(string $state = null): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'openid email profile',
            'response_type' => 'code',
            'access_type' => 'offline'
        ];

        if ($state) {
            $params['state'] = $state;
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function handleCallback(string $code): ?array
    {
        $tokenData = $this->exchangeCodeForToken($code);
        if (!$tokenData || !isset($tokenData['id_token'])) {
            return null;
        }

        $userInfo = $this->verifyIdToken($tokenData['id_token']);
        if (!$userInfo || !isset($userInfo['sub']) || !isset($userInfo['email'])) {
            return null;
        }

        return [
            'provider' => 'google',
            'provider_id' => $userInfo['sub'],
            'email' => $userInfo['email'],
            'name' => $userInfo['name'] ?? $userInfo['email'],
            'tokens' => $tokenData
        ];
    }

    public function redirectResponse(Response $response, string $state = null): Response
    {
        return $response->withHeader('Location', $this->getAuthUrl($state))->withStatus(302);
    }

    private function exchangeCodeForToken(string $code): ?array
    {
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri
        ];

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json'
            ]
        );

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response || $response === true) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function verifyIdToken(string $idToken): ?array
    {
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response || $response === true) {
            return null;
        }

        $tokenInfo = json_decode($response, true);
        if (!is_array($tokenInfo)) {
            return null;
        }

        if (isset($tokenInfo['error']) || !isset($tokenInfo['sub'])) {
            return null;
        }

        if ($tokenInfo['aud'] !== $this->clientId) {
            return null;
        }

        return $tokenInfo;
    }
}
