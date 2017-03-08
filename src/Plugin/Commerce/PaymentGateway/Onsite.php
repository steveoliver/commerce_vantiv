<?php

namespace Drupal\commerce_vantiv\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\SoftDeclineException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_vantiv\VantivApiHelper as Helper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\profile\Entity\ProfileInterface;
use litle\sdk\LitleOnlineRequest;

/**
 * Provides the Onsite payment gateway
 *
 * @CommercePaymentGateway(
 *   id = "vantiv_onsite",
 *   label = "Vantiv (Onsite)",
 *   display_label = "Vantiv (Onsite)",
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_vantiv\PluginForm\Onsite\PaymentMethodAddForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class OnSite extends OnsitePaymentGatewayBase implements OnsiteInterface {

  /** @var LitleOnlineRequest $api */
  protected $api;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);
    $this->api = new LitleOnlineRequest();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'user' => '',
      'password' => '',
      'currency_merchant_map' => [
        'default' => ''
      ],
      'url' => '',
      'proxy' => '',
      'paypage_id' => '',
      'batch_requests_path' => '',
      'litle_requests_path' => '',
      'sftp_username' => '',
      'sftp_password' => '',
      'batch_url' => '',
      'tcp_port' => '',
      'tcp_timeout' => '',
      'tcp_ssl' => '1',
      'print_xml' => '0',
      'timeout' => '500',
      'report_group' => 'Default Report Group',
      'mode' => 'test',
      'version' => '1',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    $config = $this->configuration;

    // Supplement configuration values with environment variables.
    if (!empty($_ENV['COMMERCE_VANTIV_API_USER'])) {
      $config['user'] = $_ENV['COMMERCE_VANTIV_API_USER'];
    }
    if (!empty($_ENV['COMMERCE_VANTIV_API_PASS'])) {
      $config['password'] = $_ENV['COMMERCE_VANTIV_API_PASS'];
    }
    if (!empty($_ENV['COMMERCE_VANTIV_API_MERCHANT_ID_DEFAULT'])) {
      $config['currency_merchant_map']['default'] = $_ENV['COMMERCE_VANTIV_API_MERCHANT_ID_DEFAULT'];
    }
    if (!empty($_ENV['COMMERCE_VANTIV_API_PAYPAGE_ID'])) {
      $config['paypage_id'] = $_ENV['COMMERCE_VANTIV_API_PAYPAGE_ID'];
    }

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    if (!isset($_ENV['COMMERCE_VANTIV_API_USER']) || !isset($_ENV['COMMERCE_VANTIV_API_PASS'])) {
      drupal_set_message($this->t("Warning: API credentials should be set in environment variables, NOT stored in configuration."), 'warning');
    }

    $form['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User name'),
    ];
    if (empty($_ENV['COMMERCE_VANTIV_API_USER'])) {
      $form['user']['#default_value'] = $this->configuration['user'];
      $form['user']['#description'] = $this->t('Warning: This value should be set in the COMMERCE_VANTIV_API_USER environment variable.');
    }
    else {
      $form['user']['#attributes']['disabled'] = 'disabled';
      $form['user']['#default_value'] = $_ENV['COMMERCE_VANTIV_API_USER'];
      $form['user']['#description'] = $this->t('Note: This value is set in the COMMERCE_VANTIV_API_USER environment variable.');
    }
    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
    ];
    if (empty($_ENV['COMMERCE_VANTIV_API_PASS'])) {
      $form['password']['#default_value'] = $this->configuration['password'];
      $form['password']['#description'] = $this->t('Warning: This value should be set in the COMMERCE_VANTIV_API_PASS environment variable.');
    }
    else {
      $form['password']['#attributes']['disabled'] = 'disabled';
      $form['password']['#default_value'] = $_ENV['COMMERCE_VANTIV_API_PASS'];
      $form['password']['#description'] = $this->t('Note: This value is set in the COMMERCE_VANTIV_API_PASS environment variable.');
    }
    $form['currency_merchant_map'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Currency -> Merchant ID mapping'),
    ];
    $form['currency_merchant_map']['default'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default'),
    ];
    if (empty($_ENV['COMMERCE_VANTIV_API_MERCHANT_ID_DEFAULT'])) {
      $form['currency_merchant_map']['default']['#default_value'] = $this->configuration['currency_merchant_map']['default'];
      $form['currency_merchant_map']['default']['#description'] = $this->t('Warning: This value should be set in the COMMERCE_VANTIV_API_MERCHANT_ID_DEFAULT environment variable.');
    }
    else {
      $form['currency_merchant_map']['default']['#attributes']['disabled'] = 'disabled';
      $form['currency_merchant_map']['default']['#default_value'] = $_ENV['COMMERCE_VANTIV_API_MERCHANT_ID_DEFAULT'];
      $form['currency_merchant_map']['default']['#description'] = $this->t('Note: This value is set in the COMMERCE_VANTIV_API_MERCHANT_ID_DEFAULT environment variable.');
    }
    // @see UrlMapper.php in Litle Payments SDK where URL is determined
    // We should probably send transaction mode as one of the strings defined there.
    $form['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('URL'),
      '#default_value' => $this->configuration['url'],
      '#required' => TRUE
    ];
    $form['proxy'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Proxy'),
      '#default_value' => $this->configuration['proxy'],
    ];
    $form['paypage_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PayPage ID'),
    ];
    if (empty($_ENV['COMMERCE_VANTIV_API_PAYPAGE_ID'])) {
      $form['paypage_id']['#description'] = $this->t('Warning: This value should be set in the COMMERCE_VANTIV_API_PAYPAGE_ID environment variable.');
      $form['paypage_id']['#default_value'] = $this->configuration['paypage_id'];
    }
    else {
      $form['paypage_id']['#attributes']['disabled'] = 'disabled';
      $form['paypage_id']['#description'] = $this->t('Note: This value is set in the COMMERCE_VANTIV_API_PAYPAGE_ID environment variable.');
      $form['paypage_id']['#default_value'] = $_ENV['COMMERCE_VANTIV_API_PAYPAGE_ID'];
    }
    $form['batch_requests_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Batch Requests Path'),
      '#default_value' => $this->configuration['batch_requests_path'],
    ];
    $form['litle_requests_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Litle Requests Path'),
      '#default_value' => $this->configuration['litle_requests_path'],
    ];
    $form['sftp_username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('sFTP Username'),
      '#default_value' => $this->configuration['sftp_username'],
    ];
    $form['sftp_password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('sFTP Password'),
      '#default_value' => $this->configuration['sftp_password'],
    ];
    $form['batch_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Batch URL'),
      '#default_value' => $this->configuration['batch_url'],
    ];
    $form['tcp_port'] = [
      '#type' => 'number',
      '#title' => $this->t('TCP Port'),
      '#default_value' => $this->configuration['tcp_port'],
      '#required' => TRUE
    ];
    $form['tcp_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('TCP Timeout'),
      '#default_value' => $this->configuration['tcp_timeout'],
      '#required' => TRUE
    ];
    $form['tcp_ssl'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('TCP SSL?'),
      '#default_value' => $this->configuration['tcp_ssl'],
    ];
    $form['print_xml'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Print XML?'),
      '#default_value' => $this->configuration['print_xml'],
    ];
    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout'),
      '#default_value' => $this->configuration['timeout'],
      '#required' => TRUE
    ];
    $form['report_group'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report Group'),
      '#default_value' => $this->configuration['report_group'],
      '#required' => TRUE
    ];
    // @todo: Add other form fields.

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      foreach ([
        'url', 'proxy', 'batch_requests_path',
        'litle_requests_path', 'sftp_username', 'sftp_password',
        'batch_url', 'tcp_port', 'tcp_timeout', 'tcp_ssl', 'print_xml',
        'timeout', 'report_group'] as $value) {
        $this->configuration[$value] = $values[$value];
      }
      if (empty($_ENV['COMMERCE_VANTIV_API_USER'])) {
        $this->configuration['user'] = $values['user'];
      }
      if (empty($_ENV['COMMERCE_VANTIV_API_PASS'])) {
        $this->configuration['password'] = $values['password'];
      }
      if (empty($_ENV['COMMERCE_VANTIV_API_MERCHANT_ID_DEFAULT'])) {
        $this->configuration['currency_merchant_map']['default'] = $values['currency_merchant_map']['default'];
      }
      if (empty($_ENV['COMMERCE_VANTIV_API_PAYPAGE_ID'])) {
        $this->configuration['paypage_id'] = $values['paypage_id'];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaymentOperations(PaymentInterface $payment) {
    $state = $payment->getState()->value;
    $txn_time = (strpos($state, 'capture') === 0) ? $payment->getCapturedTime() : $payment->getAuthorizedTime();
    $txn_remote_complete = time() > 60 + $txn_time;
    $txn_same_day = (strtotime('today') < $txn_time && $txn_time < (strtotime('tomorrow') - 1));
    $auth_expired = ($state != 'authorization') ? TRUE : $payment->getAuthorizationExpiresTime() <= time();
    $operations = [];
    if ($txn_remote_complete) {
      $operations['capture'] = [
        'title' => $this->t('Capture'),
        'page_title' => $this->t('Capture payment'),
        'plugin_form' => 'capture-payment',
        'access' => ($state == 'authorization' && $auth_expired === FALSE),
      ];
      $operations['void'] = [
        'title' => $this->t('Void'),
        'page_title' => $this->t('Void payment'),
        'plugin_form' => 'void-payment',
        'access' => (($state == 'authorization' && $auth_expired === FALSE) || ($state == 'capture_completed' && $txn_same_day) || ($state == 'capture_refunded' && $txn_same_day)
        ),
      ];
      $operations['refund'] = [
        'title' => $this->t('Refund'),
        'page_title' => $this->t('Refund payment'),
        'plugin_form' => 'refund-payment',
        'access' => in_array($state, ['capture_completed', 'capture_partially_refunded']),
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    if ($payment->getState()->value != 'new') {
      throw new \InvalidArgumentExcpetion('The provided payment method is in an invalid state.');
    }
    /** @var \Drupal\commerce_payment\Entity\PaymentMethod $payment_method */
    $payment_method = $payment->getPaymentMethod();
    if (empty($payment_method)) {
      throw new \InvalidArgumentException('The provided payment method has no payment method referenced.');
    }
    if (REQUEST_TIME >= $payment_method->getExpiresTime()) {
      throw new HardDeclineException('The provided payment method has expired.');
    }

    /** @var \Drupal\commerce_price\Price $amount */
    $amount = $payment->getAmount();
    /** @var \Drupal\profile\Entity\ProfileInterface $profile */
    $profile = $payment_method->getBillingProfile();
    /** @var \Drupal\user\Entity\User $user */
    $user = $profile->getOwner();
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $billing_info */
    $billing_info = $profile->get('address')->first();

    $hash_in = Helper::getApiRequestParamsFromConfig($this->getConfiguration());
    $request_data = [
      'orderId' => $payment->getOrderId(),
      'amount'  => Helper::getVantivAmountFormat($amount->getNumber()),
      'orderSource' => 'ecommerce',
      'billToAddress' => [
        'name' => $billing_info->getGivenName() . ' ' . $billing_info->getFamilyName(),
        'addressLine1' => $billing_info->getAddressLine1(),
        'city' => $billing_info->getLocality(),
        'state' => substr($billing_info->getAdministrativeArea(), -2),
        'zip' => $billing_info->getPostalCode(),
        'country' => $billing_info->getCountryCode(),
        'email' => $user->getEmail()
      ],
      'token' => [
        'litleToken' => $payment_method->getRemoteId(),
        'expDate' => Helper::getVantivCreditCardExpDate($payment_method)
      ],
    ];
    $request_method = $capture ? 'saleRequest' : 'authorizationRequest';
    $response_property = $capture ? 'saleResponse' : 'authorizationResponse';
    try {
      $response = $this->api->{$request_method}($hash_in + $request_data);
    } catch (\Exception $e) {
      throw new InvalidRequestException($e->getMessage());
    }
    $response_array = Helper::getResponseArray($response, $response_property);

    $this->ensureSuccessTransaction($response_array, 'Payment');

    $payment->state = $capture ? 'capture_completed' : 'authorization';
    $payment->setTest($this->getMode() == 'test');
    $payment->setRemoteId($response_array['litleTxnId']);
    $payment->setAuthorizedTime(REQUEST_TIME);
    if ($capture) {
      $payment->setCapturedTime(REQUEST_TIME);
    }
    else {
      $payment->setAuthorizationExpiresTime(Helper::getAuthorizationExpiresTime($payment));
    }
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    if ($payment->getState()->value != 'authorization') {
      throw new \InvalidArgumentException('Only authorizations can be captured.');
    }
    /** @var Price $capture_amount */
    $capture_amount = $amount ?: $payment->getBalance();
    if ($capture_amount->lessThan($payment->getBalance())) {
      $partial_capture = $payment->createDuplicate();
      $partial_capture->state = 'authorization';
      $partial_capture->partial = TRUE;
      $partial_capture->setAmount($capture_amount);
      $partial_capture->setRemoteId($payment->getRemoteId());
      $this->capturePayment($partial_capture, $capture_amount);
      if ($partial_capture->getCapturedTime()) {
        $payment->setAmount($payment->getAmount()->subtract($partial_capture->getAmount()));
        $payment->save();
      }
      return;
    }

    $hash_in = Helper::getApiRequestParamsFromConfig($this->getConfiguration());
    $request_data = [
      'id' => $payment->getAuthorizedTime(),
      'litleTxnId' => $payment->getRemoteId(),
      'amount' => Helper::getVantivAmountFormat($capture_amount->getNumber()),
    ];
    // Part of Vantiv partial capture issue.
    if ($payment->partial) {
      $request_data['partial'] = 'true';
    }
    try {
      $response = $this->api->captureRequest($hash_in + $request_data);
    } catch (\Exception $e) {
      throw new InvalidRequestException($e->getMessage());
    }
    $response_array = Helper::getResponseArray($response, 'captureResponse');

    $this->ensureSuccessTransaction($response_array, 'Capture');

    $payment->setRemoteId($response_array['litleTxnId']);
    $payment->setAmount($capture_amount);
    $payment->setCapturedTime(REQUEST_TIME);
    $payment->state = 'capture_completed';
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $state = $payment->getState()->value;
    $operation = ($state == 'authorization') ? 'authReversal' : 'void';
    $request_operation = "{$operation}Request";
    $response_operation = "{$operation}Response";

    $hash_in = Helper::getApiRequestParamsFromConfig($this->getConfiguration());
    $request_data = [
      'id' => $payment->getAuthorizedTime(),
      'litleTxnId' => $payment->getRemoteId(),
    ];
    try {
      $response = $this->api->{$request_operation}($hash_in + $request_data);
    } catch (\Exception $e) {
      throw new InvalidRequestException($e->getMessage());
    }
    $response_array = Helper::getResponseArray($response, $response_operation);

    $this->ensureSuccessTransaction($response_array, $operation);

    $payment->setRemoteId($response_array['litleTxnId']);
    $payment->state = $state == 'capture' ? 'capture_refunded' : 'authorization_voided';
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    if (!in_array($payment->getState()->value, ['capture_completed', 'capture_partially_refunded'])) {
      throw new \InvalidArgumentException('Only payments in the "capture_completed" and "capture_partially_refunded" states can be refunded.');
    }
    $amount = $amount ?: $payment->getAmount();
    $balance = $payment->getBalance();
    if ($amount->greaterThan($balance)) {
      throw new InvalidRequestException(sprintf("Can't refund more than %s.", $balance->__toString()));
    }

    $hash_in = Helper::getApiRequestParamsFromConfig($this->getConfiguration());
    $request_data = [
      'id' => $payment->getAuthorizedTime(),
      'litleTxnId' => $payment->getRemoteId(),
      'amount' => Helper::getVantivAmountFormat($amount->getNumber()),
    ];
    try {
      $response = $this->api->creditRequest($hash_in + $request_data);
    } catch (\Exception $e) {
      throw new InvalidRequestException($e->getMessage());
    }
    $response_array = Helper::getResponseArray($response, 'creditResponse');

    $this->ensureSuccessTransaction($response_array, 'Refund');

    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);
    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->state = 'capture_partially_refunded';
    }
    else {
      $payment->state = 'capture_refunded';
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      'response$type', 'response$paypageRegistrationId', 'expiration',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);
    $payment_method->card_type = Helper::getCommerceCreditCardType($payment_details['response$type']);
    $payment_method->card_number = $payment_details['response$lastFour'];
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];
    $payment_method->setRemoteId($payment_details['response$paypageRegistrationId']);
    $payment_method->setExpiresTime($expires);
    if ($payment_method->getOwnerId() == 0) {
      $payment_method->setReusable(FALSE);
    }
    $payment_method->save();

    $this->registerToken($payment_method);
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the remote record here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // Delete the local entity.
    $payment_method->delete();
  }

  /**
   * Registers a token with Vantiv from the AJAX provided registration id.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *    The payment method.
   */
  private function registerToken(PaymentMethodInterface $payment_method) {
    $hash_in = Helper::getApiRequestParamsFromConfig($this->getConfiguration());
    /** @var ProfileInterface $billing_profile */
    $billing_profile = $payment_method->getBillingProfile();
    $request_data = [
      'id' => $payment_method->getOriginalId(),
      'customerId' => $billing_profile->getOwnerId(),
      'paypageRegistrationId' => $payment_method->getRemoteId()
    ];

    try {
      $response = $this->api->registerTokenRequest($hash_in + $request_data);
    } catch (\Exception $e) {
      throw new InvalidRequestException($e->getMessage());
    }
    $response_array = Helper::getResponseArray($response, 'registerTokenResponse');
    $this->ensureSuccessTransaction($response_array, 'Token registration');

    $payment_method->setRemoteId($response_array['litleToken']);
    $payment_method->save();
  }

  /**
   * Ensures a successful transaction.
   *
   * Logs and throws an error if response does not contain success data.
   *
   * @param array $response_array
   *   Vantiv response array.
   *
   * @param string $txn_type
   *   Transaction type.
   *
   * @throws SoftDeclineException
   */
  private function ensureSuccessTransaction(array $response_array, $txn_type = 'Transaction') {
    if (!Helper::isResponseSuccess($response_array['response'])) {
      $error = '@type failed with code @code (@message) (@id).';
      $message = $this->t($error, [
        '@type' => $txn_type,
        '@code' => isset($response_array['response']) ? $response_array['response'] : '',
        '@message' => isset($response_array['message']) ? $response_array['message'] : '',
        '@id' => isset($response_array['litleTxnId']) ? $response_array['litleTxnId'] : ''
      ]);
      \Drupal::logger('commerce_vantiv')->log(RfcLogLevel::ERROR, $message);
      throw new SoftDeclineException($message);
    }
  }

}
