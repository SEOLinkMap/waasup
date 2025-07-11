<?php

namespace Seolinkmap\Waasup\Auth\Providers;

use Psr\Http\Message\ResponseInterface as Response;

class GithubProvider
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
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => 'user:email'
        ];

        return 'https://github.com/login/oauth/authorize?' . http_build_query($params);
    }

    public function handleCallback(string $code, string $state): ?array
    {
        $accessToken = $this->getAccessToken($code);
        if (!$accessToken) {
            return null;
        }

        $profile = $this->getUserProfile($accessToken);
        if (!$profile || !isset($profile['id'])) {
            return null;
        }

        if (empty($profile['email'])) {
            $emails = $this->getUserEmails($accessToken);
            if ($emails) {
                foreach ($emails as $email) {
                    if ($email['primary'] && $email['verified']) {
                        $profile['email'] = $email['email'];
                        break;
                    }
                }
                if (empty($profile['email'])) {
                    foreach ($emails as $email) {
                        if ($email['verified']) {
                            $profile['email'] = $email['email'];
                            break;
                        }
                    }
                }
            }
        }

        if (empty($profile['email'])) {
            return null;
        }

        return [
            'provider' => 'github',
            'provider_id' => (string)$profile['id'],
            'email' => $profile['email'],
            'name' => $profile['name'] ?? $profile['login'],
            'tokens' => ['access_token' => $accessToken]
        ];
    }

    public function redirectResponse(Response $response, string $state): Response
    {
        return $response->withHeader('Location', $this->getAuthUrl($state))->withStatus(302);
    }

    private function getAccessToken(string $code): ?string
    {
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code
        ];

        $ch = curl_init('https://github.com/login/oauth/access_token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
            'Accept: application/json',
            'User-Agent: MCP-Server'
            ]
        );

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false || $response === true) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
    }

    private function getUserProfile(string $accessToken): ?array
    {
        $ch = curl_init('https://api.github.com/user');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
            'Authorization: Bearer ' . $accessToken,
            'User-Agent: MCP-Server',
            'Accept: application/vnd.github.v3+json'
            ]
        );

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false || $response === true) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function getUserEmails(string $accessToken): ?array
    {
        $ch = curl_init('https://api.github.com/user/emails');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt(
            $ch,
            CURLOPT_HTTPHEADER,
            [
            'Authorization: Bearer ' . $accessToken,
            'User-Agent: MCP-Server',
            'Accept: application/vnd.github.v3+json'
            ]
        );

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false || $response === true) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
