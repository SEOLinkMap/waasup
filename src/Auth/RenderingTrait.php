<?php

namespace Seolinkmap\Waasup\Auth;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Renders the browser-facing OAuth pages
 */
trait RenderingTrait
{
    /**
     * CSRF token embedded in the rendered OAuth forms
     */
    private function csrfToken(): string
    {
        return $_SESSION['oauth_csrf'] ?? '';
    }

    /**
     * Render OAuth verification form
     */
    private function renderOAuthVerification(array $data = []): Response
    {
        $clientName = htmlspecialchars(
            $_SESSION['oauth_request']['client_name'] ?? 'Unknown Application',
            ENT_QUOTES,
            'UTF-8'
        );
        $error = $data['error'] ?? '';

        $baseUrl = rtrim($this->config['oauth']['base_url'] ?? '', '/');
        $verifyPath = $this->config['oauth']['auth_server']['endpoints']['verify'];
        $verifyEndpoint = htmlspecialchars($baseUrl . $verifyPath, ENT_QUOTES, 'UTF-8');

        $csrfField = "<input type='hidden' name='csrf_token' value='"
            . htmlspecialchars($this->csrfToken(), ENT_QUOTES, 'UTF-8') . "'>";

        $socialButtons = '';
        if ($this->googleProvider || $this->linkedinProvider || $this->githubProvider) {
            $socialButtons = '<form method="POST" action="' . $verifyEndpoint . '">' . $csrfField;

            if ($this->googleProvider) {
                $socialButtons .= '<button type="submit" name="provider" value="google" class="btn social google">Continue with Google</button>';
            }
            if ($this->linkedinProvider) {
                $socialButtons .= '<button type="submit" name="provider" value="linkedin" class="btn social linkedin">Continue with LinkedIn</button>';
            }
            if ($this->githubProvider) {
                $socialButtons .= '<button type="submit" name="provider" value="github" class="btn social github">Continue with GitHub</button>';
            }

            $socialButtons .= '</form><div class="divider"><span>or</span></div>';
        }

        $body = ($error ? "<div class='error'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</div>" : "") . "
{$socialButtons}
<form method='POST' action='{$verifyEndpoint}'>
{$csrfField}
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

    /**
     * Render consent screen
     */
    private function renderConsentScreen(array $data = []): Response
    {
        $oauthRequest = $_SESSION['oauth_request'];
        $oauthUser = $_SESSION['oauth_user'];

        $clientName = htmlspecialchars($oauthRequest['client_name'], ENT_QUOTES, 'UTF-8');
        $userName = htmlspecialchars($oauthUser['name'] ?? $oauthUser['email'], ENT_QUOTES, 'UTF-8');
        $userEmail = htmlspecialchars($oauthUser['email'], ENT_QUOTES, 'UTF-8');
        $scope = htmlspecialchars($oauthRequest['scope'], ENT_QUOTES, 'UTF-8');
        $error = $data['error'] ?? '';

        $baseUrl = rtrim($this->config['oauth']['base_url'] ?? '', '/');
        $consentPath = $this->config['oauth']['auth_server']['endpoints']['consent'];
        $consentEndpoint = htmlspecialchars($baseUrl . $consentPath, ENT_QUOTES, 'UTF-8');
        $csrfField = "<input type='hidden' name='csrf_token' value='"
            . htmlspecialchars($this->csrfToken(), ENT_QUOTES, 'UTF-8') . "'>";

        $resourceInfo = '';
        if (isset($oauthRequest['resource'])) {
            $resource = htmlspecialchars($oauthRequest['resource'], ENT_QUOTES, 'UTF-8');
            $resourceInfo = "<p><strong>Resource:</strong> {$resource}</p>";
        }

        $body = ($error ? "<div class='error'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</div>" : "") . "
<div class='user-info'>
<strong>Signed in as:</strong> {$userName}<br>
<small class='muted'>{$userEmail}</small>
</div>
<div class='permissions'>
<p><strong>{$clientName}</strong> is requesting access to:</p>
<ul>
<li>Access your MCP server data ({$scope})</li>
</ul>
{$resourceInfo}
</div>
<form method='POST' action='{$consentEndpoint}'>
{$csrfField}
<div class='actions'>
<button type='submit' name='action' value='allow' class='btn allow'>Allow</button>
<button type='submit' name='action' value='deny' class='btn deny'>Deny</button>
</div>
</form>";

        return $this->renderPage("Authorize {$clientName}", $body);
    }

    /**
     * Render the out-of-band authorization code for manual entry
     */
    private function renderAuthorizationCode(string $authCode): Response
    {
        $code = htmlspecialchars($authCode, ENT_QUOTES, 'UTF-8');

        $body = "<p>Copy this authorization code and paste it back into your application to complete the connection.</p>
<code class='code'>{$code}</code>
<p class='muted'>The code can only be used once and expires shortly.</p>";

        return $this->renderPage('Authorization Successful', $body);
    }

    /**
 */
    private static array $themeDefaults = [
        'background_color' => ['light' => '#f6f8fa', 'dark' => '#0d1117'],
        'text_color' => ['light' => '#1f2328', 'dark' => '#e6edf3'],
        'accent_color' => ['light' => '#0969da', 'dark' => '#2f81f7'],
        'surface_color' => ['light' => '#ffffff', 'dark' => '#161b22'],
        'muted_color' => ['light' => '#656d76', 'dark' => '#8b949e'],
        'border_color' => ['light' => '#d0d7de', 'dark' => '#30363d'],
        'error_color' => ['light' => '#a40e26', 'dark' => '#ff8189'],
        'error_background_color' => ['light' => '#ffebe9', 'dark' => '#2d1214']
    ];

    /**
     * Render a complete OAuth page
     *
     * @param string $title page title, rendered as the heading
     * @param string $body markup placed inside the card
     * @param int $status HTTP status code
     * @return Response
     */
    private function renderPage(string $title, string $body, int $status = 200): Response
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $styles = $this->renderPageStyles();

        $html = "<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='utf-8'>
<meta name='viewport' content='width=device-width, initial-scale=1'>
<meta name='color-scheme' content='light dark'>
<title>{$safeTitle}</title>
<style>
{$styles}
</style>
</head>
<body>
<div class='container'>
<h1>{$safeTitle}</h1>
{$body}
</div>
</body>
</html>";

        $stream = $this->streamFactory->createStream($html);
        return $this->responseFactory->createResponse($status)
            ->withBody($stream)
            ->withHeader('Content-Type', 'text/html');
    }

    /**
     * Build the stylesheet, including the configured palette overrides
     *
     * @return string
     */
    private function renderPageStyles(): string
    {
        $light = $this->renderThemeVariables('light');
        $dark = $this->renderThemeVariables('dark');

        return ":root { color-scheme: light dark; {$light} }
@media (prefers-color-scheme: dark) { :root { {$dark} } }
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 14px; line-height: 1.5; max-width: 420px; margin: 0 auto; padding: 48px 20px; background: var(--bg); color: var(--fg); }
.container { background: var(--surface); padding: 28px; border-radius: 10px; border: 1px solid var(--border); }
h1 { margin: 0 0 24px; font-size: 20px; text-align: center; color: var(--fg); }
p { margin: 0 0 12px; }
ul { margin: 0 0 12px; padding-left: 20px; }
a { color: var(--accent); }
.error { color: var(--error-fg); background: var(--error-bg); padding: 12px; border-radius: 6px; margin-bottom: 20px; border: 1px solid var(--error-fg); }
.user-info { background: var(--bg); padding: 15px; margin-bottom: 20px; border-radius: 6px; border: 1px solid var(--border); }
.permissions { margin: 20px 0; }
.form-group { margin-bottom: 16px; }
label { display: block; margin-bottom: 6px; font-weight: 500; }
input[type=email], input[type=password] { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 6px; font-size: 14px; background: var(--surface); color: var(--fg); }
.btn { width: 100%; padding: 12px; border: 1px solid transparent; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; margin-bottom: 8px; }
.primary { background: var(--accent); color: #ffffff; }
.social { background: var(--bg); color: var(--fg); border-color: var(--border); }
.google { background: #4285f4; color: #ffffff; }
.linkedin { background: #0077b5; color: #ffffff; }
.github { background: #24292e; color: #ffffff; }
.actions { display: flex; gap: 10px; }
.actions .btn { margin-bottom: 0; }
.allow { background: var(--accent); color: #ffffff; }
.deny { background: var(--bg); color: var(--fg); border-color: var(--border); }
.divider { text-align: center; margin: 20px 0; color: var(--muted); position: relative; }
.divider::before { content: ''; position: absolute; top: 50%; left: 0; right: 0; height: 1px; background: var(--border); }
.divider span { background: var(--surface); padding: 0 16px; position: relative; }
.code { display: block; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; word-break: break-all; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 14px; margin-bottom: 12px; }
.muted { color: var(--muted); }";
    }

    /**
     * Build the custom property declarations for one color scheme
     *
     * @param string $scheme light or dark
     * @return string
     */
    private function renderThemeVariables(string $scheme): string
    {
        $names = [
            'background_color' => 'bg',
            'text_color' => 'fg',
            'accent_color' => 'accent',
            'surface_color' => 'surface',
            'muted_color' => 'muted',
            'border_color' => 'border',
            'error_color' => 'error-fg',
            'error_background_color' => 'error-bg'
        ];

        $declarations = '';
        foreach ($names as $option => $property) {
            $declarations .= '--' . $property . ': ' . $this->getThemeColor($option, $scheme) . '; ';
        }

        return rtrim($declarations);
    }

    /**
     * Resolve one configured color, falling back to the built-in palette
     *
     * @param string $option key under oauth.ui
     * @param string $scheme light or dark
     * @return string a CSS color value
     */
    private function getThemeColor(string $option, string $scheme): string
    {
        $configured = $this->config['oauth']['ui'][$option] ?? null;

        if (is_array($configured)) {
            $configured = $configured[$scheme] ?? null;
        }

        if (is_string($configured) && $this->isValidCssColor($configured)) {
            return $configured;
        }

        return self::$themeDefaults[$option][$scheme];
    }

    /**
     * Guard against style injection through configured colors
     *
     * @param string $color candidate value
     * @return bool
     */
    private function isValidCssColor(string $color): bool
    {
        $color = trim($color);

        if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color)) {
            return true;
        }

        if (preg_match('/^[a-z]{3,20}$/i', $color)) {
            return true;
        }

        return preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9a-z.,%\s\/]{1,64}\)$/i', $color) === 1;
    }
}
