<?php
/* $Id$ */
/* vim: set tabstop=4 shiftwidth=4 expandtab: */
/**
* dashboard.php -- Implements a respondent's portal into the survey tool.
* Original Author: Bishop Bettini <bishop@ideacode.com>
*
* Administrators and designers have a management interface where they can select various operations to carry out within
* the phpESP survey tool.  Respondents benefit from a similar interface, where they can see the surveys they can complete,
* the surveys they have already completed, change their password, and get help.
*
* There are two distinct operating modes: authenticated and non-authenticated.  When not authenticated, this page presents
* a login form as well as a list of links to all public surveys.  Once authenticated, this page presents a list of links to
* all user-specific surveys (private and public surveys), a history showing his previously completed surveys, and a
* toolbox through which he can change his password, access the user manual, logout, etc.
*
* @_PATTERNS_@
*
* @_NOTES_@
* MEANING/INTERPREATION OF SURVEY STATUS
* The following table describes the meaning of the status constants:
*
* Constant             Interpretation
* -------------------- ---------------------------------------------------------------------------------------------------
* STATUS_NOT_STARTED   The user has never submitted a response.  The user may have looked at the survey.
* STATUS_ALL_PARTIAL   The user has submitted a single, incomplete response.
* STATUS_SOME_PARTIAL  The user has submitted at least one complete, but at least one incomplete, response.
* STATUS_FINISHED      The user has submitted at least one complete, but no incomplete, response.
*
* @_TODO_@
* o On login page, add link to reset a forgotten password
* o In table of surveys, add:
*   - response ID/confirmation number to finished surveys
*   - opening/closing date (FUTURE ENHANCEMENT NEEDED TO WHOLE APP)
*
*/
// hook into the phpESP environment
require_once('./phpESP.first.php');

// {{{ constants

// survey status
define('STATUS_NOT_STARTED',  _('Not Started'));
define('STATUS_ALL_PARTIAL',  _('Started, but Incomplete'));
define('STATUS_SOME_PARTIAL', _('Some Finished, some Incomplete'));
define('STATUS_FINISHED',     _('Finished'));

// miscellaneous
define('FORMAT_OUTPUT_DATE', isset($ESPCONFIG['date_format'])?$ESPCONFIG['date_format']:'%Y-%m-%d');

// }}}


// ensure we are configured to want this page
if (! $GLOBALS['ESPCONFIG']['dashboard_enable']) {
    paint_header();
    echo mkerror(_('Feature disabled; set dashboard_enable = true in your configuration to engage.'));
    paint_footer();
    exit;
}

// handle any button press events
handleLogin();
handleLogout();
handleChangeProfile();
handleChangePassword();
handleHelp();

// dispatch to the right painter
if (is_session_authenticated()) {
    paint_authenticated();
} else {
    paint_non_authenticated();
}

/* button handlers */
// {{{ handleLogin()                   Handle a log in button press

function handleLogin() {
    $handleLogin = (
                    ! is_session_authenticated() &&
                    isset($_REQUEST['doLogin']) &&
                    ! empty($_REQUEST['username']) && ! empty($_REQUEST['password']) ?
                    true : false
                   );
    if ($handleLogin) {
        $isAuthenticated = authenticate($_REQUEST['username'], $_REQUEST['password'], $realms);
        $realmsCnt = count($realms);

        // if the login information uniquely identifies a user, mark as authenticated session and move on
        if ($isAuthenticated && 1 === $realmsCnt) {
            $ok = set_current_respondent($_REQUEST['username'], current($realms), $_REQUEST['password']);
            if ($ok) {
                set_session_authentication($isAuthenticated);
                blur('/public/dashboard.php');
                assert('false; // NOTREACHED');
            }

        // if the login is recognized but not-unique, we can't figure out what to do... panic
        // NOTE: if email were mandatory, then we could use that as a key...
        } else if ($isAuthenticated && 2 <= $realmsCnt) {
            $GLOBALS['errmsg'] = mkerror(_('Please contact an administrator: multi-realm'));

        // otherwise, not recognized, throw error
        } else {
            $GLOBALS['errmsg'] = mkerror(_('Incorrect User ID or Password, or your account has been disabled/expired.'));
        }
    }
}

// }}}
// {{{ handleLogout()                  Handle a log out button press

function handleLogout() {
    $handleLogout = (isset($_REQUEST['doLogout']) && is_session_authenticated() ? true : false);
    if ($handleLogout) {
        // tag the session as no longer authenticated
        set_session_authentication(false);
    }
}

// }}}
// {{{ handleChangeProfile()           Handle a profile change button press

function handleChangeProfile() {
    // are we in change profile mode?
    $showChangeProfile   = (
                            $GLOBALS['ESPCONFIG']['dashboard_allow_change_profile'] &&
                            empty($_REQUEST['doChangeProfileCancel']) &&
                            is_session_authenticated() &&
                            isset($_REQUEST['doChangeProfile']) ?
                            true : false
                           );
    // are we also changing the password?
    $handleChangeProfile = (
                             $showChangeProfile &&
                             get_current_respondent($respondent) &&
                             isset($_REQUEST['firstName']) && isset($_REQUEST['lastName']) &&
                             isset($_REQUEST['emailAddress']) ?
                             true : false
                            );

    // if changing, handle it
    if ($handleChangeProfile) {
        $ok = change_profile(
                  $respondent['username'], $respondent['realm'],
                  $_REQUEST['firstName'], $_REQUEST['lastName'], $_REQUEST['emailAddress']
              );
        if ($ok) {
            $showChangeProfile = false;
        } else {
            $GLOBALS['errmsg'] = mkerror(_('Unable to change your password; contact an administrator'));
        }
    }

    // if we're showing the change profile form, do so
    if ($showChangeProfile) {
        if (empty($_REQUEST['firstName'])) {
            $_REQUEST['firstName'] = $respondent['fname'];
        }
        if (empty($_REQUEST['lastName'])) {
            $_REQUEST['lastName'] = $respondent['lname'];
        }
        if (empty($_REQUEST['emailAddress'])) {
            $_REQUEST['emailAddress'] = $respondent['email'];
        }

        paint_header();
        echo '<div class="dashboardPanel">' .
             '<h1>' . _('Change My Profile') . '</h1>' .
             render_profile_change_form() .
             '</div>';
        paint_footer();
        exit;
    }
}

// }}}
// {{{ handleChangePassword()          Handle a password change button press

function handleChangePassword() {
    // are we in change password mode?
    $showChangePassword   = (
                             $GLOBALS['ESPCONFIG']['dashboard_allow_change_password'] &&
                             empty($_REQUEST['doChangePasswordCancel']) &&
                             is_session_authenticated() &&
                             isset($_REQUEST['doChangePassword']) ?
                             true : false
                            );
    // are we also changing the password?
    $handleChangePassword = (
                             $showChangePassword &&
                             get_current_respondent($respondent) &&
                             ! empty($_REQUEST['oldPassword']) &&
                             ! empty($_REQUEST['newPassword']) && ! empty($_REQUEST['newPasswordConfirm']) ?
                             true : false
                            );

    // if changing, handle it
    if ($handleChangePassword) {
        $isAuthenticated = authenticate($respondent['username'], $_REQUEST['oldPassword'], $realms);
        $isAuthenticated = (1 === count($realms) ? $isAuthenticated : false);
        $isMatch = (0 === strcmp($_REQUEST['newPassword'], $_REQUEST['newPasswordConfirm']) ? true : false);

        // if the old password authenticates and the confirmation password matches, go change
        if ($isAuthenticated && $isMatch) {
            // if password changes successfully, drop out of show change password mode
            $ok = change_password($respondent['username'], $respondent['realm'], $_REQUEST['newPassword']);
            if ($ok) {
                $showChangePassword = false;
            } else {
                $GLOBALS['errmsg'] = mkerror(_('Unable to change your password; contact an administrator'));
            }

        // if the old password authenticates but the confirmation doesn't match
        } else if ($isAuthenticated && ! $isMatch) {
            $GLOBALS['errmsg'] = mkerror(_('Passwords do not match; check your typing'));

        // otherwise, bad original password, puke
        } else {
            $GLOBALS['errmsg'] = mkerror(_('Old password incorrect; check your typing'));
        }
    }

    // if we're showing the change password form, do so
    if ($showChangePassword) {
        paint_header();
        echo '<div class="dashboardPanel">' .
             '<h1>' . _('Change My Password') . '</h1>' .
             render_passwd_change_form() .
             '</div>';
        paint_footer();
        exit;
    }
}

// }}}
// {{{ handleHelp()                    Handle a help button press

function handleHelp() {
    $handleHelp = (isset($_REQUEST['doHelp']) && is_session_authenticated() ? true : false);
    if ($handleHelp) {
        $base  = $GLOBALS['ESPCONFIG']['base_url'];
        $title = _('Help');
        echo <<<EOHTML
<script type='text/javascript'>
window.open("$base/public/help/help.php", "$title");
if (window) {
    window.location="$base/public/dashboard.php";
}
</script>
<noscript>
<a href="$base/public/dashboard.php">Back</a>
EOHTML;
        require_once('help/help.php');
        echo <<<EOHTML
</noscript>
<a href="$base/public/dashboard.php">Back</a>
EOHTML;
    }
}

// }}}

/* page painters */
// {{{ paint_header()

function paint_header() {
    $cfg =& $GLOBALS['ESPCONFIG'];

    $title = _('phpESP Respondent Dashboard');
    echo <<<EOHTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.1//EN" 
"http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
EOHTML;
    if(!empty($cfg['charset'])) {
        echo('<meta http-equiv="Content-Type" content="text/html; charset='. $cfg['charset'] ."\" />\n");
    }
    if (! empty($cfg['favicon'])) {
        echo '<link rel="shortcut icon" href="' . $cfg['favicon'] . '" />';
    }
    echo <<<EOHTML
  <title>{$title}</title>
  <link rel="stylesheet" href="{$cfg['css_url']}/default.css" type="text/css" />
  <script type="text/javascript" src="{$cfg['js_url']}/default.js"></script>
EOHTML;
    echo '</head><body><div id="dashboard">';
    echo @$GLOBALS['errmsg'];
}

// }}}
// {{{ paint_footer()

function paint_footer() {
    echo '</div></body></html>';
}

// }}}

// {{{ paint_non_authenticated()       Paint the page for non-authenticated users

function paint_non_authenticated() {
    // throw it up
    paint_header();
    paint_login_panel();
    echo <<<EOHTML
<div class='dashboard'>
<p><a href='help/help.php'>Help</a></p>
</div>
EOHTML;
    paint_public_survey_list();
    paint_footer();
}

// }}}
// {{{ paint_login_panel()             Paint the login panel

function paint_login_panel() {
    echo '<div class="dashboardPanel" id="my_login">' .
         '<h1>' . _('Login') . '</h1>' .
         render_login_form() .
         (
           empty($GLOBALS['ESPCONFIG']['signup_realm']) ?
           '' :
           '<a href="signup.php">' . _("Don't have an account? Sign up."). '</a>'
         ) .
         (
           empty($GLOBALS['ESPCONFIG']['support_email_address']) ?
           '' :
           "<a href='mailto:{$GLOBALS['ESPCONFIG']['support_email_address']}'>" . _('Need help? E-mail us.'). '</a>'
         ) .
         '</div>';
}

// }}}
// {{{ paint_public_survey_list()      Paint a list of links to take the given surveys

function paint_public_survey_list() {
    // make sure we're configured to show this
    if (! $GLOBALS['ESPCONFIG']['dashboard_show_public_surveys']) {
        return;
    }

    // get the available public surveys
    get_survey_info($surveys, $_, $accessibility);
    foreach ($surveys as $sid => $survey) {
        if (isset($accessibility[$sid]['available']) && true === (bool)$accessibility[$sid]['available']) {
            continue;
        }
        unset($surveys[$sid]);
    }

    // spit them out
    if (0 < count($surveys)) {
        echo '<div class="dashboardPanel" id="my_surveys">' .
             '<h1>' . _('Public Surveys') . '</h1>' .
             '<p>' . _('If you have an account, please log in before taking these public surveys.') . '</p>' .
             '<ul>';
        foreach ($surveys as $survey) {
            printf('<li><a href="%s">%s</a></li>', survey_fetch_url_by_survey_name($survey['name']), $survey['title']);
        }
        echo '</ul>' .
             '</div>';
    }
}

// }}}

// {{{ paint_authenticated()           Paint the page for authenticated users

function paint_authenticated() {
    // get the needed data
    get_survey_info($surveys, $responses, $accessibility);
    partition_surveys($surveys, $responses, $accessibility, $current, $historical);

    // throw it up
    paint_header();
    paint_welcome();
    paint_respondent_surveys($current);
    paint_respondent_history($historical);
    paint_respondent_tools();
    paint_footer();
}

// }}}
// {{{ paint_welcome()                 Paint a friendly welcome message

function paint_welcome() {
    echo '<h1>Dashboard</h1>';
    $ok = get_current_respondent($respondent);
    if ($ok) {
        // spew a nice welcome message, if we know the person's name
        if (! empty($respondent['fname'])) {
            echo '<em>Welcome, ' . $respondent['fname'];
            if (! empty($respondent['lname'])) {
                echo ' ' . $respondent['lname'];
            }
            echo '.</em>  ';
        }
    }

    // spew the time
    printf(_('Right now, my watch shows %s.'), strftime(FORMAT_OUTPUT_DATE));
}

// }}}
// {{{ paint_respondent_surveys()      Paint a panel of links to surveys available to the current respondent

function paint_respondent_surveys($current) {
    echo '<div class="dashboardPanel" id="my_surveys">';

    // make surveys into a list
    if (0 < count($current)) {
        echo '<table>' .
             '<caption>' . _('My Surveys') . '</caption>' .
             '<thead><tr>' .
             '<th>' . _('Survey Title') . '</th>' .
             '<th>' . _('Submission Status') . '</th>' .
             '<th>' . _('Last Access') . '</th>' .
             '<th>' . _('Availability') . '</th>' .
             '</tr></thead><tbody>';

        foreach ($current as $sid => $info) {
            list ($name, $status, $date, $avail) = $info;
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', $name, $status, $date, $avail);
        }

        echo '</tbody></table>';
    } else {
       echo _('You do not have any surveys at this time.');
    }

    echo '</div>';
}

// }}}
// {{{ paint_respondent_history()      Paint a historical list of surveys this respondent has completed

function paint_respondent_history($historical) {
    echo '<div class="dashboardPanel" id="my_history">';

    // make surveys into a list
    if (0 < count($historical)) {
        echo '<table>' .
             '<caption>' . _('My History') . '</caption>' .
             '<thead><tr>' .
             '<th>' . _('Survey Title') . '</th>' .
             '<th>' . _('Submission Status') . '</th>' .
             '<th>' . _('Last Access') . '</th>' .
             '<th>' . _('Availability') . '</th>' .
             '</tr></thead><tbody>';

        foreach ($historical as $sid => $info) {
            list ($name, $status, $date, $avail) = $info;
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', $name, $status, $date, $avail);
        }

        echo '</tbody></table>';
    } else {
       echo _('You have no historical surveys at this time.');
    }

    echo '</div>';
}

// }}}
// {{{ paint_respondent_tools()        Paint a panel of tools available to this respondent

function paint_respondent_tools() {
    $cfg =& $GLOBALS['ESPCONFIG'];

    // figure out what tools to make available
    // ... the standard, always available ones
    $tools  = array (
                  "{$cfg['base_url']}/public/dashboard.php?doHelp=1"   => _('Help'),
                  "{$cfg['base_url']}/public/dashboard.php?doLogout=1" => _('Logout'),
              );

    // ... profile and password changing
    if ($GLOBALS['ESPCONFIG']['dashboard_allow_change_profile']) {
        $tools["{$cfg['base_url']}/public/dashboard.php?doChangeProfile=1"] = _('Change my profile');
    }
    if ($GLOBALS['ESPCONFIG']['dashboard_allow_change_password']) {
        $tools["{$cfg['base_url']}/public/dashboard.php?doChangePassword=1"] = _('Change my password');
    }

    // ... contacting support
    if (! empty($GLOBALS['ESPCONFIG']['support_email_address'])) {
        $tools["mailto:{$GLOBALS['ESPCONFIG']['support_email_address']}"] = _('E-mail support');
    }

    // throw it up
    $header = _('My Tools');
    echo <<<EOHTML
<div class='dashboardPanel' id='my_tools'>
<h1>$header</h1>
<ul>
EOHTML;
    foreach ($tools as $url => $label) {
        printf('<li><a href="%s">%s</a></li>', $url, $label);
    }
    echo <<<EOHTML
</ul>
</div>
EOHTML;
}

// }}}

/* helpers */
// {{{ get_survey_info()               Get the surveys, the responses, and the accessibility of surveys for the current user

function get_survey_info(&$surveys, &$responses, &$accessibility) {
    // initialize return values
    $surveys       = array ();
    $responses     = array ();
    $accessibility = array ();

    // everybody gets the public surveys
    esp_require_once('/lib/espsurvey');
    survey_get_public($surveys);
    $sids = array_keys($surveys);

    // if we have a current (authenticated) respondent
    $ok = get_current_respondent($respondent);
    if ($ok && array_key_exists('realm', $respondent)) {
        // get the surveys available to that user
        survey_get_in_realm($respondent['realm'], $private);
        survey_merge_sets($surveys, $private);
        $sids = array_keys($surveys);

        // get the responses and accessibility for those surveys
        survey_get_responses($responses, $sids, $respondent['username']);
        survey_get_accessibility($accessibility, $sids, $respondent['username'], $respondent['realm']);
    } else {
        // get the accessibility of those surveys
        survey_get_accessibility($accessibility, $sids);
    }

    return true;
}

// }}}
// {{{ partition_surveys()             Divide the user's surveys into those that are active and those that aren't

function partition_surveys($surveys, $responses, $accessibility, &$current, &$historical) {
    foreach ($surveys as $sid => $survey) {
        // if the survey is available
        if (isset($accessibility[$sid]['available']) && true === (bool)$accessibility[$sid]['available']) {
            // get a link to the survey, its status, and the last access date
            $name   = sprintf('<a href="%s">%s</a>', survey_fetch_url_by_survey_name($survey['name']), $survey['title']);
            $status = fetch_status($sid, $responses);
            $date   = fetch_latest_submission_date($sid, $responses);
            $avail  = fetch_availability($survey, $availability);

            // but, if the survey is not open, get rid of the link
            if (STATUS_OPEN !== $availability) {
                $name = $survey['title'];
            }

            $current[] = array ($name, $status, $date, $avail);

        // otherwise the survey is historical
        } else {
            $name   = $survey['title'];
            $status = fetch_status($sid, $responses);
            $date   = fetch_latest_submission_date($sid, $responses);
            $avail  = _('Closed');

            $historical[] = array ($name, $status, $date, $avail);
        }
    }
}

// }}}
// {{{ fetch_status()                  Given a set of responses and a survey ID, determine the status of those responses

function fetch_status($sid, $responses) {
    // get the status
    if (isset($responses[$sid])) {
        // there are responses
        if (isset($responses[$sid]['complete'])) {
            // there is only one response
            $status = ('Y' == $responses[$sid]['complete'] ? STATUS_FINISHED : STATUS_ALL_PARTIAL);
        } else {
            // more than one response
            $status = STATUS_FINISHED;
            foreach ($responses[$sid] as $response) {
                if ('N' == $response['complete']) {
                    $status = STATUS_SOME_PARTIAL;
                }
            }
        }
    } else {
        // no responses made, but since survey is available, this is an incomplete survey
        $status = STATUS_NOT_STARTED;
    }

    return $status;
}

// }}}
// {{{ fetch_latest_submission_date()  Given a set of responses and a survey ID, determine the latest submission date

function fetch_latest_submission_date($sid, $responses) {
    if (isset($responses[$sid])) {
        // there are responses
        if (isset($responses[$sid]['submitted'])) {
            // there is only one response
            $date = $responses[$sid]['submitted'];
        } else {
            // more than one response
            $date = '0000-00-00 00:00:00';
            foreach ($responses[$sid] as $response) {
                if ($date < $response['submitted']) {
                    $date = $response['submitted'];
                }
            }
        }

        // don't need the date down to the second, so just go down to the minute
        $datets = strtotime($date);
        if (-1 !== $datets) {
            $date = strftime(FORMAT_OUTPUT_DATE, $datets);
        }
    } else {
        $date = '';
    }

    return $date;
}

// }}}
// {{{ fetch_availability()            Given a survey, determine its availability

function fetch_availability($survey, &$rc) {
    $rc = survey_open($survey['open_date'], $survey['close_date']);
    switch ($rc) {
    case STATUS_OPEN:
        return _('Now taking submissions');
        break;

    case STATUS_CLOSED_TOO_EARLY:
        return _('Not yet taking submissions');
        break;

    case STATUS_CLOSED_TOO_LATE:
        return _('No longer taking submissions');
        break;

    default:
        assert('false; // unexpected case reached; code bug');
        return '';
    }
}

// }}}

// {{{ render_login_form()             Render a login form

function render_login_form($action = null, $usernameVar = 'username', $passwordVar = 'password', $loginButtonVar = 'doLogin') {
    $cfg =& $GLOBALS['ESPCONFIG'];
    if (empty($action)) {
        $action = $cfg['base_url'] . '/public/dashboard.php';
    }

    $usernameLabel = _('User ID');
    $passwordLabel = _('Password');
    $loginLabel    = _('Login');
    $username      = (isset($_REQUEST['username']) ? $_REQUEST['username'] : '');
    return <<<EOHTML
<form id='login' action='{$action}' method='post'>
<fieldset>
  <div class='row'>
    <label for='{$usernameVar}'>{$usernameLabel}</label>
    <input type='text' name='{$usernameVar}' id='{$usernameVar}' value='{$username}' />
  </div>
  <div class='row'>
    <label for='{$passwordVar}'>{$passwordLabel}</label>
    <input type='password' name='{$passwordVar}' id='{$passwordVar}' />
  </div>
  <div class='buttons'>
    <input type='submit' name='{$loginButtonVar}' value='{$loginLabel}' />
  </div>
</fieldset>
</form>
EOHTML;
}

// }}}
// {{{ render_profile_change_form()    Render a profile change form

function render_profile_change_form(
    $action = null,
    $firstNameVar = 'firstName', $lastNameVar = 'lastName', $emailVar = 'emailAddress',
    $changeButtonVar = 'doChangeProfile', $cancelButtonVar = 'doChangeProfileCancel'
    ) {

    $cfg =& $GLOBALS['ESPCONFIG'];
    if (empty($action)) {
        $action = $cfg['base_url'] . '/public/dashboard.php';
    }

    $firstNameLabel    = _('First Name');
    $lastNameLabel     = _('Last Name');
    $emailAddressLabel = _('Email Address');
    $changeLabel       = _('Change');
    $cancelLabel       = _('Cancel');

    $firstName         = (isset($_REQUEST[$firstNameVar]) ? htmlentities($_REQUEST[$firstNameVar]) : '');
    $lastName          = (isset($_REQUEST[$lastNameVar])  ? htmlentities($_REQUEST[$lastNameVar])  : '');
    $emailAddress      = (isset($_REQUEST[$emailVar])     ? htmlentities($_REQUEST[$emailVar])     : '');
    return <<<EOHTML
<form id='profile_change' action='{$action}' method='post'>
<fieldset>
  <div class='row'>
    <label for='{$firstNameVar}'>{$firstNameLabel}</label>
    <input type='text' name='{$firstNameVar}' id='{$firstNameVar}' value='{$firstName}' />
  </div>
  <div class='row'>
    <label for='{$lastNameVar}'>{$lastNameLabel}</label>
    <input type='text' name='{$lastNameVar}' id='{$lastNameVar}' value='{$lastName}' />
  </div>
  <div class='row'>
    <label for='{$emailVar}'>{$emailAddressLabel}</label>
    <input type='text' name='{$emailVar}' id='{$emailVar}' value='{$emailAddress}' />
  </div>
  <div class='buttons'>
    <input type='submit' name='{$changeButtonVar}' value='{$changeLabel}' />
    <input type='submit' name='{$cancelButtonVar}' value='{$cancelLabel}' />
  </div>
</fieldset>
</form>
EOHTML;
}

// }}}
// {{{ render_passwd_change_form()     Render a password change form

function render_passwd_change_form(
    $action = null,
    $oldPasswordVar = 'oldPassword', $newPasswordVar = 'newPassword', $newPasswordConfirmVar = 'newPasswordConfirm',
    $changeButtonVar = 'doChangePassword', $cancelButtonVar = 'doChangePasswordCancel'
    ) {

    $cfg =& $GLOBALS['ESPCONFIG'];
    if (empty($action)) {
        $action = $cfg['base_url'] . '/public/dashboard.php';
    }

    $oldPasswordLabel        = _('Old Password');
    $newPasswordLabel        = _('New Password');
    $newPasswordConfirmLabel = _('Confirm New Password');
    $changeLabel             = _('Change');
    $cancelLabel             = _('Cancel');
    return <<<EOHTML
<form id='passwd_change' action='{$action}' method='post'>
<fieldset>
  <div class='row'>
    <label for='{$oldPasswordVar}'>{$oldPasswordLabel}</label>
    <input type='password' name='{$oldPasswordVar}' id='{$oldPasswordVar}' />
  </div>
  <div class='row'>
    <label for='{$newPasswordVar}'>{$newPasswordLabel}</label>
    <input type='password' name='{$newPasswordVar}' id='{$newPasswordVar}' />
  </div>
  <div class='row'>
    <label for='{$newPasswordConfirmVar}'>{$newPasswordConfirmLabel}</label>
    <input type='password' name='{$newPasswordConfirmVar}' id='{$newPasswordConfirmVar}' />
  </div>
  <div class='buttons'>
    <input type='submit' name='{$changeButtonVar}' value='{$changeLabel}' />
    <input type='submit' name='{$cancelButtonVar}' value='{$cancelLabel}' />
  </div>
</fieldset>
</form>
EOHTML;
}

// }}}

?>
