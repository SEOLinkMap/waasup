<?php

namespace Seolinkmap\Waasup\Resources\Registry;

use Seolinkmap\Waasup\Exception\MCPException;
use Seolinkmap\Waasup\Resources\ResourceInterface;

/**
 * Resource registry for managing available resources and resource templates
 */
class ResourceRegistry
{
    private array $resources = [];
    private array $callables = [];
    private array $templateCallables = [];

    /**
     * Register a resource instance
     */
    public function registerResource(ResourceInterface $resource): void
    {
        $this->resources[$resource->getUri()] = $resource;
    }

    /**
     * Register a callable as a resource
     */
    public function register(string $uri, callable $handler, array $schema = []): void
    {
        $this->callables[$uri] = [
            'handler' => $handler,
            'schema' => $this->normalizeSchema($schema),
            'uri' => $uri
        ];
    }

    /**
     * Register a resource template
     */
    public function registerTemplate(string $uriTemplate, callable $handler, array $schema = []): void
    {
        $this->templateCallables[$uriTemplate] = [
            'handler' => $handler,
            'schema' => $this->normalizeSchema($schema),
            'uriTemplate' => $uriTemplate
        ];
    }

    /**
     * Read a resource by URI
     */
    public function read(string $uri, array $context = []): array
    {

        if (isset($this->resources[$uri])) {
            return $this->resources[$uri]->read($context);
        }

        if (isset($this->callables[$uri])) {
            $callable = $this->callables[$uri];
            return ($callable['handler'])($uri, $context);
        }

        foreach ($this->templateCallables as $template => $callable) {
            if ($this->matchesTemplate($template, $uri)) {
                return ($callable['handler'])($uri, $context);
            }
        }

        throw new MCPException("Resource not found: {$uri}. Call resources/list or resources/templates/list to see what this server exposes.", -32002);
    }

    /**
     * Get all registered resources for resources/list response
     */
    public function getResourcesList(string $protocolVersion = '2025-11-25'): array
    {
        $resources = [];
        $supportsIcons = strcmp($protocolVersion, '2025-11-25') >= 0;
        $supportsTitle = strcmp($protocolVersion, '2025-06-18') >= 0;

        foreach ($this->resources as $resource) {
            $resourceData = [
                'uri' => $resource->getUri(),
                'name' => $resource->getName(),
                'description' => $resource->getDescription(),
                'mimeType' => $resource->getMimeType()
            ];

            if ($supportsTitle && $resource->getTitle() !== '') {
                $resourceData['title'] = $resource->getTitle();
            }

            if ($supportsIcons && !empty($resource->getIcons())) {
                $resourceData['icons'] = $resource->getIcons();
            }

            $resources[] = $resourceData;
        }

        foreach ($this->callables as $uri => $callable) {
            $resourceData = [
                'uri' => $uri,
                'name' => $callable['schema']['name'] ?? basename($uri),
                'description' => $callable['schema']['description'] ?? "Resource: {$uri}",
                'mimeType' => $callable['schema']['mimeType'] ?? 'text/plain'
            ];

            if ($supportsTitle && !empty($callable['schema']['title'])) {
                $resourceData['title'] = $callable['schema']['title'];
            }

            if ($supportsIcons && !empty($callable['schema']['icons'])) {
                $resourceData['icons'] = $callable['schema']['icons'];
            }

            $resources[] = $resourceData;
        }

        return ['resources' => $resources];
    }

    /**
     * Get all registered resource templates
     */
    public function getResourceTemplatesList(string $protocolVersion = '2025-11-25'): array
    {
        $templates = [];
        $supportsIcons = strcmp($protocolVersion, '2025-11-25') >= 0;
        $supportsTitle = strcmp($protocolVersion, '2025-06-18') >= 0;

        foreach ($this->templateCallables as $uriTemplate => $callable) {
            $templateData = [
                'uriTemplate' => $uriTemplate,
                'name' => $callable['schema']['name'] ?? basename($uriTemplate),
                'description' => $callable['schema']['description'] ?? "Resource template: {$uriTemplate}",
                'mimeType' => $callable['schema']['mimeType'] ?? 'text/plain'
            ];

            if ($supportsTitle && !empty($callable['schema']['title'])) {
                $templateData['title'] = $callable['schema']['title'];
            }

            if ($supportsIcons && !empty($callable['schema']['icons'])) {
                $templateData['icons'] = $callable['schema']['icons'];
            }

            $templates[] = $templateData;
        }

        return ['resourceTemplates' => $templates];
    }

    /**
     * Get the declared argument schema for the template matching a URI
     *
     * @return array JSON schema, empty when no template matches
     */
    public function getTemplateSchema(string $uri): array
    {
        foreach ($this->templateCallables as $template => $callable) {
            if ($template === $uri || $this->matchesTemplate($template, $uri)) {
                return $callable['schema']['inputSchema'];
            }
        }

        return [];
    }

    /**
     * Check if a resource exists
     */
    public function hasResource(string $uri): bool
    {
        if (isset($this->resources[$uri]) || isset($this->callables[$uri])) {
            return true;
        }

        foreach ($this->templateCallables as $template => $callable) {
            if ($this->matchesTemplate($template, $uri)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get list of resource URIs
     */
    public function getResourceUris(): array
    {
        return array_merge(
            array_keys($this->resources),
            array_keys($this->callables)
        );
    }

    /**
     * Get list of resource template URIs
     */
    public function getTemplateUris(): array
    {
        return array_keys($this->templateCallables);
    }

    /**
     * Normalize schema for callable resources
     */
    private function normalizeSchema(array $schema): array
    {
        return [
            'name' => $schema['name'] ?? '',
            'description' => $schema['description'] ?? '',
            'mimeType' => $schema['mimeType'] ?? 'text/plain',
            'title' => $schema['title'] ?? '',
            'icons' => $schema['icons'] ?? [],
            'inputSchema' => $schema['inputSchema'] ?? []
        ];
    }

    /**
     * Check if a URI matches a template pattern
     */
    private function matchesTemplate(string $template, string $uri): bool
    {

        $pattern = preg_quote($template, '/');
        $pattern = preg_replace('/\\\\\{[^}]+\\\\\}/', '([^\/]+)', $pattern);
        $pattern = '/^' . $pattern . '$/';

        return preg_match($pattern, $uri) === 1;
    }
}
