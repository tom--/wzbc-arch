streamarch
==========


overview
========

the application consists of the following:

kickstream.php - run by cron hourly 1 min before the hour. deletes
expired files. defines the hourly archiving schedule. starts the
streamget.php script to fetch the audio data.

streamget.php - run by kickstream.php (or anything else if you like).
connects to a shoutcast stream server for a specified duration, saves
gotten data in an mp3 file for public access and writes m3u playlist
files to reference the mp3 files. incorporates retries in the case of
connection failure.

liststream.php - run by cron shortly after the top of the hour.
generates an html index file of m3u playlist files. gets data from
spinitron.com to annotate the m3u links. uses a template file and
puts a dynamically generated html table into it.

indextemplate.ihtml - the template file that liststream.php uses to
generate the html index file.

crontab - a crontab (5) format file to start kickstream.php and
liststream.php.

the scripts write status/error messages to stderr. the crontab shows
where they are logged.


layout
======

the php and ihtml files should be in one directory, also used as the
working directory for the scripts.

another directory called the public directory is made available by the
web server to the listeners. in the default layout, the public directory
is in the working directory.

streamget writes temporary audio data files to the working directory and
final mp3 audio and m3u playlist files to the public directory.
indextemplate.ihtml writes the index file to the public directory.

users running the scripts must have suitable permissions on the working
and public directories.


configuration
=============

details of the configuration options are given within the script files
and, for streamget.php and liststream.php, in their usage messages. most
configuration is in the top of kickstream.php and liststream.php and is
clearly commented. the only configuration global in streamget.php that
might need attention is $streambitrate;


app admin
=========

to monitor correct function of the app, check:

- application log files (currently error.log and wzbc.error.log)

- (with web browser) index.html that there are no gaps or hiccups
  in the table. it should be a solid 14 days of archive.

- that audio files are streaming ok.

- fsb's mbox for messages from cron (or wherever they go).

- that files in public are being deleted correctly.

- that disk usage for the app remains roughly constant (excepting
  log files.

- that the only temp files in working directory have the process id of
  the currently running streamget.php. delete any leftovers from dead
  streamget.php processes (see streamget.php usage message for details
  of temp file naming).


server admin
============

i noted some server configuration things in the TO-DO section of
zbconline.com-config.txt. besides that, and normal sysadmin routine stuff,
things i can think of are...

- archive apache logs carefully and securely, wzbc will need them for
  usage reporting to copyright societies.

- monitor bandwidth usage and keep historical data so that trends in
  usage can be observed.

- the max number of simultaneous listeners is probably controlled by
  MaxClients in httpd.conf, but...

- as the number of clients N increases, so will memory use (one httpd
  child process per client). at some N, probably N<256 (current
  MaxClients in httpd.conf), the system with thrash.

- might be worth tinkering with httpd to eliminate unused modules and
  save a bit of memory.

other than that, keep the server ship-shape, keep the bad guys out and
keep you clients happy -- the usual story.


Copyright and license
=====================

Copyright 2008 Tom Worster

Permission to use, copy, modify, and/or distribute this software for any purpose
with or without fee is hereby granted, provided that the above copyright notice
and this permission notice appear in all copies.

THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY AND
FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT, INDIRECT,
OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES WHATSOEVER RESULTING FROM LOSS OF USE, DATA
OR PROFITS, WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION,
ARISING OUT OF OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
