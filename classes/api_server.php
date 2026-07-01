<?php
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

namespace local_learnwise;

use external_multiple_structure;
use external_value;
use invalid_parameter_exception;
use local_learnwise\local\OAuth2\Request;
use local_learnwise\external\assign\grade;
use local_learnwise\external\assign\submissions;
use local_learnwise\external\assignments;
use local_learnwise\external\baseapi;
use local_learnwise\external\books;
use local_learnwise\external\calenderdetails;
use local_learnwise\external\courses;
use local_learnwise\external\course_modules;
use local_learnwise\external\forum\discussions;
use local_learnwise\external\forums;
use local_learnwise\external\modules;
use local_learnwise\external\notifications;
use local_learnwise\external\plugininfo;
use local_learnwise\external\quiz\attempts;
use local_learnwise\external\quiz\reviewattempt;
use local_learnwise\external\quizzes;
use local_learnwise\external\scorms;
use local_learnwise\external\userdetails;
use local_learnwise\external\users;
use local_learnwise\external\ws_proxy;
use moodle_exception;
use webservice_base_server;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

/**
 * Class api_server
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_server extends webservice_base_server {
    /**
     * The LearnWise server instance.
     *
     * @var server
     */
    public $server;

    /**
     * The current request object.
     *
     * @var Request
     */
    public $request;

    /**
     * The HTTP response object.
     *
     * @var api_response
     */
    public $response;

    /**
     * The current URL path segments.
     *
     * @var array
     */
    public $urlparts;

    /**
     * Registered external callback mappings.
     *
     * @var array
     */
    public $externalcallbacks;

    /**
     * The response format name.
     *
     * @var string
     */
    public $responseformat = 'json';

    /**
     * The list of response formatter callbacks.
     *
     * @var array
     */
    public $responseformatters = [];

    /**
     * Constructor.
     *
     * @param int $authmethod The authentication method.
     */
    public function __construct($authmethod = WEBSERVICE_AUTHMETHOD_SESSION_TOKEN) {
        parent::__construct($authmethod);
        $this->wsname = 'learnwise';
        $this->server = server::get_instance();
        $this->request = Request::createFromGlobals();
        $this->response = util::make_response([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
        $this->urlparts = explode('/', trim((string) get_file_argument(), '/'));
        $this->externalcallbacks = baseapi::exernal_callbacks();
        $this->responseformatters[] = [$this, 'clean_returns'];
        $this->parameters = [];
        if ($responseformat = $this->request->headers('accept')) {
            $responseformat = strtolower(explode(',', $responseformat, 2)[0]);
            if (strpos($responseformat, 'application/') === 0) {
                $this->responseformat = substr($responseformat, strlen('application/'));
            }
        }
    }

    /**
     * Add a response formatter callback.
     *
     * @param callable $formattercallback The formatter callback.
     * @return void
     */
    public function add_responseformatter($formattercallback) {
        $this->responseformatters[] = $formattercallback;
    }

    /**
     * Get the current URL path parts.
     *
     * @return array
     */
    public function get_urlparts() {
        return $this->urlparts;
    }

    /**
     * Get the response object.
     *
     * @return api_response
     */
    public function get_response() {
        return $this->response;
    }

    /**
     * Merge additional parameters into the request parameters.
     *
     * @param array $parameters The parameters to add.
     * @return void
     */
    public function set_parameters($parameters) {
        $this->parameters = array_merge($this->parameters, $parameters);
    }

    /**
     * Set the current function name.
     *
     * @param string $functionname The function name.
     * @return void
     */
    public function set_functionname($functionname) {
        $this->functionname = $functionname;
    }

    #[\Override]
    public function authenticate_by_token($tokentype) {
        if ($tokentype == EXTERNAL_TOKEN_EMBEDDED) {
            if (!get_config(constants::COMPONENT, 'liveapi')) {
                throw new api_exception('apidisabled');
            }
            if (!$this->server->verifyResourceRequest($this->request, $this->response)) {
                $this->send_response();
                die;
            }

            // Authenticate user.
            $token = $this->server->getAccessTokenData($this->request);
            return get_complete_user_data('id', $token['user_id'], null, true);
        }
        return parent::authenticate_by_token($tokentype);
    }

    /**
     * Parse the incoming request URL and resolve the target function.
     *
     * @return void
     */
    protected function parse_request() {
        $nextroute = array_shift($this->urlparts);

        if ($nextroute === 'ws') {
            if (get_config(constants::COMPONENT, 'aiops')) {
                ws_proxy::dispatch($this);
                return;
            } else {
                throw new api_exception('aiopsdisabled');
            }
        }

        if ($nextroute === userdetails::$route) {
            baseapi::$my = true;
            if (!$this->urlparts) {
                $this->functionname = userdetails::function_name();
            } else {
                $nextroute = array_shift($this->urlparts);
            }
        } else if ($nextroute === users::$route) {
            $nextroute = array_shift($this->urlparts);
            if (is_numeric($nextroute)) {
                $this->parameters['userid'] = $nextroute;
                $this->functionname = users::function_name();
            }
        }
        if ($nextroute === notifications::$route) {
            $this->functionname = notifications::function_name();
        } else if ($nextroute === calenderdetails::$route) {
            $this->functionname = calenderdetails::function_name();
        } else if ($nextroute === courses::$route) {
            $nextroute = array_shift($this->urlparts);
            if (is_null($nextroute)) {
                $this->functionname = courses::function_name();
            } else if (is_numeric($nextroute)) {
                courses::set_id((int) $nextroute);
                $nextroute = array_shift($this->urlparts);
                if (is_null($nextroute)) {
                    $this->functionname = courses::function_name();
                } else {
                    $this->parameters['courseid'] = courses::get_id();
                    if ($nextroute === assignments::$route) {
                        $nextroute = array_shift($this->urlparts);
                        if (is_null($nextroute)) {
                            $this->functionname = assignments::function_name();
                        } else if (is_numeric($nextroute)) {
                            assignments::set_id((int) $nextroute);
                            $this->parameters['assignmentid'] = assignments::get_id();
                            $nextroute = array_shift($this->urlparts);
                            if (is_null($nextroute)) {
                                $this->functionname = assignments::function_name();
                            } else if ($nextroute == submissions::$route) {
                                $nextroute = array_shift($this->urlparts);
                                if (is_null($nextroute)) {
                                    $this->functionname = submissions::function_name();
                                } else if (is_numeric($nextroute)) {
                                    submissions::set_id((int) $nextroute);
                                    $nextroute = array_shift($this->urlparts);
                                    if (is_null($nextroute)) {
                                        $this->functionname = submissions::function_name();
                                    } else if ($nextroute == grade::$route) {
                                        $this->functionname = grade::function_name();
                                        $this->parameters += $this->request->request;
                                    }
                                }
                            }
                        }
                    } else if ($nextroute === course_modules::$route) {
                        course_modules::$withcompletion = !empty(baseapi::$my);
                        $nextroute = array_shift($this->urlparts);
                        if (is_null($nextroute)) {
                            $this->functionname = course_modules::function_name();
                        } else if (is_numeric($nextroute)) {
                            course_modules::set_id((int) $nextroute);
                            $this->functionname = course_modules::function_name();
                        }
                    } else if ($nextroute === calenderdetails::$route) {
                        $this->functionname = calenderdetails::function_name();
                    } else if ($nextroute === forums::$route) {
                        $nextroute = array_shift($this->urlparts);
                        if (is_null($nextroute)) {
                            $this->functionname = forums::function_name();
                        } else if (is_numeric($nextroute)) {
                            forums::set_id((int) $nextroute);
                            $this->parameters['forumid'] = forums::get_id();
                            $nextroute = array_shift($this->urlparts);
                            if (is_null($nextroute)) {
                                $this->functionname = forums::function_name();
                            } else if ($nextroute == discussions::$route) {
                                $nextroute = array_shift($this->urlparts);
                                if (is_null($nextroute)) {
                                    $this->functionname = discussions::function_name();
                                } else if (is_numeric($nextroute)) {
                                    discussions::set_id((int) $nextroute);
                                    $this->functionname = discussions::function_name();
                                }
                            }
                        }
                    } else if ($nextroute === scorms::$route) {
                        $nextroute = array_shift($this->urlparts);
                        if (is_null($nextroute)) {
                            $this->functionname = scorms::function_name();
                        } else if (is_numeric($nextroute)) {
                            scorms::set_id((int) $nextroute);
                            $nextroute = array_shift($this->urlparts);
                            if (is_null($nextroute)) {
                                $this->functionname = scorms::function_name();
                            }
                        }
                    } else if ($nextroute === books::$route) {
                        $nextroute = array_shift($this->urlparts);
                        if (is_null($nextroute)) {
                            $this->functionname = books::function_name();
                        } else if (is_numeric($nextroute)) {
                            books::set_id((int) $nextroute);
                            $nextroute = array_shift($this->urlparts);
                            if (is_null($nextroute)) {
                                $this->functionname = books::function_name();
                            }
                        }
                    } else if ($nextroute === quizzes::$route) {
                        $nextroute = array_shift($this->urlparts);
                        if (is_null($nextroute)) {
                            $this->functionname = quizzes::function_name();
                        } else if (is_numeric($nextroute)) {
                            quizzes::set_id((int) $nextroute);
                            $this->parameters['quizid'] = quizzes::get_id();
                            $nextroute = array_shift($this->urlparts);
                            if (is_null($nextroute)) {
                                $this->functionname = quizzes::function_name();
                            } else if ($nextroute == attempts::$route) {
                                $nextroute = array_shift($this->urlparts);
                                if (is_null($nextroute)) {
                                    $this->functionname = attempts::function_name();
                                } else if (is_numeric($nextroute)) {
                                    attempts::set_id((int) $nextroute);
                                    $this->parameters['attemptid'] = attempts::get_id();
                                    $nextroute = array_shift($this->urlparts);
                                    if (is_null($nextroute)) {
                                        $this->functionname = attempts::function_name();
                                    } else if ($nextroute == reviewattempt::$route) {
                                        $this->functionname = reviewattempt::function_name();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } else if ($nextroute === modules::$route) {
            modules::$withcompletion = !empty(baseapi::$my);
            $nextroute = array_shift($this->urlparts);
            if (is_numeric($nextroute)) {
                modules::set_id((int) $nextroute);
                $this->functionname = modules::function_name();
            }
        } else if ($nextroute === plugininfo::$route) {
            $this->functionname = plugininfo::function_name();
        }
    }

    /**
     * Load information for the resolved external function.
     *
     * @return void
     */
    protected function load_function_info() {
        if (empty($this->functionname)) {
            throw new invalid_parameter_exception('Missing function name');
        }

        if (isset($this->externalcallbacks[$this->functionname])) {
            $this->function = baseapi::external_function_info((object) $this->externalcallbacks[$this->functionname]);
            $this->parameters = baseapi::clean_returnvalue(
                $this->function->parameters_desc,
                $this->parameters
            );
            return;
        }

        // Function must exist.
        $function = baseapi::external_function_info($this->functionname);

        // We have all we need now.
        $this->function = $function;
    }

    /**
     * Send the processed response to the client.
     *
     * @return void
     */
    protected function send_response() {
        if (
            $this->returns === []
            && $this->function->returns_desc instanceof external_multiple_structure
            && $this->response instanceof api_response
        ) {
            $this->response->set_empty_array_response();
        }

        $returns = isset($this->returns) ? $this->returns : [];

        if (!isset($this->function->returns_desc)) {
            $this->response->setStatusCode(204);
            $this->response->send($this->responseformat);
            return;
        }

        foreach ($this->responseformatters as $callback) {
            $returns = $callback($returns);
        }

        if ($this->function->returns_desc instanceof external_value) {
            $returns = [
                'structuredresponse' => $returns,
            ];
        }

        $this->response->setParameters($returns);
        $this->response->send($this->responseformat);
    }

    /**
     * Clean and normalize the response values.
     *
     * @param array $returns The raw return values.
     * @return array
     */
    protected function clean_returns($returns) {
        return baseapi::clean_returnvalue(
            $this->function->returns_desc,
            $returns
        );
    }

    /**
     * Send an error response.
     *
     * @param \Exception|null $ex The exception that caused the error.
     * @return void
     */
    protected function send_error($ex = null) {
        if ($ex instanceof api_exception) {
            $this->response->setError(404, $ex->getMessage());
        } else {
            $errorcode = $ex instanceof moodle_exception ? $ex->errorcode : null;
            $this->response->setError(404, get_string('error', constants::COMPONENT), $errorcode);
        }
        $this->response->send($this->responseformat);
    }

    /**
     * Minimal authentication used for file serving.
     */
    public function authenticate() {
        /* @phpstan-ignore argument.type */
        set_exception_handler([$this, 'exception_handler']);
        $this->authenticate_user();
    }
}
