<?php

namespace Drupal\commerce_vantiv\Event;

final class VantivEvents {

  /**
   * Name of the event fired before a request is sent to Vantiv.
   *
   * @Event
   *
   * @see \Drupal\commerce_vantiv\Event\FilterVantivRequestEvent
   */
  const FILTER_REQUEST = 'commerce_vantiv.filter_request';

}
