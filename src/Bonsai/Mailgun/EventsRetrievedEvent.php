<?php

namespace Bonsai\Mailgun;

use Symfony\Component\EventDispatcher\Event;

class EventsRetrievedEvent extends Event {
  const NAME = 'bonsai.mailgun.events_retrieved';

  private $events;

  public function __construct($events) {
    $this->events = $events;
  }

  public function getEVents() {
    return $this->events;
  }
}
