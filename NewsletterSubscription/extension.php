<?php
namespace NewsletterSubscription;
use Silex\Application;
use Symfony\Component\Validator\Constraints as Assert;

require_once "Storage.php";
require_once "Mailer.php";

class Extension extends \Bolt\BaseExtension
{
    protected $storage;
    protected $mailer;

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
                'highest_bolt_version' => "1.0",
        );

        return $data;

    }

    public function initialize()
    {
        $this->loadConfig();

        $this->storage = new Storage($this->app);
        $this->mailer = new Mailer($this->app, $this->config);

        $this->checkDatabase();

        $this->setUp();

        $this->addTwigFunction('newslettersubscription', 'newsletterSubscription');
    }

    public function newsletterSubscription()
    {
        $form = $this->createForm();

        $html = $this->processForm($form);

        return new \Twig_Markup($html, 'UTF-8');

    }

    protected function createForm()
    {
        $this->app['twig.loader.filesystem']->addPath(__DIR__);

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

    protected function processForm($form)
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

        } else { // GET

            $urlArgs = $this->app->request->query;
            if ($urlArgs->get('confirm')) {
                $showForm = false;
                $results = $this->confirmSubscriber($urlArgs->get('confirm'), $urlArgs->get('email'));
            } elseif ($urlArgs->get('unsubscribe')) {
                $showForm = false;
                $results = $this->unsubscribeSubscriber($urlArgs->get('unsubscribe'), $urlArgs->get('email'));
            }
        }

        $formhtml = $this->app['twig']
                         ->render($this->config['template'],
                         array(
                         "form" => $form->createView(),
                         "message" => isset($results['message']) ? $results['message'] : '',
                         "error" => isset($results['error']) ? $results['error'] : '',
                         "showform" => $showForm,
                         "button_text" => $this->config['button_text']
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
        // Config defaults
        $defaults = array(
                'stylesheet' => 'assets/nls.css',
                'template' => 'assets/nls_form.twig',
                'button_text' => 'Send',
                'form' => array(
                        'fields' => array(
                                'email' => array(
                                        'type' => 'email',
                                        'label' => 'Email',
                                        'placeholder' => 'Enter you email...',
                                        'class' => 'email',
                                        'required' => true,
                                ),
                                'agree' => array(
                                        'type' => 'checkbox',
                                        'label' => 'I agree',
                                        'placeholder' => 'Yes, I want to subscribe to your newsletter',
                                        'class' => 'checkbox',
                                        'required' => true,
                                )
                        )
                )
        );

        $config = $this->mergeConfiguration($defaults, $this->config);
        $this->config = $config;
    }

    protected function setUp()
    {
        // Insert the proper CSS
        $this->addCSS($this->config['stylesheet']);
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
        } else {
            $ret['error'] = $this->config['messages']['error_technical'];
            // delete the row just inserted
            $this->storage->deleteSubscriber($subscriber['email']);
        }

        // Notify admin if asked
        if ($this->config['email']['options']['notify_unconfirmed']) {
            $this->mailer->sendNotificationEmail($subscriber);
        }

        return $ret;
    }

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

        // At last, confirm it
        $subscriber['confirmed'] = true;
        $subscriber['dateconfirmed'] = date('Y-m-d H:i:s');
        $this->storage->updateSubscriber($subscriber);

        // Send confirmation email
        $res = $this->mailer->sendUserConfirmedEmail($subscriber);
        if ($res) {
            $ret['message'] = $this->config['messages']['confirmed'];
        } else {
            $ret['error'] = $this->config['messages']['error_technical'];
            // Unconfirm the confirmation just set
            $subscriber['confirmed'] = false;
            $subscriber['dateconfirmed'] = null;
            $this->storage->updateSubscriber($subscriber);
        }

        return $ret;
    }

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

        // Already unsubscribed?
        if (!$subscriber['active']) {
            $ret['error'] = $this->config['messages']['cannot_unsubscribe'];
            return $ret;
        }

        // At last, unsubscribe it
        $subscriber['active'] = false;
        $subscriber['dateunsubscribed'] = date('Y-m-d H:i:s');
        $this->storage->updateSubscriber($subscriber);

        // Send unsubscription email
        $res = $this->mailer->sendUserUnsubscriptionEmail($subscriber);
        if ($res) {
            $ret['message'] = $this->config['messages']['unsubscribed'];
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
     * Merge configuration array with defaults
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

