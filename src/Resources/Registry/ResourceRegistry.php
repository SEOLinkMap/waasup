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
        // Check resource instances first
        if (isset($this->resources[$uri])) {
            return $this->resources[$uri]->read($context);
        }

        // Check callable resources
        if (isset($this->callables[$uri])) {
            $callable = $this->callables[$uri];
            return ($callable['handler'])($uri, $context);
        }

        // Check templates for pattern match
        foreach ($this->templateCallables as $template => $callable) {
            if ($this->matchesTemplate($template, $uri)) {
                return ($callable['handler'])($uri, $context);
            }
        }

        throw new MCPException("Resource not found: {$uri}", -32601);
    }

    /**
     * Get all registered resources for resources/list response
     */
    public function getResourcesList(): array
    {
        $resources = [];

        // Add resource instances
        foreach ($this->resources as $resource) {
            $resources[] = [
                'uri' => $resource->getUri(),
                'name' => $resource->getName(),
                'description' => $resource->getDescription(),
                'mimeType' => $resource->getMimeType()
            ];
        }

        // Add callable resources
        foreach ($this->callables as $uri => $callable) {
            $resources[] = [
                'uri' => $uri,
                'name' => $callable['schema']['name'] ?? basename($uri),
                'description' => $callable['schema']['description'] ?? "Resource: {$uri}",
                'mimeType' => $callable['schema']['mimeType'] ?? 'text/plain'
            ];
        }

        return ['resources' => $resources];
    }

    /**
     * Get all registered resource templates
     */
    public function getResourceTemplatesList(): array
    {
        $templates = [];

        foreach ($this->templateCallables as $uriTemplate => $callable) {
            $templates[] = [
                'uriTemplate' => $uriTemplate,
                'name' => $callable['schema']['name'] ?? basename($uriTemplate),
                'description' => $callable['schema']['description'] ?? "Resource template: {$uriTemplate}",
                'mimeType' => $callable['schema']['mimeType'] ?? 'text/plain'
            ];
        }

        return ['resourceTemplates' => $templates];
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
        return array_replace_recursive(
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
            'mimeType' => $schema['mimeType'] ?? 'text/plain'
        ];
    }

    /**
     * Check if a URI matches a template pattern
     */
    private function matchesTemplate(string $template, string $uri): bool
    {
        // Convert template to regex pattern
        // Replace {variable} with ([^/]+) for simple variable matching
        $pattern = preg_replace('/\{[^}]+\}/', '([^/]+)', $template);
        $pattern = str_replace('/', '\/', $pattern);
        $pattern = '/^' . $pattern . '$/';

        return preg_match($pattern, $uri) === 1;
    }
}
