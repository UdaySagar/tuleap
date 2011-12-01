<?php
/**
 * Copyright (c) Xerox Corporation, Codendi Team, 2001-2009. All rights reserved
 *
 * This file is a part of Codendi.
 *
 * Codendi is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Codendi is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Codendi. If not, see <http://www.gnu.org/licenses/>.
 */

require_once('dao/Tracker_Artifact_Changeset_ValueDao.class.php');
require_once('dao/Tracker_Artifact_Changeset_CommentDao.class.php');
require_once('Tracker_Artifact_Changeset_Comment.class.php');
require_once(dirname(__FILE__).'/../FormElement/Tracker_FormElementFactory.class.php');
require_once(dirname(__FILE__).'/../Tracker_NotificationsManager.class.php');
require_once('common/date/DateHelper.class.php');
require_once('common/include/Config.class.php');
require_once('common/mail/MailManager.class.php');

class Tracker_Artifact_Changeset {
    public $id;
    public $artifact;
    public $submitted_by;
    public $submitted_on;
    public $email;

    protected $values;

    /**
     * Constructor
     *
     * @param int              $id           The changeset Id
     * @param Tracker_Artifact $artifact     The artifact
     * @param int              $submitted_by The id of the owner of this changeset
     * @param int              $submitted_on The timestamp
     * @param string           $email        The email of the submitter if anonymous mode
     */
    public function __construct($id, $artifact, $submitted_by, $submitted_on, $email) {
        $this->id           = $id;
        $this->artifact     = $artifact;
        $this->submitted_by = $submitted_by;
        $this->submitted_on = $submitted_on;
        $this->email        = $email;
    }


    /**
     * Return the value of a field in the current changeset
     *
     * @param Tracker_FormElement_Field $field The field
     *
     * @return Tracker_Artifact_ChangesetValue, or null if not found
     */
    public function getValue(Tracker_FormElement_Field $field) {
        $values = $this->getValues();
        if (isset($values[$field->getId()])) {
            return $values[$field->getId()];
        }
        return null;
    }

    /**
     * Returns the submission date of this changeset (timestamp)
     *
     * @return int The submission date of this changeset (timestamp)
     */
    public function getSubmittedOn() {
        return $this->submitted_on;
    }

    /**
     * Returns the author of this changeset
     *
     * @return int The user id or 0/null if anonymous
     */
    public function getSubmittedBy() {
        return $this->submitted_by;
    }

    /**
     * Returns the author's email of this changeset
     *
     * @return string an email
     */
    public function getEmail() {
        return $this->email;
    }

    /**
     * Return the changeset values of this changeset
     *
     * @return array of Tracker_Artifact_ChangesetValue, or empty array if not found
     */
    public function getValues() {
        if (!$this->values) {
            $this->values = array();
            $factory = $this->getFormElementFactory();
            foreach ($this->getValueDao()->searchById($this->id) as $row) {
                if ($field = $factory->getFieldById($row['field_id'])) {
                    $this->values[$field->getId()] = $field->getChangesetValue($this, $row['id'], $row['has_changed']);
                }
            }
        }
        return $this->values;
    }

    /**
     * Returns the ValueDao
     *
     * @return Tracker_Artifact_Changeset_ValueDao The dao
     */
    protected function getValueDao() {
        return new Tracker_Artifact_Changeset_ValueDao();
    }

    /**
     * Returns the Form Element Factory
     *
     * @return Tracker_FormElementFactory The factory
     */
    protected function getFormElementFactory() {
        return Tracker_FormElementFactory::instance();
    }


    /**
     * fetch followup
     *
     * @param Tracker_Artifact_Changeset $previous_changeset The previous changeset
     *
     * @return string
     */
    public function fetchFollowUp($previous_changeset) {
        $html = '';

        $html .= '<div class="tracker_artifact_followup_header">';
        $html .= '<div class="tracker_artifact_followup_title">';
        //The permalink
        $html .= '<a href="#followup_'. $this->id .'">';
        $html .= $GLOBALS['HTML']->getImage(
            'ic/comment.png', array(
                                'border' => 0,
                                'alt'   => 'permalink',
                                'class' => 'tracker_artifact_followup_permalink',
                                'style' => 'vertical-align:middle',
                                'title' => 'Link to this followup - #'. (int)$this->id
                              )
        );
        $html .= '</a> ';

        //The submitter
        if ($this->submitted_by) {
            $uh = UserHelper::instance();
            $submitter = $uh->getLinkOnUserFromUserId($this->submitted_by);
        } else {
            $hp = Codendi_HTMLPurifier::instance();
            $submitter = $hp->purify($this->email, CODENDI_PURIFIER_BASIC);
        }
        $html .= '<span class="tracker_artifact_followup_title_user">'. $submitter .'</span>';

        $html .= '</div>';

        //The date
        $html .= '<div class="tracker_artifact_followup_date">';
        $html .= DateHelper::timeAgoInWords($this->submitted_on, false, true);
        $html .= '</div>';

        $html .= '</div>';
        
        if (Config::get('sys_enable_avatars')) {
            if ($this->submitted_by) {
                $submitter = UserManager::instance()->getUserById($this->submitted_by);
            } else {
                $submitter = UserManager::instance()->getUserAnonymous();
                $submitter->setEmail($this->email);
            }
            
            $html .= '<div class="tracker_artifact_followup_avatar">';
            $html .= $submitter->fetchHtmlAvatar();
            $html .= '</div>';
        }
        // The content
        $html .= '<div class="tracker_artifact_followup_content">';
        //The comment
        if ($comment = $this->getComment()) {
            if ($this->userCanEdit() ||$this->userCanDelete()) {
                $html .= '<div class="tracker_artifact_followup_comment_controls">';
                //edit
                if ($this->userCanEdit()) {
                    $html .= '<a href="#" class="tracker_artifact_followup_comment_controls_edit">';
                    $html .= $GLOBALS['HTML']->getImage('ic/edit.png', array('border' => 0, 'alt' => $GLOBALS['Language']->getText('plugin_tracker_fieldeditor', 'edit')));
                    $html .= '</a>';
                }

                //delete
                //   We can't delete a snapshot since there is too many repercusion on subsequent changesets
                //   If the deletion is for combatting spam, then edit and empty the comment
                //   What would be nice is to make userCanDelete() return true only if the user has rights and the snapshot doesn't contain any changes
                //if ($this->userCanDelete()) {
                //    $html .= '<a href="?'. http_build_query(
                //        array(
                //            'aid'       => $this->artifact->id,
                //            'func'      => 'artifact-delete-changeset',
                //            'changeset' => $this->id,
                //        )
                //    ).'" class="tracker_artifact_followup_comment_controls_close">';
                //    $html .= $GLOBALS['HTML']->getImage('ic/bin_closed.png', array('border' => 0, 'alt' => $GLOBALS['Language']->getText('plugin_tracker_include_artifact', 'del')));
                //    $html .= '</a>';
                //}
                $html .= '</div>';
            }

            $html .= '<div class="tracker_artifact_followup_comment">';
            $html .= $comment->fetchFollowUp();
            $html .= '</div>';
        }

        //The changes
        if ($changes = $this->diffToPrevious()) {
            $html .= '<hr size="1" />';
            $html .= '<ul class="tracker_artifact_followup_changes">';
            $html .= $changes;
            $html .= '</ul>';
        }
        $html .= '</div>';

        $html .= '<div style="clear:both;"></div>';
        return $html;
    }

    /**
     * Say if a user can delete a changeset
     *
     * @param User $user The user. If null, the current logged in user will be used.
     *
     * @return boolean true if the user can delete
     */
    protected function userCanDelete(User $user = null) {
        if (!$user) {
            $user = $this->getUserManager()->getCurrentUser();
        }
        // Only super user can edit a comment
        return $user->isSuperUser();
    }

    /**
     * Say if a user can edit a comment
     *
     * @param User $user The user. If null, the current logged in user will be used.
     *
     * @return boolean true if the user can edit
     */
    public function userCanEdit(User $user = null) {
        if (!$user) {
            $user = $this->getUserManager()->getCurrentUser();
        }
        // Only tracker admin and original submitter (minus anonymous) can edit a comment
        return $this->artifact->getTracker()->userIsAdmin($user) || ((int)$this->submitted_by && $user->getId() == $this->submitted_by);
    }

    /**
     * Update the content
     *
     * @param string $body The new content
     * @param User   $user The user
     *
     * @return void
     */
    public function updateComment($body, $user) {
        if ($this->userCanEdit($user)) {
            $this->getCommentDao()->createNewVersion($this->id, $body, $user->getId(), $this->getComment()->id);
        }
    }

    /**
     * Delete the changeset
     *
     * @param User $user the user who wants to delete the changeset
     *
     * @return void
     */
    public function delete(User $user) {
        if ($this->userCanDelete($user)) {
            $this->getChangesetDao()->delete($this->id);
            $this->getCommentDao()->delete($this->id);
            $this->getValueDao()->delete($this->id);
            //todo go deeper in the deletion cascade
        }
    }

    /**
     * Get the comment (latest version)
     *
     * @return Tracker_Artifact_Changeset_Comment The comment of this changeset, or null if no comments
     */
    public function getComment() {
        $comment = null;
        if ($row = $this->getCommentDao()->searchLastVersion($this->id)->getRow()) {
            $comment = new Tracker_Artifact_Changeset_Comment($row['id'],
                                                    $this,
                                                    $row['comment_type_id'],
                                                    $row['canned_response_id'],
                                                    $row['submitted_by'],
                                                    $row['submitted_on'],
                                                    $row['body'],
                                                    $row['parent_id']);
        }
        return $comment;
    }

    /**
     * Return the ChangesetDao
     *
     * @return Tracker_Artifact_ChangesetDao The Dao
     */
    protected function getChangesetDao() {
        return new Tracker_Artifact_ChangesetDao();
    }

    /**
     * Returns the comment dao
     *
     * @return Tracker_Artifact_ChangesetCommentDao The dao
     */
    protected function getCommentDao() {
        return new Tracker_Artifact_Changeset_CommentDao();
    }

    /**
     * Returns true if there are changes in fields_data regarding this changeset, false if nothing has changed
     *
     * @param array $fields_data The data submitted (array of 'field_id' => 'value')
     *
     * @return boolean true if there are changes in fields_data regarding this changeset, false if nothing has changed
     */
    public function hasChanges($fields_data) {
        $has_changes = false;
        $used_fields = $this->getFormElementFactory()->getUsedFields($this->artifact->getTracker());
        reset($used_fields);
        while (!$has_changes && (list(,$field) = each($used_fields))) {
            if (!is_a($field, 'Tracker_FormElement_Field_ReadOnly')) {
                if (isset($fields_data[$field->id])) {
                    if ($current_value = $this->getValue($field)) {
                        $has_changes = $field->hasChanges($current_value, $fields_data[$field->id]);
                    } else {
                        //There is no current value in the changeset for the submitted field
                        //It means that the field has been added afterwards.
                        //Then consider that there is at least one change (the value of the new field).
                        $has_changes = true;
                    }
                }
            }
        }
        return $has_changes;
    }

    /**
     * Return diff between this changeset and previous one (HTML code)
     *
     * @return string The field difference between the previous changeset. or false if no changes
     */
    public function diffToPrevious($format='html', $user=null, $ignore_perms=false) {
        if ($previous_changeset = $this->getArtifact()->getPreviousChangeset($this->getId())) {
            $result = false;
            $factory = $this->getFormElementFactory();
            foreach ($this->getValues() as $field_id => $current_changeset_value) {
                if ($field = $factory->getFieldById($field_id)) {
                    if ( ( $ignore_perms || $field->userCanRead($user) ) && $current_changeset_value) {
                        if ($current_changeset_value->hasChanged()) {
                            if ($previous_changeset_value = $previous_changeset->getValue($field)) {
                                if ($diff = $current_changeset_value->diff($previous_changeset_value, $format)) {
                                    $result .= $this->displayDiff($diff, $format, $field);
                                }
                            } else {
                                //Case : field added later (ie : artifact already exists) => no value
                                if ($diff = $current_changeset_value->nodiff()) {
                                    $result .= $this->displayDiff($diff, $format, $field).PHP_EOL;
                                }
                            }
                        }
                    }
                }
            }
            return $result;
        }
        return false;
    }
    
    /**
    * Display diff messsage
    *
    * @param String $diff
    *
    */
    public function displayDiff($diff, $format, $field) {
        $result = false;
        switch($format) {
            case 'html':
                $result .= '<li>'.PHP_EOL;
                $result .= '<span class="tracker_artifact_followup_changes_field">'. $field->getLabel() .'</span> '.PHP_EOL;
                $result .= '<span class="tracker_artifact_followup_changes_changes">'. $diff .'</span>'.PHP_EOL;
                $result .= '</li>'.PHP_EOL;
            break;
            default://text
                $result .= ' * '.$field->getLabel().' : '.PHP_EOL;
                $result .= $diff.PHP_EOL;
            break;
        }
        return $result;
    }

    /**
     * Get an instance of UserManager
     *
     * @return UserManager
     */
    public function getUserManager() {
        return UserManager::instance();
    }
    
    public function getTracker() {
        return $this->artifact->getTracker();
    }
    
    /**
     * notify people
     *
     * @return void
     */
    public function notify() {
        $tracker = $this->getTracker();
        if ( ! $tracker->isNotificationStopped()) {
            $factory = $this->getFormElementFactory();
            
            // 0. Is update
            $is_update = ! $this->getArtifact()->isFirstChangeset($this);
    
            // 1. Get the recipients list
            $recipients = $this->getRecipients($is_update);
            // 2. Compute the body of the message + headers
            $messages = array();
            $um = $this->getUserManager();
            foreach ($recipients as $recipient => $check_perms) {
                $user = null;
                if ( strpos($recipient, '@') !== false ) {
                    //check for registered
                    $user = $um->getUserByEmail($recipient);
                    
                    //user does not exist (not registered/mailing list) then it is considered as an anonymous
                    if ( ! $user ) {
                        // don't call $um->getUserAnonymous() as it will always return the same instance
                        // we don't want to override previous emails
                        // So create new anonymous instance by hand
                        $user = $um->getUserInstanceFromRow(
                            array(
                                'user_id' => 0,
                                'email'   => $recipient,
                            )
                        );
                    }
                } else {
                    //is a login
                    $user = $um->getUserByUserName($recipient);
                }
                if ($user) {
                    $ignore_perms = ! $check_perms;
                    $this->buildMessage($messages, $is_update, $user, $ignore_perms);
                }
            }
    
            // 3. Send the notification
            foreach ($messages as $m) {
                $this->sendNotification(
                    $m['recipients'],
                    $m['headers'],
                    $m['subject'],
                    $m['htmlBody'],
                    $m['txtBody']
                );
            }
        }
    }

    protected function buildMessage(&$messages, $is_update, $user, $ignore_perms) {
        $recipient = $user->getEmail();
        // TODO : get the mail format from user preferences $user->getPreference('user_tracker_mailformat')
        //We send multipart mail: html & text body
        $htmlBody     = $this->getBody($is_update, $user, $ignore_perms, 'html');
        $txtBody      = $this->getBody($is_update, $user, $ignore_perms, 'text');

        $subject   = $this->getSubject($user, $ignore_perms);
        $headers   = array(); // TODO
        $hash = md5($htmlBody . $txtBody . serialize($headers) . serialize($subject));
        if (isset($messages[$hash])) {
            $messages[$hash]['recipients'][] = $recipient;
        } else {
            $messages[$hash] = array(
                    'headers'    => $headers,
                    'htmlBody'   => $htmlBody,
                    'txtBody'    => $txtBody,
                    'subject'    => $subject,
                    'recipients' => array($recipient),
            );
        }
    }
    /**
     * Send a notification
     *
     * @param array  $recipients the list of recipients
     * @param array  $headers    the additional headers
     * @param string $subject    the content of the message
     * @param string $body       the content of the message
     *
     * @return void
     */
    protected function sendNotification($recipients, $headers, $subject, $htmlBody, $txtBody) {
        $mail = new Codendi_Mail();
        $hp = Codendi_HTMLPurifier::instance();
        $breadcrumbs = array();
        $groupId = $this->getTracker()->getGroupId();
        $project = $this->getTracker()->getProject();
        $trackerId = $this->getTracker()->getID();
        $artifactId = $this->getArtifact()->getID();

        $breadcrumbs[] = '<a href="'. get_server_url() .'/projects/'. $project->getUnixName(true) .'" />'. $project->getPublicName() .'</a>';
        $breadcrumbs[] = '<a href="'. get_server_url() .'/plugins/tracker/?tracker='. (int)$trackerId .'" />'. $hp->purify(SimpleSanitizer::unsanitize($this->getTracker()->getName())) .'</a>';
        $breadcrumbs[] = '<a href="'. get_server_url().'/plugins/tracker/?aid='.(int)$artifactId.'" />'. $hp->purify($this->getTracker()->getName().' #'.$artifactId) .'</a>';

        $mail->getLookAndFeelTemplate()->set('breadcrumbs', $breadcrumbs);
        $mail->setFrom($GLOBALS['sys_noreply']);
        $mail->addAdditionalHeader("X-Codendi-Project",     $this->getArtifact()->getTracker()->getProject()->getUnixName());
        $mail->addAdditionalHeader("X-Codendi-Tracker",     $this->getArtifact()->getTracker()->getItemName());
        $mail->addAdditionalHeader("X-Codendi-Artifact-ID", $this->getId());
        foreach($headers as $header) {
            $mail->addAdditionalHeader($header['name'], $header['value']);
        }
        $mail->setTo(implode(', ', $recipients));
        $mail->setSubject($subject);
        $mail->setBodyHTML($htmlBody);
        $mail->setBodyText($txtBody);
        $mail->send();
    }

    /**
     * Get the recipients for notification
     *
     * @param bool $is_update It is an update, not a new artifact
     *
     * @return array
     */
    public function getRecipients($is_update) {
        $factory = $this->getFormElementFactory();

        // 0 Is update
        $is_update = ! $this->getArtifact()->isFirstChangeset($this);

        // 1 Get from the fields
        $recipients = array();
        foreach ($this->getValues() as $field_id => $current_changeset_value) {
            if ($field = $factory->getFieldById($field_id)) {
                if ($field->isNotificationsSupported() && $field->hasNotifications() && ($r = $field->getRecipients($current_changeset_value))) {
                    $recipients = array_merge($recipients, $r);
                }
            }
        }
        // 2 Get from the commentators
        $recipients = array_merge($recipients, $this->getArtifact()->getCommentators());
        $recipients = array_values(array_unique($recipients));
        
        //now force check perms for all this people
        $tablo = array();
        foreach($recipients as $r) {
            $tablo[$r] = true;
        }
        
        // 3 Get from the global notif
        foreach ($this->getArtifact()->getTracker()->getRecipients() as $r) {
            if ( $r['on_updates'] == 1 || !$is_update ) {
                foreach($r['recipients'] as $recipient) {
                    $tablo[$recipient] = $r['check_permissions'];
                }
            }
        }
        return $tablo;
    }

    /**
     * Get the body for notification
     *
     * @param Boolean $is_update    It is an update, not a new artifact
     * @param String  $recipient    The recipient who will receive the notification
     * @param Boolean $ignore_perms ???
     * @param String  $format       Format of the mail 'text' or 'html'
     *
     * @return String
     */
    public function getBody($is_update, $recipient_user, $ignore_perms = false, $format = 'html') {
        $art = $this->getArtifact();
        if ($format == 'text') {
            $um = $this->getUserManager();
            $user = $um->getUserById($this->submitted_by);

            $output = '+============== '.'['.$art->getTracker()->getItemName() .' #'. $art->getId().'] '.$art->fetchMailTitle($recipient_user, $format, $ignore_perms).' ==============+';
            $output .= PHP_EOL;
            $output .= PHP_EOL;
            $proto = ($GLOBALS['sys_force_ssl']) ? 'https' : 'http';
            $output .= ' <'. $proto .'://'. $GLOBALS['sys_default_domain'] .TRACKER_BASE_URL.'/?aid='. $art->getId() .'>';
            $output .= PHP_EOL;
            $output .= $GLOBALS['Language']->getText('plugin_tracker_include_artifact', 'last_edited');
            $output .= ' '.UserHelper::instance()->getDisplayNameFromUserId($this->submitted_by);
            $output .= ' on '.util_timestamp_to_userdateformat($this->submitted_on);
            if ( $comment = $this->getComment() ) {
                $output .= PHP_EOL;
                $output .= $comment->fetchFollowUp($format);
            }
            $output .= PHP_EOL;
            $output .= ' -------------- ' . $GLOBALS['Language']->getText('plugin_tracker_artifact_changeset', 'header_changeset') . ' ---------------- ' ;
            $output .= PHP_EOL;
            $output .= $this->diffToPrevious($format, $recipient_user, $ignore_perms);
            $output .= PHP_EOL;
            $output .= ' -------------- ' . $GLOBALS['Language']->getText('plugin_tracker_artifact_changeset', 'header_artifact') . ' ---------------- ';
            $output .= PHP_EOL;
            $output .= $art->fetchMail($recipient_user, $format, $ignore_perms);
            $output .= PHP_EOL;
        } else {
            $output ='<h1>'.$art->fetchMailTitle($recipient_user, $format, $ignore_perms).'</h1>'.PHP_EOL;
            // Display latest changes (diff)
            if ($comment = $this->getComment()) {
                $comment = $comment->fetchFollowUp($format, true, true);
            }
            $changes = $this->diffToPrevious($format, $recipient_user, $ignore_perms);
            if ($comment || $changes) {
                $output .= '<h2>'.$GLOBALS['Language']->getText('plugin_tracker_artifact_changeset', 'header_html_changeset').'</h2>';
                $output .= '<div class="tracker_artifact_followup_header">';
                // Last comment
                if ($comment) {
                    $output .= $comment.PHP_EOL;
                }
                // Last changes
                if ($changes) {
                    $output .= '<hr size="1" />';
                    $output .= '<ul class="tracker_artifact_followup_changes">';
                    $output .= $changes;
                    $output .= '</ul>';
                }
                $output .= '</div>'.PHP_EOL;
            }

            //Display of snapshot
            $snapshot = $art->fetchMail($recipient_user, $format, $ignore_perms);
            if ($snapshot) {
                $output .= '<hr size="1" />';
                $output .= $snapshot;
            }
        }
        return $output;
    }

    /**
     * Get the subject for notification
     *
     * @param string $recipient The recipient who will receive the notification
     *
     * @return string
     */
    public function getSubject($recipient, $ignore_perms=false) {
        //TODO check permission on title
        $s = '';
        $s .= '['. $this->getArtifact()->getTracker()->getItemName() .' #'. $this->getArtifact()->getId() .'] '.$this->getArtifact()->fetchMailTitle($recipient, 'text' ,$ignore_perms);
        return $s;
    }

    /**
     * Return the Tracker_Artifact of this changeset
     *
     * @return Tracker_Artifact The artifact of this changeset
     */
    function getArtifact() {
        return $this->artifact;
    }

    /**
     * Returns the Id of this changeset
     *
     * @return int The Id of this changeset
     */
    public function getId() {
        return $this->id;
    }
}
?>
