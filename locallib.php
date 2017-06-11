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

require_once ($CFG->dirroot.'/local/nuxeolibs/NuxeoAutomationClient/NuxeoAutomationAPI.php');

class nuxeohelp {

    public $sessionSSO;

    // private static QUERY = 'query' ;
    // private static GETBLOC = 'getbloc' ;

    function __construct($url, $user_name, $secret_key) {
        $interceptorSSO = new PortalSSORequestInterceptor($user_name, $secret_key);

        $client = new NuxeoPhpAutomationClient($url, $interceptorSSO);
        $this->sessionSSO = $client->getSession();
    }

    public function getsession() {
        $this->sessionSSO;
    }

    public function getDocument($path) {
        $sql = "
            SELECT
                *
            FROM
                Folder
            WHERE
                ecm:path = '".$path."' AND
                ecm:currentLifeCycleState != 'deleted' AND
                ecm:isCheckedInVersion = 0 AND
                ecm:isProxy = 0
        ";

        $req = $this->sessionSSO->newRequest("Document.Query")->set('params', 'query', $sql)->setSchema('*');
        $answer = nuxeohelp::send($req);

        if (!empty($answer->error)) {
            return null;
        }

        $documentsArray = $answer->content;
        $doc = current($documentsArray);

        return $doc;
    }

    /**
     * check if the current session is valide
     */
    public function issessionvalide() {
        $answer = $this->sessionSSO->isValide();
        return $answer;
    }

    public function send_file($file, $path, $format) {
        if (!$this->issessionvalide()) {
            return false;
        }

        // Prepare le fichier tmp.
        // We can't get a filepointer, so have to copy the file..
        $tmproot = make_temp_directory('nuxeouploads');
        $tmpfilepath = $tmproot.'/'.$file->get_filename();
        $file->copy_content_to($tmpfilepath);

        $this->add_moodle_signature_to($tmpfilepath, $format);
        // We upload the file.
        $blob = $tmpfilepath;
        $blobtype = 'application/zip';
        $req = $this->sessionSSO->newRequest("FileManager.Import")->set('context', 'currentDocument', $path)->loadBlob($blob, $blobtype);
        $answer = nuxeohelp::send($req, 'Import');

        if (!empty($answer->error)) {
            return false;
        }

        if ($answer->content == null) {
            unlink ( $tmpfilepath );
        }
        return true;
    }

    public static function construct_nuxeo_url($url_base) {
        $url = $url_base;

        if ($url_base[strlen($url_base) - 1] != '/') {
            $url .= '/';
        }
        $url .= 'site/automation';

        return $url;
    }

    public static function send($requeste, $typerequest = 'query') {

        $result = new stdClass();

        try {
            $answer = $requeste->sendRequest();
            if ($typerequest == 'query') {
                $result->content = $answer->getDocumentList();
            } else {
                $result->content = $answer;
            }
        } catch (Exception $ex) {
            $result->error = $ex->getMessage();
        }

        return $result;
    }

    public function createForlder($path, $foldername) {

        $properties = 'dc:title='.$foldername;
        $req = $this->sessionSSO->newRequest("Document.Create")->set('input', 'doc:'.$path)->set('params', 'type', 'Folder')->set('params', 'name', $foldername)->set('params', 'properties', $properties);

        $answer = nuxeohelp::send($req, 'Create');

        if (!empty($answer->error)) {
            return false;
        }

        return $answer->content->getDocument(0)->getPath();
    }

    public function get_export_path($path = 'moodle') {

        // Get userworkspace.
        try {
            $sql = "
                SELECT
                    *
                FROM
                    Workspace
                WHERE
                    ecm:currentLifeCycleState != 'deleted'
            ";
            $req = $this->sessionSSO->newRequest('UserWorkspace.Get')->set('params', 'query', $sql);
            $answer = nuxeohelp::send($req, 'query');

            $workspace_path = current($answer->content)->getPath();
            $workspace_id = current($answer->content)->getUid();

            // Getting export_path.
            $sql = "
                SELECT
                    *
                FROM
                    Folder
                WHERE
                    ecm:parentId = '" . $workspace_id . "' AND
                    dc:title = '" . $path . "' AND
                    ecm:currentLifeCycleState != 'deleted' AND
                    ecm:isCheckedInVersion = 0 AND
                    ecm:isProxy = 0
            ";

            $req = $this->sessionSSO->newRequest('Document.Query')->set('params', 'query', $sql)->setSchema('*');
            $answer = nuxeohelp::send($req);

            $documentsArray = $answer->content;

            if (!$documentsArray) {
                // Folder not exist yet.
                $path = $this->createForlder($workspace_path, $path);
                return $path;
            }

            return current($documentsArray)->getPath();
        } catch (Exception $e) {
            return null;
        }
    }

    public function add_moodle_signature_to($file, $content) {

        // Prepare the zip file, add a signature.
        $zip = new ZipArchive();

        if ($zip->open($file) === true) {
            $zip->addFromString('.moodle_export', $content);
            $zip->close();
        }

        return $zip;
    }

    /**
     * Get the folder list.
     *
     * This is limited to the folders in the root folder.
     *
     * @return array of folders.
     */
    public function get_folder_list($root_path) {

        $folders = array();
        $root = $this->getDocument($root_path);
        $root_id = $root->getUid();
        $root_title = $root->getTitle();

        $sql = "
            SELECT
                *
            FROM
                Folder
            WHERE
                ecm:parentId = '" . $root_id . "' AND
                ecm:primaryType = 'Folder' AND
                ecm:currentLifeCycleState != 'deleted' AND
                ecm:isCheckedInVersion = 0 AND
                ecm:isProxy = 0
        ";

        $req = $this->sessionSSO->newRequest('Document.Query')->set('params', 'query', $sql)->setSchema('*');
        $answer = nuxeohelp::send($req);

        $result = $answer->content;
        if (!is_array($result)) {
            return $folders;
        }

        foreach ($result as $item) {
            $folders[$item->getPath()] = $root_title.'/'.$item->getTitle().'/';
        }

        return $folders;
    }
}