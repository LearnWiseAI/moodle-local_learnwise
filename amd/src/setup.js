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
    [M.cfg.wwwroot + '/local/learnwise/vendorjs/zenorocha/clipboard.min.js',
        'core/str', 'core/notification', 'core/fragment', 'core/config', 'core/templates'],
    function(ClipboardJS, Str, Notification, Fragment, Config, Templates) {

    /**
     * Application state object containing all configuration settings
     * @type {Object}
     * @property {string} environment - Current environment (sandbox/production)
     * @property {string} floatingButtonAssistantId - Assistant ID for floating button
     * @property {boolean} showFloatingButton - Whether floating button is enabled
     * @property {string} ltiAssistantId - Assistant ID for LTI integration
     * @property {boolean} ltiEnabled - Whether LTI integration is enabled
     * @property {boolean} webServicesEnabled - Whether web services are enabled
     * @property {boolean} liveApiEnabled - Whether live API is enabled
     * @property {boolean} showToast - Whether to show toast notifications
     */
    var state = {
        environment: "sandbox",
        floatingButtonAssistantId: "",
        showFloatingButton: false,
        ltiAssistantId: "",
        ltiEnabled: false,
        webServicesEnabled: false,
        liveApiEnabled: false,
        aiAssessmentEnabled: false,
        courseIds: "",
    };

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

        ltiAssistantId: document.getElementById("ltiAssistantId"),
        ltiSwitch: document.getElementById("ltiSwitch"),
        ltiStatus: document.getElementById("ltiStatus"),
        ltiSwitchWrapper: document.getElementById("ltiSwitchWrapper"),
        ltiTooltip: document.getElementById("ltiTooltip"),
        ltiConfig: document.getElementById("ltiConfig"),

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
        aiAssessmentStatus: document.getElementById("aiAssessmentStatus"),
        courseIds: document.getElementById("courseIds"),
    };

    // Initialize state from form values
    if (elements.floatingButtonAssistantId) {
        state.floatingButtonAssistantId = elements.floatingButtonAssistantId.value;
    }

    if (elements.ltiAssistantId) {
        state.ltiAssistantId = elements.ltiAssistantId.value;
    }

    if (elements.floatingButtonStatus) {
        state.showFloatingButton = parseInt(elements.form.elements.floatingButtonStatus.value) === 1;
    }

    if (elements.ltiStatus) {
        state.ltiEnabled = parseInt(elements.form.elements.ltiStatus.value) === 1;
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

        // LTI assistant ID input
        if (elements.ltiAssistantId) {
            elements.ltiAssistantId.addEventListener("input", function(e) {
                state.ltiAssistantId = e.target.value;
                updateLtiSwitch();
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
                closeLtiModal();
            }
            if (e.target === elements.confirmLtiRemoval) {
                confirmLtiRemoval();
            }
            if (e.target === elements.ltiRemovalModal) {
                closeLtiModal();
            }
        });

        // Environment radio buttons
        var environmentRadios = document.querySelectorAll('input[name="environment"]');
        environmentRadios.forEach(function(radio) {
            if (radio.checked) {
                state.environment = radio.value;
            }
            radio.addEventListener("change", function(e) {
                if (e.target.checked) {
                    state.environment = e.target.value;
                    updateLtiConfig();
                }
            });
        });

        // LTI switch
        if (elements.ltiSwitch) {
            elements.ltiSwitch.addEventListener("click", function() {
                if (canEnableLti()) {
                    if (state.ltiEnabled) {
                        // Show confirmation modal
                        elements.ltiRemovalModal.style.display = "flex";
                    } else {
                        state.ltiEnabled = true;
                        updateLtiSwitch();
                    }
                }
            });
        }

        // Initialize clipboard functionality
        var clipboardInstance = new ClipboardJS('.copy-btn');
        clipboardInstance.on('success', function(e) {
            e.clearSelection();
            Str.get_string('copied', 'local_learnwise')
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
     * Checks if LTI integration can be enabled based on current state.
     * Requires a valid LTI assistant ID to be present.
     *
     * @function canEnableLti
     * @private
     * @returns {boolean} True if LTI can be enabled, false otherwise
     */
    function canEnableLti() {
        return state.ltiAssistantId.trim().length > 0;
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
                Str.get_string('statusenabled', 'local_learnwise').then(function(str) {
                    elements.floatingButtonStatus.textContent = str;
                    return null;
                }).fail(Notification.exception);
            } else {
                elements.floatingButtonSwitch.classList.remove("active");
                Str.get_string('statusdisabled', 'local_learnwise').then(function(str) {
                    elements.floatingButtonStatus.textContent = str;
                    return null;
                }).fail(Notification.exception);
            }
        } else {
            elements.floatingButtonSwitch.classList.remove("active");
            elements.floatingButtonSwitch.classList.add("disabled");
            elements.floatingButtonSwitchWrapper.style.cursor = "not-allowed";
            Str.get_string('statusdisabled', 'local_learnwise').then(function(str) {
                elements.floatingButtonStatus.textContent = str;
                return null;
            }).fail(Notification.exception);
            state.showFloatingButton = false;
        }
        elements.form.elements.floatingButtonStatus.value = state.showFloatingButton ? 1 : 0;
    }

    /**
     * Updates the LTI configuration section by loading dynamic content.
     * Fetches and renders LTI configuration HTML based on current state
     * including environment and assistant ID settings.
     *
     * @function updateLtiConfig
     * @private
     */
    function updateLtiConfig() {
        if (!elements.ltiConfig || !canEnableLti() || !state.ltiEnabled) {
            return;
        }
        Fragment.loadFragment('local_learnwise', 'process_setup', Config.contextid, {
                formdata: 'enablelti=' + (state.ltiEnabled ? 1 : 0) +
            '&assistantid=' + encodeURIComponent(elements.ltiAssistantId.value) +
            '&environment=' + encodeURIComponent(state.environment)
        }).then(function(html, js) {
            Templates.replaceNodeContents(elements.ltiConfig, html, js);
            return null;
        }).catch(Notification.exception);
    }

    /**
     * Updates the LTI switch UI and configuration visibility.
     * Manages the LTI integration toggle state, updates visual indicators,
     * and shows/hides the LTI configuration section accordingly.
     *
     * @function updateLtiSwitch
     * @private
     */
    function updateLtiSwitch() {
        if (!elements.ltiAssistantId) {
            return;
        }
        var canEnable = canEnableLti();

        if (canEnable) {
            elements.ltiSwitch.classList.remove("disabled");
            elements.ltiSwitchWrapper.style.cursor = "pointer";
            elements.ltiTooltip.style.display = "none";

            if (state.ltiEnabled) {
                elements.ltiSwitch.classList.add("active");
                Str.get_string('statusenabled', 'local_learnwise').then(function(str) {
                    elements.ltiStatus.textContent = str;
                    return null;
                }).catch(Notification.exception);
                elements.ltiConfig.style.display = "block";
            } else {
                elements.ltiSwitch.classList.remove("active");
                Str.get_string('statusdisabled', 'local_learnwise').then(function(str) {
                    elements.ltiStatus.textContent = str;
                    return null;
                }).catch(Notification.exception);
                elements.ltiConfig.style.display = "none";
            }
            updateLtiConfig();
        } else {
            elements.ltiSwitch.classList.remove("active");
            elements.ltiSwitch.classList.add("disabled");
            elements.ltiSwitchWrapper.style.cursor = "not-allowed";
            Str.get_string('statusdisabled', 'local_learnwise').then(function(str) {
                elements.ltiStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            elements.ltiConfig.style.display = "none";
            state.ltiEnabled = false;
        }
        elements.form.elements.ltiStatus.value = state.ltiEnabled ? 1 : 0;
    }

    /**
     * Updates the ai assessment switch UI and configuration.
     * Toggles ai assessment functionality, updates status display
     *
     * @function updateWebServicesSwitch
     * @private
     */
    function updateAiAssessmentSwitch() {
        if (state.aiAssessmentEnabled) {
            elements.aiAssessmentSwitch.classList.add("active");
            Str.get_string('statusenabled', 'local_learnwise').then(function(str) {
                elements.aiAssessmentStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
        } else {
            elements.aiAssessmentSwitch.classList.remove("active");
            Str.get_string('statusdisabled', 'local_learnwise').then(function(str) {
                elements.aiAssessmentStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
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
            Str.get_string('statusenabled', 'local_learnwise').then(function(str) {
                elements.webServicesStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            elements.webServicesConfig.style.display = "block";
        } else {
            elements.webServicesSwitch.classList.remove("active");
            Str.get_string('statusdisabled', 'local_learnwise').then(function(str) {
                elements.webServicesStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            elements.webServicesConfig.style.display = "none";
        }
        Fragment.loadFragment('local_learnwise', 'process_setup', Config.contextid, {
            formdata: 'enablewebservice=' + (state.webServicesEnabled ? 1 : 0)
        }).then(function(html, js) {
            Templates.replaceNodeContents(elements.webServicesConfig, html, js);
            return null;
        }).catch(Notification.exception);

        elements.form.elements.webServicesStatus.value = state.webServicesEnabled ? 1 : 0;
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
            Str.get_string('statusenabled', 'local_learnwise').then(function(str) {
                elements.liveApiStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            elements.liveApiConfig.style.display = "block";
        } else {
            elements.liveApiSwitch.classList.remove("active");
            Str.get_string('statusdisabled', 'local_learnwise').then(function(str) {
                elements.liveApiStatus.textContent = str;
                return null;
            }).catch(Notification.exception);
            elements.liveApiConfig.style.display = "none";
        }
        elements.form.elements.liveApiStatus.value = state.liveApiEnabled ? 1 : 0;
    }

    /**
     * Closes the LTI removal confirmation modal.
     * Hides the modal dialog by setting display style to none.
     *
     * @function closeLtiModal
     * @private
     */
    function closeLtiModal() {
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
        state.ltiEnabled = false;
        updateLtiSwitch();
        closeLtiModal();
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
         * @param {boolean} showToast - Whether to show toast notifications for user feedback
         * @public
         */
        init: function(showToast) {
            state.showToast = showToast;
            initializeEventListeners();

            // Initial state updates
            updateFloatingButtonSwitch();
            updateLtiSwitch();
            updateWebServicesSwitch();
            updateLiveApiSwitch();
            updateAiAssessmentSwitch();
        }
    };

});