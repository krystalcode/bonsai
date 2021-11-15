<?php

namespace Bonsai\Mailgun;

// External dependencies;
use GuzzleHttp\Exception\ConnectException;
use Http\Client\HttpClient;
use Mailgun\Connection\Exceptions\MissingEndpoint;
use Mailgun\Mailgun;
use Mailgun\Constants\Api as Sdk;
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
    /*
     * @Issue(
     *   "Use the SDK's Event API to feth events"
     *   type="task"
     *   priority="normal"
     * )
     */
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
    if (!empty($options['include_raw'])) {
        $message_options['include_raw'] = $options['include_raw'];
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

    $mailgun = $this->getMailgun($apiHost, $apiVersion);

    try {
      /*
       * @Issue(
       *   "Use the SDK's Message API to feth messages"
       *   type="task"
       *   priority="normal"
       * )
       */
      $response = $mailgun->get($endpointUrl);
    }
    // If Mailgun throws a MissingEndpoint exception, the messages has been
    // deleted from the Mailgun servers and it cannot be retrieved any
    // longer. Mailgun only keeps messages stored for up to 3 days.
    catch (MissingEndpoint $e) {
      return FALSE;
    }
    // If there is a connection problem, proceed without throwing an exception.
    // The message should still be processed next time around.
    /**
     * @Issue(
     *   "Log errors when not being able to connect to the Mailgun API"
     *   type="improvement"
     *   priority="normal"
     *   labels="error management, log management"
     * )
     */
    catch (ConnectException $e) {
        return FALSE;
    }

    $message = $response->http_response_body;

    // Include the raw data in the message, if requested.
    /**
     * @Issue(
     *   "Store remote message info such as provider, event ID, message URL etc"
     *   type="feature"
     *   priority="normal"
     */
    if (!empty($options['include_raw'])) {
        try {
            $response = $mailgun->get(
                $endpointUrl,
                [],
                ['Accept' => 'message/rfc2822']
            );
            if ($response->http_response_code === 200) {
                $message->bonsai = [
                    'raw' => json_encode($response->http_response_body),
                ];
            }
        }
        catch (\Exception $e) {
            // In some very rare cases we have been consistently getting an
            // exception on the request to include the raw message; probably an
            // internal Mailgun issue. Catch the exception in that case so that
            // the message is processed even without the raw data.
        }
    }

    if ($this->transformer) {
        $transformer_options = [];
        if (!empty($options['transformer_options'])) {
            $transformer_options['transformer_options'] = $options['transformer_options'];
        }
        return $this->transformer->transform($message, $transformer_options);
    }

    return $message;
  }

  protected function getMailgun($apiHost, $apiVersion) {
    switch ($this->sdkMajorVersion()) {
      case '1':
        return new Mailgun($this->apiKey, $apiHost, $apiVersion);

      case '2':
        $mailgun = new Mailgun($this->apiKey, null, $apiHost);
        $mailgun->setApiVersion($apiVersion);
        return $mailgun;

      default:
        throw new \Exception('Unsupported version of Mailgun SDK.');
    }
  }

  protected function sdkMajorVersion() {
    return substr(Sdk::SDK_VERSION, 0, 1);
  }
}
