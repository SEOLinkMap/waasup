<?php

namespace Seolinkmap\Waasup\Auth\Providers;

use Psr\Http\Message\ResponseInterface as Response;

class LinkedinProvider
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

    public function getAuthUrl(string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => 'openid profile email'
        ];

        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($params);
    }

    public function handleCallback(string $code, string $state): ?array
    {
        $tokenResponse = $this->getTokenResponse($code);
        if (!$tokenResponse || !isset($tokenResponse['id_token'])) {
            return null;
        }

        $userInfo = $this->decodeIdToken($tokenResponse['id_token']);
        if (!$userInfo || !isset($userInfo['sub']) || !isset($userInfo['email'])) {
            return null;
        }

        return [
            'provider' => 'linkedin',
            'provider_id' => $userInfo['sub'],
            'email' => $userInfo['email'],
            'name' => $userInfo['name'] ?? $userInfo['email'],
            'tokens' => $tokenResponse
        ];
    }

    public function redirectResponse(Response $response, string $state): Response
    {
        return $response->withHeader('Location', $this->getAuthUrl($state))->withStatus(302);
    }

    private function getTokenResponse(string $code): ?array
    {
        $data = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret
        ];

        $ch = curl_init('https://www.linkedin.com/oauth/v2/accessToken');
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

    private function decodeIdToken(string $idToken): ?array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $this->base64UrlDecode($parts[1]);
        if (!$payload) {
            return null;
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
