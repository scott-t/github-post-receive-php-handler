<?php

// two ways to address the email
// option 1) manually set the list
$email_to = "";

// option 2) use the list maintainer so people can sign-up for the list (and
// leave) on their own (recommended)
// note: to setup your own list, go to http://yuba.stanford.edu/github/list_create.php
include 'list_funcs.php';
$email_to = list_get_active_to_addrs_as_string('example');

// how to prefix the subject line (e.g., project name)
$email_subj_prefix = "[EXAMPLE]";

// grab payload
if(isset($_POST['payload']))
{
    $json = $_POST['payload'];
    include 'github_post_receive.php';
    mail_github_post_receive($email_to, $email_subj_prefix, $json);
}

?>
