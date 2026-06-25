// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * modalsaveform
 *
 * @module     local_learnwise/lticonfiguration
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([
    'jquery',
    'core/config',
    'core/fragment',
    'core/modal_events',
    'core/notification',
], function(
    $,
    Config,
    Fragment,
    ModalEvents,
    Notification
) {

    /**
     * Generate version dependent modal
     *
     * @param {Object} modalConfig
     * @returns {Promise}
     */
    function getModal(modalConfig) {
        return new window.Promise(function(resolve, reject) {
            require(['core/modal'], function(Modal) {
                if (typeof Modal.create === 'function') {
                    resolve(Modal);
                } else {
                    require(['core/modal_factory'], function(Modalfactory) {
                        resolve(Modalfactory);
                    }, reject);
                }
            }, reject);
        }).then(function(module) {
            return module.create(modalConfig);
        });
    }

    /**
     * Register Modal Form
     *
     * @param {String} wrapperselector
     * @param {Object} modalConfig
     * @param {Function} successcallback
     */
    function registerModalForm(wrapperselector, modalConfig, successcallback) {
        document.addEventListener('click', function(e) {
            var actionbutton = e.target.closest(wrapperselector);
            if (!actionbutton) {
                return;
            }
            e.preventDefault();
            var mergedConfig = {};
            var extra = modalConfig || {};
            for (var key in extra) {
                if (Object.prototype.hasOwnProperty.call(extra, key)) {
                    mergedConfig[key] = extra[key];
                }
            }
            mergedConfig.body = callFragment(actionbutton);
            mergedConfig.removeOnClose = true;
            getModal(mergedConfig).then(function(modal) {
                modal.getRoot().on('submit ' + ModalEvents.save, function(e) {
                    e.preventDefault();
                    var form = modal.getRoot().find('form')[0];
                    modal.setBody(callFragment(form).then(function(html, js, ajaxdata) {
                        if (ajaxdata.success) {
                            modal.destroy();
                            if (typeof successcallback === 'function') {
                                successcallback(ajaxdata.data);
                            }
                        }
                        return $.Deferred().resolve(html, js).promise();
                    }));
                });
                modal.show();
                return modal;
            }).catch(Notification.exception);
        });
    }

    /**
     * Call fragment api to process form
     *
     * @param {HTMLElement} catbutton
     * @returns {Promise}
     */
    function callFragment(catbutton) {
        var datasetObj = {};
        var datasetKeys = Object.keys(catbutton.dataset);
        for (var i = 0; i < datasetKeys.length; i++) {
            datasetObj[datasetKeys[i]] = catbutton.dataset[datasetKeys[i]];
        }
        var urlParams = new URLSearchParams(datasetObj);
        if (catbutton instanceof HTMLFormElement) {
            var formData = new FormData(catbutton);
            var combined = [];
            var existingEntries = Array.from(urlParams.entries());
            var formEntries = Array.from(formData.entries());
            for (var j = 0; j < existingEntries.length; j++) {
                combined.push(existingEntries[j]);
            }
            for (var k = 0; k < formEntries.length; k++) {
                combined.push(formEntries[k]);
            }
            urlParams = new URLSearchParams(combined);
        }
        return Fragment.loadFragment(
            'local_learnwise',
            'form',
            Config.contextid,
            {
                formdata: urlParams.toString()
            }
        ).then(function(html, js) {
            var ajaxdata = JSON.parse(html);
            return $.Deferred().resolve(ajaxdata.formhtml || '', js, ajaxdata).promise();
        });
    }

    return {
        getModal: getModal,

        registerModalForm: registerModalForm,

        callFragment: callFragment,
    };

});