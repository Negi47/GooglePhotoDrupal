/**
 * @file
 * Google Photos importer select items behaviors.
 */

(function ($, Drupal) {

  'use strict';

  Drupal.behaviors.gpiSelectItems = {
    attach: function (context, settings) {
      $('.form-item-select-all input', context).once().on('change', function (e) {
        if ($(this).is(':checked')) {
          $('.results-container input[type=checkbox]', context).prop('checked', true).trigger('change');
        }
        else {
          $('.results-container input[type=checkbox]', context).prop('checked', false).trigger('change');
        }
      });
    }
  };

} (jQuery, Drupal));
