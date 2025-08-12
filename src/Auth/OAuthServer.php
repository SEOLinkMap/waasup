<?php

namespace Seolinkmap\Waasup\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Seolinkmap\Waasup\Auth\Providers\{GithubProvider, GoogleProvider, LinkedinProvider};
use Seolinkmap\Waasup\Storage\StorageInterface;

class OAuthServer
{
    use OAuthEndpointsTrait;
    use SocialProviderTrait;
    use TokenHandlingTrait;
    use RenderingTrait;
    use UtilityTrait;

    private StorageInterface $storage;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;
    private ?GoogleProvider $googleProvider = null;
    private ?LinkedinProvider $linkedinProvider = null;
    private ?GithubProvider $githubProvider = null;
    private array $config;

    /**
     * @param StorageInterface $storage
     * @param ResponseFactoryInterface $responseFactory
     * @param StreamFactoryInterface $streamFactory
     * @param array $config config array (master in MCPSaaSServer::getDefaultConfig())
     */
    public function __construct(
        StorageInterface $storage,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        array $config = []
    ) {
        $this->storage = $storage;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
        $this->config = array_replace_recursive($this->getDefaultConfig(), $config);

        $this->initializeSocialProviders();
    }
}
