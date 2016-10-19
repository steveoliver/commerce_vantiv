<?php

namespace Drupal\commerce_vantiv\PluginForm\Onsite;

use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\PluginForm\PaymentMethodAddForm as BasePaymentMethodAddForm;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_vantiv\Plugin\Commerce\PaymentGateway\OnSite;
use Drupal\commerce_vantiv\VantivApiHelper;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;

class PaymentMethodAddForm extends BasePaymentMethodAddForm {

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;

    if ($payment_method->bundle() == 'credit_card') {
      $this->submitCreditCardForm($form['payment_details'], $form_state);
    }
    elseif ($payment_method->bundle() == 'paypal') {
      $this->submitPayPalForm($form['payment_details'], $form_state);
    }
    $this->submitBillingProfileForm($form['billing_information'], $form_state);

    $post_values = \Drupal::request()->request->all();
    $values = $post_values['payment_information']['add_payment_method'];

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $this->plugin;
    // The payment method form is customer facing. For security reasons
    // the returned errors need to be more generic.
    try {
      $payment_gateway_plugin->createPaymentMethod($payment_method, $values['payment_details']);
    }
    catch (DeclineException $e) {
      \Drupal::logger('commerce_payment')->warning($e->getMessage());
      throw new DeclineException('We encountered an error processing your payment method. Please verify your details and try again.');
    }
    catch (PaymentGatewayException $e) {
      \Drupal::logger('commerce_payment')->error($e->getMessage());
      throw new PaymentGatewayException('We encountered an unexpected error processing your payment method. Please try again later.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    // @todo Do not validate if ajax
    $triggering_element = $form_state->getTriggeringElement();
    if (!empty($triggering_element['#ajax'])) {
      return true;
    }
    $post_values = \Drupal::request()->request->all();
    $values = NestedArray::getValue($post_values, $element['#parents']);
    $vantiv_card_type = $values['response$type'];
    $commerce_card_type = VantivApiHelper::getCommerceCreditCardType($vantiv_card_type);
    $card_type = CreditCard::getType($commerce_card_type);
    if (!$card_type) {
      $form_state->setError($element['number'], t('You have entered a credit card number of an unsupported card type.'));
      return;
    }
    if (!CreditCard::validateExpirationDate($values['expiration']['month'], $values['expiration']['year'])) {
      $form_state->setError($element['expiration'], t('You have entered an expired credit card.'));
    }
    $form_state->setValueForElement($element['type'], $card_type->getId());
  }

  /**
   * {@inheritdoc}
   */
  public function submitCreditCardForm(array $element, FormStateInterface $form_state) {
    /** @var PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;
    $post_values = \Drupal::request()->request->all();
    $values = NestedArray::getValue($post_values, $element['#parents']);
    $payment_method->card_type = VantivApiHelper::getCommerceCreditCardType($values['response$type']);
    $payment_method->card_number = $values['response$lastFour'];
    $payment_method->card_exp_month = $values['expiration']['month'];
    $payment_method->card_exp_year = $values['expiration']['year'];
    $payment_method->setRemoteId($values['response$paypageRegistrationId']);
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function buildCreditCardForm(array $element, FormStateInterface $form_state) {

    /** @var OnSite $plugin */
    $plugin = $this->plugin;
    $configuration = $plugin->getConfiguration();
    // @todo Get order if it exists.
    // $order = $form_state->getValue('order');
    // Add hidden authorization and request fields.

    // Build the standard credit card form.
    $element = parent::buildCreditCardForm($element, $form_state);

    // Add a css class so that we can easily identify Vantiv related input fields;
    // Do not require the fields; Remove "name" attributes from Vantiv related
    // input elements to prevent card data to be sent to Drupal server.
    $credit_card_fields = ['number', 'security_code'];
    foreach ($credit_card_fields as $key) {
      $credit_card_field = &$element[$key];
      $credit_card_field['#attributes']['class'][] = 'commerce-vantiv-creditcard';
      $credit_card_field['#required'] = FALSE;
      $credit_card_field['#post_render'][] = [$this, 'removeFormElementName'];
    }

    // Add our hidden value fields.
    $element['request$paypageId'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'request$paypageId'],
      '#value' => $configuration['paypage_id']
    ];
    $element['request$merchantTxnId'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'request$merchantTxnId'],
      '#value' => $configuration['currency_merchant_map']['default']
    ];
    $element['request$orderId'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'request$orderId'],
      '#value' => (!empty($order) && isset($order->order_id)) ? $order->order_id : 0
    ];
    $element['request$reportGroup'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'request$reportGroup'],
      '#value' => $configuration['report_group'],
    ];
    $element['#attached']['drupalSettings']['commerce_vantiv']['eprotect'] = [
      'payment_pane' => TRUE,
      'paypage_url' => VantivApiHelper::getPaypageRequestUrl($plugin)
    ];
    $element['#attached']['library'][] = 'commerce_vantiv/eprotect.library.prelive';
    $element['#attached']['library'][] = 'commerce_vantiv/eprotect.client';

    // Add hidden response fields for storing information returned by Vantiv.
    foreach([
      'response$paypageRegistrationId',
      'response$bin',
      'response$code',
      'response$message',
      'response$responseTime',
      'response$type',
      'response$litleTxnId',
      'response$firstSix',
      'response$lastFour'
    ] as $eprotectfield) {
      $element[$eprotectfield] = [
        '#type' => 'hidden',
        '#value' => '',
        '#attributes' => [
          'id' => $eprotectfield,
        ]
      ];
    }

    return $element;
  }

  /**
   * @param $content
   * @param $element
   * @return mixed
   */
  public function removeFormElementName($content, $element) {
    $name_pattern = '/\sname\s*=\s*[\'"]?' . preg_quote($element['#name']) . '[\'"]?/';
    return preg_replace($name_pattern, '', $content);
  }

}

