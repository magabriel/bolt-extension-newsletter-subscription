<?php
namespace NewsletterSubscription;

use Symfony\Component\Form\Form;
use Silex\Application;
use Symfony\Component\Validator\Constraints as Assert;

require_once "src/Storage.php";
require_once "src/Mailer.php";

/**
 * Newsletter Subscription Extension
 *
 * @author Miguel Angel Gabriel (magabriel@gmail.com)
 */
class Extension extends \Bolt\BaseExtension
{
    protected $storage;
    protected $mailer;
    protected $defaults;

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
                'version' => "1.0",
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

        $this->addTwigFunction('newslettersubscription', 'newsletterSubscription');
    }

    /**
     * Sets several things up
     */
    protected function setUp()
    {
        // Helper objects
        $this->storage = new Storage($this->app);
        $this->mailer = new Mailer($this->app, $this->config);

        // Twig path
        $this->app['twig.loader.filesystem']->addPath(__DIR__);

        // Insert the proper CSS
        $this->addCSS($this->config['stylesheet']);
    }

    /**
     * Twig function entry point
     *
     * @return \Twig_Markup
     */
    public function newsletterSubscription()
    {
        $html = '';

        switch ($this->app['request']->getMethod()) {
            case 'GET':
                $html = $this->processGetActions();

                if (!$html) {
                    // Default action is showing the form
                    $form = $this->createForm();
                    $html = $this->processForm($form);
                }

                break;

            case 'POST':
                $form = $this->createForm();
                $html = $this->processForm($form);

                break;

            default:
                $html = '<p>Invalid method</p>';
        }

        return new \Twig_Markup($html, 'UTF-8');

    }

    /**
     * Carry out the actions requested via a 'GET' method
     *
     * @return string HTML to show (blank = not handled)
     */
    protected function processGetActions()
    {
        $urlArgs = $this->app->request->query;

        $handled = false;

        if ($urlArgs->get('confirm')) {
            $results = $this->confirmSubscriber($urlArgs->get('confirm'), $urlArgs->get('email'));
            $handled = true;

        } elseif ($urlArgs->get('unsubscribe')) {
            $results = $this->unsubscribeSubscriber($urlArgs->get('unsubscribe'), $urlArgs->get('email'));
            $handled = true;

        } elseif ($urlArgs->get('adminaction') == 'download') {
            $this->downloadSubscribersFile($urlArgs->get('secret'));
            $handled = true;
        }

        if ($handled) {
            $html = $this->app['twig']
                         ->render($this->config['template_extra'],
                         array(
                            "message" => isset($results['message']) ? $results['message'] : '',
                            "error" => isset($results['error']) ? $results['error'] : ''
                    ));
            return $html;
        }

        return ''; // Not handled
    }

    /**
     * Create the subscribe form
     *
     * @return Form
     */
    protected function createForm()
    {
        $form = $this->app['form.factory']->createBuilder('form');

        $fields = $this->config['form']['fields'];

        $form
            ->add('email', 'email',
                array(
                        'label' => $fields['email']['label'],
                        'required' => true,
                        'constraints' => array(
                                new Assert\NotBlank()
                        ),
                        'attr' => array(
                                'placeholder' => $fields['email']['placeholder'],
                                'class' => $fields['email']['class'],
                        )
                ));

        $form
            ->add('agree', 'checkbox',
                array(
                        'label' => $fields['agree']['label'],
                        'required' => true,
                        'constraints' => array(
                                new Assert\NotBlank()
                        ),
                        'attr' => array(
                                'placeholder' => $fields['agree']['placeholder'],
                                'class' => $fields['agree']['class'],
                        )
                ));

        return $form->getForm();
    }

    /**
     * Process the subscribre form
     *
     * @param Form $form
     * @return string HTML
     */
    protected function processForm(Form $form)
    {
        $results = array();
        $showForm = true;

        if ('POST' == $this->app['request']->getMethod()) {

            $form->bind($this->app['request']);

            if ($form->isValid()) {
                $data = $form->getData();
                $results = $this->addSubscriber($data);
                $showForm = isset($results['error']);
            } else {
                $results['error'] = $this->config['messages']['error'];
            }
        }

        $formhtml = $this->app['twig']
                         ->render($this->config['template'],
                         array(
                         "form" => $form->createView(),
                         "message" => isset($results['message']) ? $results['message'] : '',
                         "error" => isset($results['error']) ? $results['error'] : '',
                         "showform" => $showForm,
                         "button_text" => $this->config['button_text'],
                         "secret" => isset($this->config['admin_secret']) ? $this->config['admin_secret'] : '',
                         "secret_default" => $this->defaults['admin_secret']
                ));

        return $formhtml;
    }

    /**
     * Ensure that database table is present
     */
    protected function checkDatabase()
    {
        if (!$this->storage->checkSubscribersTableIntegrity()) {
            $this->storage->repairTables();
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
        $this->config = $this->mergeConfiguration($this->defaults, $this->config);
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

    /**
     * Add a subscriber and create the relevant email messages
     *
     * @param array $data The subscriber data
     * @return array Results
     */
    protected function addSubscriber(array $data)
    {
        $ret = array();
        $isNew = false;

        $subscriber = $this->storage->findSubscriber($data['email']);

        // Already subscribed?
        if ($subscriber) {
            // Active? (could mean a former subscriber that unsubscribed)
            if ($subscriber['active']) {
                // Confirmed?
                if ($subscriber['confirmed']) {
                    $ret['error'] = $this->config['messages']['already_subscribed'];
                    return $ret;
                }
            } else {
                // An unsubscribed subscribed can resubscribe as a new subscriber (...)
                $old = $subscriber;
                $subscriber = $this->storage->initSubscriber($data['email']);
                $subscriber['id'] = $old['id'];
                $this->storage->updateSubscriber($subscriber);
                $isNew = true;
            }
        } else {
            // Create subscription
            $subscriber = $this->storage->insertSubscriber($data['email']);
            $isNew = true;
        }

        $ret['subscriber'] = $subscriber;

        // Ask confirmation to user via email
        $res = $this->mailer->sendUserConfirmationEmail($subscriber);
        if ($res) {
            if ($isNew) {
                $ret['message'] = $this->config['messages']['confirmation_sent'];
            } else {
                $ret['message'] = $this->config['messages']['confirmation_resent'];
            }
            // Notify admin if asked
            if ($this->config['email']['options']['notify_unconfirmed']) {
                $this->mailer->sendNotificationEmail($subscriber);
            }
        } else {
            $ret['error'] = $this->config['messages']['error_technical'];
            // delete the row just inserted
            $this->storage->deleteSubscriber($subscriber['email']);
        }

        return $ret;
    }

    /**
     * Confirm the subscription
     *
     * @param string $confirmKey The subscription key
     * @param string $email The subscriber's email
     * @return array Results
     */
    protected function confirmSubscriber($confirmKey, $email)
    {
        $ret = array();

        // Missing data?
        if (empty($confirmKey) || empty($email)) {
            $ret['error'] = $this->config['messages']['cannot_confirm'];
            return $ret;
        }

        // Bad email?
        $subscriber = $this->storage->findSubscriber($email);
        if (!$subscriber) {
            $ret['error'] = $this->config['messages']['cannot_confirm'];
            return $ret;
        }

        // Wrong key?
        if ($subscriber['confirmkey'] != $confirmKey) {
            $ret['error'] = $this->config['messages']['cannot_confirm'];
            return $ret;
        }

        // Already confirmed?
        if ($subscriber['confirmed']) {
            $ret['error'] = $this->config['messages']['cannot_confirm'];
            return $ret;
        }

        // At last, confirm him
        $subscriber['confirmed'] = true;
        $subscriber['dateconfirmed'] = date('Y-m-d H:i:s');
        $this->storage->updateSubscriber($subscriber);

        // Send confirmation email
        $res = $this->mailer->sendUserConfirmedEmail($subscriber);
        if ($res) {
            $ret['message'] = $this->config['messages']['confirmed'];
            // Notify admin
            $this->mailer->sendNotificationEmail($subscriber);
        } else {
            $ret['error'] = $this->config['messages']['error_technical'];
            // Unconfirm the confirmation just set
            $subscriber['confirmed'] = false;
            $subscriber['dateconfirmed'] = null;
            $this->storage->updateSubscriber($subscriber);
        }

        return $ret;
    }

    /**
     * Cancels the subscription
     *
     * @param string $confirmKey The subscription key
     * @param string $email The subscriber's email
     * @return array Results
     */
    protected function unsubscribeSubscriber($confirmKey, $email)
    {
        $ret = array();

        // Missing data?
        if (empty($confirmKey) || empty($email)) {
            $ret['error'] = $this->config['messages']['cannot_unsubscribe'];
            return $ret;
        }

        // Bad email?
        $subscriber = $this->storage->findSubscriber($email);
        if (!$subscriber) {
            $ret['error'] = $this->config['messages']['cannot_unsubscribe'];
            return $ret;
        }

        // Wrong key?
        if ($subscriber['confirmkey'] != $confirmKey) {
            $ret['error'] = $this->config['messages']['cannot_unsubscribe'];
            return $ret;
        }

        // Not yet confirmed?
        if (!$subscriber['confirmed']) {
            $ret['error'] = $this->config['messages']['cannot_unsubscribe'];
            return $ret;
        }

        // Already unsubscribed?
        if (!$subscriber['active']) {
            $ret['error'] = $this->config['messages']['cannot_unsubscribe'];
            return $ret;
        }

        // At last, unsubscribe him
        $subscriber['active'] = false;
        $subscriber['dateunsubscribed'] = date('Y-m-d H:i:s');
        $this->storage->updateSubscriber($subscriber);

        // Send unsubscription email
        $res = $this->mailer->sendUserUnsubscriptionEmail($subscriber);
        if ($res) {
            $ret['message'] = $this->config['messages']['unsubscribed'];
            // Notify admin if asked
            if ($this->config['email']['options']['notify_unsubscribed']) {
                $this->mailer->sendNotificationEmail($subscriber);
            }
        } else {
            $ret['error'] = $this->config['messages']['error_technical'];
            // Undo the unsubscription just set
            $subscriber['active'] = false;
            $subscriber['dateunsubscribed'] = null;
            $this->storage->updateSubscriber($subscriber);
        }

        return $ret;
    }

    /**
     * Send a CSV file with all subscribers to the browser to be downloaded.
     *
     * @param string $secret Secret string to authorize the download
     */
    protected function downloadSubscribersFile($secret)
    {
        // Only start the download if the admin_secret has been set and is different from default
        if (!isset($this->config['admin_secret']) || $this->config['admin_secret'] == $this->defaults['admin_secret']) {
            return;
        }

        // Check correct secret
        if ($secret != $this->config['admin_secret']) {
            return;
        }

        $subscribers = $this->storage->findAllSubscribers();

        if (!$subscribers) {
            // Nothing to do
            return;
        }

        $quote = '"';
        $sep = ';';

        $lines = array();

        // Headers
        $keys = array_keys($subscribers[0]);
        $keys[] = 'unsubscribe_link';
        $lines[] = $quote . implode($quote . $sep . $quote, $keys) . $quote;

        // Records
        foreach ($subscribers as $subscriber) {
            // Add unsubscribe link when it does make sense
            $subscriber['unsubscribe_link'] = '';
            if ($subscriber['confirmed'] && $subscriber['active']) {
                $subscriber['unsubscribe_link'] = sprintf('%s?unsubscribe=%s&email=%s', $this->app['paths']['canonicalurl'],
                    $subscriber['confirmkey'], $subscriber['email']);
            }

            $lines[] = $quote . implode($quote . $sep . $quote, $subscriber) . $quote;
        }

        $data = implode("\n", $lines);

        \util::force_download('subcribers.csv', $data);
    }

    /**
     * Merge configuration array with defaults (recursive)
     * @see http://www.php.net/manual/en/function.array-merge-recursive.php#92195
     *
     * @param array $defaults
     * @param array $configuration
     * @return array merged configuration with defaults
     */
    protected function mergeConfiguration(array $defaults, array $configuration)
    {
        $merged = $defaults;

        foreach ($configuration as $key => &$value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->mergeConfiguration($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}

