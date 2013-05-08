<?php

namespace NewsletterSubscription;

/**
 * The Newsletter Subscription dashboard widget
 *
 * @author Miguel Angel Gabriel (magabriel@gmail.com)
 */
class NewsletterSubscriptionWidget
{
    protected $app;
    protected $config;

    public function __construct($app)
    {
        $this->app = $app;
        $this->config = $this->app['nls.config'];
    }

    public function render()
    {
        // Process url arguments
        $urlArgs = Tools::getUrlArgs($this->app['paths']['currenturl']);

        if (isset($urlArgs['adminaction']) && $urlArgs['adminaction'] == 'download') {
            $secret = isset($urlArgs['secret']) ? $urlArgs['secret'] : '';

            $this->app['nls.subscribers_file']->download($secret);

            // Will never reach this if successful
            $html = '<p>Invalid URL</p>';
            return new \Twig_Markup($html, 'UTF-8');
        }

        // Render widget

        $stats = $this->app['nls.storage']->subscriberStats();

        $this->app['twig.loader.filesystem']->addPath(__DIR__.'/../', 'NewsletterSubscription');
        $template = $this->config['widget_template'];

        $html = $this->app['twig']
                     ->render("@NewsletterSubscription/" . $template,
                     array(
                        'stats' => $stats,
                        'secret' => isset($this->config['admin_secret']) ? $this->config['admin_secret'] : '',
                        'secret_default' => $this->app['nls.defaults']['admin_secret']
                ));

        return new \Twig_Markup($html, 'UTF-8');
    }
}
