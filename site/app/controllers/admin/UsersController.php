<?php

namespace app\controllers\admin;

use app\authentication\DatabaseAuthentication;
use app\controllers\AbstractController;
use app\libraries\Core;
use app\libraries\Output;
use app\libraries\Utils;
use app\libraries\FileUtils;
use app\models\User;

class UsersController extends AbstractController {
    public function run() {
        switch ($_REQUEST['action']) {
            case 'get_user_details':
                $this->ajaxGetUserDetails();
                break;
            case 'update_student':
                $this->updateUser('students');
                break;
            case 'update_grader':
                $this->updateUser('graders');
                break;
            case 'graders':
                $this->core->getOutput()->addBreadcrumb('Manage Graders');
                $this->listGraders();
                break;
            case 'rotating_sections':
                $this->core->getOutput()->addBreadcrumb('Manage Sections');
                $this->rotatingSectionsForm();
                break;
            case 'update_registration_sections':
                $this->updateRegistrationSections();
                break;
            case 'update_rotating_sections':
                $this->updateRotatingSections();
                break;
            case 'upload_grader_list':
                $this->uploadUserList("graderlist");
                break;
            case 'upload_class_list':
                $this->uploadUserList("classlist");
                break;
            case 'students':
            default:
                $this->core->getOutput()->addBreadcrumb('Manage Students');
                $this->listStudents();
                break;
        }
    }

    public function listStudents() {
        $students = $this->core->getQueries()->getAllUsers();
        $reg_sections = $this->core->getQueries()->getRegistrationSections();
        $rot_sections = $this->core->getQueries()->getRotatingSections();
        $use_database = $this->core->getAuthentication() instanceof DatabaseAuthentication;

        $this->core->getOutput()->renderOutput(array('admin', 'Users'), 'listStudents', $students, $reg_sections, $rot_sections, $use_database);
        $this->renderDownloadForm('user', $use_database);
    }

    public function listGraders() {
        $graders = $this->core->getQueries()->getAllGraders();
        $reg_sections = $this->core->getQueries()->getRegistrationSections();
        $rot_sections = $this->core->getQueries()->getRotatingSections();
        $use_database = $this->core->getAuthentication() instanceof DatabaseAuthentication;

        $this->core->getOutput()->renderOutput(array('admin', 'Users'), 'listGraders', $graders, $reg_sections, $rot_sections, $use_database);
        $this->renderDownloadForm('grader', $use_database);
    }

    private function renderDownloadForm($code, $use_database) {
        $students = $this->core->getQueries()->getAllUsers();
        $graders = $this->core->getQueries()->getAllGraders();
        $reg_sections = $this->core->getQueries()->getRegistrationSections();
        $this->core->getOutput()->renderOutput(array('admin', 'Users'), 'downloadForm', $code, $students, $graders, $reg_sections, $use_database);
    }

    public function ajaxGetUserDetails() {
        $user_id = $_REQUEST['user_id'];
        $user = $this->core->getQueries()->getUserById($user_id);
        $this->core->getOutput()->renderJson(array(
            'user_id' => $user->getId(),
            'user_firstname' => $user->getLegalFirstName(),
            'user_lastname' => $user->getLegalLastName(),
            'user_preferred_firstname' => $user->getPreferredFirstName(),
            'user_preferred_lastname' => $user->getPreferredLastName(),
            'user_email' => $user->getEmail(),
            'user_group' => $user->getGroup(),
            'registration_section' => $user->getRegistrationSection(),
            'rotating_section' => $user->getRotatingSection(),
            'user_updated' => $user->isUserUpdated(),
            'instructor_updated' => $user->isInstructorUpdated(),
            'manual_registration' => $user->isManualRegistration(),
            'grading_registration_sections' => $user->getGradingRegistrationSections()
        ));
    }

    public function updateUser($action='students') {
        $return_url = $this->core->buildUrl(array('component' => 'admin', 'page' => 'users',
            'action' => $action), 'user-'.$_POST['user_id']);
        if (!$this->core->checkCsrfToken($_POST['csrf_token'])) {
            $this->core->addErrorMessage("Invalid CSRF token.");
            $this->core->redirect($return_url);
        }
        $use_database = $this->core->getAuthentication() instanceof DatabaseAuthentication;
        $_POST['user_id'] = trim($_POST['user_id']);

        if (empty($_POST['user_id'])) {
            $this->core->addErrorMessage("User ID cannot be empty");
        }

        $user = $this->core->getQueries()->getUserById($_POST['user_id']);

        $error_message = "";
        //Username must contain only lowercase alpha, numbers, underscores, hyphens
        $error_message .= User::validateUserData('user_id', trim($_POST['user_id'])) ? "" : "Error in username: \"".strip_tags($_POST['user_id'])."\"<br>";
        //First and Last name must be alpha characters, white-space, or certain punctuation.
        $error_message .= User::validateUserData('user_legal_firstname', trim($_POST['user_firstname'])) ? "" : "Error in first name: \"".strip_tags($_POST['user_firstname'])."\"<br>";
        $error_message .= User::validateUserData('user_legal_lastname', trim($_POST['user_lastname'])) ? "" : "Error in last name: \"".strip_tags($_POST['user_lastname'])."\"<br>";
        //Check email address for appropriate format. e.g. "user@university.edu", "user@cs.university.edu", etc.
        $error_message .= User::validateUserData('user_email', trim($_POST['user_email'])) ? "" : "Error in email: \"".strip_tags($_POST['user_email'])."\"<br>";
        //Preferred first name must be alpha characters, white-space, or certain punctuation.
        if (!empty($_POST['user_preferred_firstname']) && trim($_POST['user_preferred_firstname']) !== "") {
            $error_message .= User::validateUserData('user_preferred_firstname', trim($_POST['user_preferred_firstname'])) ? "" : "Error in preferred first name: \"".strip_tags($_POST['user_preferred_firstname'])."\"<br>";
        }
        if (!empty($_POST['user_preferred_lastname']) && trim($_POST['user_preferred_lastname']) !== "") {
            $error_message .= User::validateUserData('user_preferred_lastname', trim($_POST['user_preferred_lastname'])) ? "" : "Error in preferred last name: \"".strip_tags($_POST['user_preferred_lastname'])."\"<br>";
        }

        //Database password cannot be blank, no check on format
        if ($use_database && (($_POST['edit_user'] == 'true' && !empty($_POST['user_password'])) || $_POST['edit_user'] != 'true')) {
            $error_message .= User::validateUserData('user_password', $_POST['user_password']) ? "" : "Error must enter password for user<br>";
        }

        if (!empty($error_message)) {
            $this->core->addErrorMessage($error_message." Contact your sysadmin if this should not cause an error.");
            $this->core->redirect($return_url);
        }

        if ($_POST['edit_user'] == "true") {
            if ($user === null) {
                $this->core->addErrorMessage("No user found with that user id");
                $this->core->redirect($return_url);
            }
        }
        else {
            if ($user !== null) {
                $this->core->addErrorMessage("A user with that ID already exists");
                $this->core->redirect($return_url);
            }
            $user = $this->core->loadModel(User::class);
            $user->setId(trim($_POST['user_id']));
        }

        $user->setLegalFirstName(trim($_POST['user_firstname']));
        if (isset($_POST['user_preferred_firstname']) && trim($_POST['user_preferred_firstname']) != "") {
            $user->setPreferredFirstName(trim($_POST['user_preferred_firstname']));
        }

        $user->setLegalLastName(trim($_POST['user_lastname']));
        if (isset($_POST['user_preferred_lastname']) && trim($_POST['user_preferred_lastname']) != "") {
            $user->setPreferredLastName(trim($_POST['user_preferred_lastname']));
        }

        $user->setEmail(trim($_POST['user_email']));

        if (!empty($_POST['user_password'])) {
            $user->setPassword($_POST['user_password']);
        }

        if ($_POST['registered_section'] === "null") {
            $user->setRegistrationSection(null);
        }
        else {
            $user->setRegistrationSection($_POST['registered_section']);
        }

        if ($_POST['rotating_section'] == "null") {
            $user->setRotatingSection(null);
        }
        else {
            $user->setRotatingSection(intval($_POST['rotating_section']));
        }

        $user->setGroup(intval($_POST['user_group']));
        //Instructor updated flag tells auto feed to not clobber some of the users data.
        $user->setInstructorUpdated(true);
        $user->setManualRegistration(isset($_POST['manual_registration']));
        if (isset($_POST['grading_registration_section'])) {
            $user->setGradingRegistrationSections($_POST['grading_registration_section']);
        }
        else {
            $user->setGradingRegistrationSections(array());
        }

        if ($_POST['edit_user'] == "true") {
            $this->core->getQueries()->updateUser($user, $this->core->getConfig()->getSemester(), $this->core->getConfig()->getCourse());
            $this->core->addSuccessMessage("User '{$user->getId()}' updated");
        }
        else {
            if ($this->core->getQueries()->getSubmittyUser($_POST['user_id']) === null) {
                $this->core->getQueries()->insertSubmittyUser($user);
                $this->core->addSuccessMessage("Added a new user {$user->getId()} to Submitty");
                $this->core->getQueries()->insertCourseUser($user, $this->core->getConfig()->getSemester(), $this->core->getConfig()->getCourse());
                $this->core->addSuccessMessage("New Submitty user '{$user->getId()}' added");
            }
            else {
                $this->core->getQueries()->insertCourseUser($user, $this->core->getConfig()->getSemester(), $this->core->getConfig()->getCourse());
                $this->core->addSuccessMessage("Existing Submitty user '{$user->getId()}' added");
            }

        }
        $this->core->redirect($return_url);
    }

    public function rotatingSectionsForm() {
        $students = $this->core->getQueries()->getAllUsers();
        $reg_sections = $this->core->getQueries()->getRegistrationSections();
        $non_null_counts = $this->core->getQueries()->getCountUsersRotatingSections();
        $null_counts = $this->core->getQueries()->getCountNullUsersRotatingSections();
        $max_section = $this->core->getQueries()->getMaxRotatingSection();
        $this->core->getOutput()->renderOutput(array('admin', 'Users'), 'rotatingSectionsForm', $students, $reg_sections,
            $non_null_counts, $null_counts, $max_section);
    }

    public function updateRegistrationSections() {
        $return_url = $this->core->buildUrl(
            array('component' => 'admin',
                  'page' => 'users',
                  'action' => 'rotating_sections')
        );

        if (!$this->core->checkCsrfToken()) {
            $this->core->addErrorMessage("Invalid CSRF token. Try again.");
            $this->core->redirect($return_url);
        }

        $reg_sections = $this->core->getQueries()->getRegistrationSections();
        $students = $this->core->getQueries()->getAllUsers();
        $graders = $this->core->getQueries()->getAllGraders();
        if (isset($_POST['add_reg_section']) && $_POST['add_reg_section'] !== "") {
            if (User::validateUserData('registration_section', $_POST['add_reg_section'])) {
                // SQL query's ON CONFLICT clause should resolve foreign key conflicts, so we are able to INSERT after successful validation.
                // $num_new_sections indicates how many new INSERTions were performed.  0 INSERTions means the reg section given on the form is a duplicate.
                $num_new_sections = $this->core->getQueries()->insertNewRegistrationSection($_POST['add_reg_section']);
                if ($num_new_sections === 0) {
                    $this->core->addErrorMessage("Registration Section {$_POST['add_reg_section']} already present");
                }
                else {
                    $this->core->addSuccessMessage("Registration section {$_POST['add_reg_section']} added");
                }
            }
            else {
                $this->core->addErrorMessage("Registration Section entered does not follow the specified format");
                $_SESSION['request'] = $_POST;
            }
        }
        else if (isset($_POST['delete_reg_section']) && $_POST['delete_reg_section'] !== "") {
            if (User::validateUserData('registration_section', $_POST['delete_reg_section'])) {
                // DELETE trigger function in master DB will catch integrity violation exceptions (such as FK violations when users/graders are still enrolled in section).
                // $num_del_sections indicates how many DELETEs were performed.  0 DELETEs means either the section didn't exist or there are users still enrolled.
                $num_del_sections = $this->core->getQueries()->deleteRegistrationSection($_POST['delete_reg_section']);
                if ($num_del_sections === 0) {
                    $this->core->addErrorMessage("Section {$_POST['delete_reg_section']} not removed.  Section must exist and be empty of all users/graders.");
                }
                else {
                    $this->core->addSuccessMessage("Registration section {$_POST['delete_reg_section']} removed.");
                }
            }
            else {
                $this->core->addErrorMessage("Registration Section entered does not follow the specified format");
                $_SESSION['request'] = $_POST;
            }
        }

        $this->core->redirect($return_url);
    }

    public function updateRotatingSections() {
        $return_url = $this->core->buildUrl(
            array('component' => 'admin',
                  'page' => 'users',
                  'action' => 'rotating_sections')
        );

        if (!$this->core->checkCsrfToken()) {
            $this->core->addErrorMessage("Invalid CSRF token. Try again.");
            $this->core->redirect($return_url);
        }

        if (!isset($_REQUEST['sort_type'])) {
            $this->core->addErrorMessage("Must select one of the three options for setting up rotating sections");
            $this->core->redirect($return_url);
        }
        else if ($_REQUEST['sort_type'] === "drop_null") {
            $this->core->getQueries()->setNonRegisteredUsersRotatingSectionNull();
            $this->core->addSuccessMessage("Non registered students removed from rotating sections");
            $this->core->redirect($return_url);
        }

        if (isset($_REQUEST['rotating_type']) && in_array($_REQUEST['rotating_type'], array('random', 'alphabetically'))) {
            $type = $_REQUEST['rotating_type'];
        }
        else {
            $type = 'random';
        }

        $section_count = intval($_REQUEST['sections']);
        if ($section_count < 1) {
            $this->core->addErrorMessage("You must have at least one rotating section");
            $this->core->redirect($return_url);
        }

        if (in_array($_REQUEST['sort_type'], array('redo', 'fewest')) && $type == "random") {
            $sort = $_REQUEST['sort_type'];
        }
        else {
            $sort = 'redo';
        }

        $section_counts = array_fill(0, $section_count, 0);
        $team_section_counts = [];
        if ($sort === 'redo') {
            $users = $this->core->getQueries()->getRegisteredUserIds();
            $teams = $this->core->getQueries()->getTeamIdsAllGradeables();
            $users_with_reg_section = $this->core->getQueries()->getAllUsers();

            $exclude_sections = [];
            $reg_sections = $this->core->getQueries()->getRegistrationSections();
            foreach ($reg_sections as $row) {
                $test = $row['sections_registration_id'];
                if (isset($_POST[$test])) {
                    array_push($exclude_sections,$_POST[$row['sections_registration_id']]);
                }
            }
            //remove people who should not be added to rotating sections
            for ($j = 0;$j < count($users_with_reg_section);) {
                for ($i = 0;$i < count($exclude_sections);++$i) {
                    if ($users_with_reg_section[$j]->getRegistrationSection() == $exclude_sections[$i]) {
                        array_splice($users_with_reg_section,$j,1);
                        $j--;
                        break;
                    }
                }
                ++$j;

            }
            for ($i = 0;$i < count($users);) {
                $found_in = false;
                for ($j = 0;$j < count($users_with_reg_section);++$j) {
                    if ($users[$i] == $users_with_reg_section[$j]->getId()) {
                        $found_in = true;
                        break;
                    }
                }
                if (!$found_in) {
                    array_splice($users,$i,1);
                    continue;
                }
                ++$i;
            }
            if ($type === 'random') {
                shuffle($users);
                foreach ($teams as $g_id => $team_ids) {
                    shuffle($teams[$g_id]);
                }
            }
            $this->core->getQueries()->setAllUsersRotatingSectionNull();
            $this->core->getQueries()->setAllTeamsRotatingSectionNull();
            $this->core->getQueries()->deleteAllRotatingSections();
            for ($i = 1; $i <= $section_count; $i++) {
                $this->core->getQueries()->insertNewRotatingSection($i);
            }

            for ($i = 0; $i < count($users); $i++) {
                $section = $i % $section_count;
                $section_counts[$section]++;
            }
            foreach ($teams as $g_id => $team_ids) {
                for ($i = 0; $i < count($team_ids); $i ++) {
                    $section = $i % $section_count;

                    if (!array_key_exists($g_id, $team_section_counts)) {
                        $team_section_counts[$g_id] = array_fill(0, $section_count, 0);
                    }

                    $team_section_counts[$g_id][$section]++;
                }
            }
        }
        else {
            $this->core->getQueries()->setNonRegisteredUsersRotatingSectionNull();
            $max_section = $this->core->getQueries()->getMaxRotatingSection();
            if ($max_section === null) {
                $this->core->addErrorMessage("No rotating sections have been added to the system, cannot use fewest");
            }
            else if ($max_section != $section_count) {
                $this->core->addErrorMessage("Cannot use a different number of sections when setting up via fewest");
                $this->core->redirect($return_url);
            }
            $users = $this->core->getQueries()->getRegisteredUserIdsWithNullRotating();
            $teams = $this->core->getQueries()->getTeamIdsWithNullRotating();
            // only random sort can use 'fewest' type
            shuffle($users);
            foreach ($teams as $g_id => $team_ids) {
                shuffle($teams[$g_id]);
            }
            $sections = $this->core->getQueries()->getCountUsersRotatingSections();
            $use_section = 0;
            $max = $sections[0]['count'];
            foreach ($sections as $section) {
                if ($section['count'] < $max) {
                    $use_section = $section['rotating_section'] - 1;
                    break;
                }
            }

            for ($i = 0; $i < count($users); $i++) {
                $section_counts[$use_section]++;
                $use_section = ($use_section + 1) % $section_count;
            }
            foreach ($teams as $g_id => $team_ids) {
                for ($i = 0; $i < count($team_ids); $i ++) {
                    $use_section = ($use_section + 1) % $section_count;

                    if (!array_key_exists($g_id, $team_section_counts)) {
                        $team_section_counts[$g_id] = array_fill(0, $section_count, 0);
                    }

                    $team_section_counts[$g_id][$use_section]++;
                }
            }
        }

        for ($i = 0; $i < $section_count; $i++) {
            $update_users = array_splice($users, 0, $section_counts[$i]);
            if (count($update_users) == 0) {
                continue;
            }
            $this->core->getQueries()->updateUsersRotatingSection($i + 1, $update_users);
        }

        foreach ($team_section_counts as $g_id => $counts) {
            for ($i = 0; $i < $section_count; $i ++) {
                $update_teams = array_splice($teams[$g_id], 0, $team_section_counts[$g_id][$i]);

                foreach ($update_teams as $team_id) {
                    $this->core->getQueries()->updateTeamRotatingSection($team_id, $i + 1);
                }
            }
        }

        $this->core->addSuccessMessage("Rotating sections setup");
        $this->core->redirect($return_url);
    }

    /**
     * Parse uploaded users data file as either XLSX or CSV, and return its data
     *
     * @param string $filename  Original name of uploaded file
     * @param string $tmp_name  PHP assigned unique name and path of uploaded file
     * @param string $return_url
     *
     * @return array $contents  Data rows and columns read from xlsx or csv file
     */
    private function getUserDataFromUpload($filename, $tmp_name, $return_url) {
        // Data is confidential, and therefore must be deleted immediately after
        // this process ends, regardless if process completes successfully or not.
        // Vars must be declared before shutdown callback.
        $xlsx_file = null;
        $csv_file = null;

        register_shutdown_function(
            function() use (&$csv_file, &$xlsx_file) {
                foreach (array($csv_file, $xlsx_file) as $file) {
                    if (!is_null($file) && file_exists($file)) {
                        unlink($file);
                    }
                }
            }
        );

        $content_type = FileUtils::getContentType($filename);
        $mime_type = FileUtils::getMimeType($tmp_name);

        // If an XLSX spreadsheet is uploaded.
        if ($content_type === 'spreadsheet/xlsx' && $mime_type === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            // Declare tmp file paths with unique file names.
            $csv_file = FileUtils::joinPaths($this->core->getConfig()->getCgiTmpPath(), uniqid("", true));
            $xlsx_file = FileUtils::joinPaths($this->core->getConfig()->getCgiTmpPath(), uniqid("", true));

            // This is to create tmp files and set permissions to RW-RW----
            // chmod() is disabled by security policy, so we are using umask().
            // NOTE: php.net recommends against using umask() and instead suggests using chmod().
            // q.v. https://secure.php.net/manual/en/function.umask.php
            $old_umask = umask(0117);
            file_put_contents($csv_file, "");
            $did_move = move_uploaded_file($tmp_name, $xlsx_file);
            umask($old_umask);

            if ($did_move) {
                // exec() and similar functions are disabled by security policy,
                // so we are using a python script via CGI to invoke external program 'xlsx2csv'
                $xlsx_tmp = basename($xlsx_file);
                $csv_tmp = basename($csv_file);
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $this->core->getConfig()->getCgiUrl()."xlsx_to_csv.cgi?xlsx_file={$xlsx_tmp}&csv_file={$csv_tmp}");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                $output = curl_exec($ch);

                if ($output === false) {
                    $this->core->addErrorMessage("Error parsing xlsx to csv");
                    $this->core->redirect($return_url);
                }

                $output = json_decode($output, true);
                if ($output === null) {
                    $this->core->addErrorMessage("Error parsing JSON response: ".json_last_error_msg());
                    $this->core->redirect($return_url);
                } else if ($output['error'] === true) {
                    $this->core->addErrorMessage("Error parsing xlsx to csv: ".$output['error_message']);
                    $this->core->redirect($return_url);
                } else if ($output['success'] !== true) {
                    $this->core->addErrorMessage("Error on response on parsing xlsx: ".curl_error($ch));
                    $this->core->redirect($return_url);
                }

                curl_close($ch);
            } else {
                $this->core->addErrorMessage("Did not properly recieve spredsheet. Contact your sysadmin.");
                $this->core->redirect($return_url);
            }

        } else if ($content_type === 'text/csv' && $mime_type === 'text/plain') {
            $csv_file = $tmp_name;
        } else {
            $this->core->addErrorMessage("Must upload xlsx or csv");
            $this->core->redirect($return_url);
        }

        // Set environment config to allow '\r' EOL encoding. (Used by Microsoft Excel on Macintosh)
        ini_set("auto_detect_line_endings", true);

        // Parse user data (should be in CSV form by now)
        $user_data = array_map('str_getcsv', file($csv_file, FILE_SKIP_EMPTY_LINES));
        if (is_null($user_data)) {
            $this->core->addErrorMessage("File was not properly uploaded. Contact your sysadmin.");
            $this->core->redirect($return_url);
        }

        //Apply trim() to all data values.
        array_walk_recursive($user_data, function(&$val) {
            $val = trim($val);
        });

        return $user_data;
    }

    /**
     * Upsert user list data to database
     *
     * @param string $list_type "classlist" or "graderlist"
     */
    public function uploadUserList($list_type = "classlist") {
        // A few places have different behaviors depending on $list_type.
        // These closure functions will help control those few times when
        // $list_type dictates behavior.

        /**
         * Validate $row[4] depending on $list_type
         * @return string "" on successful validation, an error message otherwise
         */
        $row4_validation_function = function() use ($list_type, &$row[4]) {
            //$row[4] is different based on classlist vs graderlist
            switch($list_type) {
            case "classlist":
                //student
                if (isset($row[4]) && strtolower($row[4]) === "null") {
                    $row[4] = null;
                }
                //Check registration for appropriate format. Allowed characters - A-Z,a-z,_,-
                return User::validateUserData('registration_section', $row[4]) ? "" : "ERROR on row {$row_num}, Registration Section \"".strip_tags($row[4])."\"<br>";
            case "graderlist":
                //grader
                if (isset($row[4]) && is_numeric($row[4])) {
                    $row = intval($row[4]); //change float read from xlsx to int
                }
                //grader-level check is a digit between 1 - 4.
               return User::validateUserData('user_group', $row[4]) ? "" : "ERROR on row {$row_num}, Grader Group \"".strip_tags($row[4])."\"<br>";
            default:
                return "Developer error: Unknown classlist: \"{$list_type}\"";
            }
        };

        $return_url = $this->core->buildUrl(array('component'=>'admin', 'page'=>'users', 'action'=>'students'));
        $use_database = $this->core->getAuthentication() instanceof DatabaseAuthentication;

        if (!$this->core->checkCsrfToken($_POST['csrf_token'])) {
            $this->core->addErrorMessage("Invalid CSRF token");
            $this->core->redirect($return_url);
        }

        if ($_FILES['upload']['name'] == "") {
            $this->core->addErrorMessage("No input file specified");
            $this->core->redirect($return_url);
        }

        $user_data = $this->getUserDataFromUpload($_FILES['upload']['name'], $_FILES['upload']['tmp_name'], $return_url);

        //Validation and error checking.
        $num_reg_sections = count($this->core->getQueries()->getRegistrationSections());
        $pref_firstname_idx = $use_database ? 6 : 5;
        $pref_lastname_idx = $pref_firstname_idx + 1;
        $error_message = "";
        $row_num = 0;
        foreach($user_data as $row) {
            $row_num++;

            //Username must contain only lowercase alpha, numbers, underscores, hyphens
            $error_message .= User::validateUserData('user_id', $row[0]) ? "" : "ERROR on row {$row_num}, User Name \"".strip_tags($vals[0])."\"<br>";

            //First and Last name must be alpha characters, white-space, or certain punctuation.
            $error_message .= User::validateUserData('user_legal_firstname', $row[1]) ? "" : "ERROR on row {$row_num}, First Name \"{$vals[1]}\"<br>";
            $error_message .= User::validateUserData('user_legal_lastname', $row[2]) ? "" : "ERROR on row {$row_num}, Last Name \"".strip_tags($vals[2])."\"<br>";

            //Check email address for appropriate format. e.g. "student@university.edu", "student@cs.university.edu", etc.
            $error_message .= User::validateUserData('user_email', $row[3]) ? "" : "ERROR on row {$row_num}, email \"".strip_tags($vals[3])."\"<br>";

            //$row[4] validation varies by $list_type
            $error_message .= $row4_validation_function();

            //Preferred first and last name must be alpha characters, white-space, or certain punctuation.
            if (isset($vals[$pref_firstname_idx]) && ($vals[$pref_firstname_idx] !== "")) {
                $error_message .= User::validateUserData('user_preferred_firstname', $row[$pref_firstname_idx]) ? "" : "ERROR on row {$row_num}, Preferred First Name \"".strip_tags($vals[$pref_firstname_idx])."\"<br>";
            }
            if (isset($vals[$pref_lastname_idx]) && ($vals[$pref_lastname_idx] !== "")) {
                $error_message .= User::validateUserData('user_preferred_lastname', $row[$pref_lastname_idx]) ? "" : "ERROR on row {$row_num}, Preferred Last Name \"".strip_tags($vals[$pref_lastname_idx])."\"<br>";
            }

            //Database password cannot be blank, no check on format
            if ($use_database) {
                $error_message .= User::validateUserData('user_password', $row[5]) ? "" : "ERROR on row {$row_num}, password cannot be blank<br>";
            }
        }

        //Display any accumulated errors.  Quit on errors, otherwise continue.
        if (!empty($error_message)) {
            $this->core->addErrorMessage($error_message." Contact your sysadmin if this should not cause an error.");
            $this->core->redirect($return_url);
        }


        /* NOTE: Checking for existing users and skipping over them is better achieved on database side using SQL */
        //Existing students are not updated.
        $existing_users = $this->core->getQueries()->getAllUsers();
        $users_to_add = array();
        $users_to_update = array();
        foreach($user_data as $user) {
            $exists = false;
            foreach($existing_users as $i => $existing_user) {
                if ($user_data[0] === $existing_user->getId()) {
                    $datapoint = ($list_type === 'classlist') ? $existing_user->getRegistrationSection() : $existing_user->getGroup();
                    if ($user_data[4] !== $datapoint) {
                            $users_to_update[] = $user;
                    }
                    unset($existing_users[$i]);
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $users_to_add[] = $user;
            }
        }

        //Insert new students to database
        $semester = $this->core->getConfig()->getSemester();
        $course = $this->core->getConfig()->getCourse();
        foreach($users_to_add as $user_data) {
            $user = new User($this->core);
            $user->setId($user_data[0]);
            $user->setLegalFirstName($user_data[1]);
            $user->setLegalLastName($user_data[2]);
            $user->setEmail($user_data[3]);

            if ($list_type === 'classlist') {
                //student
                $user->setRegistrationSection($user_data[4]);
                $user->setGroup(4);
            }
            else {
                //grader
                $user->setGroup($user_data[4]);
            }

            if (isset($student_data[$pref_firstname_idx]) && ($student_data[$pref_firstname_idx] !== "")) {
                $user->setPreferredFirstName($user_data[$pref_firstname_idx]);
            }
            if (isset($student_data[$pref_lastname_idx]) && ($student_data[$pref_lastname_idx] !== "")) {
                $user->setPreferredLastName($user_data[$pref_lastname_idx]);
            }
            if ($use_database) {
                $user->setPassword($user_data[5]);
            }
            if ($this->core->getQueries()->getSubmittyUser($user_data[0]) === null) {
                $this->core->getQueries()->insertSubmittyUser($user);
            }
            $this->core->getQueries()->insertCourseUser($user, $semester, $course);
        }
        foreach($users_to_update as $user_data) {
            $student = $this->core->getQueries()->getUserById($user_data[0]);
            if ($list_type === 'classlist') {
                $user->setRegistrationSection($user_data[4]);
            }
            else {
                $user->setGroup($user_data[4]);
            }
            $this->core->getQueries()->updateUser($student, $semester, $course);
        }

        $added = count($users_to_add);
        $updated = count($users_to_update);

        if ($list_type === "classlist" && isset($_POST['move_missing'])) {
            foreach($existing_users as $user) {
                if (is_null($user->getRegistrationSection())) {
                    $user->setRegistrationSection(null);
                    $this->core->getQueries()->updateUser($user, $semester, $course);
                    $updated++;
                }
            }
        }

        $this->core->addSuccessMessage("Uploaded {$_FILES['upload']['name']}: ({$added} added, {$updated} updated)");
        $this->core->redirect($return_url);
    }
}
