<?php

namespace Drupal\commerce_vantiv\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * Defines the event for unsuccessful transactions
 *
 * @see \Drupal\commerce_vantiv\Event\VantivEvents
 */
class TransactionUnsuccessfulEvent extends Event {

  /**
   * The response values.
   *
   * @var array
   */
  protected $response;

  /**
   * Constructs a new TransactionUnsuccessfulEvent object.
   *
   * @param array $response
   *   The request values.
   */
  public function __construct(array $response) {
    $this->response = $response;
  }

  /**
   * Gets the response values.
   *
   * @return array
   *   The response values.
   */
  public function getResponse() {
    return $this->response;
  }

  /**
   * Sets the response values.
   *
   * @param array $response
   *   The response values.
   *
   * @return $this
   */
  public function setResponse(array $response) {
    $this->response = $response;
    return $this;
  }

}
