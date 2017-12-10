#!/usr/local/bin/php
<?php

// Copyright 2008 Tom Worster. ISC license. See readme.txt

/* =================================================================

this script generates an html index of m3u playlist files. call it
from cron a minute after streamget.php would be expected to finish.
so if kickstream.php runs at 1 min before the hour, and streamget.php
runs with duration 62 minutes, then it should finish 1 min after the
hour, so cron should run liststream.php at 2 min after the hour.

command line arguments are in the usage message below.

liststream.php first fetches a program schedule and recent playlist
headings from spinitron.

it then scans $pub_dir for m3u files, figuring the start time
of each archive file from the file name.

for each m3u file it searches for a matching playlist, if none it
searches for a matching scheduled show. the corresponding playlist
or show is listed beside the link to the m3u file in the resulting
index file.

$template is the name of the html template file used for the index.
must contain the string '{pagecontent}' which is replaced by this
script with the generated index html table.

================================================================= */

//
// default configuration globals
//
// dir for files served by http server, should be same in all 3 .php files
$pub_dir = __DIR__ . '/../public_html';
$plinfourl = 'http://www.spinitron.com/public/plinfo.php?station=wzbc';
$template = __DIR__ . '/indextemplate.ihtml';
$log_prefix = date(DATE_ATOM) . " liststream.php:";
// output html index file
$index = 'index.html';
// huh?
$prefix = '';

function usage()
{
    //
    // print usage message and exit
    //
    global $plinfourl;
    fwrite(STDERR,
        "usage: liststream.php [options] [url]
url          location of playlist/show information server, defaults to
             \"$plinfourl\".
-p pub_dir   directory to find publicmp3 and m3a files and to write html index,
             defaults to \"./public\" (w.r.t. liststream's current working
             directory).
-i index     name of output html index file, default \"./pub_dir/index.html\".
-f prefix    prefix to m3a file names in pub_dir to include in the index,
             defaults to empty string.\n");
    exit;
}

if (in_array('-h', $argv)) {
    usage();
}

function checkopts($s1, $s2)
{
    //
    // processes pairs of command line args into config globals, overriding defaults
    //
    global $pub_dir, $index, $prefix;
    if ($s1 === '-p') {
        $pub_dir = $s2;
    } elseif ($s1 === '-i') {
        $index = $s2;
    } elseif ($s1 === '-f') {
        $prefix = $s2;
    } else {
        usage();
    }
}

//
// check and process arguments
//
if ($argc > 8) {
    usage();
}
if ($argc % 2 == 0) {
    $plinfourl = $argv[$argc - 1];
}

//
// process arguments, overriding defaults
//
for ($j = 1; $j <= $argc - 2; $j += 2) {
    checkopts($argv[$j], $argv[$j + 1]);
}

//
// check if files in $pub_dir are readable and $index is writable, die on error
//
if (!preg_match('/\//', $index)) {
    $index = "$pub_dir/$index";
}
if (!is_dir($pub_dir)) {
    fwrite(STDERR, "$log_prefix \"$pub_dir\" is not a directory\n");
    exit;
}
if (!is_readable($pub_dir)) {
    fwrite(STDERR, "$log_prefix directory \"$pub_dir\" is not readable\n");
    exit;
}
if (file_exists($index)) {
    if (!is_writable($index)) {
        fwrite(STDERR, "$log_prefix \"$index\" is not writable\n");
        exit;
    }
} else {
    if (($fp = fopen($index, 'w')) === false
        || fwrite($fp, 'qwertyuiop') === false
    ) {
        fwrite(STDERR, "$log_prefix could not write to \"$index\"\n");
        exit;
    } else {
        @fclose($fp);
        @unlink($index);
    }
}

/*

next step is to get schedule and playlist data from spinitron. the resulting data
should be formatted as shown in the following examples...

$showinfo is an array of recent playlist objects, e.g.
$showinfo[63] => stdClass Object
			(
				[showid] => 3509
				[sdays] => Sun
				[scat] => Specialty
				[sname] => Cafe of Shame
				[onair] => 22:00:00
				[offair] => 00:00:00
				[regular] => Yes
				[showdescription] =>
				[showurl] => http://www.cafeofshame.org
			)
$plinfo is an array of regular show objects, e.g.
$plinfo[21] => stdClass Object
			(
				[plid] => 12729
				[showid] => 3077
				[date] => 2008-12-28
				[djname] => Cousin Kate
				[scat] => Specialty
				[sname] => Sunday Morning Country
				[onair] => 10:00:00
				[offair] => 14:00:00
				[regular] => Yes
				[showdescription] => Bladh blah
				[showurl] => http://www.cafeofshame.org
			)
*/

//
// use curl to get the data from spinitron
//
$devnul = fopen('/dev/null', 'a');
$ch = curl_init();
curl_setopt_array($ch, array(
    CURLOPT_URL => $plinfourl,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_AUTOREFERER => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_STDERR => $devnul
));
$curldata = curl_exec($ch);
$info = curl_getinfo($ch);
$info['curl_error'] = curl_errno($ch);
curl_close($ch);
fclose($devnul);
if ($info['curl_error'] != 0
    || $info['http_code'] != 200
) {
    // if curl got no data, log an error and initialize, $plinfo, $showinfo as empty
    fwrite(STDERR, "$log_prefix liststream.php got curl error fetching playlist info from $plinfourl");
    $plinfo = array();
    $showinfo = array();
} else {
    list($plinfo, $showinfo) = unserialize($curldata);
    // if $plinfo or $showinfo as gotten from spinitron aren't arrays, initialize them
    if (!is_array($plinfo)) {
        $plinfo = array();
    }
    if (!is_array($showinfo)) {
        $showinfo = array();
    }
}

//
// scan $pub_dir for m3u files
//
$plfiles = glob("$pub_dir/$prefix*.m3u");
if ($plfiles === false || count($plfiles) == 0) {
    // if no files availble, log error and write status to index file
    $pc = "<p>There are currently no audio archive files available.</p>";
    fwrite(STDERR, "$log_prefix liststream.php found no .m3u playlist files in $pub_dir");
} else {

    function plmatch($date, $hour)
    {
        //
        // plmatch() searches $plinfo global for a playlist matching an m3u file at
        // the specified date and time. returns first matching index to $plinfo plus
        // one or false on no match.
        //
        global $plinfo;
        if (count($plinfo) == 0) {
            return false;
        }
        foreach ($plinfo as $key => $pl) {
            $on = substr($pl->onair, 0, 2);
            $off = substr($pl->offair, 0, 2);
            if ($date == $pl->date
                && (($on < $off && $on <= $hour && $hour < $off)
                    || ($on > $off && ($on <= $hour /*|| $hour < $off*/)))
            ) {
                return $key + 1;
            } elseif ($on > $off && $hour < $off) {
                $prevdate = date('Y-m-d', strtotime($date) - 24 * 3600);
                if ($prevdate == $pl->date) {
                    return $key + 1;
                }
            }
        }

        return false;
    }

    $daybefore = array(
        'Mon' => 'Sun',
        'Tue' => 'Mon',
        'Wed' => 'Tue',
        'Thu' => 'Wed',
        'Fri' => 'Thu',
        'Sat' => 'Fri',
        'Sun' => 'Sat'
    );

    function showmatch($dow, $hour)
    {
        //
        // showmatch() searches $showinfo global for a scheduled show matching an
        // m3u file at the specified time on the given day of the week. returns
        // -1 times first matching index to $showinfo minus one or false on no
        // match
        //
        global $showinfo, $daybefore;
        if (count($showinfo) == 0) {
            return false;
        }
        foreach ($showinfo as $key => $show) {
            $on = substr($show->onair, 0, 2);
            $off = substr($show->offair, 0, 2);
            if (preg_match("/$dow/", $show->sdays)
                && (($on < $off && $on <= $hour && $hour < $off)
                    || ($on > $off && ($on <= $hour /*|| $hour < $off*/)))
            ) {
                return -$key - 1;
            } elseif ($on > $off && $hour < $off) {
                $prevdow = strtr($dow, $daybefore);
                if (preg_match("/$prevdow/", $show->sdays)) {
                    return -$key - 1;
                }
            }
        }

        return false;
    }

    //
    // so plmatch() returns a positive key indexing $plinfo while showmatch() returns
    // a negative key indexing $showinfo. thus one variable can hold a match to
    // either playlist (+ve), a show (-ve) or no match (0 or false).
    //

    // $days organizes $m3u file info hierarchically by date and time
    $days = array();
    $pub_dir_quoted = preg_quote($pub_dir);
    foreach ($plfiles as $pl) {
        if (preg_match("{^$pub_dir_quoted/(.+(\d\d\d\d-\d\d-\d\d)-(\d\d-\d\d)\.m3u)$}", $pl, $matches)) {
            $days[$matches[2]][$matches[3]] = array($matches[1]);
        }
    }

    // for each m3u file, search for playlists or shows
    foreach ($days as $date => &$pls) {                    // repeat over the dates
        asort($pls);                                    // sort the date
        foreach ($pls as $time => &$plrow) {            // repeat over times in each date
            $hour = substr($time, 0, 2);                // form the hour from the time
            $plrow[1] = plmatch($date, $hour);            // playlist match on date and hour
            if ($plrow[1] === false) {                // if no match...
                $dow = date('D', strtotime($date));        // form the day of the week
                $plrow[1] = showmatch($dow, $hour);        // show match
            }
        }
    }
    unset($pls, $plrow);
    krsort($days);
    //
    // by now, each $days[date][time] is an array(m3u_filename, key), where key points to either a
    // playlist in $plinfo or show in $showinfo according to the convention noted above
    //

    //
    // start building the html table for the index file
    //
    $pc = "<table id=\"archivelinks\" cellspacing=\"1\">\n";
    foreach ($days as $date => $pls) {                    // loop thru each date in $day
        $pc .= "  <tr>\n";                                // put a row in the table
        $pc .= "    <td colspan=\"4\" class=\"date\">"
            . date('l F jS Y', strtotime($date))        // containing the date heading
            . "</td>\n";
        $pc .= "  </tr>\n";

        //
        // first figure the table structure for the day and gater the information to
        // be put into each html table row into that structure
        //
        // $rows stores the data for table rows for the date as
        // array(time, m3ufile, pl_show_key, rowspan).
        // time = time string from m3u file name
        // m3ufile = m3u file name
        // pl_show_key is the coded index to either $plinfo (+ve) or $showinfo (-ve)
        // rowspan = 0 means put no row in the pl/show info column next to the m3u link
        // rowspan > 0 means put pl/show info beside the m3u link spanning that many m3u column rows
        //
        $rows = array();
        $i = 0;
        foreach ($pls as $time => $plrow) {                // loop thru the hours of the day.
            if ($i == 0                                // figure how many m3u files correspond to one playlist
                || $plrow[1] === false                    // or to one show. start a new row in the pl/show column
                || $rows[$i - 1][2] != $plrow[1]
            ) {        // on any of these 3 conditions.
                $rowspan = 1;                            // restart the rowspan counter for new row.
                $rows[$i] = array($time, $plrow[0], $plrow[1], 1);
            } else {                                    // otherwise continue the previous row
                $rowspan += 1;                            // inc rowspan counter for continuing rows in pl/show col
                $rows[$i] = array($time, $plrow[0], $plrow[1], 0);
                $rows[$i - $rowspan + 1][3] = $rowspan;    // copy rowspan value back to first row in the span
            }
            $i++;
        }

        //
        // second, build the html table rows for the day given the structure previously figured
        // code converts data from $rows into html.
        //
        foreach ($rows as $row) {
            $pc .= "  <tr>\n";
            $ttime = date('g:ia', strtotime($date . substr($row[0], 0, 2) . ':' . substr($row[0], 3, 2)));
            $tlink = " <a href=\"$row[1]\">Listen</a>";
            $ntb = $row[3] == 0 ? 'ntb' : '';
            $pc .= "    <td class=\"c1 ntb\"></td>\n";
            $pc .= "    <td class=\"c2 $ntb\">$ttime</td>\n";
            $pc .= "    <td class=\"c3 $ntb\">$tlink</td>\n";
            if ($row[3] != 0) {
                if ($row[2] == 0) {
                    $tshow = '';
                } else {
                    $info = $row[2] < 0 ? $showinfo[-$row[2] - 1] : $plinfo[$row[2] - 1];
                    $tshow = "<b>" . htmlspecialchars($info->sname) . "</b>";
                    if ($row[2] > 0) {
                        $tshow .= " with " . htmlspecialchars($info->djname)
                            . "<br><a href=\"http://spinitron.com/public/index.php"
                            . "?station=wzbc&amp;plid=$info->plid\">View playlist</a>";
                    }
                    $tshow .= $info->showurl == ''
                        ? ''
                        : "<br><a href=\"$info->showurl\">"
                        . htmlspecialchars($info->showurl)
                        . "</a>";
                }
                $pc .= "    <td class=\"c3\" rowspan=\"$row[3]\">$tshow</td>\n";
            }
            $pc .= "  </tr>\n";
        }
        $pc .= "  <tr>\n";
        $pc .= "    <td class=\"space ntb\"></td>\n";
        $pc .= "    <td colspan=\"3\" class=\"space \"></td>\n";
        $pc .= "  </tr>\n";
    }
    $pc .= "</table>\n";

    //print_r($plinfo);
    //print_r($showinfo);
}

//
// now put the generated content into the template files.
//

// read the template file into $page
if (($page = file_get_contents($template)) === false) {
    // log an error and use a simple default template on read error
    $page =
        "<html><head></head><body>{pagecontent}<p style=\"margin-top:4em\">error reading page template</p></body></html>";
    fwrite(STDERR, "$log_prefix error reading template");
}

// substitute generated content into template
$page = preg_replace('/\{pagecontent\}/', $pc, $page);

// write index file, log error on failure
if (file_put_contents($index, $page) === false) {
    fwrite(STDERR, "$log_prefix error writing final html index file");
}
