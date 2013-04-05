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

    public function __construct($app, array $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    public function sendUserConfirmationEmail(array $data)
    {
        $res = $this->sendEmail(
                $this->config['email']['messages']['confirmation']['subject'],
                array($this->config['email']['sender']['email'] => $this->config['email']['sender']['name']),
                array($data['email'] => $data['email']),
                $this->config['email']['messages']['confirmation']['template'],
                $data);

        return $res;
    }

    public function sendUserUnsubscriptionEmail(array $data)
    {
        $res = $this->sendEmail(
                $this->config['email']['messages']['unsubscribed']['subject'],
                array($this->config['email']['sender']['email'] => $this->config['email']['sender']['name']),
                array($data['email'] => $data['email']),
                $this->config['email']['messages']['unsubscribed']['template'],
                $data);

        return $res;
    }

    public function sendUserConfirmedEmail(array $data)
    {
        $res = $this->sendEmail(
                $this->config['email']['messages']['confirmed']['subject'],
                array($this->config['email']['sender']['email'] => $this->config['email']['sender']['name']),
                array($data['email'] => $data['email']),
                $this->config['email']['messages']['confirmed']['template'],
                $data);

        return $res;
    }

    public function sendNotificationEmail(array $data)
    {
        $subject =  $this->config['email']['messages']['notification']['subject'];
        if (!$data['confirmed']) {
            $subject =  $this->config['email']['messages']['notification_unconfirmed']['subject'];
        } elseif (!$data['active']) {
            $subject =  $this->config['email']['messages']['notification_unsubscribed']['subject'];
        }

        $res = $this->sendEmail(
                $subject,
                array($this->config['email']['sender']['email'] => $this->config['email']['sender']['name']),
                array($this->config['email']['notify_to']['email'] => $this->config['email']['notify_to']['name']),
                $this->config['email']['messages']['notification']['template'],
                $data);

        return $res;
    }

    protected function sendEmail($subject, $from, $to, $template, array $data)
    {
        $htmlBody = $this->app['twig']->render(
                $template,
                array('data' =>  $data )
        );

        if ($this->config['email']['options']['prepend_sitename']) {
            $subject = '['.$this->app['config']['general']['sitename'].'] '.$subject;
        }

        $message = \Swift_Message::newInstance()
                        ->setSubject($subject)
                        ->setFrom($from)
                        ->setTo($to)
                        ->setBody(strip_tags($htmlBody))
                        ->addPart($htmlBody, 'text/html');

        $res = $this->app['mailer']->send($message);

        return $res;
    }
}