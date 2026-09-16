<?php

namespace Seolinkmap\Waasup;

/**
 * Merges application configuration over a component's defaults
 */
class Config
{
    /**
     * Merge configuration over the defaults
     *
     * The defaults decide the shape of each option. An option group merges key by
     * key, so an application supplies only the options it changes. Everything else
     * is replaced by what the application supplies, so naming two protocol
     * versions leaves two rather than overwriting the first two of five.
     *
     * @param array $defaults the component's defaults
     * @param array $config the application's configuration
     * @return array the merged configuration
     */
    public static function merge(array $defaults, array $config): array
    {
        foreach ($config as $key => $value) {
            $default = $defaults[$key] ?? null;

            if (is_array($value) && self::isGroup($default)) {
                $defaults[$key] = self::merge($default, $value);
                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }

    /**
     * Whether a default is a group of named options rather than a value
     *
     * @param mixed $default the shipped default
     */
    private static function isGroup(mixed $default): bool
    {
        return is_array($default) && $default !== [] && !array_is_list($default);
    }
}
