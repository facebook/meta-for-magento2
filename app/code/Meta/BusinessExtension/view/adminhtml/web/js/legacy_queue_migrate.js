/**
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Drives the "Move them now" button. Each request moves one slice and the browser keeps
 * asking until nothing is pending, so no request is long enough to time out. Slices are
 * committed server side, so closing the tab part way through is safe.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    return {
        /**
         * @param {String} url - route that moves one slice
         * @param {String} formKey - admin form key
         * @param {String} htmlId - id prefix the template rendered against
         * @param {Number} moved - already moved by earlier slices, before this page load
         * @param {Number} expired - already discarded by earlier slices
         * @param {Number} failed - already marked failed by earlier slices
         */
        init: function (url, formKey, htmlId, moved, expired, failed) {
            var button = $('#' + htmlId + '_btn'),
                text = $('#' + htmlId + '_text'),
                // Seeded from the server so a reload carries on counting.
                total = {
                    moved: moved || 0,
                    expired: expired || 0,
                    failed: failed || 0
                };

            /**
             * @param {String} message
             */
            function say(message) {
                text.text(message);
            }

            /**
             * @returns {String}
             */
            function progress() {
                var parts = [$t('Moved %1.').replace('%1', total.moved)];

                if (total.expired > 0) {
                    parts.push($t('%1 were too old to send and were discarded.').replace('%1', total.expired));
                }

                if (total.failed > 0) {
                    parts.push($t('%1 could not be read and were marked failed.').replace('%1', total.failed));
                }

                return parts.join(' ');
            }

            function slice() {
                $.ajax({
                    url: url,
                    type: 'POST',
                    dataType: 'json',
                    data: { form_key: formKey },
                    showLoader: false
                }).done(function (response) {
                    if (!response || response.success === false) {
                        button.prop('disabled', false);
                        say(response && response.message
                            ? response.message
                            : $t('The migration could not be started. Check the Magento logs.'));

                        return;
                    }

                    total.moved += response.moved || 0;
                    total.expired += response.expired || 0;
                    total.failed += response.failed || 0;

                    if (!response.done) {
                        say(progress() + ' ' + $t('%1 to go...').replace('%1', response.remaining));
                        slice();

                        return;
                    }

                    if (response.stalled) {
                        button.prop('disabled', false);
                        say(progress() + ' ' + $t('%1 could not be moved. Check the Magento logs for the reason.')
                            .replace('%1', response.remaining));

                        return;
                    }

                    button.remove();
                    say(progress() + ' ' + $t('The MySQL queue is now empty.'));
                }).fail(function () {
                    button.prop('disabled', false);
                    say(progress() + ' ' +
                        $t('The last request failed. Click to carry on from where it stopped.'));
                });
            }

            button.on('click', function () {
                button.prop('disabled', true);
                say($t('Moving messages...'));
                slice();
            });
        }
    };
});
