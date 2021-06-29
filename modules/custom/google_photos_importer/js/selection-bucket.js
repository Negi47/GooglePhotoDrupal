/**
 * @file
 * Google Photos importer selection bucket behaviors.
 */

(function ($, Drupal) {

  'use strict';

  Drupal.gpiSelectionBucketState = Drupal.gpiSelectionBucketState || {
    key: 'gaSelectionBucket',
    items: [],
    add: function (item, context) {
      if (!this.items.includes(item)) {
        this.items.push(item);
      }
      this.setSelectAllNoneCheckbox(context);
    },
    remove: function (item, context) {
      if (this.items.includes(item)) {
        var index = this.items.indexOf(item);
        this.items.splice(index, 1);
      }
      this.setSelectAllNoneCheckbox(context);
    },
    setItems: function () {
      if (localStorage.getItem(this.key) === null) {
        this.items = [];
      }
      else {
        if (localStorage.getItem(this.key).length === 0) {
          this.items = [];
        }
        else {
          this.items = localStorage.getItem(this.key).split(',');
        }
      }
    },
    triggerChanged: function () {
      $(document).trigger('state_changed');
    },
    displaySelectedCount: function (context) {
      var count = this.items.length;
      var label = Drupal.formatPlural(
        count,
        '1 Item',
        '@count Items',
      );
      $('#edit-total-selected', context).html(label);
    },
    setItemsCheckboxes: function (context) {
      this.items.forEach(function (item) {
        $('.results-container input[data-media-id=' + item + ']', context).prop('checked', true);
      });
      this.setSelectAllNoneCheckbox(context);
    },
    setSelectAllNoneCheckbox: function (context) {
      if (document.querySelectorAll('.results-container input[type=checkbox]').length === document.querySelectorAll('.results-container input[type=checkbox]:checked').length) {
        $('.form-item-select-all input', context).prop('checked', true);
      }
      else {
        $('.form-item-select-all input', context).prop('checked', false);
      }
    },
    addToLocalStorage: function () {
      localStorage.setItem(this.key, this.items);
    },
    addToFormElement: function (context) {
      $('#ga-selection-bucket', context).val(localStorage.getItem(this.key));
    },
  };

  /**
   * The behaviour callbak.
   *
   * @type {{attach: Drupal.gpiSelectionBucket.attach}}
   */
  Drupal.behaviors.gpiSelectionBucket = {
    attach: function (context, settings) {

      $('.results-container input[type=checkbox]', context).once().on('change', function (e) {
        var media_id = this.getAttribute('data-media-id');
        if ($(this).is(':checked')) {
          Drupal.gpiSelectionBucketState.add(media_id, context);
        }
        else {
          Drupal.gpiSelectionBucketState.remove(media_id, context);
        }
        Drupal.gpiSelectionBucketState.triggerChanged();
      });

      $('input.google-photos-importer-form.form-submit', context).once().on('click', function (e) {
        localStorage.removeItem(Drupal.gpiSelectionBucketState.key);
      });

      $(document).once().on('state_changed', function () {
        Drupal.gpiSelectionBucketState.displaySelectedCount(context);
        Drupal.gpiSelectionBucketState.addToLocalStorage();
        Drupal.gpiSelectionBucketState.addToFormElement(context);
      });

      $(window).once().on('load', function () {
        Drupal.gpiSelectionBucketState.setItems();
        Drupal.gpiSelectionBucketState.displaySelectedCount(context);
        Drupal.gpiSelectionBucketState.setItemsCheckboxes(context);
        Drupal.gpiSelectionBucketState.addToFormElement(context);
      });

      // Load the images once dialog window open.
      $(window).off('dialog:aftercreate').on('dialog:aftercreate', function () {
        Drupal.gpiSelectionBucketState.items.forEach(function (item) {
          var settings = {
            "async": true,
            "crossDomain": true,
            "url": "https://photoslibrary.googleapis.com/v1/mediaItems/" + item,
            "method": "GET",
            "headers": {
              "Content-Type": "application/json",
              "Authorization": "Bearer " + drupalSettings.google_photos_api_token
            }
          }

          $.ajax(settings).done(function (response) {
            var src = response.baseUrl + '=w200-h300';
            var date = new Date(response.mediaMetadata.creationTime);
            var title = date.toDateString() + '\n' + (response.description ? response.description : response.filename);
            $('#selected-image-' + item).attr('src', src).siblings('.image-title').text(title);
          })
        });
      });

      $('#edit-total-selected', context).once().on('click', function (e) {
        e.preventDefault();
        if ($(this).text() !== '0 Items') {
          // Destroy previous dialog.
          $('#selection-bucket-dialog').remove();
          var $dialog = $('<div id="selection-bucket-dialog">' + Drupal.theme('mediaImages') + '</div>');
          var $modalBox = $dialog.appendTo('body');
          Drupal.dialog($modalBox, {
            title: Drupal.t('Your selection'),
            width: '60%',
            buttons: [{
              text: Drupal.t('Clear selections bucket'),
              click: function click() {
                $('#selection-bucket-dialog .image-container button', context).click();
                $('button.ui-dialog-titlebar-close', context).click();
              }
            }],
          }).showModal();
        }
      });

      /**
       * Theme function for modal box.
       *
       * @return {string}
       *   Markup for the modal box.
       */
      Drupal.theme.mediaImages = function () {
        var $imageWrapper = $('<div></div>');
        // Contract placeholders.
        Drupal.gpiSelectionBucketState.items.forEach(function (item) {
          var $imageContainer = $('<div></div>').attr('data-media-id', item).addClass('image-container');
          var $image = $('<img>').attr('id', 'selected-image-' + item).attr('alt', 'Image');
          var $title = $('<div></div>').addClass('image-title');
          var $remove = $('<button title="Remove selected" type="button" aria-label="Remove image">x</button>')
            .attr('id', 'remove-button-' + item)
            .attr('data-media-id', item);
          // Add remove functionality.
          $(document).on('click', '#remove-button-' + item, function (e) {
            $(this).parents('[data-media-id=' + item + ']').remove();
            // Need to enforce the change in case user on the page which
            // doesn't contain the checkboxes with desired ids.
            $('.results-container input[data-media-id=' + item + ']', context).prop('checked', false).trigger('change');
            Drupal.gpiSelectionBucketState.remove(item, context);
            Drupal.gpiSelectionBucketState.triggerChanged();
          });
          $imageContainer.append($image, $title, $remove);
          $imageWrapper.append($imageContainer);
        });

        return $imageWrapper.html();
      };

    }
  };

}(jQuery, Drupal));
