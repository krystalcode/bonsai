<?php

namespace Bonsai\Mailgun;

// External dependencies;
use Http\Client\HttpClient;
use Mailgun\Connection\Exceptions\MissingEndpoint;
use Mailgun\Mailgun;
use Symfony\Component\EventDispatcher\EventDispatcher;

// Internal dependencies.
use Bonsai\MessageTransformerInterface;
use Bonsai\Mailgun\EventsRetrievedEvent;
use Bonsai\RepositoryInterface;

class Repository implements RepositoryInterface {
  private $mailgun;
  private $dispatcher;
  private $apiKey;
  private $transformer;

  /**
   * @Issue(
   *   "Make the EventDispatcher and the MessageTransformer optional and do not
   *   perform related operations if they are not provided"
   *   type="improvement"
   *   priority="normal"
   *   labels="api, 1.0.0"
   * )
   * @Issue(
   *   "Add a Message class and return the messages in that format if they are
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
    MessageTransformerInterface $transformer = NULL
  ) {
    $this->mailgun     = $mailgun;
    $this->dispatcher  = $dispatcher;
    $this->apiKey      = $apiKey;
    $this->transformer = $transformer;
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
    $options = array_merge(['event_type' => 'stored'], $options);
    if (empty($options['domain'])) {
      throw new \Exception('The domain for which to retrieve the list of messages must be provided.');
    }

    $domain = $options['domain'];

    $params = [];
    if ($options['event_type']) {
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

    $messages = [];
    $message_options = [];
    if (!empty($options['transformer_options'])) {
        $message_options['transformer_options'] = $options['transformer_options'];
    }
    foreach ($events->items as $event) {
      $messages[] = $this->getOne($event->storage->url, $message_options);
    }

    // Filter the results for empty values. This can happen for messages that are
    // older than 3 days and they are not available anymore.
    return array_filter($messages);
  }

  public function getOne($url, array $options = []) {
    $urlParts = explode('/', $url);

    $protocol    = array_shift($urlParts);
    $nothing     = array_shift($urlParts);
    $apiHost     = array_shift($urlParts);
    $apiVersion  = array_shift($urlParts);
    $endpointUrl = implode('/', $urlParts);

    $mailgun = new Mailgun($this->apiKey, $apiHost, $apiVersion);

    try {
      $response = $mailgun->get($endpointUrl);
    }
    // If Mailgun throws a MissingEndpoint exception, the messages has been
    // deleted from the Mailgun servers and it cannot be retrieved any
    // longer. Mailgun only keeps messages stored for up to 3 days.
    catch (MissingEndpoint $e) {
      return FALSE;
    }

    $message = $response->http_response_body;

    if ($this->transformer) {
        $transformer_options = [];
        if (!empty($options['transformer_options'])) {
            $transformer_options['transformer_options'] = $options['transformer_options'];
        }
        return $this->transformer->transform($message, $transformer_options);
    }

    return $message;
  }
}
