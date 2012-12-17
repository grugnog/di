<?php
/**
* Update the feeds
* 
*/
function UpdateFeeds()
{
    if( !is_dir('./tmp') )
        mkdir('./tmp', 0777);

    $feedData = array();

    $lockFile = fopen( './tmp/feeds.lock', 'w',  false);
    if( $lockFile )
    {
        // make sure we're not trying to update the same feeds in parallel
        if( flock($lockFile, LOCK_EX | LOCK_NB) )
        {
            // load the list of feeds
            require_once('./settings/feeds.inc');
            require_once('./lib/simplepie.inc');

            // loop through and update each one
            foreach( $feeds as $category => &$feedList )
            {
                $feedData[$category] = array();
                
                foreach( $feedList as $feedSource => $feedUrl )
                {
                    $feedUrl = trim($feedUrl);
                    if( strlen($feedUrl) )
                    {
                        $feed = new SimplePie();
                        if( $feed )
                        {
                            $ctx = stream_context_create(array(
                                'http' => array(
                                    'header' => 'Connection: close',
                                    'timeout' => 10
                                    )
                                )
                            );
                            $rawFeed = trim(file_get_contents($feedUrl,0 , $ctx));
                            $feed->set_raw_data($rawFeed);
                            $feed->enable_cache(false);
                            $feed->init();

                            // try sanitizing the data if we have a problem parsing the feed
                            if( strlen($feed->error()) )
                            {                
                                FixFeed($rawFeed);
                                $feed->set_raw_data($rawFeed);
                                $feed->enable_cache(false);
                                $feed->init();
                            }

                            foreach ($feed->get_items() as $item)
                            {
                                $dateStr = $item->get_date(DATE_RSS);
                                if( $dateStr && strlen($dateStr) )
                                {
                                    $date = strtotime($dateStr);
                                    if( $date )
                                    {
                                        // only include articles from the last 30 days
                                        $now = time();
                                        $elapsed = 0;
                                        if( $now > $date )
                                            $elapsed = $now - $date;
                                        $days = (int)($elapsed / 86400);
                                        if( $days <= 30 )
                                        {
                                            // make sure we don't have another article from the exact same time
                                            while(isset($feedData[$category][$date]))
                                                $date++;

                                            $feedData[$category][$date] = array ( 
                                                    'source' => $feedSource,
                                                    'title' => $item->get_title(),
                                                    'link' => urldecode($item->get_permalink()),
                                                    'date' => $dateStr
                                                );
                                        }
                                    }
                                }

                                $item->__destruct();
                            }
                            
                            $feed->__destruct();
                            unset($feed);
                        }
                    }
                }
                
                if( count($feedData[$category]) )
                    krsort($feedData[$category]);
            }

            // save out the feed data
            file_put_contents('./tmp/feeds.dat', json_encode($feedData));
            fclose($lockFile);
        }
    }
}

/**
* MyBB has a busted feed creator so go through and remove any invalid characters
* 
* @param mixed $rawFeed
*/
function FixFeed(&$rawFeed)
{
    $rawFeed = preg_replace('/[^(\x20-\x7F)]*/', '', $rawFeed);
}
?>
