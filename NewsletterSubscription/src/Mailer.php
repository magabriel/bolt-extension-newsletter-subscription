<?php
namespace NewsletterSubscription;

use Bolt\Application;

/**
 * Manages the mails sent by the extension
 *
 * @author Miguel Angel Gabriel (magabriel@gmail.com)
 */
class Mailer
{
    protected $config = array();
    protected $app;

    public function __construct($app)
    {
        $this->app = $app;
        $this->config = $this->app['nls.config'];
    }

    public function sendUserConfirmationEmail(array $data)
    {
        $res = $this->sendEmail(
                $this->config['email']['messages']['confirmation']['subject'],
                array(
                        $this->config['email']['sender']['email'] => $this->config['email']['sender']['name']
                ),
                array(
                        $data['email'] => $data['email']
                ),
                $this->config['email']['messages']['confirmation']['template'],
                $data);

        return $res;
    }

    public function sendUserUnsubscriptionEmail(array $data)
    {
        $res = $this->sendEmail(
                $this->config['email']['messages']['unsubscribed']['subject'],
                array(
                        $this->config['email']['sender']['email'] => $this->config['email']['sender']['name']
                ),
                array(
                        $data['email'] => $data['email']
                ),
                $this->config['email']['messages']['unsubscribed']['template'],
                $data);

        return $res;
    }

    public function sendUserConfirmedEmail(array $data)
    {
        $res = $this->sendEmail(
                $this->config['email']['messages']['confirmed']['subject'],
                array(
                        $this->config['email']['sender']['email'] => $this->config['email']['sender']['name']
                ),
                array(
                        $data['email'] => $data['email']
                ),
                $this->config['email']['messages']['confirmed']['template'],
                $data);

        return $res;
    }

    public function sendNotificationEmail(array $data)
    {
        $subject = $this->config['email']['messages']['notification']['subject'];
        if (!$data['confirmed']) {
            $subject = $this->config['email']['messages']['notification_unconfirmed']['subject'];
        } elseif (!$data['active']) {
            $subject = $this->config['email']['messages']['notification_unsubscribed']['subject'];
        }

        // $data contains 'field' => 'value' for subscriber and 'extra_fields' array

        // Flatten the extra fields into the data for easier handling into the template
        if (isset($data['extra_fields'])) {
            foreach ($data['extra_fields'] as $field) {
                $data[$field['name']] = $field['value'];
            }
        }

        // Transform into 'field label' => 'value' for only the user entered fields
        // Also, make checkboxes into 'yes/no' fields
        $fieldsData = array();
        foreach ($data as $field => $value) {
            $fieldDef = null;
            if (isset($this->config['form']['fields'][$field])) {
                $fieldDef = $this->config['form']['fields'][$field];
            } elseif (isset($this->config['form']['extra_fields'][$field])) {
                $fieldDef = $this->config['form']['extra_fields'][$field];
            }

            if ($fieldDef) {
                if ('checkbox' == $fieldDef['type']) {
                    $value = ($value == "1" ? 'yes' : 'no');
                }
                $fieldsData[$fieldDef['label']] = $value;
            }
        }

        // Add fields information to data
        $data['fields'] = $fieldsData;

        $res = $this->sendEmail(
                $subject,
                array(
                        $this->config['email']['sender']['email'] => $this->config['email']['sender']['name']
                ),
                array(
                        $this->config['email']['notify_to']['email'] => $this->config['email']['notify_to']['name']
                ),
                $this->config['email']['messages']['notification']['template'],
                $data);

        return $res;
    }

    protected function sendEmail($subject, $from, $to, $template, array $data)
    {
        $htmlBody = $this->app['twig']->render($template, array(
                         'data' => $data
            ));

        if ($this->config['email']['options']['prepend_sitename']) {
            $subject = '[' . $this->app['config']['general']['sitename'] . '] ' . $subject;
        }

        $message = \Swift_Message::newInstance()->setSubject($subject)->setFrom($from)->setTo($to)
                                                ->setBody(strip_tags($htmlBody))->addPart($htmlBody, 'text/html');

        $res = $this->app['mailer']->send($message);

        return $res;
    }
}
