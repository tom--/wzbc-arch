#!/usr/local/bin/php
<?php

// Copyright 2008 Tom Worster. ISC license. See readme.txt

/* =================================================================

this script attempts to fetch mp3 audio data from the stream server
and save it into archive mp3 files in the public directory. it also
generates m3u playlist files for each "successful" fetch.

configuration parameters are taken from command line arguments as
documented below. type the command line to see the summary.

streamget.php may be run directly from command line or cron etc. but
was intended to be started by kickstream.php. it must be run in a
writable current working directory.

streamget.php saves temporary audio data files in the current working
directory under file names described below. status messages are
logged to stderr.

================================================================= */

// set default configuration globals
// dir for files served by http server, should be same in all 3 .php files
$pub_dir = __DIR__ . '/../public_html';
// uri of pub_dir to use in m3u playlist file
$location = '';

// print usage message and exit
function usage()
{
    fwrite(STDERR, <<<TXT
usage: streamget.php [options] url prefix
  url          url of stream to fetch from
  prefix       prefix of audio, playlist and temp files
  -p pub_dir   directory to put final mp3 files for the public, defaults
               to \"./public\" (w.r.t. streamget's current working directory)
  -d seconds   duration in seconds of the mp3 archive to fetch, defaults to 62
               minutes.
  -l location  uri to pub_dir in m3u playlist file, defaults to empty string.
  -t title     title of audio file to use in m3u file. defaults to prefix.
streamget fetches audio data from a shoutcast server with the specified url
to mp3 files. temporary files are saved in the current working directory and
are named prefix-pid-NNN where pid is the process id of the streamget script
and NNN is the temporary file number incrementing from 000. final output
mp3 file is saved in pub_dir named prefix-YYYY-MM-DD-HH-MM.mp3 where
YYYY-MM-DD-HH-MM is the date and time that streamget was started rounded to
the nearest quarter hour. streamget also writes a playlist file to pub_dir
named prefix-YYYY-MM-DD-HH-MM.m3a. status messages are written to stderr.

TXT
    );
    exit;
}

if (in_array('-h', $argv)) {
    usage();
}

$log_prefix = date(DATE_ATOM) . " streamget.php:";

function checkOptions($s1, $s2)
{
    //
    // processes a pair of command line parameters. set corresponding globals.
    //
    global $pub_dir, $duration, $location, $title, $log_prefix;
    if ($s1 === '-p') {
        // sets $pub_dir
        $pub_dir = $s2;
    } elseif ($s1 === '-d') {
        // sets $duration of the audio fetched
        if (!is_numeric($s2)) {
            fwrite(STDERR, "$log_prefix specified duration is not a number\n");
            exit;
        } elseif (($s2 = intval($s2)) == 0 || $s2 < 60 || $s2 > 86400) {
            fwrite(STDERR, "$log_prefix duration must be an integer between 60 and 86400 \n");
            exit;
        } else {
            $duration = $s2;
        }
    } elseif ($s1 === '-l') {
        // $location of public mp3 file, used in m3u files
        $location = preg_replace('/\/*$/', '', $s2);
        $location = $location == '' ? '' : "$location/";
    } elseif ($s1 === '-t') {
        // $title of stream, used in m3u files
        $title = $s2;
    } else {
        usage();
    }
}

// check for suitable number of arguments
if ($argc % 2 == 0 || $argc < 3 || $argc > 11) {
    usage();
}

// set default configuration globals
$streamUrl = $argv[$argc - 2];
$prefix = $argv[$argc - 1];
// duration of audio data to fetch
$duration = 62 * 60;
$title = $prefix;

// process command line arguments, overriding defaults
for ($j = 1; $j <= $argc - 3; $j += 2) {
    checkOptions($argv[$j], $argv[$j + 1]);
}

// check usability of $pub_dir
if (!is_dir($pub_dir)) {
    fwrite(STDERR, "$log_prefix \"$pub_dir\" is not a directory\n");
    exit;
}
if (!is_writable($pub_dir)) {
    fwrite(STDERR, "$log_prefix directory \"$pub_dir\" is not writable\n");
    exit;
}

// set remaining config globals
// output file name
$audioFilePrefix = $prefix . '-' . posix_getpid();
// max number of consecutive curls
$maxAttempts = 100;
// block size
$blockSize = 64 * 1024;
// bits per second (perhaps should passed as arg)
$streamBitrate = 128000;
// min duration in seconds of audio chunk worth keeping
$minChunk = 5;
// max delay inserted between consecutive curl attempts
$maxRetryDelay = 30;

// warn on low disk space.
$diskFreeSpace = disk_free_space($pub_dir);
if ($diskFreeSpace < round(($streamBitrate / 8 * $duration) * 4)) {
    $diskFreeSpace = number_format($diskFreeSpace / 1024 / 1024, 1);
    fwrite(STDERR, "$log_prefix warning: low disk free space: $diskFreeSpace MiB\n");
}

function curlget($streamurl, $f, $duration)
{
    //
    // uses curl to download audio data:
    //   for $duration minutes
    //   from location $streamurl
    //   saving the data into a file. $f is an open, writable file handle.
    // returns: array returned by PHP curl_getinfo() function with return
    //   value from curl_error() appended in element 'curl_error'
    //
    global $streamBitrate, $minChunk;
    $devnul = fopen('/dev/null', 'a');
    $ch = curl_init();
    curl_setopt_array($ch, array(
            CURLOPT_URL => $streamurl,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_HEADER => true,
            CURLOPT_LOW_SPEED_LIMIT => $streamBitrate / 8 / 4,
            CURLOPT_LOW_SPEED_TIME => $minChunk * 4,
            CURLOPT_TIMEOUT => $duration,
            CURLOPT_FILE => $f,
            CURLOPT_STDERR => $devnul,
    ));
    curl_exec($ch);
    $info = curl_getinfo($ch);
    $info['curl_errno'] = curl_errno($ch);
    $info['curl_error'] = curl_error($ch);
    curl_close($ch);
    fclose($devnul);

    return ($info);
}

//
// what's the time?
// round it to the nearest 15 minutes so that file names etc. will look clean
// to users of the archives
//
$startTime = time();
date_default_timezone_set('America/New_York');
$m1 = intval(date('i', $startTime));
$m2 = $m1 % 15;
$m3 = $m2 < 8 ? $m1 - $m2 : $m1 - $m2 + 15;
if ($m3 == 60) {
    $m3 = 0;
    $m4 = (15 - $m2) * 60;
} else {
    $m4 = 0;
}
$datetime = date('Y-m-d-H-', $startTime + $m4) . sprintf('%02d', $m3);

// the following while loop repeatedly attempts to download audio data for
// a total of $duration minutes, up to a the repetition limit $maxattempts.
// each attempt involves:
// - open a temp output file named prefix-pid-NNN in the current working
//   directory. see usage message for more.
// - calls curlget() to download audio data into the file
// all temp output files are kept.
//
// if a curl attempt fails quickly then a short delay is inserted before
// the next curl attempt. the delay increases on each failure up to a limit
// if attempts repeatedly fail in quick succession.
$curlTime = time();
$curlDelay = 0;
$audioFiles = array();
$attempt = 1;
$endTime = $startTime + $duration;
do {
    $audioFile = "$audioFilePrefix-" . sprintf('%03d', $attempt);

    $audioFilePointer = fopen($audioFile, 'w');
    if ($audioFilePointer === false) {
        fwrite(STDERR, "$log_prefix fopen output file failed\n");

        break;
    }

    sleep($curlDelay);
    $curlTime = time();
    $curlinfo = curlget($streamUrl, $audioFilePointer, $startTime + $duration - time());
    $curlTime = time() - $curlTime;

    if ($curlinfo['curl_errno'] !== 0 && !($curlinfo['curl_errno'] === 28 && $curlTime > 3600)) {
        $message = implode(' ', [
                $log_prefix,
                'curl error',
                '(' . $curlinfo['curl_errno'] . ')',
                $curlinfo['curl_error'],
                'http:' . $curlinfo['http_code'],
                'Ctt:' . $curlinfo['total_time'],
                'elapsed:' . $curlTime,
        ]);
        fwrite(STDERR, $message . "\n");
    }

    if (fclose($audioFilePointer) === false) {
        fwrite(STDERR, "$log_prefix fclose failed on: $audioFile. deleting\n");
        @unlink($audioFile);

        exit;
    }

    if (filesize($audioFile) < $minChunk * $streamBitrate / 8) {
        fwrite(STDERR, "$log_prefix deleting: $audioFile\n");
        unlink($audioFile);
    } else {
        $audioFiles[] = $audioFile;
    }

    $curlDelay = min($maxRetryDelay, max(0, $curlDelay + 2 - $curlTime));

    $attempt += 1;
} while (time() < $endTime - (2 * $minChunk) && $attempt < $maxAttempts);

if (empty($audioFiles)) {
    fwrite(STDERR, "$log_prefix failed to get any useful audio data\n");

    exit;
}

$outputFile = "$prefix-$datetime.mp3";
$outputFilePointer = fopen("$pub_dir/$outputFile", 'w');
if ($outputFilePointer === false) {
    fwrite(STDERR, "$log_prefix fopen $pub_dir/$outputFile for writing failed\n");
    exit;
}

// loop over the temporary files, copying data from them into the public mp3 file
foreach ($audioFiles as $audioFile) {
    $audioFilePointer = fopen($audioFile, 'r');
    $buffer = fread($audioFilePointer, $blockSize);

    // search for shoutcast stream headers
    if (preg_match('/^(ICY 200 OK.+\r\nicy-br:\d{1,3}\r\n\r\n)(.+)$/s', $buffer, $matches)) {
        // drop that header and write rest of block to public mp3 file
        fwrite($outputFilePointer, $matches[2]);
    } else {
        // log as anomalous and copy entire block
        fwrite(STDERR, "$log_prefix ICY headers not found in $audioFile\n");
        fwrite($outputFilePointer, $buffer);
    }

    while (!feof($audioFilePointer)) {
        $buffer = fread($audioFilePointer, $blockSize);
        if (!$buffer) {
            break;
        }
        fwrite($outputFilePointer, $buffer);
    }

    fclose($audioFilePointer);
    unlink($audioFile);
}

fclose($outputFilePointer);

$ofilesize = filesize("$pub_dir/$outputFile");
if ($ofilesize * 8 < $streamBitrate * 300) {
    // file too small (less than 5 minutes) so delete it
    fwrite(STDERR, "$log_prefix mp3 file less than 300 sec duration. deleting\n");
    unlink("$pub_dir/$outputFile");
    exit;
}

// otherwise, if all is well, write an m3u playlist file to reference the mp3 file
$playlistFileName = "$pub_dir/$prefix-$datetime.m3u";
$fileDuration = (int) floor($ofilesize * 8 / $streamBitrate);
file_put_contents($playlistFileName, "#EXTM3U\n#EXTINF:$fileDuration,$title\n$location$outputFile\n");
