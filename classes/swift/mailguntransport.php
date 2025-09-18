<?php defined('SYSPATH') OR die('No direct access allowed.');

/*
 * Custom Mailgun Transport for SwiftMailer
 * Compatible with PHP 5.6
 * Part of the kohana-email-new module
 */

/**
 * Sends Messages using the Mailgun API.
 *
 * @package    Swift
 * @subpackage Transport
 */
class Swift_MailgunTransport implements Swift_Transport
{
    /** Mailgun API configuration */
    private $_api_key;
    private $_domain;
    private $_api_url;

    /** The event dispatcher from the plugin API */
    private $_eventDispatcher;

    /** Transport started state */
    private $_started = false;

    /**
     * Create a new MailgunTransport.
     *
     * @param string $api_key Mailgun API key
     * @param string $domain Mailgun domain
     * @param string $api_url Mailgun API URL (optional)
     * @param Swift_Events_EventDispatcher $eventDispatcher
     */
    public function __construct($api_key, $domain, $api_url = 'https://api.mailgun.net/v3/', Swift_Events_EventDispatcher $eventDispatcher = null)
    {
        $this->_api_key = $api_key;
        $this->_domain = $domain;
        $this->_api_url = rtrim($api_url, '/') . '/';
        $this->_eventDispatcher = $eventDispatcher;
    }

    /**
     * Test if this Transport mechanism has started.
     *
     * @return boolean
     */
    public function isStarted()
    {
        return $this->_started;
    }

    /**
     * Start this Transport mechanism.
     */
    public function start()
    {
        $this->_started = true;
    }

    /**
     * Stop this Transport mechanism.
     */
    public function stop()
    {
        $this->_started = false;
    }

    /**
     * Send the given Message via Mailgun API.
     *
     * Recipient/sender data will be retrieved from the Message API.
     * The return value is the number of recipients who were accepted for delivery.
     *
     * @param Swift_Mime_Message $message
     * @param string[]           $failedRecipients An array of failures by-reference
     *
     * @return int
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $failedRecipients = (array) $failedRecipients;

        if ($this->_eventDispatcher && $evt = $this->_eventDispatcher->createSendEvent($this, $message)) {
            $this->_eventDispatcher->dispatchEvent($evt, 'beforeSendPerformed');
            if ($evt->bubbleCancelled()) {
                return 0;
            }
        }

        try {
            // Build Mailgun API data from message
            $data = $this->_buildApiData($message);
            
            // Send via Mailgun API
            $response = $this->_sendApiRequest($data);
            
            // Count successful recipients
            $count = (
                count((array) $message->getTo())
                + count((array) $message->getCc())
                + count((array) $message->getBcc())
            );

            if ($this->_eventDispatcher && $evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
                $this->_eventDispatcher->dispatchEvent($evt, 'sendPerformed');
            }

            return $count;

        } catch (Exception $e) {
            if ($this->_eventDispatcher && $evt) {
                $evt->setResult(Swift_Events_SendEvent::RESULT_FAILED);
                $this->_eventDispatcher->dispatchEvent($evt, 'sendPerformed');
            }
            
            // Add all recipients to failed list
            $recipients = array_merge(
                array_keys((array) $message->getTo()),
                array_keys((array) $message->getCc()),
                array_keys((array) $message->getBcc())
            );
            $failedRecipients = array_merge($failedRecipients, $recipients);
            
            throw new Swift_TransportException('Mailgun API error: ' . $e->getMessage());
        }
    }

    /**
     * Register a plugin in the Transport.
     *
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        if ($this->_eventDispatcher) {
            $this->_eventDispatcher->bindEventListener($plugin);
        }
    }

    /**
     * Build Mailgun API data from Swift message
     *
     * @param Swift_Mime_Message $message
     * @return array
     */
    private function _buildApiData(Swift_Mime_Message $message)
    {
        $data = array();

        // From address
        $from = $message->getFrom();
        if (is_array($from)) {
            $fromEmail = key($from);
            $fromName = current($from);
            $data['from'] = $fromName ? $fromName . ' <' . $fromEmail . '>' : $fromEmail;
        } else {
            $data['from'] = $from;
        }

        // To recipients
        $to = $message->getTo();
        if ($to) {
            $data['to'] = $this->_formatRecipients($to);
        }

        // CC recipients
        $cc = $message->getCc();
        if ($cc) {
            $data['cc'] = $this->_formatRecipients($cc);
        }

        // BCC recipients
        $bcc = $message->getBcc();
        if ($bcc) {
            $data['bcc'] = $this->_formatRecipients($bcc);
        }

        // Reply-To
        $replyTo = $message->getReplyTo();
        if ($replyTo) {
            $data['h:Reply-To'] = $this->_formatRecipients($replyTo);
        }

        // Subject
        $data['subject'] = $message->getSubject();

        // Body
        $contentType = $message->getContentType();
        if ($contentType === 'text/html') {
            $data['html'] = $message->getBody();
        } else {
            $data['text'] = $message->getBody();
        }

        // Handle multipart messages
        $children = $message->getChildren();
        foreach ($children as $child) {
            if ($child instanceof Swift_Mime_MimePart) {
                if ($child->getContentType() === 'text/html') {
                    $data['html'] = $child->getBody();
                } elseif ($child->getContentType() === 'text/plain') {
                    $data['text'] = $child->getBody();
                }
            }
        }

        return $data;
    }

    /**
     * Format recipients for Mailgun API
     *
     * @param array $recipients
     * @return string
     */
    private function _formatRecipients($recipients)
    {
        $formatted = array();
        foreach ($recipients as $email => $name) {
            if ($name) {
                $formatted[] = $name . ' <' . $email . '>';
            } else {
                $formatted[] = $email;
            }
        }
        return implode(',', $formatted);
    }

    /**
     * Send API request to Mailgun
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    private function _sendApiRequest($data)
    {
        $url = $this->_api_url . $this->_domain . '/messages';
        
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $this->_api_key);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL error: ' . $error);
        }
        
        $decoded_response = json_decode($response, true);
        
        if ($http_code >= 400) {
            $error_message = 'HTTP ' . $http_code;
            if (isset($decoded_response['message'])) {
                $error_message .= ': ' . $decoded_response['message'];
            }
            throw new Exception($error_message);
        }
        
        return $decoded_response;
    }
}