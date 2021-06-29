/**
 * @file
 * Google Photos importer selection album bucket behaviors.
 */

(function ($, Drupal) {

  'use strict';

  Drupal.gpiSelectionAlbumBucketState = Drupal.gpiSelectionAlbumBucketState || {
    keyJson: 'gaSelectionAlbumBucketJson',
    itemsAssoc: {},
    add: function (album_id, album_title, context) {
      this.itemsAssoc[album_id] = album_title;
      this.setSelectAllNoneCheckbox(context);
    },
    remove: function (album_id, context) {
      if (this.itemsAssoc.hasOwnProperty(album_id)) {
        delete this.itemsAssoc[album_id];
      }
      this.setSelectAllNoneCheckbox(context);
    },
    setItems: function () {
      if (localStorage.getItem(this.keyJson) === null) {
        this.itemsAssoc =  {};
      }
      else {
        if (localStorage.getItem(this.keyJson).length === 0) {
          this.itemsAssoc = {};
        }
        else {
          this.itemsAssoc = JSON.parse(localStorage.getItem(this.keyJson));
        }
      }
    },
    triggerChanged: function () {
      $(document).trigger('state_changed');
    },
    displaySelectedCount: function (context) {
      var count = Object.keys(this.itemsAssoc).length
      var label = Drupal.formatPlural(
        count,
        '1 Item',
        '@count Items',
      );
      $('#edit-total-selected', context).html(label);
    },
    setItemsCheckboxes: function (context) {
      Object.keys(this.itemsAssoc).forEach(function (album_id, album_title) {
        $('.results-container input[data-album-id=' + album_id + ']', context).prop('checked', true);
      });
      this.setSelectAllNoneCheckbox(context);
    },
    setSelectAllNoneCheckbox: function (context) {
      if (document.querySelectorAll('.results-container input[type=checkbox]').length
        === document.querySelectorAll('.results-container input[type=checkbox]:checked').length) {
        $('.form-item-select-all input', context).prop('checked', true);
      }
      else {
        $('.form-item-select-all input', context).prop('checked', false);
      }
    },
    addToLocalStorage: function () {
      localStorage.setItem(this.keyJson, JSON.stringify(this.itemsAssoc));
    },
    addToFormElement: function (context) {
      $('#ga-selection-bucket', context).val(localStorage.getItem(this.keyJson));
    },
  };

  /**
   * The behaviour callback.
   *
   * @type {{attach: Drupal.gpiSelectionAlbumBucket.attach}}
   */
  Drupal.behaviors.gpiSelectionAlbumBucket = {
    attach: function (context, settings) {

      $('.results-container input[type=checkbox]', context).once().on('change', function (e) {
        var media_id = this.getAttribute('data-album-id');
        var album_title = this.getAttribute('data-album-title');
        if ($(this).is(':checked')) {
          Drupal.gpiSelectionAlbumBucketState.add(media_id, album_title, context);
        }
        else {
          Drupal.gpiSelectionAlbumBucketState.remove(media_id, context);
        }
        Drupal.gpiSelectionAlbumBucketState.triggerChanged();
      });

      $('input.google-photos-importer-form.form-submit', context).once().on('click', function (e) {
        localStorage.removeItem(Drupal.gpiSelectionAlbumBucketState.keyJson);
      });

      $(document).once().on('state_changed', function () {
        Drupal.gpiSelectionAlbumBucketState.displaySelectedCount(context);
        Drupal.gpiSelectionAlbumBucketState.addToLocalStorage();
        Drupal.gpiSelectionAlbumBucketState.addToFormElement(context);
      });

      $(window).once().on('load', function () {
        Drupal.gpiSelectionAlbumBucketState.setItems();
        Drupal.gpiSelectionAlbumBucketState.displaySelectedCount(context);
        Drupal.gpiSelectionAlbumBucketState.setItemsCheckboxes(context);
        Drupal.gpiSelectionAlbumBucketState.addToFormElement(context);
      });

      // Load the images once dialog window open.
      $(window).off('dialog:aftercreate').on('dialog:aftercreate', function () {
        Object.keys(Drupal.gpiSelectionAlbumBucketState.itemsAssoc)
        .forEach(function (item) {
          var settings = {
            "async": true,
            "crossDomain": true,
            "url": "https://photoslibrary.googleapis.com/v1/albums/" + item,
            "method": "GET",
            "headers": {
              "Content-Type": "application/json",
              "Authorization": "Bearer " + drupalSettings.google_photos_api_token
            }
          }

          $.ajax(settings).done(function (response) {
            var src = response.coverPhotoBaseUrl + '=w200-h300';
            var photos_number = response.mediaItemsCount;
            var album_title = typeof photos_number !== "undefined" ? response.title + '\n' + photos_number + Drupal.t(' photos') : response.title;
            $('#selected-image-' + item).attr('src', src).siblings('.image-title').text(album_title);
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
        Object.keys(Drupal.gpiSelectionAlbumBucketState.itemsAssoc)
          .forEach(function (item) {
          var $imageContainer = $('<div></div>').attr('data-album-id', item).addClass('image-container');
          var $image = $('<img>').attr('id', 'selected-image-' + item).attr('alt', 'Image');
          var $title = $('<div></div>').addClass('image-title');
          var $remove = $('<button title="Remove selected" type="button" aria-label="Remove image">x</button>')
            .attr('id', 'remove-button-' + item)
            .attr('data-album-id', item);
          // Add remove functionality.
          $(document).on('click', '#remove-button-' + item, function (e) {
            $(this).parents('[data-album-id=' + item + ']').remove();
            // Need to enforce the change in case user on the page which
            // doesn't contain the checkboxes with desired ids.
            $('.results-container input[data-album-id=' + item + ']', context).prop('checked', false).trigger('change');
            Drupal.gpiSelectionAlbumBucketState.remove(item, context);
            Drupal.gpiSelectionAlbumBucketState.triggerChanged();
          });
          $imageContainer.append($image, $title, $remove);
          $imageWrapper.append($imageContainer);
        });

        return $imageWrapper.html();
      };

    }
  };

}(jQuery, Drupal));
