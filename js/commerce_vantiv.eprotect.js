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
      if (context.firstChild.nodeName != 'html') {
        return false;
      }
      settings = settings.commerce_vantiv.eprotect;
      if (!Drupal.vantivEprotect.isPayPageLibraryLoaded()) {
        alert('Vantiv e-Protect error (Litle PayPage library unavailable).');
        return false;
      }
      var buttonId = Drupal.vantivEprotect.getSubmitButtonID(settings);
      Drupal.vantivEprotect.delegateSubmitButton(buttonId, settings);
    }
  };

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
     * Ensures the global LitlePayPage library is loaded.
     *
     * @returns {boolean}
     */
    isPayPageLibraryLoaded: function() {
      if (typeof LitlePayPage !== 'function') {
        alert('We are experiencing technical difficulties. Please try again later or call 555-555-1212 (API unavailable)');
        return false;
      }

      return true;
    },

    /**
     * Gets the URL to which PayPage requests are sent.
     *
     * @param {Drupal.settings.vantivSettings} settings
     *
     * @returns {string}
     */
    getPayPageRequestUrl: function(settings) {
      // @todo pass this url in via settings.
      return 'https://request-prelive.np-securepaypage-litle.com';
    },

    /**
     * Gets the submit button ID based on the current form being used.
     *
     * @param {Drupal.settings.vantivSettings} settings Vantiv settings.
     *
     * @returns {string} HTML ID of the submit button to control.
     */
    getSubmitButtonID: function(settings) {
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
      if (settings.checkout_pane) {
        var formFields = {
          "accountNum": document.getElementById("edit-commerce-payment-payment-details-credit-card-number"),
          "cvv2": document.getElementById("edit-commerce-payment-payment-details-credit-card-code"),
          "paypageRegistrationId": document.getElementById("response$paypageRegistrationId"),
          "bin": document.getElementById("response$bin")
        };
      }
      else if (settings.payment_pane) {
        var formFields = {
          'accountNum': document.getElementById('edit-payment-information-add-payment-method-payment-details-number'),
          'cvv2': document.getElementById('edit-payment-information-add-payment-method-payment-details-security-code'),
          'paypageRegistrationId': document.getElementById('response$paypageRegistrationId'),
          'bin': document.getElementById('response$bin')
        };
      }
      else if (settings.cardonfile_form) {
        var formFields = {
          "accountNum": document.getElementById("edit-credit-card-number"),
          "cvv2": document.getElementById("edit-credit-card-code"),
          "paypageRegistrationId": document.getElementById("response$paypageRegistrationId"),
          "bin": document.getElementById("response$bin")
        };
      }

      return formFields;
    },

    /**
     * Sets hidden form values based on API response.
     *
     * @param response
     */
    setLitleResponseFields: function(response) {
      document.getElementById('response$code').value = response.response || '';
      document.getElementById('response$message').value = response.message || '';
      document.getElementById('response$responseTime').value = response.responseTime || '';
      document.getElementById('response$litleTxnId').value = response.litleTxnId || '';
      document.getElementById('response$type').value = response.type || '';
      document.getElementById('response$firstSix').value = response.firstSix || '';
      document.getElementById('response$lastFour').value = response.lastFour || '';
    },

    /**
     * Timeout callback.
     */
    timeoutOnLitle: function() {
      alert('We are experiencing technical difficulties. Please try again later call 555-555-1212 (timeout)');
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
        alert('We are experiencing technical difficulties. Please try again later or call 555-555-1212');
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
        alert('We are experiencing technical difficulties. Please try again later or call 555-555-1212');
      }

      return false;
    },

    getRequest: function(settings) {
      return {
        'paypageId': document.getElementById('request$paypageId').value,
        'reportGroup': document.getElementById('request$reportGroup').value,
        'orderId': document.getElementById('request$orderId').value,
        'id': document.getElementById('request$merchantTxnId').value,
        'url': Drupal.vantivEprotect.getPayPageRequestUrl(settings)
      };
    },

    /**
     * Delegates the form's Continue button click event to Vantiv eProtect.
     *
     * @param {string} submitButtonId
     * @param {Drupal.settings.vantivSettings} settings
     */
    delegateSubmitButton: function(submitButtonId, settings) {
      $(submitButtonId).bind('click', {
        settings: settings
      }, function(event, passthru) {
        var settings = event.data.settings;
        if (passthru) {
          return true;
        }
        var submitButton = event.currentTarget;

        // Clear Litle response fields.
        Drupal.vantivEprotect.setLitleResponseFields({'response': '', 'message': ''});

        // Build the custom success callback.
        var onSuccess = function(response) {
          Drupal.vantivEprotect.setLitleResponseFields(response);
          // @todo: Test expiration date here to avoid trip to Drupal
          // since all other payment fields are handled client-side.
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
    }
  }

})(jQuery, Drupal);