#!/usr/local/bin/php
<?php

// Copyright 2008 Tom Worster. ISC license. See readme.txt

/* =================================================================

this script should be started by cron one minute before every hour.
it's primary functions are:

1. delete expired mp3 and m3u files
2. define the weekly schedule of hours for audio archives
3. kick off the stream capture script streamget.php

configuration parameters are set in this file below.

command line flags:

-k	keep files. causes the script to skip the step of deleting
    expired audio/playlist files in public

-s	show schedule. display the recording schedule and quit,
    skipping all other tasks.

================================================================= */

//
// configuration globals:
//

// home for scripts and script's working directory
$cwd = __DIR__;

// dir for files served by http server, should be same in all 3 .php files
$pub_dir = __DIR__ . '/../public_html';

// uri to pub_dir
$location = 'http://zbconline.com/';

// part of the title in m3u files
$title = 'WZBC Archive';

// url of shoutcast audio stream
$url = 'http://amber.streamguys.com:4860/';

// prefix of mp3 and m3u file names, streamget's temp files and error log
$prefix = 'wzbc';

// mp3 and m3u files expire and are deleted after these days
$keepdays = 14;

$starttime = time();
$log_prefix = date(DATE_ATOM) . " kickstream.php:";

// keep public files, i.e. skip deletion of expired files
$keepfiles = in_array('-k', $argv);

// display the schedule and do nothing else
$showsched = in_array('-s', $argv);

//
// $s is the weekly schedule of days/hours for which archives are made.
// $s[0, .., 6] is an array of days of the week (0 is sunday). each
// element is an array of the hours at which stremget is kicked off for
// nominally one hour. each element of $s[.] is an hour (0 thru 23).
//
$s = array(
    array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23), // su
    array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23), // mo
    array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23), // tu
    array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23), // we
    array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23), // th
    array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23), // fr
    array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23), // sa
);

if ($showsched) {
    // display the schedule and exit
    $daynames = array('Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa');
    print("   00 01 02 03 04 05 06 07 08 09 10 11 12 13 14 15 16 17 18 19 20 21 22 23\n");
    foreach ($daynames as $day => $name) {
        print("$name");
        foreach (range(0, 24) as $hour) {
            if (in_array($hour, $s[$day])) {
                print('  *');
            } else {
                print('   ');
            }
        }
        print("\n");
    }
    exit;
}

if (!$keepfiles) {
    //
    // delete expired files. logging may be excessive but i needed it for debugging and
    // it gives something to look at in the logs to see that something is working.
    //
    $pfiles = glob("$pub_dir/$prefix*");
    //fwrite(STDERR, "$log_prefix considering " . (count($pfiles)) . " files for deletion\n");
    $nd = 0;
    if (count($pfiles) > 0) {
        foreach ($pfiles as $pfile) {
            if (filemtime($pfile) < $starttime - $keepdays * 24 * 3600) {
                $nd++;
                if (unlink($pfile)) {
                    //fwrite(STDERR, "$log_prefix deleted $pfile\n");
                } else {
                    fwrite(STDERR, "$log_prefix failed to delete $pfile\n");
                }
            }
        }
    }
    //fwrite(STDERR, "$log_prefix deleted $nd files\n");
}

//
// if the current time is within 5 minutes of the top of the hour and the hour and day are
// in the schedule, kick off streamget.php.
//
date_default_timezone_set('America/New_York');
list($d, $h, $m) = explode(' ', (date('w H i', $starttime)));  // day, hour, minute

if ($m > 5 && $m < 55) {
    // if now isn't near the top of the hour
    exit;
}

// if now is before the hour, rounding up is required
if ($m >= 55) {
    // round up hour, wrapping to 00 if necessary
    $h = ($h + 1) % 24;
    if ($h == 0) {
        // if hour got wrapped, round up day too, wrapping if necessary
        $d = ($d + 1) % 7;
    }
}

// if day and hour are in the schedule
if (isset($s[$d]) && in_array(intval($h), $s[$d])) {
    // secs from now and 1 min after top of the next hour
    $duration = 60 * (61 - $m + ($m < 30 ? 0 : 60));
    // adjustment to $starttime to round it to an hour
    $m4 = $starttime % 3600 - ($m < 30 ? 0 : 3600);
    // tidy date/time string used to title the archive file
    $datetime = date('D M jS g:00a', $starttime - $m4);
    // command to run streamget.php. make sure to redirect stdout and stderr and run in background, otherwise
    // this script will block until the command completes.
    $cmd = "cd $cwd; ./streamget.php -d $duration -l $location "
        . "-p $pub_dir -t '$title $datetime' $url $prefix >> $prefix.error.log 2>&1 &";
    shell_exec($cmd);
}
