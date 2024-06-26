<?php
/*
	Question2Answer by Gideon Greenspan and contributors
	http://www.question2answer.org/

	Description: Changing questions, answer and comments (application level)


	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	More about this license: http://www.question2answer.org/license.php
*/

if (!defined('QA_VERSION')) { // don't allow this page to be requested directly from browser
	header('Location: ../../');
	exit;
}

require_once QA_INCLUDE_DIR . 'app/post-create.php';
require_once QA_INCLUDE_DIR . 'app/updates.php';
require_once QA_INCLUDE_DIR . 'db/post-create.php';
require_once QA_INCLUDE_DIR . 'db/post-update.php';
require_once QA_INCLUDE_DIR . 'db/points.php';
require_once QA_INCLUDE_DIR . 'db/hotness.php';


define('QA_POST_STATUS_NORMAL', 0);
define('QA_POST_STATUS_HIDDEN', 1);
define('QA_POST_STATUS_QUEUED', 2);


/**
 * Change the fields of a question (application level) to $title, $content, $format, $tagstring, $notify, $extravalue
 * and $name, then reindex based on $text. For backwards compatibility if $name is null then the name will not be
 * changed. Pass the question's database record before changes in $oldquestion and details of the user doing this in
 * $userid, $handle and $cookieid. Set $remoderate to true if the question should be requeued for moderation if
 * modified. Set $silent to true to not mark the question as edited. Reports event as appropriate. See /qa-include/app/posts.php
 * for a higher-level function which is easier to use.
 * @param array $oldquestion
 * @param string $title
 * @param string $content
 * @param string $format
 * @param string $text
 * @param string $tagstring
 * @param bool $notify
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param string|null $extravalue
 * @param string|null $name
 * @param bool $remoderate
 * @param bool $silent
 */
function qa_question_set_content($oldquestion, $title, $content, $format, $text, $tagstring, $notify, $userid, $handle, $cookieid, $extravalue = null, $name = null, $remoderate = false, $silent = false)
{
	qa_post_unindex($oldquestion['postid']);

	$wasqueued = ($oldquestion['type'] == 'Q_QUEUED');
	$titlechanged = strcmp($oldquestion['title'], $title) !== 0;
	$contentchanged = strcmp($oldquestion['content'], $content) !== 0 || strcmp($oldquestion['format'], $format) !== 0;
	$tagschanged = strcmp($oldquestion['tags'], $tagstring) !== 0;
	$setupdated = ($titlechanged || $contentchanged || $tagschanged) && (!$wasqueued) && !$silent;

	qa_db_post_set_content($oldquestion['postid'], $title, $content, $format, $tagstring, $notify,
		$setupdated ? $userid : null, $setupdated ? qa_remote_ip_address() : null,
		($titlechanged || $contentchanged) ? QA_UPDATE_CONTENT : QA_UPDATE_TAGS, $name);

	if (isset($extravalue)) {
		require_once QA_INCLUDE_DIR . 'db/metas.php';
		qa_db_postmeta_set($oldquestion['postid'], 'qa_q_extra', $extravalue);
	}

	if ($setupdated && $remoderate) {
		require_once QA_INCLUDE_DIR . 'app/posts.php';

		$answers = qa_post_get_question_answers($oldquestion['postid']);
		$commentsfollows = qa_post_get_question_commentsfollows($oldquestion['postid']);
		$closepost = qa_post_get_question_closepost($oldquestion['postid']);

		foreach ($answers as $answer)
			qa_post_unindex($answer['postid']);

		foreach ($commentsfollows as $comment) {
			if ($comment['basetype'] == 'C')
				qa_post_unindex($comment['postid']);
		}

		if (isset($closepost) && $closepost['parentid'] == $oldquestion['postid'])
			qa_post_unindex($closepost['postid']);

		qa_db_post_set_type($oldquestion['postid'], 'Q_QUEUED');

		qa_private_question_cache_recalc($oldquestion, -1);

		qa_db_queuedcount_update(1);
		qa_db_points_update_ifuser($oldquestion['userid'], array('qposts', 'aselects'));

		if ($oldquestion['flagcount'] > 0) {
			qa_db_flaggedcount_update(-$oldquestion['flagcount']);
		}
	} elseif ($oldquestion['type'] == 'Q') { // not hidden or queued
		qa_post_index($oldquestion['postid'], 'Q', $oldquestion['postid'], $oldquestion['parentid'], $title, $content, $format, $text, $tagstring, $oldquestion['categoryid']);
		if ($tagschanged) {
			qa_db_tagcount_update();
		}
	}

	qa_question_uncache($oldquestion['postid']);

	$eventparams = array(
		'postid' => $oldquestion['postid'],
		'title' => $title,
		'content' => $content,
		'format' => $format,
		'text' => $text,
		'tags' => $tagstring,
		'extra' => $extravalue,
		'name' => $name,
		'oldquestion' => $oldquestion,
	);

	qa_report_event('q_edit', $userid, $handle, $cookieid, $eventparams + array(
		'silent' => $silent,
		'oldtitle' => $oldquestion['title'],
		'oldcontent' => $oldquestion['content'],
		'oldformat' => $oldquestion['format'],
		'oldtags' => $oldquestion['tags'],
		'titlechanged' => $titlechanged,
		'contentchanged' => $contentchanged,
		'tagschanged' => $tagschanged,
	));

	if ($setupdated && $remoderate)
		qa_report_event('q_requeue', $userid, $handle, $cookieid, $eventparams);
}


/**
 * Set the selected answer (application level) of $oldquestion to $selchildid. Pass details of the user doing this
 * in $userid, $handle and $cookieid, and the database records for the selected and deselected answers in $answers.
 * Handles user points values and notifications.
 * See /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $oldquestion
 * @param int|null $selchildid
 * @param array $answers
 */
function qa_question_set_selchildid($userid, $handle, $cookieid, $oldquestion, $selchildid, $answers)
{
	$oldselchildid = $oldquestion['selchildid'];

	$lastip = qa_remote_ip_address();

	qa_db_post_set_selchildid($oldquestion['postid'], isset($selchildid) ? $selchildid : null, $userid, $lastip);

	qa_db_points_update_ifuser($oldquestion['userid'], 'aselects');

	if (isset($oldselchildid) && isset($answers[$oldselchildid])) {
		// Update caches
		qa_db_unselqcount_update(1);

		qa_db_points_update_ifuser($answers[$oldselchildid]['userid'], 'aselecteds');

		qa_report_event('a_unselect', $userid, $handle, $cookieid, array(
			'parentid' => $oldquestion['postid'],
			'parent' => $oldquestion,
			'postid' => $oldselchildid,
			'answer' => $answers[$oldselchildid],
		));

		if (!empty($oldquestion['closed']) && empty($oldquestion['closedbyid'])) {
			qa_db_post_set_closed($oldquestion['postid'], null, $userid, $lastip);

			qa_report_event('q_reopen', $userid, $handle, $cookieid, array(
				'postid' => $oldquestion['postid'],
				'oldquestion' => $oldquestion,
			));
		}
	}

	if (isset($selchildid)) {
		// Update caches
		qa_db_unselqcount_update(-1);

		qa_db_points_update_ifuser($answers[$selchildid]['userid'], 'aselecteds');

		qa_report_event('a_select', $userid, $handle, $cookieid, array(
			'parentid' => $oldquestion['postid'],
			'parent' => $oldquestion,
			'postid' => $selchildid,
			'answer' => $answers[$selchildid],
		));

		if (empty($oldquestion['closed']) && qa_opt('do_close_on_select')) {
			qa_db_post_set_closed($oldquestion['postid'], null, $userid, $lastip);

			qa_report_event('q_close', $userid, $handle, $cookieid, array(
				'postid' => $oldquestion['postid'],
				'oldquestion' => $oldquestion,
				'reason' => 'answer-selected',
				'originalid' => $answers[$selchildid],
			));
		}
	}
}


/**
 * Reopen $oldquestion if it was closed. Pass details of the user doing this in $userid, $handle and $cookieid, and the
 * $oldclosepost (to match $oldquestion['closedbyid']) if any.
 * See /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldquestion
 * @param array|null $oldclosepost
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 */
function qa_question_close_clear($oldquestion, $oldclosepost, $userid, $handle, $cookieid)
{
	if (isset($oldquestion['closedbyid'])) {
		qa_db_post_set_closed($oldquestion['postid'], null, $userid, qa_remote_ip_address());

		if (isset($oldclosepost) && ($oldclosepost['parentid'] == $oldquestion['postid'])) {
			qa_post_unindex($oldclosepost['postid']);
			qa_db_post_delete($oldclosepost['postid']);
		}

		qa_private_question_basic_cache_recalc($oldquestion, 1);

		qa_report_event('q_reopen', $userid, $handle, $cookieid, array(
			'postid' => $oldquestion['postid'],
			'oldquestion' => $oldquestion,
		));
	}
}


/**
 * Close $oldquestion as a duplicate of the question with id $originalpostid. Pass details of the user doing this in
 * $userid, $handle and $cookieid, and the $oldclosepost (to match $oldquestion['closedbyid']) if any. See
 * /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldquestion
 * @param array $oldclosepost
 * @param int $originalpostid
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 */
function qa_question_close_duplicate($oldquestion, $oldclosepost, $originalpostid, $userid, $handle, $cookieid)
{
	qa_question_close_clear($oldquestion, $oldclosepost, $userid, $handle, $cookieid);

	qa_db_post_set_closed($oldquestion['postid'], $originalpostid, $userid, qa_remote_ip_address());

	// Update caches
	qa_private_question_basic_cache_recalc($oldquestion, -1);

	qa_report_event('q_close', $userid, $handle, $cookieid, array(
		'postid' => $oldquestion['postid'],
		'oldquestion' => $oldquestion,
		'reason' => 'duplicate',
		'originalid' => $originalpostid,
	));
}


/**
 * Close $oldquestion with the reason given in $note. Pass details of the user doing this in $userid, $handle and
 * $cookieid, and the $oldclosepost (to match $oldquestion['closedbyid']) if any.
 * See /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldquestion
 * @param array $oldclosepost
 * @param string $note
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 */
function qa_question_close_other($oldquestion, $oldclosepost, $note, $userid, $handle, $cookieid)
{
	qa_question_close_clear($oldquestion, $oldclosepost, $userid, $handle, $cookieid);

	$postid = qa_db_post_create('NOTE', $oldquestion['postid'], $userid, isset($userid) ? null : $cookieid,
		qa_remote_ip_address(), null, $note, '', null, null, $oldquestion['categoryid']);

	qa_db_posts_calc_category_path($postid);

	if ($oldquestion['type'] == 'Q')
		qa_post_index($postid, 'NOTE', $oldquestion['postid'], $oldquestion['postid'], null, $note, '', $note, null, $oldquestion['categoryid']);

	qa_db_post_set_closed($oldquestion['postid'], $postid, $userid, qa_remote_ip_address());

	// Update caches
	qa_private_question_basic_cache_recalc($oldquestion, -1);

	qa_report_event('q_close', $userid, $handle, $cookieid, array(
		'postid' => $oldquestion['postid'],
		'oldquestion' => $oldquestion,
		'reason' => 'other',
		'note' => $note,
	));
}


/**
 * Set $oldquestion to hidden if $hidden is true, visible/normal if otherwise. All other parameters are as for
 * qa_question_set_status(...)
 * @deprecated Replaced by qa_question_set_status.
 * @param array $oldquestion
 * @param bool $hidden
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $answers
 * @param array $commentsfollows
 * @param int|null $closepost
 */
function qa_question_set_hidden($oldquestion, $hidden, $userid, $handle, $cookieid, $answers, $commentsfollows, $closepost = null)
{
	qa_question_set_status($oldquestion, $hidden ? QA_POST_STATUS_HIDDEN : QA_POST_STATUS_NORMAL, $userid, $handle, $cookieid, $answers, $commentsfollows, $closepost);
}


/**
 * Set the status (application level) of $oldquestion to $status, one of the QA_POST_STATUS_* constants above. Pass
 * details of the user doing this in $userid, $handle and $cookieid, the database records for all answers to the
 * question in $answers, the database records for all comments on the question or the question's answers in
 * $commentsfollows ($commentsfollows can also contain records for follow-on questions which are ignored), and
 * $closepost to match $oldquestion['closedbyid'] (if any). Handles indexing, user points, cached counts and event
 * reports. See /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldquestion
 * @param int $status
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $answers
 * @param array $commentsfollows
 * @param array|null $closepost
 */
function qa_question_set_status($oldquestion, $status, $userid, $handle, $cookieid, $answers, $commentsfollows, $closepost = null)
{
	require_once QA_INCLUDE_DIR . 'app/format.php';
	require_once QA_INCLUDE_DIR . 'app/updates.php';

	$washidden = ($oldquestion['type'] == 'Q_HIDDEN');
	$wasqueued = ($oldquestion['type'] == 'Q_QUEUED');
	$wasrequeued = $wasqueued && isset($oldquestion['updated']);

	qa_post_unindex($oldquestion['postid']);

	foreach ($answers as $answer) {
		qa_post_unindex($answer['postid']);
	}

	foreach ($commentsfollows as $comment) {
		if ($comment['basetype'] == 'C')
			qa_post_unindex($comment['postid']);
	}

	if (isset($closepost) && $closepost['parentid'] == $oldquestion['postid'])
		qa_post_unindex($closepost['postid']);

	$setupdated = false;
	$event = null;

	if ($status == QA_POST_STATUS_QUEUED) {
		$newtype = 'Q_QUEUED';
		if (!$wasqueued)
			$event = 'q_requeue'; // same event whether it was hidden or shown before

	} elseif ($status == QA_POST_STATUS_HIDDEN) {
		$newtype = 'Q_HIDDEN';
		if (!$washidden) {
			$event = $wasqueued ? 'q_reject' : 'q_hide';
			if (!$wasqueued)
				$setupdated = true;
		}

	} elseif ($status == QA_POST_STATUS_NORMAL) {
		$newtype = 'Q';
		if ($wasqueued)
			$event = 'q_approve';
		elseif ($washidden) {
			$event = 'q_reshow';
			$setupdated = true;
		}

	} else
		qa_fatal_error('Unknown status in qa_question_set_status(): ' . $status);

	qa_db_post_set_type($oldquestion['postid'], $newtype, $setupdated ? $userid : null, $setupdated ? qa_remote_ip_address() : null, QA_UPDATE_VISIBLE);

	if ($wasqueued && $status == QA_POST_STATUS_NORMAL && qa_opt('moderate_update_time')) { // ... for approval of a post, can set time to now instead
		if ($wasrequeued) // reset edit time to now if there was one, since we're approving the edit...
			qa_db_post_set_updated($oldquestion['postid'], null);

		else { // ... otherwise we're approving original created post
			qa_db_post_set_created($oldquestion['postid'], null);
			qa_db_hotness_update($oldquestion['postid']);
		}
	}

	// Update caches
	$difference = null;
	if ($status === QA_POST_STATUS_NORMAL) {
		if ($oldquestion['type'] !== 'Q') {
			$difference = 1;
		}
	} else {
		if ($oldquestion['type'] === 'Q') {
			$difference = -1;
		}
	}

	if (isset($difference)) {
		qa_private_question_cache_recalc($oldquestion, $difference);
	}

	if ($wasqueued) {
		if ($status !== QA_POST_STATUS_QUEUED) {
			qa_db_queuedcount_update(-1);
		}
	} else {
		if ($status === QA_POST_STATUS_QUEUED) {
			qa_db_queuedcount_update(1);
		}
	}

	qa_db_points_update_ifuser($oldquestion['userid'], array('qposts', 'aselects'));

	if ($event !== 'q_approve') { // all other events change visibility
		qa_db_hiddencount_update();
	}

	if ($oldquestion['flagcount'] > 0 && isset($difference)) {
		qa_db_flaggedcount_update($difference * $oldquestion['flagcount']);
	}

	if ($status == QA_POST_STATUS_NORMAL) {
		qa_post_index($oldquestion['postid'], 'Q', $oldquestion['postid'], $oldquestion['parentid'], $oldquestion['title'], $oldquestion['content'],
			$oldquestion['format'], qa_viewer_text($oldquestion['content'], $oldquestion['format']), $oldquestion['tags'], $oldquestion['categoryid']);

		foreach ($answers as $answer) {
			if ($answer['type'] == 'A') { // even if question visible, don't index hidden or queued answers
				qa_post_index($answer['postid'], $answer['type'], $oldquestion['postid'], $answer['parentid'], null,
					$answer['content'], $answer['format'], qa_viewer_text($answer['content'], $answer['format']), null, $answer['categoryid']);
			}
		}

		foreach ($commentsfollows as $comment) {
			if ($comment['type'] == 'C') {
				$answer = @$answers[$comment['parentid']];

				if (!isset($answer) || $answer['type'] == 'A') { // don't index comment if it or its parent is hidden
					qa_post_index($comment['postid'], $comment['type'], $oldquestion['postid'], $comment['parentid'], null,
						$comment['content'], $comment['format'], qa_viewer_text($comment['content'], $comment['format']), null, $comment['categoryid']);
				}
			}
		}

		if (isset($closepost) && $closepost['parentid'] == $oldquestion['postid']) {
			qa_post_index($closepost['postid'], $closepost['type'], $oldquestion['postid'], $closepost['parentid'], null,
				$closepost['content'], $closepost['format'], qa_viewer_text($closepost['content'], $closepost['format']), null, $closepost['categoryid']);
		}
	}

	qa_question_uncache($oldquestion['postid']); // remove hidden posts immediately

	$eventparams = array(
		'postid' => $oldquestion['postid'],
		'parentid' => $oldquestion['parentid'],
		'parent' => isset($oldquestion['parentid']) ? qa_db_single_select(qa_db_full_post_selectspec(null, $oldquestion['parentid'])) : null,
		'title' => $oldquestion['title'],
		'content' => $oldquestion['content'],
		'format' => $oldquestion['format'],
		'text' => qa_viewer_text($oldquestion['content'], $oldquestion['format']),
		'tags' => $oldquestion['tags'],
		'categoryid' => $oldquestion['categoryid'],
		'name' => $oldquestion['name'],
	);

	if (isset($event)) {
		qa_report_event($event, $userid, $handle, $cookieid, $eventparams + array(
				'oldquestion' => $oldquestion,
			));
	}

	if ($wasqueued && ($status == QA_POST_STATUS_NORMAL) && !$wasrequeued) {
		require_once QA_INCLUDE_DIR . 'db/selects.php';
		require_once QA_INCLUDE_DIR . 'util/string.php';

		qa_report_event('q_post', $oldquestion['userid'], $oldquestion['handle'], $oldquestion['cookieid'], $eventparams + array(
			'notify' => isset($oldquestion['notify']),
			'email' => qa_email_validate($oldquestion['notify']) ? $oldquestion['notify'] : null,
			'delayed' => $oldquestion['created'],
		));
	}
}


/**
 * Sets the category (application level) of $oldquestion to $categoryid. Pass details of the user doing this in
 * $userid, $handle and $cookieid, the database records for all answers to the question in $answers, the database
 * records for all comments on the question or the question's answers in $commentsfollows ($commentsfollows can also
 * contain records for follow-on questions which are ignored), and $closepost to match $oldquestion['closedbyid'] (if any).
 * Set $silent to true to not mark the question as edited. Handles cached counts and event reports and will reset category
 * IDs and paths for all answers and comments. See /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldquestion
 * @param int $categoryid
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $answers
 * @param array $commentsfollows
 * @param int|null $closepost
 * @param bool $silent
 */
function qa_question_set_category($oldquestion, $categoryid, $userid, $handle, $cookieid, $answers, $commentsfollows, $closepost = null, $silent = false)
{
	$oldpath = qa_db_post_get_category_path($oldquestion['postid']);

	qa_db_post_set_category($oldquestion['postid'], $categoryid, $silent ? null : $userid, $silent ? null : qa_remote_ip_address());
	qa_db_posts_calc_category_path($oldquestion['postid']);

	$newpath = qa_db_post_get_category_path($oldquestion['postid']);

	qa_db_category_path_qcount_update($oldpath);
	qa_db_category_path_qcount_update($newpath);

	$otherpostids = array();
	foreach ($answers as $answer) {
		$otherpostids[] = $answer['postid'];
	}

	foreach ($commentsfollows as $comment) {
		if ($comment['basetype'] == 'C')
			$otherpostids[] = $comment['postid'];
	}

	if (isset($closepost) && $closepost['parentid'] == $oldquestion['postid'])
		$otherpostids[] = $closepost['postid'];

	qa_db_posts_set_category_path($otherpostids, $newpath);

	$searchmodules = qa_load_modules_with('search', 'move_post');
	foreach ($searchmodules as $searchmodule) {
		$searchmodule->move_post($oldquestion['postid'], $categoryid);
		foreach ($otherpostids as $otherpostid) {
			$searchmodule->move_post($otherpostid, $categoryid);
		}
	}

	qa_report_event('q_move', $userid, $handle, $cookieid, array(
		'postid' => $oldquestion['postid'],
		'oldquestion' => $oldquestion,
		'categoryid' => $categoryid,
		'oldcategoryid' => $oldquestion['categoryid'],
	));
}


/**
 * Permanently delete a question (application level) from the database. The question must not have any answers or
 * comments on it. Pass details of the user doing this in $userid, $handle and $cookieid, and $closepost to match
 * $oldquestion['closedbyid'] (if any). Handles unindexing, votes, points, cached counts and event reports.
 * See /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldquestion
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array|null $oldclosepost
 */
function qa_question_delete($oldquestion, $userid, $handle, $cookieid, $oldclosepost = null)
{
	require_once QA_INCLUDE_DIR . 'db/votes.php';

	if ($oldquestion['type'] != 'Q_HIDDEN')
		qa_fatal_error('Tried to delete a non-hidden question');

	$params = array(
		'postid' => $oldquestion['postid'],
		'oldquestion' => $oldquestion,
	);

	qa_report_event('q_delete_before', $userid, $handle, $cookieid, $params);

	if (isset($oldclosepost) && ($oldclosepost['parentid'] == $oldquestion['postid'])) {
		qa_db_post_set_closed($oldquestion['postid'], null); // for foreign key constraint
		qa_post_unindex($oldclosepost['postid']);
		qa_db_post_delete($oldclosepost['postid']);
	}

	$useridvotes = qa_db_uservote_post_get($oldquestion['postid']);
	$oldpath = qa_db_post_get_category_path($oldquestion['postid']);

	qa_post_unindex($oldquestion['postid']);
	qa_db_post_delete($oldquestion['postid']); // also deletes any related voteds due to foreign key cascading
	qa_db_category_path_qcount_update($oldpath);
	qa_db_hiddencount_update();
	qa_db_points_update_ifuser($oldquestion['userid'], array('qposts', 'aselects', 'qvoteds', 'upvoteds', 'downvoteds'));

	foreach ($useridvotes as $voteruserid => $vote) {
		// could do this in one query like in qa_db_users_recalc_points() but this will do for now - unlikely to be many votes
		qa_db_points_update_ifuser($voteruserid, ($vote > 0) ? 'qupvotes' : 'qdownvotes');
	}

	qa_report_event('q_delete', $userid, $handle, $cookieid, $params);
}


/**
 * Set the author (application level) of $oldquestion to $userid and also pass $handle and $cookieid
 * of user. Updates points and reports events as appropriate.
 * @param array $oldquestion
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 */
function qa_question_set_userid($oldquestion, $userid, $handle, $cookieid)
{
	require_once QA_INCLUDE_DIR . 'db/votes.php';

	$postid = $oldquestion['postid'];

	qa_db_post_set_userid($postid, $userid);
	qa_db_uservote_remove_own($postid);
	qa_db_post_recount_votes($postid);

	qa_db_points_update_ifuser($oldquestion['userid'], array('qposts', 'aselects', 'qvoteds', 'upvoteds', 'downvoteds'));
	qa_db_points_update_ifuser($userid, array('qposts', 'aselects', 'qvoteds', 'qupvotes', 'qdownvotes', 'upvoteds', 'downvoteds'));

	qa_report_event('q_claim', $userid, $handle, $cookieid, array(
		'postid' => $postid,
		'oldquestion' => $oldquestion,
	));
}


/**
 * Remove post $postid from our index and update appropriate word counts. Calls through to all search modules.
 * @param int $postid
 */
function qa_post_unindex($postid)
{
	global $qa_post_indexing_suspended;

	if ($qa_post_indexing_suspended > 0)
		return;

	// Send through to any search modules for unindexing

	$searchmodules = qa_load_modules_with('search', 'unindex_post');
	foreach ($searchmodules as $searchmodule) {
		$searchmodule->unindex_post($postid);
	}
}


/**
 * Delete the cache for a question. Used after it or its answers/comments are hidden, to prevent them remaining visible to visitors/search engines.
 * @param int $questionId Post ID to delete.
 * @return bool
 */
function qa_question_uncache($questionId)
{
	$cacheDriver = \Q2A\Storage\CacheFactory::getCacheDriver();
	return $cacheDriver->delete("question:$questionId");
}


/**
 * Change the fields of an answer (application level) to $content, $format, $notify and $name, then reindex based on
 * $text. For backwards compatibility if $name is null then the name will not be changed. Pass the answer's database
 * record before changes in $oldanswer, the question's in $question, and details of the user doing this in $userid,
 * $handle and $cookieid. Set $remoderate to true if the question should be requeued for moderation if modified. Set
 * $silent to true to not mark the question as edited. Handle indexing and event reports as appropriate. See
 * /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldanswer
 * @param string $content
 * @param string $format
 * @param string $text
 * @param bool $notify
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $question
 * @param string|null $name
 * @param bool $remoderate
 * @param bool $silent
 */
function qa_answer_set_content($oldanswer, $content, $format, $text, $notify, $userid, $handle, $cookieid, $question, $name = null, $remoderate = false, $silent = false)
{
	qa_post_unindex($oldanswer['postid']);

	$wasqueued = ($oldanswer['type'] == 'A_QUEUED');
	$contentchanged = strcmp($oldanswer['content'], $content) || strcmp($oldanswer['format'], $format);
	$setupdated = $contentchanged && (!$wasqueued) && !$silent;

	qa_db_post_set_content($oldanswer['postid'], $oldanswer['title'], $content, $format, $oldanswer['tags'], $notify,
		$setupdated ? $userid : null, $setupdated ? qa_remote_ip_address() : null, QA_UPDATE_CONTENT, $name);

	if ($setupdated && $remoderate) {
		require_once QA_INCLUDE_DIR . 'app/posts.php';

		$commentsfollows = qa_post_get_answer_commentsfollows($oldanswer['postid']);

		foreach ($commentsfollows as $comment) {
			if ($comment['basetype'] == 'C' && $comment['parentid'] == $oldanswer['postid'])
				qa_post_unindex($comment['postid']);
		}

		qa_db_post_set_type($oldanswer['postid'], 'A_QUEUED');

		// Update caches
		qa_private_disable_answer_recalc($question, $oldanswer['netvotes']);

		qa_db_queuedcount_update(1);
		qa_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds'));

		if ($oldanswer['flagcount'] > 0) {
			qa_db_flaggedcount_update(-$oldanswer['flagcount']);
		}

	} elseif ($oldanswer['type'] == 'A' && $question['type'] == 'Q') { // don't index if question or answer are hidden/queued
		qa_post_index($oldanswer['postid'], 'A', $question['postid'], $oldanswer['parentid'], null, $content, $format, $text, null, $oldanswer['categoryid']);
	}

	qa_question_uncache($question['postid']);

	$eventparams = array(
		'postid' => $oldanswer['postid'],
		'parentid' => $oldanswer['parentid'],
		'parent' => $question,
		'content' => $content,
		'format' => $format,
		'text' => $text,
		'name' => $name,
		'oldanswer' => $oldanswer,
	);

	qa_report_event('a_edit', $userid, $handle, $cookieid, $eventparams + array(
		'silent' => $silent,
		'oldcontent' => $oldanswer['content'],
		'oldformat' => $oldanswer['format'],
		'contentchanged' => $contentchanged,
	));

	if ($setupdated && $remoderate)
		qa_report_event('a_requeue', $userid, $handle, $cookieid, $eventparams);
}


/**
 * Set $oldanswer to hidden if $hidden is true, visible/normal if otherwise. All other parameters are as for qa_answer_set_status(...)
 * @deprecated Replaced by qa_answer_set_status.
 * @param array $oldanswer
 * @param bool $hidden
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $question
 * @param array $commentsfollows
 */
function qa_answer_set_hidden($oldanswer, $hidden, $userid, $handle, $cookieid, $question, $commentsfollows)
{
	qa_answer_set_status($oldanswer, $hidden ? QA_POST_STATUS_HIDDEN : QA_POST_STATUS_NORMAL, $userid, $handle, $cookieid, $question, $commentsfollows);
}


/**
 * Set the status (application level) of $oldanswer to $status, one of the QA_POST_STATUS_* constants above. Pass
 * details of the user doing this in $userid, $handle and $cookieid, the database record for the question in $question,
 * and the database records for all comments on the answer in $commentsfollows ($commentsfollows can also contain other
 * records which are ignored). Handles indexing, user points, cached counts and event reports. See /qa-include/app/posts.php for
 * a higher-level function which is easier to use.
 * @param array $oldanswer
 * @param int $status
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $question
 * @param array $commentsfollows
 */
function qa_answer_set_status($oldanswer, $status, $userid, $handle, $cookieid, $question, $commentsfollows)
{
	require_once QA_INCLUDE_DIR . 'app/format.php';

	$washidden = ($oldanswer['type'] == 'A_HIDDEN');
	$wasqueued = ($oldanswer['type'] == 'A_QUEUED');
	$wasrequeued = $wasqueued && isset($oldanswer['updated']);

	qa_post_unindex($oldanswer['postid']);

	foreach ($commentsfollows as $comment) {
		if ($comment['basetype'] == 'C' && $comment['parentid'] == $oldanswer['postid'])
			qa_post_unindex($comment['postid']);
	}

	$setupdated = false;
	$event = null;

	if ($status == QA_POST_STATUS_QUEUED) {
		$newtype = 'A_QUEUED';
		if (!$wasqueued)
			$event = 'a_requeue'; // same event whether it was hidden or shown before

	} elseif ($status == QA_POST_STATUS_HIDDEN) {
		$newtype = 'A_HIDDEN';
		if (!$washidden) {
			$event = $wasqueued ? 'a_reject' : 'a_hide';
			if (!$wasqueued)
				$setupdated = true;
		}

		if ($question['selchildid'] == $oldanswer['postid']) { // remove selected answer
			qa_question_set_selchildid(null, null, null, $question, null, array($oldanswer['postid'] => $oldanswer));
		}

	} elseif ($status == QA_POST_STATUS_NORMAL) {
		$newtype = 'A';
		if ($wasqueued)
			$event = 'a_approve';
		elseif ($washidden) {
			$event = 'a_reshow';
			$setupdated = true;
		}

	} else
		qa_fatal_error('Unknown status in qa_answer_set_status(): ' . $status);

	qa_db_post_set_type($oldanswer['postid'], $newtype, $setupdated ? $userid : null, $setupdated ? qa_remote_ip_address() : null, QA_UPDATE_VISIBLE);

	if ($wasqueued && ($status == QA_POST_STATUS_NORMAL) && qa_opt('moderate_update_time')) { // ... for approval of a post, can set time to now instead
		if ($wasrequeued)
			qa_db_post_set_updated($oldanswer['postid'], null);
		else
			qa_db_post_set_created($oldanswer['postid'], null);
	}

	// Update caches
	$difference = null;
	if ($status === QA_POST_STATUS_NORMAL) {
		if ($oldanswer['type'] !== 'A') {
			$difference = 1;
		}
	} else {
		if ($oldanswer['type'] === 'A') {
			$difference = -1;
		}
	}

	if ($difference === 1) {
		qa_db_acount_update_for_q($question['postid'], 1);

		if ((int)$question['acount'] === 0 && $question['closedbyid'] === null) {
			qa_db_unaqcount_update(-1);
		}

		if ($oldanswer['netvotes'] > $question['amaxvote']) {
			qa_db_amaxvote_update_for_q($question['postid'], $oldanswer['netvotes'], false);

			if ((int)$question['amaxvote'] === 0 && $question['closedbyid'] === null) {
				qa_db_unupaqcount_update(-1);
			}
		}

		qa_db_hotness_update($question['postid']);
		qa_db_acount_update(1);
	} elseif ($difference === -1) {
		qa_private_disable_answer_recalc($question, $oldanswer['netvotes']);
	}

	if ($wasqueued) {
		if ($status !== QA_POST_STATUS_QUEUED) {
			qa_db_queuedcount_update(-1);
		}
	} else {
		if ($status === QA_POST_STATUS_QUEUED) {
			qa_db_queuedcount_update(1);
		}
	}

	qa_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds'));

	if ($event === 'a_hide' || $event === 'a_reshow') {
		qa_db_hiddencount_update();
	}

	if ($oldanswer['flagcount'] > 0 && isset($difference)) {
		qa_db_flaggedcount_update($difference * $oldanswer['flagcount']);
	}

	if ($question['type'] == 'Q' && $status == QA_POST_STATUS_NORMAL) { // even if answer visible, don't index if question is hidden or queued
		qa_post_index($oldanswer['postid'], 'A', $question['postid'], $oldanswer['parentid'], null, $oldanswer['content'],
			$oldanswer['format'], qa_viewer_text($oldanswer['content'], $oldanswer['format']), null, $oldanswer['categoryid']);

		foreach ($commentsfollows as $comment) {
			if ($comment['type'] == 'C' && $comment['parentid'] == $oldanswer['postid']) { // and don't index hidden/queued comments
				qa_post_index($comment['postid'], $comment['type'], $question['postid'], $comment['parentid'], null, $comment['content'],
					$comment['format'], qa_viewer_text($comment['content'], $comment['format']), null, $comment['categoryid']);
			}
		}
	}

	qa_question_uncache($question['postid']); // remove hidden posts immediately

	$eventparams = array(
		'postid' => $oldanswer['postid'],
		'parentid' => $oldanswer['parentid'],
		'parent' => $question,
		'content' => $oldanswer['content'],
		'format' => $oldanswer['format'],
		'text' => qa_viewer_text($oldanswer['content'], $oldanswer['format']),
		'categoryid' => $oldanswer['categoryid'],
		'name' => $oldanswer['name'],
	);

	if (isset($event)) {
		qa_report_event($event, $userid, $handle, $cookieid, $eventparams + array(
				'oldanswer' => $oldanswer,
			));
	}

	if ($wasqueued && ($status == QA_POST_STATUS_NORMAL) && !$wasrequeued) {
		require_once QA_INCLUDE_DIR . 'util/string.php';

		qa_report_event('a_post', $oldanswer['userid'], $oldanswer['handle'], $oldanswer['cookieid'], $eventparams + array(
			'notify' => isset($oldanswer['notify']),
			'email' => qa_email_validate($oldanswer['notify']) ? $oldanswer['notify'] : null,
			'delayed' => $oldanswer['created'],
		));
	}
}

/**
 * Recalc answer-related stats for question after hiding, moderating or converting to comment an answer.
 * This function is not expected to be used by plugins.
 *
 * @param array $question Parent question
 * @param int $netvotes Netvotes of the answer
 */
function qa_private_disable_answer_recalc($question, $netvotes)
{
	qa_db_hotness_update($question['postid']);
	qa_db_acount_update(-1);

	qa_db_acount_update_for_q($question['postid'], -1);

	$isOnlyAnswer = (int)$question['acount'] === 1;
	if ($isOnlyAnswer && $question['closedbyid'] === null) {
		qa_db_unaqcount_update(1);
	}
	if ($question['amaxvote'] > 0 && $question['amaxvote'] == $netvotes) {
		if ($isOnlyAnswer) {
			qa_db_amaxvote_update_for_q($question['postid'], 0, false);
			if ($question['closedbyid'] === null) {
				qa_db_unupaqcount_update(1);
			}
		} else {
			$affectedRows = qa_db_amaxvote_update_for_q($question['postid']);

			if ($affectedRows > 0 && $question['closedbyid'] === null) {
				$tempQuestion = qa_db_single_select(qa_db_posts_selectspec(null, array($question['postid'])))[$question['postid']];

				if ((int)$tempQuestion['amaxvote'] === 0) {
					qa_db_unupaqcount_update(1);
				}
			}
		}
	}
}


/**
 * Recalc minimal question-related stats after hiding, moderating, opening or closing a question.
 * This function is not expected to be used by plugins.
 *
 * @param array $question
 * @param int $difference
 */
function qa_private_question_basic_cache_recalc($question, $difference)
{
	if ((int)$question['acount'] === 0) {
		qa_db_unaqcount_update($difference);
	}
	if ((int)$question['amaxvote'] === 0) {
		qa_db_unupaqcount_update($difference);
	}
	if ($question['selchildid'] === null) {
		qa_db_unselqcount_update($difference);
	}
}

/**
 * Recalc general question-related stats after hiding, moderating, and showing a question.
 * This function is not expected to be used by plugins.
 *
 * @param array $oldquestion
 * @param int $difference
 */
function qa_private_question_cache_recalc($oldquestion, $difference)
{
	qa_db_category_path_qcount_update(qa_db_post_get_category_path($oldquestion['postid']));

	qa_db_qcount_update($difference);
	if ($oldquestion['closedbyid'] === null) {
		qa_private_question_basic_cache_recalc($oldquestion, $difference);
	}
	qa_db_tagcount_update();
}


/**
 * Permanently delete an answer (application level) from the database. The answer must not have any comments or
 * follow-on questions. Pass the database record for the question in $question and details of the user doing this
 * in $userid, $handle and $cookieid. Handles unindexing, votes, points, cached counts and event reports.
 * See /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldanswer
 * @param array $question
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 */
function qa_answer_delete($oldanswer, $question, $userid, $handle, $cookieid)
{
	require_once QA_INCLUDE_DIR . 'db/votes.php';

	if ($oldanswer['type'] != 'A_HIDDEN')
		qa_fatal_error('Tried to delete a non-hidden answer');

	$useridvotes = qa_db_uservote_post_get($oldanswer['postid']);

	$params = array(
		'postid' => $oldanswer['postid'],
		'parentid' => $oldanswer['parentid'],
		'oldanswer' => $oldanswer,
	);

	qa_report_event('a_delete_before', $userid, $handle, $cookieid, $params);

	qa_post_unindex($oldanswer['postid']);
	qa_db_post_delete($oldanswer['postid']); // also deletes any related voteds due to cascading

	if ($question['selchildid'] == $oldanswer['postid']) {
		qa_db_post_set_selchildid($question['postid'], null);
		qa_db_points_update_ifuser($question['userid'], 'aselects');
	}

	qa_db_hiddencount_update();
	qa_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds', 'avoteds', 'upvoteds', 'downvoteds'));

	foreach ($useridvotes as $voteruserid => $vote) {
		// could do this in one query like in qa_db_users_recalc_points() but this will do for now - unlikely to be many votes
		qa_db_points_update_ifuser($voteruserid, ($vote > 0) ? 'aupvotes' : 'adownvotes');
	}

	qa_report_event('a_delete', $userid, $handle, $cookieid, $params);
}


/**
 * Set the author (application level) of $oldanswer to $userid and also pass $handle and $cookieid
 * of user. Updates points and reports events as appropriate.
 * @param array $oldanswer
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 */
function qa_answer_set_userid($oldanswer, $userid, $handle, $cookieid)
{
	require_once QA_INCLUDE_DIR . 'db/votes.php';

	$postid = $oldanswer['postid'];

	qa_db_post_set_userid($postid, $userid);
	qa_db_uservote_remove_own($postid);
	qa_db_post_recount_votes($postid);

	qa_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds', 'avoteds', 'upvoteds', 'downvoteds'));
	qa_db_points_update_ifuser($userid, array('aposts', 'aselecteds', 'avoteds', 'aupvotes', 'adownvotes', 'upvoteds', 'downvoteds'));

	qa_report_event('a_claim', $userid, $handle, $cookieid, array(
		'postid' => $postid,
		'parentid' => $oldanswer['parentid'],
		'oldanswer' => $oldanswer,
	));
}


/**
 * Change the fields of a comment (application level) to $content, $format, $notify and $name, then reindex based on
 * $text. For backwards compatibility if $name is null then the name will not be changed. Pass the comment's database
 * record before changes in $oldcomment, details of the user doing this in $userid, $handle and $cookieid, the
 * antecedent question in $question and the answer's database record in $answer if this is a comment on an answer,
 * otherwise null. Set $remoderate to true if the question should be requeued for moderation if modified. Set $silent
 * to true to not mark the question as edited. Handles unindexing and event reports. See /qa-include/app/posts.php for a
 * higher-level function which is easier to use.
 * @param array $oldcomment
 * @param string $content
 * @param string $format
 * @param string $text
 * @param bool $notify
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $question
 * @param array $parent
 * @param string|null $name
 * @param bool $remoderate
 * @param bool $silent
 */
function qa_comment_set_content($oldcomment, $content, $format, $text, $notify, $userid, $handle, $cookieid, $question, $parent, $name = null, $remoderate = false, $silent = false)
{
	if (!isset($parent))
		$parent = $question; // for backwards compatibility with old answer parameter

	qa_post_unindex($oldcomment['postid']);

	$wasqueued = ($oldcomment['type'] == 'C_QUEUED');
	$contentchanged = strcmp($oldcomment['content'], $content) || strcmp($oldcomment['format'], $format);
	$setupdated = $contentchanged && (!$wasqueued) && !$silent;

	qa_db_post_set_content($oldcomment['postid'], $oldcomment['title'], $content, $format, $oldcomment['tags'], $notify,
		$setupdated ? $userid : null, $setupdated ? qa_remote_ip_address() : null, QA_UPDATE_CONTENT, $name);

	if ($setupdated && $remoderate) {
		qa_db_post_set_type($oldcomment['postid'], 'C_QUEUED');

		// Update caches
		// If comment was in normal state
		if ($oldcomment['type'] === 'C') {
			qa_db_ccount_update(-1);
		}
		qa_db_queuedcount_update(1);
		qa_db_points_update_ifuser($oldcomment['userid'], array('cposts'));

		if ($oldcomment['flagcount'] > 0) {
			qa_db_flaggedcount_update(-$oldcomment['flagcount']);
		}

	} elseif ($oldcomment['type'] == 'C' && $question['type'] == 'Q' && ($parent['type'] == 'Q' || $parent['type'] == 'A')) { // all must be visible
		qa_post_index($oldcomment['postid'], 'C', $question['postid'], $oldcomment['parentid'], null, $content, $format, $text, null, $oldcomment['categoryid']);
	}

	qa_question_uncache($question['postid']);

	$eventparams = array(
		'postid' => $oldcomment['postid'],
		'parentid' => $oldcomment['parentid'],
		'parenttype' => $parent['basetype'],
		'parent' => $parent,
		'questionid' => $question['postid'],
		'question' => $question,
		'content' => $content,
		'format' => $format,
		'text' => $text,
		'name' => $name,
		'oldcomment' => $oldcomment,
	);

	qa_report_event('c_edit', $userid, $handle, $cookieid, $eventparams + array(
		'silent' => $silent,
		'oldcontent' => $oldcomment['content'],
		'oldformat' => $oldcomment['format'],
		'contentchanged' => $contentchanged,
	));

	if ($setupdated && $remoderate)
		qa_report_event('c_requeue', $userid, $handle, $cookieid, $eventparams);
}


/**
 * Convert an answer to a comment (application level) and set its fields to $content, $format, $notify and $name. For
 * backwards compatibility if $name is null then the name will not be changed. Pass the answer's database record before
 * changes in $oldanswer, the new comment's $parentid to be, details of the user doing this in $userid, $handle and
 * $cookieid, the antecedent question's record in $question, the records for all answers to that question in $answers,
 * and the records for all comments on the (old) answer and questions following from the (old) answer in
 * $commentsfollows ($commentsfollows can also contain other records which are ignored). Set $remoderate to true if the
 * question should be requeued for moderation if modified. Set $silent to true to not mark the question as edited.
 * Handles indexing (based on $text), user points, cached counts and event reports.
 * @param array $oldanswer
 * @param int $parentid
 * @param string $content
 * @param string $format
 * @param string $text
 * @param bool $notify
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $question
 * @param array $answers
 * @param array $commentsfollows
 * @param string|null $name
 * @param bool $remoderate
 * @param bool $silent
 */
function qa_answer_to_comment($oldanswer, $parentid, $content, $format, $text, $notify, $userid, $handle, $cookieid, $question, $answers, $commentsfollows, $name = null, $remoderate = false, $silent = false)
{
	require_once QA_INCLUDE_DIR . 'db/votes.php';

	$parent = isset($answers[$parentid]) ? $answers[$parentid] : $question;

	qa_post_unindex($oldanswer['postid']);

	$wasqueued = ($oldanswer['type'] == 'A_QUEUED');
	$contentchanged = strcmp($oldanswer['content'], $content) || strcmp($oldanswer['format'], $format);
	$setupdated = $contentchanged && (!$wasqueued) && !$silent;

	if ($setupdated && $remoderate)
		$newtype = 'C_QUEUED';
	else
		$newtype = substr_replace($oldanswer['type'], 'C', 0, 1);

	qa_db_post_set_type($oldanswer['postid'], $newtype, ($wasqueued || $silent) ? null : $userid,
		($wasqueued || $silent) ? null : qa_remote_ip_address(), QA_UPDATE_TYPE);
	qa_db_post_set_parent($oldanswer['postid'], $parentid);
	qa_db_post_set_content($oldanswer['postid'], $oldanswer['title'], $content, $format, $oldanswer['tags'], $notify,
		$setupdated ? $userid : null, $setupdated ? qa_remote_ip_address() : null, QA_UPDATE_CONTENT, $name);

	foreach ($commentsfollows as $commentfollow) {
		if ($commentfollow['parentid'] == $oldanswer['postid']) // do same thing for comments and follows
			qa_db_post_set_parent($commentfollow['postid'], $parentid);
	}

	// Update caches
	if ($oldanswer['type'] === 'A') {
		qa_private_disable_answer_recalc($question, $oldanswer['netvotes']);
	}

	// If new comment is in normal state
	if ($newtype === 'C') {
		qa_db_ccount_update(1);
	}

	if ($wasqueued) {
		if ($newtype !== 'C_QUEUED') {
			qa_db_queuedcount_update(-1);
		}
	} else {
		if ($newtype === 'C_QUEUED') {
			qa_db_queuedcount_update(1);
		}
	}

	qa_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds', 'cposts', 'avoteds', 'cvoteds'));

	$useridvotes = qa_db_uservote_post_get($oldanswer['postid']);
	foreach ($useridvotes as $voteruserid => $vote) {
		// could do this in one query like in qa_db_users_recalc_points() but this will do for now - unlikely to be many votes
		qa_db_points_update_ifuser($voteruserid, ($vote > 0) ? 'aupvotes' : 'adownvotes');
	}

	if ($setupdated && $remoderate) {
		if ($oldanswer['flagcount'] > 0) {
			qa_db_flaggedcount_update(-$oldanswer['flagcount']);
		}
	} elseif ($oldanswer['type'] == 'A' && $question['type'] == 'Q' && ($parent['type'] == 'Q' || $parent['type'] == 'A')) // only if all fully visible
		qa_post_index($oldanswer['postid'], 'C', $question['postid'], $parentid, null, $content, $format, $text, null, $oldanswer['categoryid']);

	if ($question['selchildid'] == $oldanswer['postid']) { // remove selected answer
		qa_question_set_selchildid(null, null, null, $question, null, array($oldanswer['postid'] => $oldanswer));
	}

	$eventparams = array(
		'postid' => $oldanswer['postid'],
		'parentid' => $parentid,
		'parenttype' => $parent['basetype'],
		'parent' => $parent,
		'questionid' => $question['postid'],
		'question' => $question,
		'content' => $content,
		'format' => $format,
		'text' => $text,
		'name' => $name,
		'oldanswer' => $oldanswer,
	);

	qa_report_event('a_to_c', $userid, $handle, $cookieid, $eventparams + array(
		'silent' => $silent,
		'oldcontent' => $oldanswer['content'],
		'oldformat' => $oldanswer['format'],
		'contentchanged' => $contentchanged,
	));

	if ($setupdated && $remoderate) {
		// a-to-c conversion can be detected by presence of $event['oldanswer'] instead of $event['oldcomment']
		qa_report_event('c_requeue', $userid, $handle, $cookieid, $eventparams);
	}
}


/**
 * Set $oldcomment to hidden if $hidden is true, visible/normal if otherwise. All other parameters are as for qa_comment_set_status(...)
 * @deprecated Replaced by qa_comment_set_status.
 * @param array $oldcomment
 * @param bool $hidden
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $question
 * @param array $parent
 */
function qa_comment_set_hidden($oldcomment, $hidden, $userid, $handle, $cookieid, $question, $parent)
{
	qa_comment_set_status($oldcomment, $hidden ? QA_POST_STATUS_HIDDEN : QA_POST_STATUS_NORMAL, $userid, $handle, $cookieid, $question, $parent);
}


/**
 * Set the status (application level) of $oldcomment to $status, one of the QA_POST_STATUS_* constants above. Pass the
 * antecedent question's record in $question, details of the user doing this in $userid, $handle and $cookieid, and the
 * answer's database record in $answer if this is a comment on an answer, otherwise null. Handles indexing, user
 * points, cached counts and event reports. See /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldcomment
 * @param int $status
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 * @param array $question
 * @param array $parent
 */
function qa_comment_set_status($oldcomment, $status, $userid, $handle, $cookieid, $question, $parent)
{
	require_once QA_INCLUDE_DIR . 'app/format.php';

	if (!isset($parent))
		$parent = $question; // for backwards compatibility with old answer parameter

	$washidden = ($oldcomment['type'] == 'C_HIDDEN');
	$wasqueued = ($oldcomment['type'] == 'C_QUEUED');
	$wasrequeued = $wasqueued && isset($oldcomment['updated']);

	qa_post_unindex($oldcomment['postid']);

	$setupdated = false;
	$event = null;

	if ($status == QA_POST_STATUS_QUEUED) {
		$newtype = 'C_QUEUED';
		if (!$wasqueued)
			$event = 'c_requeue'; // same event whether it was hidden or shown before

	} elseif ($status == QA_POST_STATUS_HIDDEN) {
		$newtype = 'C_HIDDEN';
		if (!$washidden) {
			$event = $wasqueued ? 'c_reject' : 'c_hide';
			if (!$wasqueued)
				$setupdated = true;
		}

	} elseif ($status == QA_POST_STATUS_NORMAL) {
		$newtype = 'C';
		if ($wasqueued)
			$event = 'c_approve';
		elseif ($washidden) {
			$event = 'c_reshow';
			$setupdated = true;
		}

	} else
		qa_fatal_error('Unknown status in qa_comment_set_status(): ' . $status);

	qa_db_post_set_type($oldcomment['postid'], $newtype, $setupdated ? $userid : null, $setupdated ? qa_remote_ip_address() : null, QA_UPDATE_VISIBLE);

	if ($wasqueued && ($status == QA_POST_STATUS_NORMAL) && qa_opt('moderate_update_time')) { // ... for approval of a post, can set time to now instead
		if ($wasrequeued)
			qa_db_post_set_updated($oldcomment['postid'], null);
		else
			qa_db_post_set_created($oldcomment['postid'], null);
	}

	// Update caches
	$difference = null;
	if ($status === QA_POST_STATUS_NORMAL) {
		if ($oldcomment['type'] !== 'C') {
			$difference = 1;
		}
	} else {
		if ($oldcomment['type'] === 'C') {
			$difference = -1;
		}
	}

	if ($wasqueued) {
		if ($status !== QA_POST_STATUS_QUEUED) {
			qa_db_queuedcount_update(-1);
		}
	} else {
		if ($status === QA_POST_STATUS_QUEUED) {
			qa_db_queuedcount_update(1);
		}
	}

	qa_db_points_update_ifuser($oldcomment['userid'], array('cposts'));

	if ($event === 'c_hide' || $event === 'c_reshow') {
		qa_db_hiddencount_update();
	}

	if (isset($difference)) {
		qa_db_ccount_update($difference);
		if ($oldcomment['flagcount'] > 0) {
			qa_db_flaggedcount_update($difference * $oldcomment['flagcount']);
		}
	}

	if ($question['type'] == 'Q' && ($parent['type'] == 'Q' || $parent['type'] == 'A') && $status == QA_POST_STATUS_NORMAL) {
		// only index if none of the things it depends on are hidden or queued
		qa_post_index($oldcomment['postid'], 'C', $question['postid'], $oldcomment['parentid'], null, $oldcomment['content'],
			$oldcomment['format'], qa_viewer_text($oldcomment['content'], $oldcomment['format']), null, $oldcomment['categoryid']);
	}

	qa_question_uncache($question['postid']); // remove hidden posts immediately

	$eventparams = array(
		'postid' => $oldcomment['postid'],
		'parentid' => $oldcomment['parentid'],
		'parenttype' => $parent['basetype'],
		'parent' => $parent,
		'questionid' => $question['postid'],
		'question' => $question,
		'content' => $oldcomment['content'],
		'format' => $oldcomment['format'],
		'text' => qa_viewer_text($oldcomment['content'], $oldcomment['format']),
		'categoryid' => $oldcomment['categoryid'],
		'name' => $oldcomment['name'],
	);

	if (isset($event)) {
		qa_report_event($event, $userid, $handle, $cookieid, $eventparams + array(
			'oldcomment' => $oldcomment,
		));
	}

	if ($wasqueued && $status == QA_POST_STATUS_NORMAL && !$wasrequeued) {
		require_once QA_INCLUDE_DIR . 'db/selects.php';
		require_once QA_INCLUDE_DIR . 'util/string.php';

		$commentsfollows = qa_db_single_select(qa_db_full_child_posts_selectspec(null, $oldcomment['parentid']));
		$thread = array();

		foreach ($commentsfollows as $comment) {
			if ($comment['type'] == 'C' && $comment['parentid'] == $parent['postid'])
				$thread[] = $comment;
		}

		qa_report_event('c_post', $oldcomment['userid'], $oldcomment['handle'], $oldcomment['cookieid'], $eventparams + array(
			'thread' => $thread,
			'notify' => isset($oldcomment['notify']),
			'email' => qa_email_validate($oldcomment['notify']) ? $oldcomment['notify'] : null,
			'delayed' => $oldcomment['created'],
		));
	}
}


/**
 * Permanently delete a comment in $oldcomment (application level) from the database. Pass the database question in $question
 * and the answer's database record in $answer if this is a comment on an answer, otherwise null. Pass details of the user
 * doing this in $userid, $handle and $cookieid. Handles unindexing, points, cached counts and event reports.
 * See /qa-include/app/posts.php for a higher-level function which is easier to use.
 * @param array $oldcomment
 * @param array $question
 * @param array $parent
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 */
function qa_comment_delete($oldcomment, $question, $parent, $userid, $handle, $cookieid)
{
	if (!isset($parent))
		$parent = $question; // for backwards compatibility with old answer parameter

	if ($oldcomment['type'] != 'C_HIDDEN')
		qa_fatal_error('Tried to delete a non-hidden comment');

	$params = array(
		'postid' => $oldcomment['postid'],
		'parentid' => $oldcomment['parentid'],
		'oldcomment' => $oldcomment,
		'parenttype' => $parent['basetype'],
		'questionid' => $question['postid'],
	);

	qa_report_event('c_delete_before', $userid, $handle, $cookieid, $params);

	qa_post_unindex($oldcomment['postid']);
	qa_db_post_delete($oldcomment['postid']);
	qa_db_hiddencount_update();
	qa_db_points_update_ifuser($oldcomment['userid'], array('cposts'));

	qa_report_event('c_delete', $userid, $handle, $cookieid, $params);
}


/**
 * Set the author (application level) of $oldcomment to $userid and also pass $handle and $cookieid
 * of user. Updates points and reports events as appropriate.
 * @param array $oldcomment
 * @param mixed $userid
 * @param string $handle
 * @param string $cookieid
 */
function qa_comment_set_userid($oldcomment, $userid, $handle, $cookieid)
{
	require_once QA_INCLUDE_DIR . 'db/votes.php';

	$postid = $oldcomment['postid'];

	qa_db_post_set_userid($postid, $userid);
	qa_db_uservote_remove_own($postid);
	qa_db_post_recount_votes($postid);

	qa_db_points_update_ifuser($oldcomment['userid'], array('cposts'));
	qa_db_points_update_ifuser($userid, array('cposts'));

	qa_report_event('c_claim', $userid, $handle, $cookieid, array(
		'postid' => $postid,
		'parentid' => $oldcomment['parentid'],
		'oldcomment' => $oldcomment,
	));
}
