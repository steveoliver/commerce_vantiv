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
  public function validateCreditCardForm(array &$element, FormStateInterface $form_state) {
    // @todo Do not validate if ajax
    $triggering_element = $form_state->getTriggeringElement();
    if (!empty($triggering_element['#ajax'])) {
      return true;
    }
    $post_values = \Drupal::request()->request->all();
    $values = NestedArray::getValue($post_values, $element['#parents']);
    $vantiv_card_type = $values['vantivResponseType'];
    $commerce_card_type = VantivApiHelper::getCommerceCreditCardType($vantiv_card_type);
    if (!$commerce_card_type) {
      // (if values doesn't have response$type).
      // (seems to happen when adding a new credit card when one already exists).
      $form_state->setError($element['number'], t('Invalid credit card type.'));
      return;
    }
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
    $payment_method->card_type = VantivApiHelper::getCommerceCreditCardType($values['vantivResponseType']);
    $payment_method->card_number = $values['vantivResponseLastFour'];
    $payment_method->card_exp_month = $values['expiration']['month'];
    $payment_method->card_exp_year = $values['expiration']['year'];
    $payment_method->setRemoteId($values['vantivResponsePaypageRegistrationId']);
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

    // Add our hidden request value fields.
    $element['vantivRequestPaypageId'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'vantivRequestPaypageId'],
      '#value' => $configuration['paypage_id']
    ];
    $element['vantivRequestMerchantTxnId'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'vantivRequestMerchantTxnId'],
      '#value' => $configuration['currency_merchant_map']['default']
    ];
    $element['vantivRequestOrderId'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'vantivRequestOrderId'],
      '#value' => (!empty($order) && isset($order->order_id)) ? $order->order_id : 0
    ];
    $element['vantivRequestReportGroup'] = [
      '#type' => 'hidden',
      '#attributes' => ['id' => 'vantivRequestReportGroup'],
      '#value' => $configuration['report_group'],
    ];

    // Add values to drupalSettings that help load the correct external library
    // and tailor the eProtect functionality to whichever payment method form
    // we are working with (either user card on file or checkout new payment).
    $element['#attached']['drupalSettings']['commerce_vantiv']['eprotect'] = [
      'mode' => $plugin->getMode() == 'live' ? 'live' : 'prelive',
      'checkout_pane' => TRUE,
    ];

    // Add hidden response fields for storing information returned by Vantiv.
    foreach([
      'vantivResponsePaypageRegistrationId',
      'vantivResponseBin',
      'vantivResponseCode',
      'vantivResponseMessage',
      'vantivResponseTime',
      'vantivResponseType',
      'vantivResponseLitleTxnId',
      'vantivResponseFirstSix',
      'vantivResponseLastFour'
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

