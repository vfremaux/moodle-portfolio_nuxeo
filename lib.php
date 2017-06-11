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

/**
 * Created on 26 mai 2014 To change the template for this generated file go to Window - Preferences - PHPeclipse - PHP - Code Templates
 *
 * @package    portfolio_nuxeo
 * @category   portfolio
 * @copyright  Académie de Rennes (http://www.toutatice.fr)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/portfolio/nuxeo/locallib.php');

class portfolio_plugin_nuxeo extends portfolio_plugin_push_base {

    private $nuxeo = null;

    private $urlnuxeo;

    private $url_base;

    private $secretkey;

    private $user_name;

    public static function get_name() {
        return get_string('pluginname', 'portfolio_nuxeo');
    }

    public function prepare_package() {
        return true;
    }

    public function send_package() {
        global $_SERVER;

        if (! $this->nuxeo->issessionvalide()) {
            throw new portfolio_plugin_exception('noauthtoken', 'portfolio_nuxeo');
        }

        $exportpath = $this->nuxeo->get_export_path($this->get_config('export_path'));

        // If we need to create the folder, do it now.

        if ($newfolder = $this->get_export_config('newfolder')) {
            $created = $this->nuxeo->createForlder($exportpath, $newfolder);
            if (!$created) {
                throw new portfolio_plugin_exception('foldercreatefailed', 'portfolio_nuxeo');
            }
            $this->set_export_config(array(
                'folder' => $created
            ));
        } else if (!$this->get_export_config('folder')) {
            $this->set_export_config(array(
                'folder' => $exportpath
            ));
        }

        $file = $this->get('exporter')->zip_tempfiles();

        // Export info.
        $format = 'format-export='.$this->resolve_format()."\n";
        $format .= 'nuxeo-server='.$this->url_base."\n";

        if (!$this->nuxeo->send_file($file, $this->get_export_config('folder'), $format)) {
            throw new portfolio_plugin_exception('sendfailed', 'portfolio_nuxeo', $file->get_filename());
        }
    }

    public function expected_time($callertime) {
        /*
         * We're forcing this to be run 'interactively' because the plugin
         * does not support running in cron.
         */
        return PORTFOLIO_TIME_LOW; // PORTFOLIO_TIME_MODERATE or PORTFOLIO_TIME_HIGH.
    }

    public function get_interactive_continue_url() {
        $continue_url = $this->url_base;
        if ($continue_url[strlen($continue_url) - 1] != '/') {
            $continue_url .= '/';
        }
        $continue_url .= 'nxpath/default'.$this->get_export_config('folder').'@view_documents';
        return $continue_url;
    }

    public static function has_admin_config() {
        return true;
    }

    /**
     * globals var name
     */
    public static function get_allowed_config() {
        return array (
            'url_nuxeo',
            'export_path'
        );
    }

    public static function admin_config_form(&$mform) {
        $strrequired = get_string('required');

        $mform->addElement('text', 'url_nuxeo', get_string('url_nuxeo', 'portfolio_nuxeo'));
        $mform->setType('url_nuxeo', PARAM_RAW_TRIMMED);
        $mform->addElement('text', 'export_path', get_string('export_path', 'portfolio_nuxeo'));
        $mform->setType('export_path', PARAM_RAW_TRIMMED);
        $mform->setDefault('export_path', 'moodle');

        $mform->addRule('url_nuxeo', $strrequired, 'required', null, 'client');
        $mform->addRule('export_path', $strrequired, 'required', null, 'client');
    }

    public function steal_control($stage) {
        global $CFG;

        if ($stage != PORTFOLIO_STAGE_CONFIG) {
            return false;
        }

        if ($this->nuxeo == null) {
            $this->initialize ();
        }

        if ($stage == PORTFOLIO_STAGE_FINISHED) {
            global $CFG;

            return $CFG->wwwroot.'/portfolio/download/file.php?id='.$this->get('exporter')->get('id');
        }
    }

    public function initialize() {
        global $USER, $CFG;

        $this->url_base = $this->get_config('url_nuxeo');
        $this->urlnuxeo = nuxeohelp::construct_nuxeo_url($this->url_base);
        $this->secretkey = $CFG->nuxeokey;
        $this->user_name = $USER->username;
        $this->nuxeo = new nuxeohelp($this->urlnuxeo, $this->user_name, $this->secretkey);
    }

    public function instance_sanity_check() {
        global $CFG;

        $url = $this->get_config('url_nuxeo');
        $secret = $CFG->nuxeokey;

        /*
         * If there is no oauth config (e.g. plugins upgraded from < 2.3 then
         * there will be no config and this plugin should be disabled.
         */
        if (empty($url) or empty($secret)) {
            return 'nooauthcredentials';
        }

        return 0;
    }

    public function has_export_config() {
        return true;
    }

    public function get_allowed_export_config() {
        return array (
            'folder',
            'newfolder'
        );
    }

    public function export_config_form(&$mform) {
        $root_path = $this->get_config('export_path');
        $folders = $this->nuxeo->get_folder_list($this->nuxeo->get_export_path($root_path));
        $mform->addElement('text', 'plugin_newfolder', get_string('newfolder', 'portfolio_nuxeo'));
        $mform->setType('plugin_newfolder', PARAM_RAW);

        $folders[0] = $root_path.'/';
        asort($folders);
        $mform->addElement('select', 'plugin_folder', get_string('existingfolder', 'portfolio_nuxeo'), $folders);
    }

    public function get_export_summary() {
        $root_path = $this->get_config('export_path');
        if ($newfolder = $this->get_export_config('newfolder')) {
            $foldername = $root_path."/".$newfolder.' ('.get_string('tobecreated', 'portfolio_nuxeo').')';
        } else if ($this->get_export_config('folder')) {
            $allfolders = $this->nuxeo->get_folder_list($this->nuxeo->get_export_path($root_path));
            $foldername = $allfolders[$this->get_export_config('folder')];
        } else {
            $foldername = $this->get_config('export_path').'/';
        }

        return array (
            get_string('infoexporttitle', 'portfolio_nuxeo') => get_string('infoexport', 'portfolio_nuxeo'),
            get_string('targetfolder', 'portfolio_nuxeo') => ($foldername)
        );
    }

    /**
     * internal helper function, that converts between the format constant,
     * which might be too specific (eg 'image') and the class in our *supported* list
     * which might be higher up the format hierarchy tree (eg 'file')
     */
    private function resolve_format() {
        global $CFG;

        $thisformat = $this->get_export_config('format');
        return $thisformat;
    }
}

