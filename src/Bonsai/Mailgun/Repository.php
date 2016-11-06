<?php

namespace Bonsai\Mailgun;

// External dependencies;
use Http\Client\HttpClient;
use Mailgun\Connection\Exceptions\MissingEndpoint;
use Mailgun\Mailgun;

// Internal dependencies.
use Bonsai\MessageTransformerInterface;
use Bonsai\Mailgun\EventsRetrievedEvent;
use Bonsai\RepositoryInterface;

class Repository implements RepositoryInterface {
  private $mailgun;
  private $dispatcher;
  private $apiKey;
  private $transformer;

  public function __construct($mailgun, $dispatcher, $apiKey, MessageTransformerInterface $transformer) {
    $this->mailgun     = $mailgun;
    $this->dispatcher  = $dispatcher;
    $this->apiKey      = $apiKey;
    $this->transformer = $transformer;
  }

  public function getList(array $options = []) {
    if (empty($options['domain'])) {
      throw new \Exception('The domain for which to retrieve the list of messages must be provided.');
    }

    $domain = $options['domain'];

    $params = ['event' => 'stored'];

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
    $event = new EventsRetrievedEvent($events);
    $this->dispatcher->dispatch(EventsRetrievedEvent::NAME, $event);

    if (empty($events->items)) {
      return [];
    }

    $messages = [];
    foreach ($events->items as $event) {
      $messages[] = $this->getOne($event->storage->url);
    }

    return $messages;
  }

  public function getOne($url) {
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
      return;
    }

    $message = $response->http_response_body;

    return $this->transformer->transform($message);
  }
}
