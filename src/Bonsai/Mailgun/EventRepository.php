<?php

namespace Bonsai\Mailgun;

// External dependencies;
use Mailgun\Constants\Api as Sdk;
use Symfony\Component\EventDispatcher\EventDispatcher;

// Internal dependencies.
use Bonsai\EventRepositoryInterface;
use Bonsai\EventTransformerInterface;
use Bonsai\Mailgun\EventsRetrievedEvent;

class EventRepository implements EventRepositoryInterface {
  private $mailgun;
  private $dispatcher;
  private $apiKey;
  private $eventTransformer;

  /**
   * @Issue(
   *   "Make the EventDispatcher and the EventTransformer optional and do not
   *   perform related operations if they are not provided"
   *   type="improvement"
   *   priority="normal"
   *   labels="api, 1.0.0"
   * )
   * @Issue(
   *   "Add an Event class and return the events in that format if they are
   *   not transformed"
   *   type="improvement"
   *   priority="normal"
   *   labels="api, 1.0.0"
   * )
   */
  public function __construct(
    $mailgun,
    $apiKey,
    EventDispatcher $dispatcher = NULL,
    EventTransformerInterface $eventTransformer = NULL
  ) {
    $this->mailgun     = $mailgun;
    $this->dispatcher  = $dispatcher;
    $this->apiKey      = $apiKey;

    $this->eventTransformer = $eventTransformer;

    // Strangely, in SDK v2 the default Mailgun API version is set to 2 and
    // things do not work. Manually set it to 3.
    if ($this->sdkMajorVersion() === '2') {
      $this->mailgun->setApiVersion('v3');
    }
  }

  /**
   * @Issue(
   *   "Implement scrolling/pagination for fetching large number of events"
   *   type="bug"
   *   priority="normal"
   *   labels="api, 1.0.0"
   * )
   */
  public function getList(array $options = []) {
    //$options = array_merge(['event_type' => 'stored'], $options);
    if (empty($options['domain'])) {
      throw new \Exception('The domain for which to retrieve the list of events must be provided.');
    }

    $domain = $options['domain'];

    $params = [];
    if (!empty($options['event_type'])) {
      $params['event'] = $options['event_type'];
    }

    if (!empty($options['time_range'])) {
      $now             = now();
      $params['begin'] = $now - $options['time_range'];
      $params['end']   = $now;
    }

    if (!empty($options['limit'])) {
      $params['limit'] = $options['limit'];
    }

    // Get the events.
    $response = $this->mailgun->get("$domain/events", $params);
    $events = $response->http_response_body;

    if (empty($events->items)) {
      return [];
    }

    // Allow the application to alter the events for which we will be retrieving
    // the messages. This can be useful to allow filtering out the messages that
    // have already been processed.
    if ($this->dispatcher) {
      $event = new EventsRetrievedEvent($events);
      $this->dispatcher->dispatch(EventsRetrievedEvent::NAME, $event);
    }

    if (empty($events->items)) {
      return [];
    }

    if (!$this->eventTransformer) {
      return $events->items;
    }

    $transformed_events = [];
    $transformer_options = [];
    if (!empty($options['transformer_options'])) {
      $transformer_options['transformer_options'] = $options['transformer_options'];
    }
    foreach ($events->items as $event) {
      $transformed_events[] = $this->eventTransformer->transform($event, $transformer_options);
    }

    return $transformed_events;
  }

  protected function sdkMajorVersion() {
    return substr(Sdk::SDK_VERSION, 0, 1);
  }
}
