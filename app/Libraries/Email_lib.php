<?php

namespace app\Libraries;

use CodeIgniter\Email\Email;
use Config\OSPOS;
use Config\Services;

/**
 * Email library
 *
 * Library with utilities to configure and send emails
 */
class Email_lib
{
    private Email $email;
    private array $config;

    /**
     * Builds the email service without decrypting a stored password.
     */
    public function __construct()
    {
        $this->email  = new Email();
        $this->config = config(OSPOS::class)->settings;
    }

    /**
     * Returns the stored SMTP password when a usable encryption key exists.
     */
    private function getSmtpPassword(): string
    {
        $smtp_pass = $this->config['smtp_pass'] ?? '';
        if (empty($smtp_pass) || empty(config('Encryption')->key)) {
            return '';
        }

        return Services::encrypter()->decrypt($smtp_pass);
    }

    /**
     * Email sending function.
     *
     * A missing encryption key leaves the stored SMTP password empty.
     *
     * Example of use: $response = sendEmail('john@doe.com', 'Hello', 'This is a message', $filename);
     */
    public function sendEmail(string $to, string $subject, string $message, ?string $attachment = null): bool
    {
        $email_config = [
            'mailType'    => 'html',
            'userAgent'   => 'OSPOS',
            'validate'    => true,
            'protocol'    => $this->config['protocol'],
            'mailPath'    => $this->config['mailpath'],
            'SMTPHost'    => $this->config['smtp_host'],
            'SMTPUser'    => $this->config['smtp_user'],
            'SMTPPass'    => $this->getSmtpPassword(),
            'SMTPPort'    => (int) $this->config['smtp_port'],
            'SMTPTimeout' => (int) $this->config['smtp_timeout'],
            'SMTPCrypto'  => $this->config['smtp_crypto'],
        ];
        $this->email->initialize($email_config);
        $email = $this->email;

        $email->setFrom($this->config['email'], $this->config['company']);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        if (! empty($attachment)) {
            $email->attach($attachment);
        }

        $result = $email->send();

        if (! $result) {
            error_log($email->printDebugger());
        }

        return $result;
    }
}
