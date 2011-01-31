<?php
/**
 * github POST processor.
 *
 * @author David Underhill
 * @version 0.1 (updated 31-May-2009 @ 06:01 PDT)
 */

define('SEND_HTML_EMAIL', false);
define('SEND_DIFF', true);
define('SHOW_AGGREGATE', false); // For the moment, this should be !SEND_DIFF

define('EMAIL_FROM', 'noreply@example.com');

// some constants for HTML tags
define('HTML_HEADER',         SEND_HTML_EMAIL ? '<html><body>' : '');
define('HTML_BR',             SEND_HTML_EMAIL ? '<br/>' : "\n");
define('HTML_P',              SEND_HTML_EMAIL ? '<p>' : "\n");
define('HTML_P_END',          SEND_HTML_EMAIL ? '</p>' : "\n");
define('HTML_BLOCKQUOTE',     SEND_HTML_EMAIL ? '<blockquote>' : "\n");
define('HTML_BLOCKQUOTE_END', SEND_HTML_EMAIL ? '</blockquote>' : '');
define('HTML_FOOTER',         SEND_HTML_EMAIL ? '</body></html>' : '');

/** Generates a URL based on SEND_HTML_EMAIL. */
function make_url($url, $text, $is_mail_to) {
    if(SEND_HTML_EMAIL)
        return '<a href="' . ($is_mail_to ? 'mailto:' : '') . $url .'">' . $text . '</a>';
    elseif($is_mail_to)
        return $url;
    else
        return "$text ($url)";
}

/**
 * Emails information about a push specified by github's JSON format.
 *
 * @param to           email address(es)
 * @param subj_header  text to prefix the header with
 * @param github_json  string which contains github's JSON post-receive data
 */
function mail_github_post_receive($to, $subj_header, $github_json) {
    $obj = json_decode($github_json);
    if(!$obj) {
        error_log("bad JSON: $github_json");
        exit(0);
    }

    $num_commits = count($obj->{'commits'});
    if($num_commits == 0) {
        error_log("no commits in JSON: $github_json");
        exit(0);
    }

    // create the subject line
    $branch = str_replace('refs/heads/', '', $obj->{'ref'});
    $last_commit = $obj->{'after'};
    $subj = "$subj_header $branch -> $last_commit";

    // repo details for diff
    $repo_owner = $obj->{'repository'}->{'owner'}->{'name'};
    $repo = $obj->{'repository'}->{'name'};

    // extract information about each commit
    $commits = '';
    $added = array();
    $deleted = array();
    $modified = array();
    foreach($obj->{'commits'} as $commit) {
        $id = $commit->{'id'};
        $url = $commit->{'url'};
        $author = $commit->{'author'};
        $author_name = $author->{'name'};
        $author_email = $author->{'email'};
        $msg = $commit->{'message'};
        $date = $commit->{'timestamp'};

        if(SHOW_AGGREGATE) {
            if(isset($commit->{'added'})) {
                $added = array_merge($added, $commit->{'added'});
            }
            if(isset($commit->{'deleted'})) {
                $deleted = array_merge($deleted, $commit->{'deleted'});
            }
            if(isset($commit->{'modified'})) {
                $modified = array_merge($modified, $commit->{'modified'});
            }
        }

        if(!SEND_HTML_EMAIL) {
            $msg = "\n$msg";
        }

        if(SHOW_DIFF) {
            $msg = "Commit Message:\n$msg";
        }

        $commits .=
            HTML_P  . 'Commit: ' . make_url($url, $id, false) .
            HTML_BR . "Author: $author_name (" . make_url($author_email, $author_email, true) . ')' .
            HTML_BR . "Date: $date" .
            HTML_BLOCKQUOTE . str_replace("\n", HTML_BR, $msg . "\n\n" . github_get_diff($repo_owner, $repo, $id)) . HTML_BLOCKQUOTE_END . HTML_P_END;
    }

    // create a list of aggregate additions/deletions/modifications
    $changes = array("Additions"=>$added, "Deletions"=>$deleted, "Modifications"=>$modified);
    $changes_txt = '';
    foreach($changes as $what => $what_list) {
         if(count($what_list) > 0) {
            $changes_txt .= HTML_BR . "$what:" . HTML_BR;
            $items = array_unique($what_list);
            sort($items);
            foreach($items as $item) {
                $changes_txt .= " -- $item" . HTML_BR;
            }
        }
    }

    // create the body of the mail
    $repo = $obj->{'repository'};
    $name = $repo->{'name'};
    $url = $repo->{'url'};
    $commits_noun = ($num_commits == 1) ? 'commit' : 'commits';
    $body = HTML_HEADER .
        "This automated email contains information about $num_commits new $commits_noun which have been\n" .
        "pushed to the '$name' repo located at $url.\n" .
        "\n" .
        $commits .
        $changes_txt .
        HTML_FOOTER;

    // build the mail headers
    $headers = "From: " . EMAIL_FROM . " ($subj_header Mailer)\r\n";
    if(SEND_HTML_EMAIL)
        $headers .= "MIME-Version: 1.0\r\n" .
                    "Content-type: text/html\r\n";

    // send the mail
    if(!mail($to, $subj, $body, $headers))
        error_log("failed to email github info to '$to' ($subj, $body)");
    else {
        $body = str_replace("\n", "<br/>", $body);
        echo "$to<br/>$subj<br/>$body<br/>";
    }
}

function github_get_diff($repo_owner, $repo, $commit)
{
    if (SEND_DIFF == false)
        return '';

    $json = file_get_contents("http://github.com/api/v2/json/commits/show/$repo_owner/$repo/$commit");

    $json = json_decode($json);
    if ($json == null)
        return '*bad json when retrieving commit diff*';

    $ret = '';

    if(!SHOW_AGGREGATE) {
        $ret = "Changed paths:\n";
        if (count($json->{'commit'}->{'added'}) > 0) {
            foreach($json->{'commit'}->{'added'} as $add)
                $ret .= "  A $add\n";
        }

        if (count($json->{'commit'}->{'removed'}) > 0) {
            foreach($json->{'commit'}->{'removed'} as $rem)
                $ret .= "  R $rem\n";
        }
    }

    if (count($json->{'commit'}->{'modified'}) > 0) {
        if(!SHOW_AGGREGATE) {
            foreach($json->{'commit'}->{'modified'} as $mod)
                $ret .= "    " . $mod->{'filename'} . "\n";
        }

        $ret .= "\n";

        foreach($json->{'commit'}->{'modified'} as $mod) {
            $ret .= "Modified: " . $mod->{'filename'} . "\n" .
                    "===================================================================\n" . $mod->{'diff'} . "\n\n";
        }
    }


    return $ret;
}

?>
