/**
 * @file
 * Javascript to generate eProtect token in PCI-compliant way.
 */

(function ($, Drupal) {

  'use strict';

  /**
   * Attaches the vantivCreditCardEprotect behavior.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the checkoutPaneOverview behavior.
   *
   * @see Drupal.checkoutPaneOverview.attach
   */
  Drupal.behaviors.vantivCreditCardEprotect = {
    attach: function (context, settings) {

      // Load eProtect functionality if settings exist and is not already loaded.
      if (settings.commerce_vantiv && !window.vantivSubmitButtonBound) {

        // Get only the settings we will use.
        settings = settings.commerce_vantiv.eprotect;

        // Load the eProtect library since we can't load it via an #attached
        // library on the payment form.
        Drupal.vantivEprotect.load(settings);

        // Customize the submit button functionality for each form.
        var buttonId = Drupal.vantivEprotect.getSubmitButtonSelector(settings);
        Drupal.vantivEprotect.delegateSubmitButton(buttonId, settings);
      }
    }
  };

  /**
   * @typedef {object} Drupal~settings~vantivSettings
   *
   * @prop {string} mode
   *   The payment gateway transaction environment, either 'live' or 'prelive'.
   * @prop {boolean} checkout_pane
   *   TRUE if operating on a Checkout 'new payment method' form.
   */

  /**
   * Namespace for the Vantiv eProtect functionality.
   *
   * @namespace
   */
  Drupal.vantivEprotect = {

    /**
     * {@property} API timeout setting (milliseconds).
     */
    timeout: 15000,

    /**
     * Loads the external Litle PayPage (eProtect) library script for the current transaction mode.
     *
     * @param {Drupal.settings.vantivSettings} settings
     */
    load: function(settings) {
      if ((typeof LitlePayPage === 'function')) {
        return;
      }
      $.getScript(Drupal.vantivEprotect.getPayPageHost(settings) + '/LitlePayPage/litle-api2.js');
    },

    /**
     * Gets the PayPage host for the given transaction mode.
     *
     * @param {Drupal.settings.vantivSettings} settings
     *
     * @returns {string} URL of the eProtect host without a trailing slash.
     */
    getPayPageHost: function(settings) {
      return (settings.mode == 'live') ? 'https://request.securepaypage-litle.com' : 'https://request-prelive.np-securepaypage-litle.com';
    },

    /**
     * Gets the submit button ID based on the current form being used.
     *
     * @param {Drupal.settings.vantivSettings} settings Vantiv settings.
     *
     * @returns {string} jQuery selector for the submit button to control.
     */
    getSubmitButtonSelector: function(settings) {
      // @todo Determine correct and stable ids to target.
      // Checkout pane vs. Payment pane .. what's the diff?
      var id = settings.checkout_pane ? '#edit-actions #edit-actions-next' : '#edit-actions #edit-actions-next';
      if (settings.cardonfile_form) {
        // @todo: Figure out what this is supposed to be once we have card on file create form.
        id = '#commerce-vantiv-creditcard-cardonfile-create-form #edit-submit';
      }

      return id;
    },

    /**
     * Gets the Drupal Commerce form fields that hold the values to send to Litle.
     *
     * @param {Drupal.settings.vantivSettings} settings
     *
     * @returns {object} HTML field elements keyed by Vantiv request keys.
     */
    getCommerceFormFields: function(settings) {

      // Some form fields will always be the same.
      var formFields = {
        'paypageRegistrationId': $('#vantivResponsePaypageRegistrationId').get(0),
        'bin': $('#vantivResponseBin').get(0)
      };

      // Some form fields will change based on which form they are a part of.
      if (settings.checkout_pane) {
        formFields.accountNum = $('[data-drupal-selector="edit-payment-information-add-payment-method-payment-details-number"]').get(0);
        formFields.cvv2 = $('[data-drupal-selector="edit-payment-information-add-payment-method-payment-details-security-code"]').get(0);
      }
      else if (settings.cardonfile_form) {
        formFields.accountNum = $('[data-drupal-selector="edit-payment-method-payment-details-number"]').get(0);
        formFields.cvv2 = $('[data-drupal-selector="edit-payment-method-payment-details-security-code"]').get(0);
      }

      return formFields;
    },

    /**
     * Sets hidden form values based on API response.
     *
     * @param {object} response
     */
    setLitleResponseFields: function(response) {
      $('#vantivResponseCode').val(response.response);
      $('#vantivResponseMessage').val(response.message);
      $('#vantivResponseTime').val(response.responseTime);
      $('#vantivResponseLitleTxnId').val(response.litleTxnId);
      $('#vantivResponseType').val(response.type);
      $('#vantivResponseFirstSix').val(response.firstSix);
      $('#vantivResponseLastFour').val(response.lastFour);
    },

    /**
     * Timeout callback.
     */
    timeoutOnLitle: function() {
      alert('We are experiencing technical difficulties (timeout). Please try again later.');
    },

    /**
     * Error callback.
     *
     * @param response
     * @returns {boolean}
     */
    onErrorAfterLitle: function(response) {
      Drupal.vantivEprotect.setLitleResponseFields(response);
      if (response.response == '871') {
        alert('Invalid card number. Check and retry. (Not Mod10)');
      }
      else if (response.response == '872') {
        alert('Invalid card number. Check and retry. (Too short)');
      }
      else if (response.response == '873') {
        alert('Invalid card number. Check and retry. (Too long)');
      }
      else if (response.response == '874') {
        alert('Invalid card number. Check and retry. (Not a number)');
      }
      else if (response.response == '875') {
        alert('We are experiencing technical difficulties. Please try again later.');
      }
      else if (response.response == '876') {
        alert('Invalid card number. Check and retry. (Failure from Server)');
      }
      else if (response.response == '881') {
        alert('Invalid card validation code. Check and retry. (Not a number)');
      }
      else if (response.response == '882') {
        alert('Invalid card validation code. Check and retry. (Too short)');
      }
      else if (response.response == '883') {
        alert('Invalid card validation code. Check and retry. (Too long)');
      }
      else if (response.response == '889') {
        alert('We are experiencing technical difficulties. Please try again later.');
      }

      return false;
    },

    /**
     * Gets a request object from the fields of the current payment form.
     *
     * @param {Drupal~settings~vantivSettings} settings
     *
     * @return {object}
     *   An object with the properties of the request required when calling Vantiv.
     */
    getRequest: function(settings) {
      return {
        paypageId: $('#vantivRequestPaypageId').val(),
        reportGroup: $('#vantivRequestMerchantTxnId').val(),
        orderId: $('#vantivRequestOrderId').val(),
        id: $('#vantivRequestReportGroup').val(),
        url: Drupal.vantivEprotect.getPayPageHost(settings)
      };
    },

    /**
     * Delegates the form's Continue button click event to Vantiv eProtect.
     *
     * @param {string} submitButtonId
     * @param {Drupal.settings.vantivSettings} settings
     */
    delegateSubmitButton: function(submitButtonId, settings) {

      // Leave the submit button alone if we've already bound our logic.
      if (window.vantivSubmitButtonBound) {
        return;
      }

      // Bind our custom submit button functionality.
      $(submitButtonId).bind('click', {
        settings: settings
      }, function(event, passthru) {

        // Get settings for this closure from the parent scope.
        var settings = event.data.settings;

        // Use the default (Drupal) behaviour if we've already successfully submitted to Vantiv.
        if (passthru) {
          return true;
        }

        var submitButton = event.currentTarget;

        // Clear Litle response fields.
        Drupal.vantivEprotect.setLitleResponseFields({'response': '', 'message': ''});

        // Build the custom success callback.
        var onSuccess = function(response) {

          // Set the transaction/token values from Vantiv in our hidden form fields.
          Drupal.vantivEprotect.setLitleResponseFields(response);

          // @todo: Test expiration date here to avoid trip to Drupal
          // since all other payment fields are handled client-side.

          // Trigger this submit handler again using the passthru flag.
          $(submitButton).trigger('click', true);
        };

        // Build the request based on current form values.
        var request = Drupal.vantivEprotect.getRequest(settings);
        var fields = Drupal.vantivEprotect.getCommerceFormFields(settings);
        var onError = Drupal.vantivEprotect.onErrorAfterLitle;
        var onTimeout = Drupal.vantivEprotect.timeoutOnLitle;
        var timeout = Drupal.vantivEprotect.timeout;

        // Make the API call.
        var api = new LitlePayPage();
        api.sendToLitle(request, fields, onSuccess, onError, onTimeout, timeout);

        // Prevent further regular form submit handling.
        return false;
      });

      window.vantivSubmitButtonBound = true;
    }

  }

})(jQuery, Drupal);