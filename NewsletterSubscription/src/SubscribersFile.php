<?php

namespace NewsletterSubscription;

/**
 * Create and download to browser a CSV file with all subscribers
 *
 * @author Miguel Angel Gabriel (magabriel@gmail.com)
 */
class SubscribersFile
{
    protected $app;
    protected $config;
    protected $defaults;

    public function __construct($app)
    {
        $this->app = $app;
        $this->config = $this->app['nls.config'];
        $this->defaults = $this->app['nls.defaults'];
    }

    /**
     * Send a CSV file with all subscribers to the browser to be downloaded.
     *
     * @param string $secret Secret string to authorize the download
     */
    public function download($secret)
    {
        // Only start the download if the admin_secret has been set and is different from default
        if (!isset($this->config['admin_secret']) ||
                   $this->config['admin_secret'] == $this->defaults['admin_secret']) {
            return;
        }

        // Check correct secret
        if ($secret != $this->config['admin_secret']) {
            return;
        }

        $subscribers = $this->app['nls.storage']->subscriberFindAll();

        if (!$subscribers) {
            // Nothing to do
            return;
        }

        $quote = '"';
        $sep = ';';

        $lines = array();

        // Construct headers

        // - First, the subscribers fields minus 'extra_fields'
        $keys = array_flip(array_keys($subscribers[0]));
        unset($keys['extra_fields']);
        $keys = array_flip($keys);

        // - Then, the 'extra_fields' names
        if (isset($this->config['form']['extra_fields'])) {
            $keys = array_merge($keys, array_keys($this->config['form']['extra_fields']));
        }

        $lines[] = $quote . implode($quote . $sep . $quote, $keys) . $quote;

        // Records
        foreach ($subscribers as $subscriber) {

            $extraFields = $subscriber['extra_fields'];
            unset($subscriber['extra_fields']);

            if (isset($this->config['form']['extra_fields'])) {
                foreach ($extraFields as $field) {
                    $subscriber[$field['name']] = str_replace('"', '""', $field['value']);
                }
            }
            $lines[] = $quote . implode($quote . $sep . $quote, $subscriber) . $quote;
        }

        $data = implode("\n", $lines);

        \util::force_download('subcribers.csv', $data);

        // Exit the script to avoid junk into the downloaded data!!!
        exit;
    }
}
