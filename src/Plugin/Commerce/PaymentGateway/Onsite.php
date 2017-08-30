<?php

namespace Drupal\commerce_vantiv\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\SoftDeclineException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_price\Price;
use Drupal\commerce_vantiv\VantivApiHelper as Helper;
use Drupal\Component\Datetime\TimeInterface;
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
 *   js_library = "commerce_vantiv/eprotect"
 * )
 */
class OnSite extends OnsitePaymentGatewayBase implements OnsiteInterface {

  /** @var LitleOnlineRequest $api */
  protected $api;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->api = new LitleOnlineRequest();
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'proxy' => '',
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
      'machine_name' => '',
      'version' => '1',
    ] + parent::defaultConfiguration();
  }

  /**
   * Gets configuration values with supplemental environment values if set.
   *
   * @return array
   *   An array of this gateway's configuration.
   */
  public function getSecureConfiguration() {
    $config = $this->getConfiguration();
    $gateway_machine_name = strtoupper($config['machine_name']);
    $key_prefix = "COMMERCE_VANTIV_{$gateway_machine_name}_";
    $user_key = $key_prefix . 'USER';
    $pass_key = $key_prefix . 'PASS';
    $mid_key = $key_prefix . 'MERCHANT_ID_DEFAULT';
    $paypage_key = $key_prefix . 'PAYPAGE_ID';

    // Supplement configuration values with environment variables.
    if (!empty($_ENV[$user_key])) {
      $config['user'] = $_ENV[$user_key];
    }
    if (!empty($_ENV[$pass_key])) {
      $config['password'] = $_ENV[$pass_key];
    }
    if (!empty($_ENV[$mid_key])) {
      $config['currency_merchant_map']['default'] = $_ENV[$mid_key];
    }
    if (!empty($_ENV[$paypage_key])) {
      $config['paypage_id'] = $_ENV[$paypage_key];
    }

    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $entity = $form_state->getFormObject()->getEntity();
    $machine_name = $entity ? $entity->id() : NULL;

    $var_key = $machine_name ? strtoupper($machine_name) : 'XXX';
    $var_key_desc = "Where {$var_key} is the UPPERCASE version of this payment gateway's machine name. For example, if the machine name is 'my_gateway', {$var_key} would be MY_GATEWAY.";

    $user_key = 'COMMERCE_VANTIV_' . $var_key . '_USER';
    $pass_key = 'COMMERCE_VANTIV_' . $var_key . '_PASS';
    $mid_key = 'COMMERCE_VANTIV_' . $var_key . '_MERCHANT_ID_DEFAULT';
    $paypage_key = 'COMMERCE_VANTIV_' . $var_key . '_PAYPAGE_ID';

    if (!isset($_ENV[$user_key]) || !isset($_ENV[$pass_key])) {
      drupal_set_message($this->t("Warning: API credentials should be set in environment variables, NOT stored in configuration."), 'warning');
    }

    $should_be_message = 'Warning: This value should be set in the :key environment variable. ' .  $var_key_desc;
    $is_set_message = 'Note: This value is set in the :key environment variable.';

    $form['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User name'),
    ];
    if (empty($_ENV[$user_key])) {
      $form['user']['#default_value'] = $this->configuration['user'];
      $form['user']['#description'] = $this->t($should_be_message, [
        ':key' => $user_key,
      ]);
    }
    else {
      $form['user']['#attributes']['disabled'] = 'disabled';
      $form['user']['#default_value'] = $_ENV[$user_key];
      $form['user']['#description'] = $this->t($is_set_message, [
        ':key' => $user_key,
      ]);
    }
    $form['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
    ];
    if (empty($_ENV[$pass_key])) {
      $form['password']['#default_value'] = $this->configuration['password'];
      $form['password']['#description'] = $this->t($should_be_message, [
        ':key' => $pass_key,
      ]);
    }
    else {
      $form['password']['#attributes']['disabled'] = 'disabled';
      $form['password']['#default_value'] = $_ENV[$pass_key];
      $form['password']['#description'] = $this->t($is_set_message, [
        ':key' => $pass_key,
      ]);
    }
    $form['currency_merchant_map'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Currency -> Merchant ID mapping'),
    ];
    $form['currency_merchant_map']['default'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default'),
    ];
    if (empty($_ENV[$mid_key])) {
      $form['currency_merchant_map']['default']['#default_value'] = $this->configuration['currency_merchant_map']['default'];
      $form['currency_merchant_map']['default']['#description'] = $this->t($should_be_message, [
        ':key' => $mid_key,
      ]);
    }
    else {
      $form['currency_merchant_map']['default']['#attributes']['disabled'] = 'disabled';
      $form['currency_merchant_map']['default']['#default_value'] = $_ENV[$mid_key];
      $form['currency_merchant_map']['default']['#description'] = $this->t($is_set_message, [
        ':key' => $mid_key,
      ]);
    }
    $form['proxy'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Proxy'),
      '#default_value' => $this->configuration['proxy'],
    ];
    $form['paypage_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PayPage ID'),
    ];
    if (empty($_ENV[$paypage_key])) {
      $form['paypage_id']['#description'] = $this->t($should_be_message, [
        ':key' => $paypage_key,
      ]);
      $form['paypage_id']['#default_value'] = $this->configuration['paypage_id'];
    }
    else {
      $form['paypage_id']['#attributes']['disabled'] = 'disabled';
      $form['paypage_id']['#description'] = $this->t($is_set_message, [
        ':key' => $paypage_key,
      ]);
      $form['paypage_id']['#default_value'] = $_ENV[$paypage_key];
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
      $config = $this->getConfiguration();
      $gateway_machine_name = strtoupper($config['machine_name']);
      $key_prefix = "COMMERCE_VANTIV_{$gateway_machine_name}_";
      $user_key = $key_prefix . 'USER';
      $pass_key = $key_prefix . 'PASS';
      $mid_key = $key_prefix . 'MERCHANT_ID_DEFAULT';
      $paypage_key = $key_prefix . 'PAYPAGE_ID';

      $values = $form_state->getValue($form['#parents']);
      $machine_name = $form_state->getValue('id');
      $this->configuration['machine_name'] = $machine_name;
      foreach ([
        'proxy', 'batch_requests_path',
        'litle_requests_path', 'sftp_username', 'sftp_password',
        'batch_url', 'tcp_port', 'tcp_timeout', 'tcp_ssl', 'print_xml',
        'timeout', 'report_group'] as $value) {
        $this->configuration[$value] = $values[$value];
      }
      if (empty($_ENV[$user_key])) {
        $this->configuration['user'] = $values['user'];
      }
      if (empty($_ENV[$pass_key])) {
        $this->configuration['password'] = $values['password'];
      }
      if (empty($_ENV[$mid_key])) {
        $this->configuration['currency_merchant_map']['default'] = $values['currency_merchant_map']['default'];
      }
      if (empty($_ENV[$paypage_key])) {
        $this->configuration['paypage_id'] = $values['paypage_id'];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaymentOperations(PaymentInterface $payment) {
    $state = $payment->getState()->value;
    $txn_time = $payment->getCompletedTime() ? $payment->getCompletedTime() : $payment->getAuthorizedTime();
    $txn_remote_complete = time() > 60 + $txn_time;
    $txn_same_day = (strtotime('today') < $txn_time && $txn_time < (strtotime('tomorrow') - 1));
    $operations = [];
    if ($txn_remote_complete) {
      $operations['capture'] = [
        'title' => $this->t('Capture'),
        'page_title' => $this->t('Capture payment'),
        'plugin_form' => 'capture-payment',
        'access' => ($state == 'authorization' && !$payment->isExpired()),
      ];
      $operations['void'] = [
        'title' => $this->t('Void'),
        'page_title' => $this->t('Void payment'),
        'plugin_form' => 'void-payment',
        'access' => ($state == 'authorization' && !$payment->isExpired()) || (in_array($state, ['completed', 'refunded']) && $txn_same_day),
      ];
      $operations['refund'] = [
        'title' => $this->t('Refund'),
        'page_title' => $this->t('Refund payment'),
        'plugin_form' => 'refund-payment',
        'access' => in_array($state, ['completed', 'partially_refunded']),
      ];
    }

    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->assertPaymentState($payment, ['new']);
    $payment_method = $payment->getPaymentMethod();
    $this->assertPaymentMethod($payment_method);

    /** @var \Drupal\commerce_price\Price $amount */
    $amount = $payment->getAmount();
    /** @var \Drupal\profile\Entity\ProfileInterface $profile */
    $profile = $payment_method->getBillingProfile();
    /** @var \Drupal\user\Entity\User $user */
    $user = $profile->getOwner();
    /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $billing_info */
    $billing_info = $profile->get('address')->first();

    $hash_in = Helper::getApiRequestParamsFromConfig($this->getSecureConfiguration());
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
    $next_state = $capture ? 'completed' : 'authorization';

    $payment->setState($next_state);
    $payment->setRemoteId($response_array['litleTxnId']);
    if (!$capture) {
      $payment->setExpiresTime(Helper::getAuthorizationExpiresTime($payment));
    }
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['authorization']);
    /** @var Price $capture_amount */
    $capture_amount = $amount ?: $payment->getBalance();
    if ($capture_amount->lessThan($payment->getBalance())) {
      $partial_capture = $payment->createDuplicate();
      $partial_capture->state = 'authorization';
      $partial_capture->partial = TRUE;
      $partial_capture->setAmount($capture_amount);
      $partial_capture->setRemoteId($payment->getRemoteId());
      $this->capturePayment($partial_capture, $capture_amount);
      if ($partial_capture->getCompletedTime()) {
        $payment->setAmount($payment->getAmount()->subtract($partial_capture->getAmount()));
        $payment->save();
      }
      return;
    }

    $hash_in = Helper::getApiRequestParamsFromConfig($this->getSecureConfiguration());
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
    $payment->setState('completed');
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

    $hash_in = Helper::getApiRequestParamsFromConfig($this->getSecureConfiguration());
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
    $next_state = $state == 'authorization' ? 'authorization_voided' : 'refunded';

    $payment->setRemoteId($response_array['litleTxnId']);
    $payment->setState($next_state);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    $hash_in = Helper::getApiRequestParamsFromConfig($this->getSecureConfiguration());
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
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      'vantivResponseType', 'vantivResponsePaypageRegistrationId', 'expiration',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);
    $payment_method->card_type = Helper::getCommerceCreditCardType($payment_details['vantivResponseType']);
    $payment_method->card_number = $payment_details['vantivResponseLastFour'];
    $payment_method->card_exp_month = $payment_details['expiration']['month'];
    $payment_method->card_exp_year = $payment_details['expiration']['year'];
    $payment_method->setRemoteId($payment_details['vantivResponsePaypageRegistrationId']);
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
    $hash_in = Helper::getApiRequestParamsFromConfig($this->getSecureConfiguration());
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
