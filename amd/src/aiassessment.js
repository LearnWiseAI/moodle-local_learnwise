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
 * LearnWise assessment module
 *
 * @module     local_learnwise/aiassessment
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification'], function($, Ajax, Notification) {
    return {
        setup: false,
        init: function() {
            var self = this;
            var $document = $(document);
            $document.on('finish-loading-user', function() {
                var $userInfo = $document.find('[data-region="user-info"]');
                var instanceId = $userInfo.attr('data-assignmentid');
                var userid = $userInfo.attr('data-userid');
                if (!userid) {
                    var gradenavpanel = $userInfo.closest('[data-region="grading-navigation-panel"]');
                    userid = gradenavpanel.data('first-userid');
                }
                if (self.setup) {
                    self.redirectUserGrading(userid);
                    return;
                }
                Ajax.call([{
                    methodname: 'local_learnwise_assign_get_submission',
                    args: {
                        assignid: instanceId,
                        userid: userid
                    }
                }])[0].then(function(response) {
                    self.setup = true;
                    document.dispatchEvent(new CustomEvent('initLearnWiseAssessment', {
                        detail: response,
                    }));
                    return null;
                }).catch(Notification.exception);
            }).on('user-changed', function(e, userid) {
                if (self.setup) {
                    e.stopPropagation();
                    self.redirectUserGrading(userid);
                }
            });
        },
        redirectUserGrading: function(userid) {
            var url = new URL(location.href);
            url.searchParams.delete('userid');
            url.searchParams.append('userid', userid);
            location.href = url.toString();
        }
    };
});