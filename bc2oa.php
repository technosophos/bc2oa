<?php
/**
 * To run this:
 * drush --user=1 php-script ./bc2oa.php 
 *
 * This is a single-purpose script for importing BaseCamp XML data into
 * an OpenAtrium project. It is not intended as a single solution for 
 * all environments, but as a starting point for you to create your own solution.
 */
require 'QueryPath/QueryPath.php';

// ==== SETTINGS ====

// The BaseCamp XML file to read
$file = 'spinehealth-20100507143515.xml.foo';

// The formatter used for content.
$default_format = 5;

// The NID of the project to assign cases to.
$default_project_id = 297;

// The NID of the organic group that you are adding cases to.
$default_og_group_id = 287;

// == To get these values, check out the type, priority, and status
// == values on your "Create Case" node edit form.

// Default case type.
$default_case_type_id = 16;

// Default case priority (3 = LOW)
$default_case_priority_id = 3;

// Default status id (4 = OPEN)
$default_case_status_id = 4;

// ==== END SETTINGS ====

if (!file_exists($file)) {
  print "You need to edit bc2ao.php and configure the settings section." . PHP_EOL;
  exit(1);
}

$qp = qp($file);

// Build a map of user IDs to email addresses:
$userMap = array();
foreach($qp->branch(':root>firm>people>person') as $person) {
  $aid = $person->find('>id')->text();
  $email = $person->nextAll('email-address')->text();
  list($user, $domain,) = explode('@', $email);
  
  // XXX: For debugging only!!!!
  //$user = 'mbutcher';
  
  // Look up user IDs in Drupal:
  $user_object = user_load(array('name' => $user));
  $uid = $user_object->uid;
  
  $usernameMap[$aid] = $user;
  $userMap[$aid] = $uid;
}

// Begin with messages: 

$project = $qp->branch(':root>projects>project:last');

// Node template:
$node_tpl = array(
  'type' => 'casetracker_basic_case',
  //'uid' => $author,
  'status' => 1,
  //'created' => $date,
  //'changed' => $date,
  'sticky' => 0,
  'comment' => COMMENT_NODE_READ_WRITE,
  'promote' => 0,
  'moderate' => 0,
  
  //'tnid' => 0,
  //'translate' => 0,
  
  
  'format' => $default_format,
  'casetracker' => array(
    'pid' => $default_project_id,
    'case_priority_id' => $default_case_priority_id,
    'case_type_id' => $default_case_type_id,
    //'assign_to' => $author,
    'case_status_id' => $default_case_status_id,
  ),
  'og_groups' => array($default_og_group_id => $default_og_group_id),
  //'og_groups_both' => array($default_og_group_id => 'Group'),
);

foreach($project->find('>posts>post') as $post) {
  $title = $post->find('>title')->text();
  $aid = $post->end()->find('>author-id')->text();
  $author = $userMap[$aid];
  $date = @strtotime($post->end()->find('>posted-on')->text());
  $body = $post->end()->find('>body')->text();
  
  $commentList = $post->end()->find('>comments>comment');
  
  //$format = '%s (by %s on %d)' . PHP_EOL;
  //printf($format, $title, $author, $date, $body);
  
  $node = (object)$node_tpl;
  
  $node->uid = $author;
  $node->title = $title;
  $node->body = $body;
  $node->teaser = $body;
  $node->created = $node->changed = $date;
  $node->casetracker['assign_to'] = $author;
  $node->casetracker['case_status_id'] = 5; // Resolved.
  
  node_save($node);
  
  // TODO: Write node.
  
  $cformat = '    Comment by %s at %d'.PHP_EOL;
  
  // We need to unset a couple of products.
  $ct_copy = (array)$node->casetracker;
  unset($ct_copy['nid'], $ct_copy['vid'], $ct_copy['case_number']);
  
  foreach ($commentList as $comment) {
    $caid = $comment->find('>author-id')->text();
    $cauthor = $userMap[$caid];
    $cbody = $comment->end()->find('>body')->text();
    $csubject = substr($cbody, 0, 16);
    $cdate = @strtotime($comment->end()->find('>created-at:first')->text());
    printf($cformat, $cauthor, $cdate);
    
    $comment = array(
      'author' => $usernameMap[$caid],
      'comment' => $cbody,
      'format' => $default_format,
      'nid' => $node->nid,
      'uid' => $cauthor,
      'status' => 0,
      'timestamp' => $cdate,
      'date' => $cdate,
      'subject' => $csubject,
      
      // Not sure whether we need these.
      'op' => 'Save',
      'submit' => 'Save',
      'notifications_content_disable' => 0,
      'notifications_team' => array('selected' => TRUE),
      
      // Grab a copy from the parent node.
      // Wish there was a way to skip this, as it will cause duplicate data
      // to be written once for every comment.
      'casetracker' => $ct_copy,
    );
    comment_save($comment);
  }
  print PHP_EOL;
  
}

// Now do TODO lists:

$todolists = $qp->branch(':root>projects>project:last>todo-lists>todo-list');
foreach ($todolists as $todolist) {
  $tdlist = $todolist->find('>name:first')->text();
  
  
  print "TODO LIST $tdlist" . PHP_EOL;
  $tdformat = '    %s on %s assigned to %s' . PHP_EOL;
  
  foreach ($todolist->end()->find('>todo-items>todo-item') as $item) {
    
    $tditem_body = $item->find('>content')->text();
    $tditem_created_by = $item->end()->find('>creator-id')->text();
    $tditem_title = $tdlist . ': ' . substr($tditem_body, 0, 32);
    $tditem_date = @strtotime($item->end()->find('>created-at:first')->text());
    $tditem_completed = ($item->end()->find('>completed:last')->text() == 'true');
    $tditem_assigned_id = $item->end()->find('>responsible-party-id:first')->text();
    $tditem_assigned_to = $userMap[$tditem_assigned_id];
    
    //$tditem_date = $item->end()->find('created-at:first')->text();
    
    printf($tdformat, $tditem_title, $tditem_date, $tditem_assigned_to);
    
    // Clone the base node.
    $node = (object)$node_tpl;
    $node->uid = $userMap[$tditem_created_by];
    $node->title = $tditem_title;
    $node->body = $tditem_body;
    $node->teaser = $tditem_body;
    $node->created = $tditem_date;
    $node->changed = $tditem_date;
    $node->casetracker['assign_to'] = $userMap[$tditem_created_by];
    $node->casetracker['case_status_id'] = $tditem_completed ? 5 : 4;
    
    // Save the node.
    node_save($node);
    
    // Hack to work around node.module's autotimestamping.
    db_query('UPDATE {node} SET changed = %d WHERE nid = %d', $tditem_date, $node->nid);
    
    // Get a clean copy of the casetracker datastructure.
    $ct_copy = (array)$node->casetracker;
    unset($ct_copy['nid'], $ct_copy['vid'], $ct_copy['case_number']);
    
    foreach ($item->end()->find('>comments>comment') as $comment) {
      $tdcomment_aid = $comment->find('>author-id')->text();
      $tdcomment_author = $userMap[$caid];
      $tdcomment_body = $comment->end()->find('>body')->text();
      $tdcomment_subject = substr($tdcomment_body, 0, 32);
      $tdcomment_date = @strtotime($comment->end()->find('>created-at:first')->text());
      
      printf('  ' . $cformat, $tdcomment_author, $tdcomment_date);

      // TODO: Write comment.
      $comment = array(
        'author' => $usernameMap[$tdcomment_aid],
        'comment' => $tdcomment_body,
        'format' => $default_format,
        'nid' => $node->nid,
        'uid' => $tdcomment_author,
        'status' => 0,
        
        // This gets updated to time() anyway.
        'timestamp' => $tdcomment_date,
        //'date' => $tdcomment_date,
        'subject' => $tdcomment_subject,

        // Not sure whether we need these.
        'op' => 'Save',
        'submit' => 'Save',
        'notifications_content_disable' => 0,
        'notifications_team' => array('selected' => TRUE),

        // Grab a copy from the parent node.
        // Wish there was a way to skip this, as it will cause duplicate data
        // to be written once for every comment.
        'casetracker' => $ct_copy,
      );
      //print_r($comment);
      $cid = comment_save($comment);
      
      // Hack to get around comment.module's automatic timestamp.
      db_query('UPDATE {comments} SET timestamp = %s WHERE cid = %d', $tdcomment_date, $cid);
      _comment_update_node_statistics($node->nid);
    }
    print PHP_EOL;
  }
  
  
  
}