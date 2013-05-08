<?php

namespace NewsletterSubscription;

use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\Form;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Newsletter Subscription function
 *
 * @author Miguel Angel Gabriel (magabriel@gmail.com)
 */
class NewsletterSubscriptionFunction
{
    protected $app;
    protected $config;

    public function __construct($app)
    {
        $this->app = $app;
        $this->config = $this->app['nls.config'];

        // Set Twig path
        $this->app['twig.loader.filesystem']->addPath(__DIR__.'/../');
    }

    public function process()
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
        $urlArgs = Tools::getUrlArgs($this->app['paths']['currenturl']);

        $handled = false;

        if (isset($urlArgs['confirm'])) {
            $email = isset($urlArgs['email']) ? $urlArgs['email'] : '';
            $results = $this->confirmSubscriber($urlArgs['confirm'], $email);
            $handled = true;

        } elseif (isset($urlArgs['unsubscribe'])) {
            $email = isset($urlArgs['email']) ? $urlArgs['email'] : '';
            $results = $this->unsubscribeSubscriber($urlArgs['unsubscribe'], $email);
            $handled = true;

        } elseif (isset($urlArgs['adminaction']) && $urlArgs['adminaction'] == 'download') {
            $secret = isset($urlArgs['secret']) ? $urlArgs['secret'] : '';
            $this->app['nls.subscribers_file']->download($secret);
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

        // Form standard fields are always required
        $this->createFormFields($form, $this->config['form']['fields'], true);

        // Form extra fields do not need to be required
        $this->createFormFields($form, $this->config['form']['extra_fields']);

        return $form->getForm();
    }

    protected function createFormFields(FormBuilder $form, array $fields, $forceRequired = false)
    {

        foreach ($fields as $name => $field) {

            $options = array();

            if (!empty($field['label'])) {
                $options['label'] = $field['label'];
            }
            if (!empty($field['placeholder'])) {
                $options['attr']['placeholder'] = $field['placeholder'];
            }
            if (!empty($field['class'])) {
                $options['attr']['class'] = $field['class'];
            }

            if ($forceRequired || (!empty($field['required']) && $field['required'] == true)) {
                $options['required'] = true;
                $options['constraints'][] = new Assert\NotBlank();
            } else {
                $options['required'] = false;
            }
            if (!empty($field['choices']) && is_array($field['choices'])) {
                // Make the keys more sensible.
                $options['choices'] = array();
                foreach ($field['choices'] as $option) {
                    $options['choices'][safeString($option)] = $option;
                }
            }
            if (!empty($field['expanded'])) {
                $options['expanded'] = $field['expanded'];
            }
            if (!empty($field['multiple'])) {
                $options['multiple'] = $field['multiple'];
            }
            // Make sure $field has a type, or the form will break.
            if (empty($field['type'])) {
                $field['type'] = "text";
            } elseif ($field['type'] == "email") {
                $options['constraints'][] = new Assert\Email();
            }

            $form->add($name, $field['type'], $options);

        }

        return $form;
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
                        "secret_default" => $this->app['nls.defaults']['admin_secret']
                ));

        return $formhtml;
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

        $subscriber = $this->app['nls.storage']->subscriberFind($data['email']);

        // Check if the subscriber exists, is active and confirmed
        if ($subscriber && $subscriber['active'] && $subscriber['confirmed']) {
            $ret['error'] = $this->config['messages']['already_subscribed'];
            return $ret;
        }

        $db = $this->app['db'];

        $db->beginTransaction();
        try {
            $isNew = true;

            if ($subscriber) {
                // Either unactive (=> unsubscribed) or unconfirmed (=> new subscription attempt)
                // Discard all old information
                $this->app['nls.storage']->subscriberDelete($subscriber['email']);

                if ($subscriber['active'] && !$subscriber['confirmed']) {
                    $isNew = false; // Just for notifying a "resend" instead of a "send"
                }
            }

            // Create subscription for new subscriber
            $subscriber = $this->app['nls.storage']->subscriberInsert($data);

            $ret['subscriber'] = $subscriber;

            // Ask confirmation to user via email
            $res = $this->app['nls.mailer']->sendUserConfirmationEmail($subscriber);
            if ($res) {
                if ($isNew) {
                    $ret['message'] = $this->config['messages']['confirmation_sent'];
                } else {
                    $ret['message'] = $this->config['messages']['confirmation_resent'];
                }
                // Notify admin if asked
                if ($this->config['email']['options']['notify_unconfirmed']) {
                    $this->app['nls.mailer']->sendNotificationEmail($subscriber);
                }
            } else {
                // Error sending: forces the subscription to be rolled back (because
                // the user never received the confirmation message so he cannot confirm...)
                throw new \Exception('Could not send confirmation email');
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();

            $ret['error'] = $this->config['messages']['error_technical'];
            $this->app['log']->add($e->getMessage(), 2);
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

        $subscriber = $this->app['nls.storage']->subscriberFind($email);

        // Bad email?
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

        $db = $this->app['db'];

        $db->beginTransaction();
        try {
            // At last, confirm him
            $subscriber['confirmed'] = true;
            $subscriber['dateconfirmed'] = date('Y-m-d H:i:s');
            $subscriber['unsubscribe_link'] = sprintf('%s?unsubscribe=%s&email=%s', $this->app['paths']['canonicalurl'],
                    $subscriber['confirmkey'], $subscriber['email']);

            $this->app['nls.storage']->subscriberUpdate($subscriber);

            // Send confirmation email
            $res = $this->app['nls.mailer']->sendUserConfirmedEmail($subscriber);
            if ($res) {
                $ret['message'] = $this->config['messages']['confirmed'];
                // Notify admin
                $this->app['nls.mailer']->sendNotificationEmail($subscriber);
            } else {
                // Error sending: forces the confirmation to be rolled back (because
                // the user never received the "confirmed" message so he cannot know it done...)
                throw new \Exception(sprintf('Could not send "confirmed" email to "%s"', $subscriber['email']));
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();

            $ret['error'] = $this->config['messages']['error_technical'];
            $this->app['log']->add($e->getMessage(), 2);
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
        $subscriber = $this->app['nls.storage']->subscriberFind($email);
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

        $db = $this->app['db'];

        $db->beginTransaction();
        try {
            // At last, unsubscribe him
            $subscriber['active'] = false;
            $subscriber['dateunsubscribed'] = date('Y-m-d H:i:s');
            $subscriber['unsubscribe_link'] = null; // no longer needed

            $this->app['nls.storage']->subscriberUpdate($subscriber);

            // Send unsubscription email
            $res = $this->app['nls.mailer']->sendUserUnsubscriptionEmail($subscriber);
            if ($res) {
                $ret['message'] = $this->config['messages']['unsubscribed'];
                // Notify admin if asked
                if ($this->config['email']['options']['notify_unsubscribed']) {
                    $this->app['nls.mailer']->sendNotificationEmail($subscriber);
                }
            } else {
                // Error sending: forces the unsubscription to be rolled back (because
                // the user never received the "unsubscribed" message so he cannot know it done...)
                throw new \Exception(sprintf('Could not send "unsubscribed" email to "%s"', $subscriber['email']));
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();

            $ret['error'] = $this->config['messages']['error_technical'];
            $this->app['log']->add($e->getMessage(), 2);
        }

        return $ret;
    }
}
