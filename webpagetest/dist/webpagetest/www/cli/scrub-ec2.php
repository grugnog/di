<?php
chdir('..');
include 'common.inc';
include './cli/ec2-keys.inc.php';
include './ec2/sdk.class.php';
set_time_limit(0);
$minimum = true;
ob_start();

$counts = array();
foreach( $regions as $region => &$amiData ) {
    foreach( $amiData as $ami => &$regionData ) {
        $counts["$region.$ami"] = 0;
    }
}

$instanceType = 'm1.small';
if (isset($instanceSize)) {
    $instanceType = $instanceSize;
}

// we only terminate instances at the top of the hour, but we can add instances at other times
$addOnly = true;
$minute = (int)gmdate('i');
if( $minute < 5 || $minute > 55 )
    $addOnly = false;
$now = time();
    
echo "Fetching list of running instances...\n";
$ec2 = new AmazonEC2($keyID, $secret);
if( $ec2 )
{
    foreach( $regions as $region => &$amiData )
    {
        foreach( $amiData as $ami => &$regionData )
        {
            $location = $regionData['location'];
            echo "\n$region ($location):\n";
            $ec2->set_region($region);
            
            // load the valid testers in this location
            $testers = array();
            $locations = explode(',', $location);
            $locCount = count($locations);
            foreach($locations as $loc) {
                $loc_testers = json_decode(file_get_contents("./tmp/$loc.tm"), true);
                foreach ($loc_testers as $id => $info) {
                    $elapsed = 0;
                    if (array_key_exists('updated', $info) && $info['updated'] < $now) {
                        $elapsed = $now - $info['updated'];
                    }
                    if ($elapsed < 1800) {   // only count test machines that have contacted us in the last 30 minutes
                        if (!array_key_exists($id, $testers)) {
                            $testers[$id] = $info;
                            $testers[$id]['locCount'] = 1;
                        } else {
                            $testerLocCount = $testers[$id]['locCount'];
                            if (array_key_exists('test', $info) && strlen($info['test'])) {
                                $testers[$id] = $info;
                            }
                            $testers[$id]['locCount'] = $testerLocCount + 1;
                        }
                    }
                }
            }
            
            // if any testers have been known for more than 10 minutes and
            // they aren't showing up for all of the locations, reboot them
            $reboot = array();
            foreach ($testers as $id => $info) {
                $lifetime = 0;
                if (array_key_exists('first_contact', $info) && $info['first_contact'] < $now) {
                    $lifetime = $now - $info['first_contact'];
                }
                $busy = false;
                if (array_key_exists('test', $info) && strlen($info['test'])) {
                    $busy = true;
                }
                if ($info['locCount'] < $locCount && 
                    !$busy &&
                    $lifetime > 600) {
                    echo "$id needs to be rebooted\n";
                    $reboot[] = $id;
                }
            }
            
            if (count($reboot)) {
                $ec2->reboot_instances($reboot);
            }

            // get the list of current running ec2 instances        
            $terminate = array();
            $count = 0;
            $response = $ec2->describe_instances();
            $activeCount = 0;
            $idleCount = 0;
            $offlineCount = 0;
            if( $response->isOK() ) {
                foreach( $response->body->reservationSet->item as $item ) {
                    foreach( $item->instancesSet->item as $instance ) {
                        if( $instance->imageId == $ami && $instance->instanceState->code <= 16 ) {
                            $id = (string)$instance->instanceId;
                            if( array_key_exists($id, $testers) || $addOnly ) {
                                if( $testers[$id]['offline'] )
                                    $offlineCount++;
                                elseif( strlen($testers[$id]['test']) || $addOnly )
                                    $activeCount++;
                                else
                                    $idleCount++;
                            } else {
                                echo "$location - Unknown Tester: $id (state {$instance->instanceState->code})\n";
                                $terminate[] = $id;
                                $unknownCount++;
                            }
                            $count++;
                        }
                    }
                }
            }
            $termCount = count($terminate);
            $counts["$region.$ami"] = $count - $termCount;

            // figure out what the target number of testers for this location is
            // if we have any idle testers them plan to eliminate 50% of them
            // otherwise, increase the number until we kit the expected backlog
            echo "Active: $activeCount\n";
            echo "Idle: $idleCount\n";
            echo "Offline: $offlineCount\n";
            $targetCount = $activeCount;
            if( $idleCount ) {
                $targetCount = (int)($activeCount + ($idleCount / 4));
            } elseif( $targetBacklog ) {
                // get the current backlog
                $backlog = GetPendingTests($location, $bk, $avgTime);
                echo "Backlog: $backlog\n";
                if ($activeCount)
                    $ratio = $backlog / $activeCount;
                if( !$activeCount || $ratio > $targetBacklog )
                    $targetCount = (int)($backlog / $targetBacklog);
                echo "Target from Backlog: $targetCount\n";
            }
            $targetCount = max(min($targetCount,$regionData['max']), $regionData['min']);
            if( $targetCount > $regionData['min'] )
                $minimum = false;
            echo "Target: $targetCount (max = {$regionData['max']}, min = {$regionData['min']})\n";
            
            $needed = $targetCount - $counts["$region.$ami"];
            echo "Needed: $needed\n";
            if( $needed > 0 ) {
                echo "Adding $needed spot instances in $region...";
                $size = $instanceType;
                if (array_key_exists('size', $regionData)) {
                    $size = $regionData['size'];
                }
                $response = $ec2->request_spot_instances($regionData['price'], array(
                    'InstanceCount' => (int)$needed,
                    'Type' => 'one-time',
                    'LaunchSpecification' => array(
                        'ImageId' => $ami,
                        'InstanceType' => $size,
                        'UserData' => base64_encode($regionData['userdata'])
                    ),
                ));
                if( $response->isOK() )
                {
                    echo "ok\n";
                    $counts["$region.$ami"] += $needed;
                }
                else
                    echo "failed\n";
            } elseif( $needed < 0 && !$addOnly ) {
                // lock the location while we mark some free instances for decomm
                $count = abs($needed);
                $locations = explode(',', $location);
                foreach($locations as $loc) {
                    if( $lock = LockLocation($loc) ) {
                        $testers = json_decode(file_get_contents("./tmp/$loc.tm"), true);
                        if (count($testers)) {
                            foreach($testers as &$tester) {
                                if (array_key_exists('ec2', $tester) && strlen($tester['ec2']) && !$tester['offline']) {
                                    if( $count > 0 && !strlen($tester['test']) ) {
                                        $terminate[] = $tester['ec2'];
                                        $count--;
                                        $counts["$region.$ami"]--;
                                    }
                                    
                                    // see if this tester is on the terminate list (for testers that support multiple locations)
                                    foreach($terminate as $id) {
                                        if ($tester['ec2'] == $id) {
                                            $tester['offline'] = true;
                                        }
                                    }
                                }
                            }
                            file_put_contents("./tmp/$loc.tm", json_encode($testers));
                        }
                        UnlockLocation($lock);
                    }
                }
            }
            
            // final step, terminate the instances we don't need
            if (!$addOnly) {
                $termCount = count($terminate);
                echo "Terminating $termCount instances running in $region...";
                if( $termCount ) {
                    $response = $ec2->terminate_instances($terminate);
                    if( $response->isOK() )
                        echo "ok\n";
                    else
                        echo "failed\n";
                }
                else
                    echo "ok\n";
            }
        }
    }
}

echo "\n";
$summary = 'EC2 Counts:';
$countsTxt = "EC2 Instance counts:\n";
foreach( $counts as $region => $count )
{
    $countsTxt .= "  $count : $region\n";
    $summary .= " $count";
}
echo $countsTxt;

$detail = ob_get_flush();

// send out a mail message if we are not running at the minimum levels
if( !$addOnly && !$minimum )
    mail('pmeenan@webpagetest.org', $summary, $detail );
?>
