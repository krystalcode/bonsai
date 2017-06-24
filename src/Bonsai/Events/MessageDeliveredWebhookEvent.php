<?php

namespace Bonsai\Mailgun;

use Symfony\Component\EventDispatcher\Event;

class MessageDeliveredWebhookEvent extends Event {
  const NAME = 'bonsai.webhooks.message_delivered';

  private $message;

  public function __construct($message) {
    $this->message = $message;
  }

  public function getMessage() {
    return $this->message;
  }
}
