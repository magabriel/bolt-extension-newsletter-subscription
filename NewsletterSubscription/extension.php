<?php
namespace NewsletterSubscription;

// We don't have autoloading :(
require_once "src/Storage.php";
require_once "src/Mailer.php";
require_once "src/Tools.php";
require_once "src/NewsletterSubscriptionFunction.php";
require_once "src/NewsletterSubscriptionWidget.php";
require_once "src/SubscribersFile.php";

/**
 * Newsletter Subscription Extension
 *
 * @author Miguel Angel Gabriel (magabriel@gmail.com)
 */
class Extension extends \Bolt\BaseExtension
{
    protected $defaults;
    protected $subscribersFile;

    /**
     * Provide information about this extension
     *
     * @see \Bolt\BaseExtension::info()
     */
    public function info()
    {
        $data = array(
                'name' => "Newsletter Subscription",
                'description' => "Allow your users to subscribe to your newsletter with two-phase confirmation.",
                'author' => "Miguel Angel Gabriel",
                'link' => "http://bolt.cm",
                'version' => "0.2.2",
                'type' => "Twig function",
                'first_releasedate' => "2013-04-01",
                'latest_releasedate' => "2013-04-01",
                'required_bolt_version' => "1.0",
                'highest_bolt_version' => "1.1",
        );

        return $data;
    }

    /**
     * Initializes this extension
     *
     * @see \Bolt\BaseExtension::initialize()
     */
    public function initialize()
    {
        $this->loadConfig();

        $this->setUp();

        $this->checkDatabase();

        $this->addTwigFunction('newslettersubscription', 'newsletterSubscriptionFunction');

        // Note that the widget cannot use caching to be able to process download requests
        $this->addWidget('dashboard', 'right_first', 'newsletterSubscriptionWidget', null, false, -1);
    }

    /**
     * Sets several things up
     */
    protected function setUp()
    {
        // Register some values to use around
        $this->app['nls.config'] = $this->config;
        $this->app['nls.defaults'] = $this->defaults;

        $app = $this->app;

        // Register some helper objects
        $this->app['nls.mailer'] = $this->app->share(function() use ($app) {
            return new Mailer($app);
        });

        $this->app['nls.storage'] = $this->app->share(function() use ($app) {
            return new Storage($app);
        });

        $this->app['nls.subscribers_file'] = $this->app->share(function () use ($app) {
            return new SubscribersFile($app);
        });

    }

    /**
     * Twig function entry point
     *
     * @return \Twig_Markup
     */
    public function newsletterSubscriptionFunction()
    {
        // Insert the proper CSS
        $this->addCSS($this->config['stylesheet']);

        $function = new NewsletterSubscriptionFunction($this->app);
        return $function->process();
    }

    /**
     * Dashboard widget contents
     *
     * @return \Twig_Markup
     */
    public function newsletterSubscriptionWidget()
    {
        $widget = new NewsletterSubscriptionWidget($this->app);
        return $widget->render();
    }

    /**
     * Ensure that database table is present
     */
    protected function checkDatabase()
    {
        if (!$this->app['nls.storage']->checkSubscribersTableIntegrity()) {
            $this->app['nls.storage']->repairTables();
        }
    }

    /**
     * Load and check configuration
     */
    protected function loadConfig()
    {
        $this->config = $this->getConfig();

        // Load defaults and merge into config
        $this->defaults = $this->getConfigDefaults();
        $this->config = Tools::mergeConfiguration($this->defaults, $this->config);
    }

    /**
     * Get the config defaults from config.yml.dist
     *
     * @return array
     */
    public function getConfigDefaults()
    {
        $configdistfile = $this->basepath . '/config.yml.dist';

        // Check there's a config.yml.dist
        if (is_readable($configdistfile)) {
            $yamlparser = new \Symfony\Component\Yaml\Parser();
            return $yamlparser->parse(file_get_contents($configdistfile) . "\n");
        }

        // No default config (weird!)
        return array();
    }


}

