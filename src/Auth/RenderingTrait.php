<?php

namespace Seolinkmap\Waasup\Auth;

use Psr\Http\Message\ResponseInterface as Response;

trait RenderingTrait
{
    /**
     * Render OAuth verification form
     */
    private function renderOAuthVerification(array $data = []): Response
    {
        $clientName = $_SESSION['oauth_request']['client_name'] ?? 'Unknown Application';
        $error = $data['error'] ?? '';

        $socialButtons = '';
        if ($this->googleProvider || $this->linkedinProvider || $this->githubProvider) {
            $socialButtons = '<form method="POST" action="/oauth/verify">';

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

        {$socialButtons}

        <form method='POST' action='/oauth/verify'>
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

    /**
     * Render consent screen
     */
    private function renderConsentScreen(array $data = []): Response
    {
        $oauthRequest = $_SESSION['oauth_request'];
        $oauthUser = $_SESSION['oauth_user'];

        $clientName = $oauthRequest['client_name'];
        $userName = $oauthUser['name'] ?? $oauthUser['email'];
        $userEmail = $oauthUser['email'];
        $scope = $oauthRequest['scope'];
        $error = $data['error'] ?? '';

        // Show resource information for 2025-06-18
        $resourceInfo = '';
        if (isset($oauthRequest['resource'])) {
            $resourceInfo = "<p><strong>Resource:</strong> {$oauthRequest['resource']}</p>";
        }

        $html = "<!DOCTYPE html>
<html>
<head>
    <title>Authorize {$clientName}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 400px; margin: 80px auto; padding: 20px; background: #f9f9f9; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .error { color: #d73a49; background: #ffeef0; padding: 12px; border-radius: 4px; margin-bottom: 20px; }
        .user-info { background: #f6f8fa; padding: 15px; margin-bottom: 20px; border-radius: 5px; border: 1px solid #d1d5da; }
        .permissions { margin: 20px 0; }
        .btn { padding: 12px 24px; margin: 5px; border: none; cursor: pointer; border-radius: 4px; font-weight: 500; }
        .allow { background: #28a745; color: white; }
        .deny { background: #dc3545; color: white; }
        h1 { color: #24292e; margin-bottom: 24px; font-size: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>Authorize {$clientName}</h1>
        " . ($error ? "<div class='error'>{$error}</div>" : "") . "
        <div class='user-info'>
            <strong>Signed in as:</strong> {$userName}<br>
            <small>{$userEmail}</small>
        </div>
        <div class='permissions'>
            <p><strong>{$clientName}</strong> is requesting access to:</p>
            <ul>
                <li>Access your MCP server data ({$scope})</li>
            </ul>
            {$resourceInfo}
        </div>
        <form method='POST' action='/oauth/consent'>
            <button type='submit' name='action' value='allow' class='btn allow'>Allow</button>
            <button type='submit' name='action' value='deny' class='btn deny'>Deny</button>
        </form>
    </div>
</body>
</html>";

        $stream = $this->streamFactory->createStream($html);
        return $this->responseFactory->createResponse(200)
            ->withBody($stream)
            ->withHeader('Content-Type', 'text/html');
    }
}
