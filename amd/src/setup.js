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
 * LearnWise setup module for managing plugin configuration interface.
 * Handles floating button, LTI integration, web services, and live API settings.
 *
 * @module     local_learnwise/setup
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(
    [
        M.cfg.wwwroot + '/local/learnwise/vendorjs/zenorocha/clipboard.min.js',
        'core/str', 'core/notification', 'core/fragment', 'core/config', 'core/templates',
        'core/ajax', 'local_learnwise/lticonfiguration'
    ],
    function(ClipboardJS, Str, Notification, Fragment, Config, Templates, Ajax, LtiConfiguration) {

    /**
     * Application state object containing all configuration settings
     * @type {Object}
     * @property {string} environment - Current environment (sandbox/production)
     * @property {string} floatingButtonAssistantId - Assistant ID for floating button
     * @property {boolean} showFloatingButton - Whether floating button is enabled
     * @property {boolean} ltiEnabled - Whether LTI integration is enabled
     * @property {boolean} webServicesEnabled - Whether web services are enabled
     * @property {boolean} liveApiEnabled - Whether live API is enabled
     * @property {boolean} showToast - Whether to show toast notifications
     * @property {boolean} ltiSetup - Whether to use lti config
     */
    var state = {
        environment: "sandbox",
        floatingButtonAssistantId: "",
        showFloatingButton: false,
        ltiEnabled: false,
        webServicesEnabled: false,
        liveApiEnabled: false,
        aiAssessmentEnabled: false,
        courseIds: "",
        aiAssessmentAssistantId: "",
        showToast: false,
        ltiSetup: false,
        isTokenShown: false,
    };

    var COMPONENT = 'local_learnwise';

    /**
     * DOM elements cache for efficient access to form elements and controls
     * @type {Object}
     */
    var elements = {
        form: document.getElementById('form1'),
        floatingButtonAssistantId: document.getElementById("floatingButtonAssistantId"),
        floatingButtonSwitch: document.getElementById("floatingButtonSwitch"),
        floatingButtonStatus: document.getElementById("floatingButtonStatus"),
        floatingButtonSwitchWrapper: document.getElementById("floatingButtonSwitchWrapper"),
        floatingButtonTooltip: document.getElementById("floatingButtonTooltip"),

        ltiConfigTable: document.querySelector('[data-region="ltilist"]'),

        webServicesSwitch: document.getElementById("webServicesSwitch"),
        webServicesStatus: document.getElementById("webServicesStatus"),
        webServicesConfig: document.getElementById("webServicesConfig"),

        liveApiSwitch: document.getElementById("liveApiSwitch"),
        liveApiStatus: document.getElementById("liveApiStatus"),
        liveApiConfig: document.getElementById("liveApiConfig"),

        ltiRemovalModal: document.getElementById("ltiRemovalModal"),
        closeLtiModal: document.getElementById("closeLtiModal"),
        confirmLtiRemoval: document.getElementById("confirmLtiRemoval"),

        aiAssessmentSwitch: document.getElementById("aiAssessmentSwitch"),
        aiAssessmentAssistantId: document.getElementById("aiAssessmentAssistantId"),
        aiAssessmentSwitchWrapper: document.getElementById("aiAssessmentSwitchWrapper"),
        aiAssessmentTooltip: document.getElementById("aiAssessmentTooltip"),
        aiAssessmentStatus: document.getElementById("aiAssessmentStatus"),
        courseIds: document.getElementById("courseIds"),

        webServiceRotateTokenButton: document.getElementById("webservicerotatetokenbutton"),
        wsRotateTokenModal: document.getElementById("wsRotateTokenModal"),
        closeWsRotateTokenModal: document.getElementById("closeWsRotateTokenModal"),
        confirmWsRotateToken: document.getElementById("confirmWsRotateToken"),

        webserviceAccessTokenInput: document.getElementById("webserviceAccessTokenInput"),
        visibilityControlBtn: document.getElementById("visibilityControlBtn"),
        hiddenTokenIconWrapper: document.getElementById("hiddenTokenIconWrapper"),
        seenTokenIconWrapper: document.getElementById("seenTokenIconWrapper"),
    };

    // Initialize state from form values
    if (elements.floatingButtonAssistantId) {
        state.floatingButtonAssistantId = elements.floatingButtonAssistantId.value;
    }

    if (elements.aiAssessmentAssistantId) {
        state.aiAssessmentAssistantId = elements.aiAssessmentAssistantId.value;
    }

    if (elements.floatingButtonStatus) {
        state.showFloatingButton = parseInt(elements.form.elements.floatingButtonStatus.value) === 1;
    }

    if (elements.webServicesStatus) {
        state.webServicesEnabled = parseInt(elements.form.elements.webServicesStatus.value) === 1;
    }

    if (elements.liveApiStatus) {
        state.liveApiEnabled = parseInt(elements.form.elements.liveApiStatus.value) === 1;
    }

    if (elements.aiAssessmentStatus) {
        state.aiAssessmentEnabled = parseInt(elements.form.elements.aiAssessmentStatus.value) === 1;
    }

    if (elements.courseIds) {
        state.courseIds = elements.courseIds.value;
    }

    /**
     * Initializes all event listeners for the setup interface.
     * Sets up handlers for input changes, switch toggles, modal interactions,
     * and clipboard functionality.
     *
     * @function initializeEventListeners
     * @private
     */
    function initializeEventListeners() {
        // Floating button assistant ID input
        elements.floatingButtonAssistantId.addEventListener("input", function(e) {
            state.floatingButtonAssistantId = e.target.value;
            updateFloatingButtonSwitch();
        });

        // AI Assessment assistant ID input
        if (elements.aiAssessmentAssistantId) {
            elements.aiAssessmentAssistantId.addEventListener("input", function(e) {
                state.aiAssessmentAssistantId = e.target.value;
                updateAiAssessmentSwitch();
            });
        }

        // Floating button switch
        elements.floatingButtonSwitch.addEventListener("click", function() {
            if (canEnableFloatingButton()) {
                state.showFloatingButton = !state.showFloatingButton;
                updateFloatingButtonSwitch();
            }
        });

        // Web services switch
        elements.webServicesSwitch.addEventListener("click", function() {
            state.webServicesEnabled = !state.webServicesEnabled;
            updateWebServicesSwitch();
        });

        // Live API switch
        elements.liveApiSwitch.addEventListener("click", function() {
            state.liveApiEnabled = !state.liveApiEnabled;
            updateLiveApiSwitch();
        });

        // Ai Assessment switch
        elements.aiAssessmentSwitch.addEventListener("click", function() {
            state.aiAssessmentEnabled = !state.aiAssessmentEnabled;
            updateAiAssessmentSwitch();
        });

        // Course ids input
        elements.courseIds.addEventListener("input", function(e) {
            state.courseIds = e.target.value;
            updateCourseIdsInput();
        });

        // Modal close on overlay click
        elements.ltiRemovalModal.addEventListener("click", function(e) {
            if (e.target === elements.closeLtiModal) {
                closeLtiRemovalModal();
            }
            if (e.target === elements.confirmLtiRemoval) {
                confirmLtiRemoval();
            }
            if (e.target === elements.ltiRemovalModal) {
                closeLtiRemovalModal();
            }
        });

        elements.wsRotateTokenModal.addEventListener("click", function(e) {
            if (e.target === elements.closeWsRotateTokenModal) {
                closeWsRotateTokenModal();
            }
            if (e.target === elements.confirmWsRotateToken) {
                confirmWsRotateToken();
            }
            if (e.target === elements.wsRotateTokenModal) {
                closeWsRotateTokenModal();
            }
        });

        // Environment radio buttons
        var environmentRadios = document.querySelectorAll('input[name="environment"]');
        environmentRadios.forEach(function(radio) {
            if (radio.checked) {
                state.environment = radio.value;
            }
        });

        // Initialize clipboard functionality
        var clipboardInstance = new ClipboardJS('.copy-btn');
        clipboardInstance.on('success', function(e) {
            e.clearSelection();
            Str.get_string('copied', COMPONENT)
            .then(function(str) {
                if (state.showToast) {
                    require(['core/toast'], function(Toast) {
                        Toast.add(str);
                    });
                } else {
                    // eslint-disable-next-line no-alert
                    alert(str);
                }
                return null;
            })
            .catch(Notification.exception);
        });

        var sectionWrapper = elements.webServicesConfig ? elements.webServicesConfig.closest('.section') : null;
        if (sectionWrapper) {
            if (elements.webserviceAccessTokenInput) {
                state.isTokenShown = elements.webserviceAccessTokenInput.getAttribute('type') === 'text';
            }
            sectionWrapper.addEventListener('click', function(e) {
                // Add Rotate Key Confirmation
                if (elements.webServiceRotateTokenButton &&
                    elements.webServiceRotateTokenButton.contains(e.target)) {
                    e.preventDefault();
                    showWsRotateTokenModal();
                }
                // Hide/show Token
                if (elements.visibilityControlBtn &&
                    elements.visibilityControlBtn.contains(e.target)) {
                    e.preventDefault();
                    setWebServicesTokenVisibility(true);
                }
            });
        }
    }

    /**
     * Checks if the floating button can be enabled based on current state.
     * Requires a valid assistant ID to be present.
     *
     * @function canEnableFloatingButton
     * @private
     * @returns {boolean} True if floating button can be enabled, false otherwise
     */
    function canEnableFloatingButton() {
        return state.floatingButtonAssistantId.trim().length > 0;
    }

    /**
     * Checks if Aiassessment integration can be enabled based on current state.
     * Requires a valid Aiassessment assistant ID to be present.
     *
     * @function canEnableAiassessment
     * @private
     * @returns {boolean} True if Assessment can be enabled, false otherwise
     */
    function canEnableAiassessment() {
        return state.aiAssessmentAssistantId.trim().length > 0;
    }

    /**
     * Updates the floating button switch UI and form state.
     * Handles enabling/disabling the switch based on assistant ID availability,
     * updates visual states, and synchronizes with form values.
     *
     * @function updateFloatingButtonSwitch
     * @private
     */
    function updateFloatingButtonSwitch() {
        var canEnable = canEnableFloatingButton();

        if (canEnable) {
            elements.floatingButtonSwitch.classList.remove("disabled");
            elements.floatingButtonSwitchWrapper.style.cursor = "pointer";
            elements.floatingButtonTooltip.style.display = "none";

            if (state.showFloatingButton) {
                elements.floatingButtonSwitch.classList.add("active");
                Str.get_string('statusenabled', COMPONENT).then(function(str) {
                    elements.floatingButtonStatus.textContent = str;
                    return null;
                }).fail(Notification.exception);
            } else {
                elements.floatingButtonSwitch.classList.remove("active");
                Str.get_string('statusdisabled', COMPONENT).then(function(str) {
                    elements.floatingButtonStatus.textContent = str;
                    return null;
                }).fail(Notification.exception);
            }
        } else {
            elements.floatingButtonSwitch.classList.remove("active");
            elements.floatingButtonSwitch.classList.add("disabled");
            elements.floatingButtonSwitchWrapper.style.cursor = "not-allowed";
            Str.get_string('statusdisabled', COMPONENT).then(function(str) {
                elements.floatingButtonStatus.textContent = str;
                return null;
            }).fail(Notification.exception);
            state.showFloatingButton = false;
        }
        elements.form.elements.floatingButtonStatus.value = state.showFloatingButton ? 1 : 0;
    }

    /**
     * Updates the ai assessment switch UI and configuration.
     * Toggles ai assessment functionality, updates status display
     *
     * @function updateAiAssessmentSwitch
     * @private
     */
    function updateAiAssessmentSwitch() {
         if (!elements.aiAssessmentAssistantId) {
            return;
        }
        var canEnable = canEnableAiassessment();
        if (canEnable) {
            elements.aiAssessmentSwitch.classList.remove("disabled");
            elements.aiAssessmentSwitchWrapper.style.cursor = "pointer";
            elements.aiAssessmentTooltip.style.display = "none";

            if (state.aiAssessmentEnabled) {
                elements.aiAssessmentSwitch.classList.add("active");
                Str.get_string('statusenabled', COMPONENT).then(function(str) {
                    elements.aiAssessmentStatus.textContent = str;
                    return null;
                }).catch(Notification.exception);
            } else {
                elements.aiAssessmentSwitch.classList.remove("active");
                Str.get_string('statusdisabled', COMPONENT).then(function(str) {
                    elements.aiAssessmentStatus.textContent = str;
                    return null;
                }).catch(Notification.exception);
            }
        } else {
            elements.aiAssessmentSwitch.classList.remove("active");
            elements.aiAssessmentSwitch.classList.add("disabled");
            elements.aiAssessmentSwitchWrapper.style.cursor = "not-allowed";
            Str.get_string('statusdisabled', COMPONENT).then(function(str) {
                elements.aiAssessmentStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            state.aiAssessmentEnabled = false;
        }

        elements.form.elements.aiAssessmentStatus.value = state.aiAssessmentEnabled ? 1 : 0;
    }

    /**
     * Updates the courseid
     *
     * @function updateCourseIdsInput
     * @private
     */
    function updateCourseIdsInput() {
        var pattern = /^(\d+)(,\d+)*$/;
        var saveBtn = document.getElementById('save-btn');
        var inputEl = elements.courseIds;

        var value = (state.courseIds || "").trim();
        if (!value) {
            inputEl.classList.remove('border-danger');
            saveBtn.disabled = false;
            saveBtn.className = 'save-btn';
            return;
        }

        var isValid = pattern.test(state.courseIds);
        inputEl.classList.toggle('border-danger', !isValid);
        saveBtn.disabled = !isValid;
        saveBtn.className = isValid ? 'save-btn' : 'save-btn bg-gray text-dark';
    }

    /**
     * Updates the web services switch UI and configuration.
     * Toggles web services functionality, updates status display,
     * and loads dynamic configuration content via Fragment API.
     *
     * @function updateWebServicesSwitch
     * @private
     */
    function updateWebServicesSwitch() {
        if (state.webServicesEnabled) {
            elements.webServicesSwitch.classList.add("active");
            Str.get_string('statusenabled', COMPONENT).then(function(str) {
                elements.webServicesStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            elements.webServicesConfig.style.display = "block";
        } else {
            elements.webServicesSwitch.classList.remove("active");
            Str.get_string('statusdisabled', COMPONENT).then(function(str) {
                elements.webServicesStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            elements.webServicesConfig.style.display = "none";
        }
        Fragment.loadFragment(COMPONENT, 'process_setup', Config.contextid, {
            formdata: 'enablewebservice=' + (state.webServicesEnabled ? 1 : 0)
        }).then(function(html, js) {
            return Templates.replaceNodeContents(elements.webServicesConfig, html, js);
        }).then(function() {
            setWebServicesElements();
            return;
        }).catch(Notification.exception);

        elements.form.elements.webServicesStatus.value = state.webServicesEnabled ? 1 : 0;
    }

    /**
     * Toggles visibility of the web services access token input.
     *
     * @param {boolean} toggle If true, flips the current visibility state.
     * @private
     */
    function setWebServicesTokenVisibility(toggle) {
        if (toggle) {
            state.isTokenShown = !state.isTokenShown;
        }
        if (elements.webserviceAccessTokenInput) {
            elements.webserviceAccessTokenInput.setAttribute('type', !state.isTokenShown ? 'password' : 'text');
        }
        if (elements.hiddenTokenIconWrapper) {
            elements.hiddenTokenIconWrapper.classList.toggle('hidden', state.isTokenShown);
        }
        if (elements.seenTokenIconWrapper) {
            elements.seenTokenIconWrapper.classList.toggle('hidden', !state.isTokenShown);
        }
    }

    /**
     * Refreshes cached web services DOM elements after the fragment reloads.
     * Rebinds the token input, button, and visibility icon wrappers, then
     * applies the current token visibility state.
     *
     * @private
     */
    function setWebServicesElements() {
        elements.webServiceRotateTokenButton = document.getElementById("webservicerotatetokenbutton");
        elements.webserviceAccessTokenInput = document.getElementById("webserviceAccessTokenInput");
        elements.visibilityControlBtn = document.getElementById("visibilityControlBtn");
        elements.hiddenTokenIconWrapper = document.getElementById("hiddenTokenIconWrapper");
        elements.seenTokenIconWrapper = document.getElementById("seenTokenIconWrapper");
        setWebServicesTokenVisibility();
    }

    /**
     * Updates the live API switch UI and configuration visibility.
     * Manages live API functionality toggle, updates status indicators,
     * and controls the display of related configuration sections.
     *
     * @function updateLiveApiSwitch
     * @private
     */
    function updateLiveApiSwitch() {
        if (state.liveApiEnabled) {
            elements.liveApiSwitch.classList.add("active");
            Str.get_string('statusenabled', COMPONENT).then(function(str) {
                elements.liveApiStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            elements.liveApiConfig.style.display = "block";
        } else {
            elements.liveApiSwitch.classList.remove("active");
            Str.get_string('statusdisabled', COMPONENT).then(function(str) {
                elements.liveApiStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            elements.liveApiConfig.style.display = "none";
        }
        elements.form.elements.liveApiStatus.value = state.liveApiEnabled ? 1 : 0;
    }

    /**
     * Show the LTI removal confirmation modal.
     * Show the modal dialog by setting display style to flex.
     *
     * @function showLtiRemovalModal
     * @private
     */
    function showLtiRemovalModal() {
        if (!state.deleteconfigid) {
            throw new Error('delete config id is not defined');
        }
        elements.ltiRemovalModal.style.display = "flex";
    }

    /**
     * Closes the LTI removal confirmation modal.
     * Hides the modal dialog by setting display style to none.
     *
     * @function closeLtiRemovalModal
     * @private
     */
    function closeLtiRemovalModal() {
        delete state.deleteconfigid;
        elements.ltiRemovalModal.style.display = "none";
    }

    /**
     * Confirms LTI removal and updates the interface.
     * Disables LTI integration, updates the switch state,
     * and closes the confirmation modal.
     *
     * @function confirmLtiRemoval
     * @private
     */
    function confirmLtiRemoval() {
        if (!state.deleteconfigid) {
            throw new Error('delete config id is not defined');
        }
        Ajax.call([{
            methodname: 'local_learnwise_deletelti',
            args: {
                id: state.deleteconfigid,
            }
        }])[0].then(function(response) {
            if (response.success) {
                var removeElements = elements.ltiConfigTable.querySelectorAll(
                    '[data-rowindex="' + state.deleteconfigid + '"],#detail-' + state.deleteconfigid
                );
                removeElements.forEach(function(removeElement) {
                    removeElement.remove();
                });
                closeLtiRemovalModal();
                if (!document.querySelector('[data-rowindex]')) {
                    updateLtiConfigTable();
                }
            } else {
                throw new Error('config with id is not deleted');
            }
            return null;
        }).catch(Notification.exception);
    }

    /**
     * Register lti related operations
     *
     * @function registerLtiCrud
     * @private
     */
    function registerLtiCrud() {
        if (!state.ltiSetup) {
            return;
        }
        LtiConfiguration.registerModalForm('[data-action="addlti"],[data-action="updatelti"]', {
            title: Str.get_string('setuplti', COMPONENT),
        }, updateLtiConfigTable);
        document.addEventListener('click', function(e) {
            var deleteaction = e.target.closest('[data-action="deletelti"]');
            if (deleteaction) {
                e.preventDefault();
                state.deleteconfigid = deleteaction.dataset.id;
                showLtiRemovalModal();
            }
            var viewassistantbtn = e.target.closest('[data-action="viewlti"]');
            if (viewassistantbtn) {
                var table = viewassistantbtn.closest('[data-region="ltilist"]');
                if (table) {
                    var detailrow = table.querySelector('#detail-' + viewassistantbtn.dataset.index);
                    if (detailrow) {
                        if (detailrow.style.display === 'none') {
                            detailrow.style.display = '';
                        } else {
                            detailrow.style.display = 'none';
                        }
                    }
                }
            }
        });
    }

    /**
     * Update lti config html table.
     *
     * @function updateLtiConfigTable
     * @private
     * @param {Object} data Data used to update the LTI config table.
     */
    function updateLtiConfigTable(data) {
        var removeel = null;
        var append = false;
        var params = {
            action: 'refreshtable'
        };
        var replaceelement = elements.ltiConfigTable;
        if (data && data.id) {
            params.action = 'refreshtablerow';
            params.id = data.id;
            replaceelement = elements.ltiConfigTable.querySelector('[data-rowindex="' + params.id + '"]');
            removeel = elements.ltiConfigTable.querySelector('#detail-' + params.id);
            if (!replaceelement) {
                append = true;
                replaceelement = elements.ltiConfigTable;
            }
        }
        Fragment.loadFragment(COMPONENT, 'refresh_lticonfig', Config.contextid, params)
        .then(function(html, js) {
            var norecordsrow = elements.ltiConfigTable.querySelector('[data-region="norecords"]');
            if (norecordsrow) {
                norecordsrow.remove();
            }
            if (removeel) {
                removeel.remove();
            }
            if (append) {
                Templates.appendNodeContents(replaceelement, html, js);
            } else {
                Templates.replaceNode(replaceelement, html, js);
            }
            elements.ltiConfigTable = document.querySelector('[data-region="ltilist"]');
            return null;
        }).catch(Notification.exception);
    }

    /**
     * Show the WS Rotate confirmation modal.
     * Show the modal dialog by setting display style to flex.
     *
     * @function showWsRotateTokenModal
     * @private
     */
    function showWsRotateTokenModal() {
        elements.wsRotateTokenModal.style.display = "flex";
    }

    /**
     * Closes the WS Rotate confirmation modal.
     * Hides the modal dialog by setting display style to none.
     *
     * @function closeWsRotateTokenModal
     * @private
     */
    function closeWsRotateTokenModal() {
        elements.wsRotateTokenModal.style.display = "none";
    }

    /**
     * Confirms WS Rotate removal and updates the interface.
     *
     * @function confirmWsRotateToken
     * @private
     */
    function confirmWsRotateToken() {
        state.webServicesEnabled = 1;
        Fragment.loadFragment('local_learnwise', 'process_setup', Config.contextid, {
            formdata: 'enablewebservice=' + (state.webServicesEnabled ? 1 : 0) +
                '&rotatewebservicetoken=1'
        }).then(function(html, js) {
            return Templates.replaceNodeContents(elements.webServicesConfig, html, js);
        }).then(function() {
            closeWsRotateTokenModal();
            setWebServicesElements();
            return;
        })
        .catch(Notification.exception);

        elements.form.elements.webServicesStatus.value = state.webServicesEnabled ? 1 : 0;
    }

    /**
     * Public API for the setup module.
     * Provides initialization method to set up the entire interface.
     *
     * @namespace
     * @public
     */
    return {
        /**
         * Initializes the LearnWise setup interface.
         * Sets up event listeners, initializes state, and updates all UI components.
         *
         * @function init
         * @public
         * @param {boolean} showToast Whether to show toast notifications for user feedback.
         * @param {boolean} ltiSetup LTI setup configuration data.
         */
        init: function(showToast, ltiSetup) {
            state.showToast = showToast;
            state.ltiSetup = ltiSetup;
            initializeEventListeners();

            // Initial state updates
            updateFloatingButtonSwitch();
            updateWebServicesSwitch();
            updateLiveApiSwitch();
            updateAiAssessmentSwitch();
            registerLtiCrud();
        }
    };

});