/**
 * @file
 * NBG Currency block behaviors.
 */
(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.ngbCurrencyFilterByText = {
    attach: function (context, settings) {
      // Currency filter.
      $('input.currency-filter-search', context).once().on('input', function (e) {
        var value = $(this).val().toLowerCase();
        $(".nbg-currency")
          .closest('.form-type-checkbox')
          .filter(function () {
          $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
      })
    }
  };
})(jQuery, Drupal);
