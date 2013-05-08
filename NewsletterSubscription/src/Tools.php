<?php

namespace NewsletterSubscription;

/**
 * Some utility function
 *
 * @author Miguel Angel Gabriel (magabriel@gmail.com)
 */
class Tools
{

    /**
     * Get the arguments of the url
     *
     * @param string $url
     * @return array
     */
    public static function getUrlArgs($url)
    {
        $parts = explode('?', $url);

        if (!isset($parts[1])) {
            return array();
        }

        $args = explode('&', $parts[1]);

        $ret = array();
        foreach ($args as $arg) {
            $parts = explode('=', $arg);
            if (isset($parts[1])) {
                $ret[$parts[0]] = $parts[1];
            } else {
                $ret[$parts[0]] = '';
            }
        }

        return $ret;
    }

    /**
     * Merge configuration array with defaults (recursive)
     * @see http://www.php.net/manual/en/function.array-merge-recursive.php#92195
     *
     * @param array $defaults
     * @param array $configuration
     * @return array merged configuration with defaults
     */
    public static function mergeConfiguration(array $defaults, array $configuration)
    {
        $merged = $defaults;

        foreach ($configuration as $key => &$value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = self::mergeConfiguration($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

}
