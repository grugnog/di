<?php
chdir('..');
$MIN_DAYS = 2;

// bail if we are already running
$lock = fopen('./tmp/archive.lock', 'w');
if ($lock) {
    if (flock($lock, LOCK_EX | LOCK_NB) == false) {
        fclose($lock);
        echo "Archive process is already running\n";
        exit(0);
    }
}

include 'common.inc';
require_once('archive.inc');
ignore_user_abort(true);
set_time_limit(3300);   // only allow it to run for 55 minutes

if (array_key_exists('archive_days', $settings)) {
    $MIN_DAYS = $settings['archive_days'];
}
$MIN_DAYS = max($MIN_DAYS,1);

$kept = 0;
$archiveCount = 0;
$deleted = 0;
$log = fopen('./cli/archive.log', 'w');

// check the old tests first
/*
*   Archive any tests that have not already been archived
*   We will also keep track of all of the tests that are 
*   known to have been archived separately so we don't thrash
*/  
$UTC = new DateTimeZone('UTC');

$now = time();
//CheckRelay();
CheckOldDir('./results/old');

// Archive the actual tests
$years = scandir('./results');
foreach( $years as $year ) {
    mkdir('./logs/archived', 0777, true);
    $yearDir = "./results/$year";
    if( is_numeric($year) && is_dir($yearDir) && $year != '.' && $year != '..' ) {
        $months = scandir($yearDir);
        foreach( $months as $month ) {
            $monthDir = "$yearDir/$month";
            if( is_dir($monthDir) && $month != '.' && $month != '..' ) {
                $days = scandir($monthDir);
                foreach( $days as $day ) {
                    $dayDir = "$monthDir/$day";
                    if( is_dir($dayDir) && $day != '.' && $day != '..' ) {
                        if (ElapsedDays($year, $month, $day) >= ($MIN_DAYS - 1)) {
                            CheckDay($dayDir, "$year$month$day");
                        }
                    }
                }
                rmdir($monthDir);
            }
        }
        rmdir($yearDir);
    }
}
echo "\nDone\n\n";

if( $log ) {
    fwrite($log, "Archived: $archiveCount\nDeleted: $deleted\nKept: $kept\n" . gmdate('r') . "\n");;
    fclose($log);
}

if ($lock) {
    fclose($lock);
}

/**
* Clean up the relay directory of old tests
* 
*/
function CheckRelay() {
    $dirs = scandir('./results/relay');
    $keys = parse_ini_file('./settings/keys.ini');
    foreach($dirs as $key) {
        if ($key != '.' && $key != '..') {
            $keydir = "./results/relay/$key";
            if (is_dir($keydir)) {
                if (array_key_exists($key, $keys)) {
                    echo "\rChecking relay tests for $key";
                    $years = scandir($keydir);
                    foreach( $years as $year ) {
                        if ($year != '.' && $year != '..') {
                            $yearDir = "$keydir/$year";
                            if (is_numeric($year)) {
                                if (ElapsedDays($year, 1, 1) < 10) {
                                    $months = scandir($yearDir);
                                    foreach( $months as $month ) {
                                        if ($month != '.' && $month != '..') {
                                            $monthDir = "$yearDir/$month";
                                            if (is_numeric($month)) {
                                                if (ElapsedDays($year, $month, 1) < 10) {
                                                    $days = scandir($monthDir);
                                                    foreach( $days as $day ) {
                                                        if ($day != '.' && $day != '..') {
                                                            $dayDir = "$monthDir/$day";
                                                            if (is_numeric($day)) {
                                                                if (ElapsedDays($year, $month, $day) >= 10) {
                                                                    delTree($dayDir);
                                                                }
                                                            } else {
                                                                if (is_file($dayDir)) {
                                                                    unlink($dayDir);
                                                                } else {
                                                                    delTree($dayDir);
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    delTree($monthDir);
                                                }
                                            } else {
                                                if (is_file($monthDir)) {
                                                    unlink($monthDir);
                                                } else {
                                                    delTree($monthDir);
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    delTree($yearDir);
                                }
                            } else {
                                if (is_file($yearDir)) {
                                    unlink($yearDir);
                                } else {
                                    delTree($yearDir);
                                }
                            }
                        }
                    }
                } else {
                    delTree($keydir);
                }
            } else {
                unlink($keydir);
            }
        }
    }
}

/**
* Recursively scan the old directory for tests
* 
* @param mixed $path
*/
function CheckOldDir($path) {
    $oldDirs = scandir($path);
    foreach( $oldDirs as $oldDir ) {
        if( $oldDir != '.' && $oldDir != '..' ) {
            // see if it is a test or a higher-level directory
            if( is_file("$path/$oldDir/testinfo.ini") )
                CheckTest("$path/$oldDir", $oldDir);
            else
                CheckOldDir("$path/$oldDir");
        }
    }
    rmdir($path);
}

/**
* Recursively check within a given day
* 
* @param mixed $dir
* @param mixed $baseID
* @param mixed $archived
*/
function CheckDay($dir, $baseID) {
    $tests = scandir($dir);
    foreach( $tests as $test ) {
        if( $test != '.' && $test != '..' ) {
            // see if it is a test or a higher-level directory
            if( is_file("$dir/$test/testinfo.ini") )
                CheckTest("$dir/$test", "{$baseID}_$test");
            else
                CheckDay("$dir/$test", "{$baseID}_$test");
        }
    }
    rmdir($dir);
}

/**
* Check the given log file for all tests that match
* 
* @param mixed $logFile
* @param mixed $match
*/
function CheckTest($testPath, $id) {
    global $archiveCount;
    global $deleted;
    global $kept;
    global $log;
    global $MIN_DAYS;
    $logLine = "$id : ";

    echo "\rArc:$archiveCount, Del:$deleted, Kept:$kept, Checking:" . str_pad($id,45);

    $elapsed = TestLastAccessed($id);
    if( $elapsed >= $MIN_DAYS ) {
        $delete = true;
        if (ArchiveTest($id) ) {
            $archiveCount++;
            $logLine .= "Archived";
                                                                                          
            if (!VerifyArchive($id) && $elapsed < 60) {
                $delete = false;
            }
        } else if ($elapsed < 60) {
            $delete = false;
        }
        
        if ($delete) {
            delTree("$testPath/");
            $deleted++;
            $logLine .= " Deleted";
        } else {
            $kept++;
        }
    } else {
        $logLine .= "Last Accessed $elapsed days";
        $kept++;
    }
        
    if( $log ) {
        $logLine .= "\n";
        fwrite($log, $logLine);
    }
}

/**
* Calculate how many days have passed since the given day
*/
function ElapsedDays($year, $month, $day) {
    global $now;
    global $UTC;
    $date = DateTime::createFromFormat('ymd', "$year$month$day", $UTC);
    $daytime = $date->getTimestamp();
    $elapsed = max($now - $daytime, 0) / 86400;
    return $elapsed;
}
?>
